<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Plugin;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Plugin\ClientFactoryPlugin;
use MagoAssistant\ClaudeSubscription\Service\Bridge\PlatformFactory;
use MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClient;
use MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClientFactory;
use MagoAssistant\ClaudeSubscription\Service\Client\UsageDecorator;
use MagoAssistant\ClaudeSubscription\Service\Translation\RequestTranslator;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\AvailableBridge;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeProxy;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeSelector;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeService;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FieldFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\ClientFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientFactoryPluginTest extends TestCase
{
    /**
     * Arguments the plugin handed to the client factory, or null when it did not build a client.
     *
     * @var array<string, mixed>|null
     */
    private ?array $built = null;

    /**
     * How many clients went through the usage decorator.
     *
     * @var int
     */
    private int $wrapped = 0;

    #[Test]
    public function createByIdOfOurRowBuildsTheClientAndSkipsProceed(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-1', 'claude_subscription', ['base_url' => 'http://proxy.test/v1', 'model' => 'opus']),
        ]);

        $client = $plugin->aroundCreateById($this->subject(), $this->neverProceed(), 'row-1', 'mago');

        self::assertInstanceOf(ClaudeSubscriptionClient::class, $client);
        self::assertSame(
            ['baseUrl' => 'http://proxy.test/v1', 'model' => 'opus', 'serviceId' => 'row-1', 'consumer' => 'mago'],
            $this->built
        );
        self::assertSame('mago', $client->getConsumer());
        self::assertSame(1, $this->wrapped);
    }

    #[Test]
    public function createByIdOfAnotherProviderProceedsUntouched(): void
    {
        $plugin = $this->plugin([new FakeService('row-2', 'openai', [])]);
        $seen = null;
        $proceed = function (...$args) use (&$seen): AiClientInterface {
            $seen = $args;
            return $this->createStub(AiClientInterface::class);
        };

        $plugin->aroundCreateById($this->subject(), $proceed, 'row-2', 'mago');

        self::assertSame(['row-2', 'mago'], $seen);
        self::assertNull($this->built);
    }

    #[Test]
    public function aNamedCodeOfOursIsServedHere(): void
    {
        $plugin = $this->plugin([new FakeService('row-1', 'claude_subscription', ['model' => 'haiku'])]);

        $client = $plugin->aroundCreate($this->subject(), $this->neverProceed(), 'claude_subscription', null);

        self::assertSame('haiku', $client->getModel());
        self::assertSame('unknown', $client->getConsumer());
    }

    #[Test]
    public function aNamedCodeOfAnotherProviderProceedsUntouched(): void
    {
        $plugin = $this->plugin([new FakeService('row-2', 'openai', [])]);
        $seen = null;
        $proceed = function (?string $code, ?string $consumer) use (&$seen): AiClientInterface {
            $seen = [$code, $consumer];
            return $this->createStub(AiClientInterface::class);
        };

        $plugin->aroundCreate($this->subject(), $proceed, 'openai', 'c');

        self::assertSame(['openai', 'c'], $seen);
    }

    #[Test]
    public function automaticSelectionSkipsAnUnavailableRowAndThenUsesOurs(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-9', 'missing', []),
            new FakeService('row-1', 'claude_subscription', ['model' => 'sonnet']),
        ]);

        $client = $plugin->aroundCreate($this->subject(), $this->neverProceed(), null, 'mago');

        self::assertSame('sonnet', $client->getModel());
    }

    #[Test]
    public function automaticSelectionProceedsWhenAnAvailableBridgeComesFirst(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-2', 'openai', []),
            new FakeService('row-1', 'claude_subscription', ['model' => 'sonnet']),
        ]);
        $seen = null;
        $proceed = function (?string $code, ?string $consumer) use (&$seen): AiClientInterface {
            $seen = [$code, $consumer];
            return $this->createStub(AiClientInterface::class);
        };

        $plugin->aroundCreate($this->subject(), $proceed, null, 'mago');

        self::assertSame([null, 'mago'], $seen);
        self::assertNull($this->built);
    }

    #[Test]
    public function aRowWithoutAModelNamesTheProvider(): void
    {
        $plugin = $this->plugin([new FakeService('row-1', 'claude_subscription', ['base_url' => 'http://p/v1'])]);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pick a model on the Claude Subscription row');

        $plugin->aroundCreateById($this->subject(), $this->neverProceed(), 'row-1', null);
    }

    #[Test]
    public function theBaseUrlIsTrimmedLikeTheModelListPath(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-1', 'claude_subscription', ['base_url' => ' http://p/v1/ ', 'model' => 'sonnet']),
        ]);

        $plugin->aroundCreate($this->subject(), $this->neverProceed(), null, null);

        self::assertSame('http://p/v1', $this->built['baseUrl'] ?? null);
    }

    #[Test]
    public function aMissingBaseUrlUsesTheDockerHostDefault(): void
    {
        $plugin = $this->plugin([new FakeService('row-1', 'claude_subscription', ['model' => 'sonnet'])]);

        $plugin->aroundCreate($this->subject(), $this->neverProceed(), null, null);

        self::assertSame(ClaudeSubscription::DEFAULT_BASE_URL, $this->built['baseUrl'] ?? null);
    }

    /**
     * @param AiServiceInterface[] $rows
     */
    private function plugin(array $rows): ClientFactoryPlugin
    {
        $this->built = null;
        $this->wrapped = 0;

        $clients = $this->createStub(ClaudeSubscriptionClientFactory::class);
        $clients->method('create')->willReturnCallback(function (array $data): ClaudeSubscriptionClient {
            $this->built = $data;
            return new ClaudeSubscriptionClient(new FakeProxy(), new RequestTranslator(), ...$data);
        });
        $usage = $this->createStub(UsageDecorator::class);
        $usage->method('wrap')->willReturnCallback(function (AiClientInterface $client): AiClientInterface {
            $this->wrapped++;
            return $client;
        });

        return new ClientFactoryPlugin(
            new FakeSelector($rows),
            new BridgeRegistry([
                'openai' => ['factory' => AvailableBridge::class],
                'claude_subscription' => ['factory' => PlatformFactory::class],
            ]),
            new ClaudeSubscription(new FieldFactory(), $this->createStub(JsonFetcherInterface::class)),
            $clients,
            $usage,
        );
    }

    private function subject(): ClientFactory
    {
        return $this->createStub(ClientFactory::class);
    }

    private function neverProceed(): callable
    {
        return static function (): never {
            throw new \RuntimeException('proceed must not be called');
        };
    }
}
