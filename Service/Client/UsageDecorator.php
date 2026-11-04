<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Client;

use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Model\Client\RecordingAiClientFactory;
use MageOS\AiBase\Model\Usage\UsageConfig;

/**
 * Applies AiBase usage recording when the admin has enabled it, as ClientFactory does for bridges.
 */
class UsageDecorator
{
    /**
     * @param UsageConfig $usageConfig
     * @param RecordingAiClientFactory $recordingFactory
     */
    public function __construct(
        private readonly UsageConfig $usageConfig,
        private readonly RecordingAiClientFactory $recordingFactory,
    ) {
    }

    /**
     * The client itself, or the recording decorator around it.
     *
     * @param AiClientInterface $client
     * @return AiClientInterface
     */
    public function wrap(AiClientInterface $client): AiClientInterface
    {
        if (!$this->usageConfig->isEnabled()) {
            return $client;
        }

        return $this->recordingFactory->create(['delegate' => $client]);
    }
}
