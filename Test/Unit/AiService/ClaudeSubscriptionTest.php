<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\AiService;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClaudeSubscriptionTest extends TestCase
{
    #[Test]
    public function itAdvertisesClaudeSubscriptionAndTheCuratedModels(): void
    {
        $service = new ClaudeSubscription(new MockHttpClient());

        self::assertSame('claude_subscription', $service->getCode());
        self::assertSame('Claude Subscription', $service->getName());
        self::assertSame('Sonnet', $service->getSupportedModels()['sonnet']);
        self::assertArrayHasKey('opus', $service->getSupportedModels());
        self::assertArrayHasKey('fable', $service->getSupportedModels());
        self::assertArrayHasKey('haiku', $service->getSupportedModels());

        $fields = [];
        foreach ($service->getConfigurationFields() as $field) {
            $fields[$field->getName()] = $field;
        }
        self::assertSame(ClaudeSubscription::DEFAULT_BASE_URL, $fields['base_url']->getDefault());
        self::assertSame('sonnet', $fields['model']->getDefault());
        self::assertSame('select', $fields['model']->getType());
        self::assertArrayNotHasKey('api_key', $fields);
    }

    #[Test]
    public function fetchModelsReadsTheOpenAiList(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse('{"data":[{"id":"claude-sonnet-4"},{"id":"custom"}]}');
        });
        $service = new ClaudeSubscription($http);

        $models = $service->fetchModels([
            'base_url' => 'http://host.docker.internal:3456/v1/',
        ]);

        self::assertSame(['claude-sonnet-4' => 'claude-sonnet-4', 'custom' => 'custom'], $models);
        self::assertSame('GET', $seen['method']);
        self::assertSame('http://host.docker.internal:3456/v1/models', $seen['url']);
        self::assertArrayNotHasKey('authorization', $seen['options']['normalized_headers'] ?? []);
        self::assertSame(20, (int) $seen['options']['timeout']);
    }

    #[Test]
    public function fetchModelsNamesTheUrlWhenTheBodyIsNotAList(): void
    {
        $http = new MockHttpClient(new MockResponse('{"nope":true}'));

        try {
            (new ClaudeSubscription($http))->fetchModels([]);
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('not a model list', $e->getMessage());
            $this->assertStringContainsString('http://host.docker.internal:3456/v1/models', $e->getMessage());
        }
    }

    #[Test]
    public function fetchModelsNamesTheUrlWhenTheHostIsDown(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });

        try {
            (new ClaudeSubscription($http))->fetchModels(['base_url' => 'http://proxy.test/v1']);
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('not reachable', $e->getMessage());
            $this->assertStringContainsString('http://proxy.test/v1/models', $e->getMessage());
        }
    }

    #[Test]
    public function fetchModelsThrowsHttpStatusOnNon2xxBeforeParsingBody(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>', ['http_code' => 502]));

        try {
            (new ClaudeSubscription($http))->fetchModels([]);
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
            $this->assertStringContainsString(ClaudeSubscription::DEFAULT_BASE_URL . '/models', $e->getMessage());
        }
    }
}
