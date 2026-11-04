<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Client;

use MagoAssistant\ClaudeSubscription\Model\Client\PlatformFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformFactoryTest extends TestCase
{
    #[Test]
    public function createPlatformThrowsLocalizedException(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Claude Subscription does not use a Symfony bridge.');

        (new PlatformFactory())->createPlatform();
    }
}
