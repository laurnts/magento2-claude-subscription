<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Proxy;

use Magento\Framework\Exception\LocalizedException;

/**
 * One chat completion against the host proxy.
 */
interface ProxyClientInterface
{
    /**
     * Buffered completion.
     *
     * @param string   $baseUrl
     * @param string   $model
     * @param array    $messages Role and content pairs
     * @param int|null $maxTokens
     * @return array Content and token counts
     * @throws LocalizedException
     */
    public function complete(
        string $baseUrl,
        string $model,
        array $messages,
        ?int $maxTokens
    ): array;

    /**
     * Streaming completion. Yields content deltas and returns token counts.
     *
     * @param string   $baseUrl
     * @param string   $model
     * @param array    $messages Role and content pairs
     * @param int|null $maxTokens
     * @return \Generator Content deltas, then token counts
     * @throws LocalizedException
     */
    public function stream(
        string $baseUrl,
        string $model,
        array $messages,
        ?int $maxTokens
    ): \Generator;
}
