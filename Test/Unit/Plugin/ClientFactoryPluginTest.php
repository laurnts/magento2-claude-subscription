<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Plugin;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Plugin\ClientFactoryPlugin;
use MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClient;
use MagoAssistant\ClaudeSubscription\Service\Translation\RequestTranslator;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\AvailableBridge;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeProxy;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeSelector;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeService;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeUsage;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\ClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientFactoryPluginTest extends TestCase
{
    /**
     * Proxy captured from the last client the plugin built.
     *
     * @var FakeProxy
     */
    private FakeProxy $proxy;

    #[Test]
    public function createByIdOfClaudeSubscriptionDoesNotCallProceed(): void
    {
        $usage = new FakeUsage();
        $plugin = $this->plugin(
            [new FakeService('row-1', 'claude_subscription', ['model' => 'claude-sonnet-4'])],
            $usage
        );
        $proceeded = false;

        $client = $plugin->aroundCreateById(
            $this->createStub(ClientFactory::class),
            static function () use (&$proceeded): never {
                $proceeded = true;
                throw new \RuntimeException('proceed');
            },
            'row-1',
            'mago'
        );

        self::assertFalse($proceeded);
        self::assertInstanceOf(ClaudeSubscriptionClient::class, $client);
        self::assertSame('mago', $client->getConsumer());
        self::assertTrue($usage->wrapped);
    }

    #[Test]
    public function createByIdOfOpenAiCallsProceed(): void
    {
        $plugin = $this->plugin([new FakeService('row-2', 'openai', [])], new FakeUsage());
        $seen = null;

        $plugin->aroundCreateById(
            $this->createStub(ClientFactory::class),
            static function (...$args) use (&$seen): AiClientInterface {
                $seen = $args;
                return new ClaudeSubscriptionClient(
                    new FakeProxy(),
                    new RequestTranslator(),
                    'http://x',
                    'm',
                    'row-2',
                    'mago'
                );
            },
            'row-2',
            'mago'
        );

        self::assertSame(['row-2', 'mago'], $seen);
    }

    #[Test]
    public function automaticSelectionUsesClaudeSubscriptionWhenItIsTheOnlyRow(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-1', 'claude_subscription', [
                'base_url' => 'http://proxy.test/v1',
                'model' => 'claude-sonnet-4',
            ]),
        ], new FakeUsage());
        $proceeded = false;

        $client = $plugin->aroundCreate(
            $this->createStub(ClientFactory::class),
            static function () use (&$proceeded): never {
                $proceeded = true;
                throw new \RuntimeException('proceed');
            },
            null,
            null
        );

        self::assertFalse($proceeded);
        self::assertSame('claude_subscription', $client->getServiceCode());
        self::assertSame('unknown', $client->getConsumer());
    }

    #[Test]
    public function automaticSelectionSkipsAnUnavailableProviderAndThenUsesClaudeSubscription(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-9', 'missing', []),
            new FakeService('row-1', 'claude_subscription', ['model' => 'claude-haiku-4']),
        ], new FakeUsage());

        $client = $plugin->aroundCreate(
            $this->createStub(ClientFactory::class),
            static function (): never {
                throw new \RuntimeException('proceed');
            },
            null,
            'mago'
        );

        self::assertSame('claude-haiku-4', $client->getModel());
    }

    #[Test]
    public function aNamedOpenAiCodeStillProceeds(): void
    {
        $plugin = $this->plugin([new FakeService('row-2', 'openai', [])], new FakeUsage());
        $seen = null;

        $plugin->aroundCreate(
            $this->createStub(ClientFactory::class),
            static function (?string $code, ?string $consumer) use (&$seen): AiClientInterface {
                $seen = [$code, $consumer];
                return new ClaudeSubscriptionClient(
                    new FakeProxy(),
                    new RequestTranslator(),
                    'http://x',
                    'm',
                    'row-2',
                    'c'
                );
            },
            'openai',
            'c'
        );

        self::assertSame(['openai', 'c'], $seen);
    }

    #[Test]
    public function aPartialRowWithProxySettingsButNoModelThrows(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-1', 'claude_subscription', ['base_url' => 'http://proxy.test/v1']),
        ], new FakeUsage());
        $this->expectExceptionMessage('Pick a model on the Claude Subscription row');

        $plugin->aroundCreate(
            $this->createStub(ClientFactory::class),
            static function (): never {
                throw new \RuntimeException('proceed');
            },
            null,
            null
        );
    }

    #[Test]
    public function createByIdWithoutAModelTellsTheAdminToPickOne(): void
    {
        $plugin = $this->plugin([new FakeService('row-1', 'claude_subscription', [])], new FakeUsage());
        $this->expectExceptionMessage('Pick a model on the Claude Subscription row');

        $plugin->aroundCreateById(
            $this->createStub(ClientFactory::class),
            static function (): never {
                throw new \RuntimeException('proceed');
            },
            'row-1',
            null
        );
    }

    #[Test]
    public function aMissingBaseUrlUsesTheDockerHostDefault(): void
    {
        $plugin = $this->plugin([
            new FakeService('row-1', 'claude_subscription', ['model' => 'claude-sonnet-4']),
        ], new FakeUsage());
        $client = $plugin->aroundCreate(
            $this->createStub(ClientFactory::class),
            static function (): never {
                throw new \RuntimeException('proceed');
            },
            null,
            null
        );

        $client->chat(new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]));

        self::assertSame(ClaudeSubscription::DEFAULT_BASE_URL, $this->proxy->baseUrl);
    }

    /**
     * @param AiServiceInterface[] $rows
     */
    private function plugin(array $rows, FakeUsage $usage): ClientFactoryPlugin
    {
        $this->proxy = new FakeProxy();
        return new ClientFactoryPlugin(
            new FakeSelector($rows),
            new BridgeRegistry(['openai' => ['factory' => AvailableBridge::class]]),
            new RequestTranslator(),
            $this->proxy,
            $usage,
        );
    }
}
