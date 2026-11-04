<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Bridge marker so BridgeRegistry::isAvailable() returns true for claude_subscription in production.
 *
 * Claude Subscription does not use a Symfony AI bridge; this class only satisfies the
 * class_exists + method_exists check so the admin service list shows the row.
 */
final class PlatformFactory
{
    /**
     * Not a real platform factory. Throws to prevent accidental use.
     *
     * @return never
     * @throws LocalizedException
     */
    public function createPlatform(): never
    {
        throw new LocalizedException(
            new Phrase('Claude Subscription does not use a Symfony bridge.')
        );
    }
}
