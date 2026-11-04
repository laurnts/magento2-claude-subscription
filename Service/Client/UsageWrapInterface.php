<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Client;

use MageOS\AiBase\Api\AiClientInterface;

/**
 * Wraps a client so MageOS can record the call when usage tracking is on.
 */
interface UsageWrapInterface
{
    /**
     * Return the client to hand back from the factory.
     *
     * @param AiClientInterface $client
     * @return AiClientInterface
     */
    public function wrap(AiClientInterface $client): AiClientInterface;
}
