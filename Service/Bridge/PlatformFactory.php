<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Bridge;

use Magento\Framework\Exception\LocalizedException;

/**
 * Bridge marker that makes BridgeRegistry::isAvailable() true for claude_subscription.
 *
 * MageOS AiBase treats a provider as usable when its registered factory class exists and has a
 * createPlatform() method. This provider is served by ClientFactoryPlugin rather than a Symfony
 * AI platform, so createPlatform() must never be reached.
 */
class PlatformFactory
{
    /**
     * Refuse: the plugin builds the client before AiBase asks for a platform.
     *
     * @return never
     * @throws LocalizedException
     */
    public function createPlatform(): never
    {
        throw new LocalizedException(__('Claude Subscription does not use a Symfony AI platform.'));
    }
}
