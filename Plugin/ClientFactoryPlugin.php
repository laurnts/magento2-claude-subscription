<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Plugin;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClientFactory;
use MagoAssistant\ClaudeSubscription\Service\Client\UsageDecorator;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\ClientFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Serves a Claude Subscription row itself and leaves every other provider to AiBase.
 *
 * AiBase builds clients from Symfony AI platform bridges. This provider has none, so the two
 * entry points are intercepted: when the resolved row is ours the client is built here, and
 * otherwise the call proceeds untouched.
 */
class ClientFactoryPlugin
{
    /**
     * @param AiServiceSelectorInterface $selector
     * @param BridgeRegistry $bridges
     * @param ClaudeSubscription $service
     * @param ClaudeSubscriptionClientFactory $clients
     * @param UsageDecorator $usage
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $selector,
        private readonly BridgeRegistry $bridges,
        private readonly ClaudeSubscription $service,
        private readonly ClaudeSubscriptionClientFactory $clients,
        private readonly UsageDecorator $usage,
    ) {
    }

    /**
     * Named code, or the first usable row when the caller asked for automatic selection.
     *
     * The automatic case mirrors ClientFactory::resolveDefaultService(): the first row whose
     * bridge is available wins, and the registered marker makes this provider available.
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
        $row = $serviceCode === null
            ? $this->firstAvailable()
            : ($this->selector->getByCode($serviceCode)[0] ?? null);

        return $this->isOurs($row) ? $this->clientFor($row, $consumer) : $proceed($serviceCode, $consumer);
    }

    /**
     * One configured row, by the id the consumer stored.
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

        return $this->isOurs($row) ? $this->clientFor($row, $consumer) : $proceed($serviceId, $consumer);
    }

    /**
     * The row AiBase itself would pick when no code is given.
     *
     * @return AiServiceInterface|null
     */
    private function firstAvailable(): ?AiServiceInterface
    {
        foreach ($this->selector->getAll() as $row) {
            if ($this->bridges->isAvailable($row->getCode())) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether this row is a Claude Subscription row.
     *
     * @param AiServiceInterface|null $row
     * @return bool
     * @phpstan-assert-if-true AiServiceInterface $row
     */
    private function isOurs(?AiServiceInterface $row): bool
    {
        return $row !== null && $row->getCode() === ClaudeSubscription::CODE;
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
        $model = $configuration['model'] ?? '';
        if (!is_string($model) || $model === '') {
            throw new LocalizedException(
                __('Pick a model on the %1 row before using it.', $this->service->getName())
            );
        }
        $client = $this->clients->create([
            'baseUrl' => $this->service->baseUrl($configuration),
            'model' => $model,
            'serviceId' => $row->getId(),
            'consumer' => $consumer ?? '',
        ]);

        return $this->usage->wrap($client);
    }
}
