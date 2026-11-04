<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Proxy;

use MagoAssistant\ClaudeSubscription\Service\Proxy\HttpProxyClient;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpProxyClientTest extends TestCase
{
    #[Test]
    public function aCompletionSendsNoAuthorizationHeader(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse(
                '{"choices":[{"message":{"content":"hello"}}],"usage":{"prompt_tokens":2,"completion_tokens":3}}'
            );
        });
        $client = new HttpProxyClient(new NullLogger(), $http);

        $result = $client->complete(
            'http://host.docker.internal:3456/v1/',
            'claude-sonnet-4',
            [['role' => 'user', 'content' => 'Hi']],
            null
        );

        self::assertSame('POST', $seen['method']);
        self::assertSame('http://host.docker.internal:3456/v1/chat/completions', $seen['url']);
        self::assertArrayNotHasKey('authorization', $seen['options']['normalized_headers'] ?? []);
        $body = json_decode((string) $seen['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('claude-sonnet-4', $body['model']);
        self::assertFalse($body['stream']);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertArrayNotHasKey('user', $body);
        self::assertSame(['content' => 'hello', 'prompt_tokens' => 2, 'completion_tokens' => 3], $result);
    }

    #[Test]
    public function aMaxTokensValueIsSent(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;
            return new MockResponse('{"choices":[{"message":{"content":""}}]}');
        });

        $result = (new HttpProxyClient(new NullLogger(), $http))->complete('http://proxy.test/v1', 'm', [], 128);

        self::assertArrayNotHasKey('authorization', $seen['normalized_headers'] ?? []);
        $body = json_decode((string) $seen['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(128, $body['max_tokens']);
        self::assertSame(0, $result['prompt_tokens']);
        self::assertSame(0, $result['completion_tokens']);
    }

    #[Test]
    public function aStreamYieldsDeltasAndStopsOnDone(): void
    {
        $body = ":ok\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]"
            . ",\"usage\":{\"prompt_tokens\":4,\"completion_tokens\":5}}\n\n"
            . "data: [DONE]\n\n";
        $http = new MockHttpClient(
            new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']])
        );
        $client = new HttpProxyClient(new NullLogger(), $http);

        $stream = $client->stream('http://proxy.test/v1', 'm', [], null);
        $chunks = [];
        foreach ($stream as $delta) {
            $chunks[] = $delta;
        }

        self::assertSame(['Hel', 'lo'], $chunks);
        self::assertSame(['content' => '', 'prompt_tokens' => 4, 'completion_tokens' => 5], $stream->getReturn());
    }

    #[Test]
    public function aConnectionFailureNamesTheHostCommands(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });
        $client = new HttpProxyClient(new NullLogger(), $http);

        try {
            $client->complete('http://proxy.test/v1', 'm', [], null);
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('http://proxy.test/v1/chat/completions', $e->getMessage());
            $this->assertStringContainsString('claude auth login', $e->getMessage());
            $this->assertStringContainsString('bin/claude-subscription', $e->getMessage());
        }
    }

    #[Test]
    public function aStreamDropAfterOpenNamesTheHostCommands(): void
    {
        $mockResponse = $this->createStub(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturn($mockResponse);
        $http->method('stream')->willThrowException(new TransportException('connection dropped mid-stream'));

        $client = new HttpProxyClient(new NullLogger(), $http);

        try {
            $gen = $client->stream('http://proxy.test/v1', 'm', [], null);
            /** @phpstan-ignore-next-line */
            foreach ($gen as $_) {
            }
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('http://proxy.test/v1/chat/completions', $e->getMessage());
            $this->assertStringContainsString('claude auth login', $e->getMessage());
            $this->assertStringContainsString('bin/claude-subscription', $e->getMessage());
        }
    }

    #[Test]
    public function aProxyErrorMessageIsSurfaced(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"no"}}', ['http_code' => 401]));
        $this->expectExceptionMessage('no');

        (new HttpProxyClient(new NullLogger(), $http))->complete('http://proxy.test/v1', 'm', [], null);
    }
}
