<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Bridge;

use MagoAssistant\ClaudeSubscription\Service\Bridge\PlatformFactory;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformFactoryTest extends TestCase
{
    #[Test]
    public function theMarkerMakesTheProviderAvailableToAiBase(): void
    {
        $registry = new BridgeRegistry(['claude_subscription' => ['factory' => PlatformFactory::class]]);

        self::assertTrue($registry->isSupported('claude_subscription'));
        self::assertTrue($registry->isAvailable('claude_subscription'));
    }

    #[Test]
    public function createPlatformRefuses(): void
    {
        $this->expectException(LocalizedException::class);

        (new PlatformFactory())->createPlatform();
    }
}
