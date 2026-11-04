<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Plugin;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClient;
use MagoAssistant\ClaudeSubscription\Service\Client\UsageWrapInterface;
use MagoAssistant\ClaudeSubscription\Service\Proxy\ProxyClientInterface;
use MagoAssistant\ClaudeSubscription\Service\Translation\RequestTranslator;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\ClientFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Returns the Claude Subscription client for that service row and leaves every other provider alone.
 */
class ClientFactoryPlugin
{
    /**
     * @param AiServiceSelectorInterface $selector
     * @param BridgeRegistry $bridges
     * @param RequestTranslator $requests
     * @param ProxyClientInterface $proxy
     * @param UsageWrapInterface $usage
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $selector,
        private readonly BridgeRegistry $bridges,
        private readonly RequestTranslator $requests,
        private readonly ProxyClientInterface $proxy,
        private readonly UsageWrapInterface $usage,
    ) {
    }

    /**
     * Named code, or the first usable row when the caller asked for automatic selection.
     *
     * @param ClientFactory $subject
     * @param callable $proceed
     * @param string|null $serviceCode
     * @param string|null $consumer
     * @return AiClientInterface
     * @throws LocalizedException
     */
    public function aroundCreate(
        ClientFactory $subject,
        callable $proceed,
        ?string $serviceCode = null,
        ?string $consumer = null
    ): AiClientInterface {
        if ($serviceCode !== null) {
            $row = $this->selector->getByCode($serviceCode)[0] ?? null;
            if ($row !== null && $row->getCode() === ClaudeSubscription::CODE) {
                return $this->clientFor($row, $consumer);
            }
            return $proceed($serviceCode, $consumer);
        }
        foreach ($this->selector->getAll() as $row) {
            $ours = $row->getCode() === ClaudeSubscription::CODE;
            if (!$ours && !$this->bridges->isAvailable($row->getCode())) {
                continue;
            }
            if ($ours) {
                return $this->clientFor($row, $consumer);
            }
            return $proceed(null, $consumer);
        }
        return $proceed(null, $consumer);
    }

    /**
     * One configured row, by the id Mago stored.
     *
     * @param ClientFactory $subject
     * @param callable $proceed
     * @param string $serviceId
     * @param string|null $consumer
     * @return AiClientInterface
     * @throws LocalizedException
     */
    public function aroundCreateById(
        ClientFactory $subject,
        callable $proceed,
        string $serviceId,
        ?string $consumer = null
    ): AiClientInterface {
        $row = $this->selector->getById($serviceId);
        if ($row !== null && $row->getCode() === ClaudeSubscription::CODE) {
            return $this->clientFor($row, $consumer);
        }
        return $proceed($serviceId, $consumer);
    }

    /**
     * Build the client from the decrypted row.
     *
     * @param AiServiceInterface $row
     * @param string|null $consumer
     * @return AiClientInterface
     * @throws LocalizedException
     */
    private function clientFor(AiServiceInterface $row, ?string $consumer): AiClientInterface
    {
        $configuration = $row->getConfiguration();
        $baseUrl = $configuration['base_url'] ?? '';
        if (!is_string($baseUrl) || $baseUrl === '') {
            $baseUrl = ClaudeSubscription::DEFAULT_BASE_URL;
        }
        $model = $configuration['model'] ?? '';
        if (!is_string($model) || $model === '') {
            throw new LocalizedException(__('Pick a model on the Claude Subscription row before using it.'));
        }
        $client = new ClaudeSubscriptionClient(
            $this->proxy,
            $this->requests,
            $baseUrl,
            $model,
            $row->getId(),
            is_string($consumer) ? $consumer : ''
        );
        return $this->usage->wrap($client);
    }
}
