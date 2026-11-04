<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Fakes;

use MagoAssistant\ClaudeSubscription\Service\Client\UsageWrapInterface;
use MageOS\AiBase\Api\AiClientInterface;

/**
 * Usage wrap that records it was asked.
 */
final class FakeUsage implements UsageWrapInterface
{
    /**
     * @var bool
     */
    public bool $wrapped = false;

    /**
     * @inheritdoc
     */
    public function wrap(AiClientInterface $client): AiClientInterface
    {
        $this->wrapped = true;
        return $client;
    }
}
