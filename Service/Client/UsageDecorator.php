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
 * Applies MageOS usage recording when the admin has enabled it.
 */
class UsageDecorator implements UsageWrapInterface
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
     * @inheritdoc
     */
    public function wrap(AiClientInterface $client): AiClientInterface
    {
        if (!$this->usageConfig->isEnabled()) {
            return $client;
        }
        return $this->recordingFactory->create(['delegate' => $client]);
    }
}
