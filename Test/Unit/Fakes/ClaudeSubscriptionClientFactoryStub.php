<?php
/**
 * Copyright © Mago Assistant
 *
 * Stand-in for the factory Magento generates at runtime. The class_exists guard keeps the real
 * generated class authoritative when generated/code already holds one. Tests only stub it.
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Client;

if (!class_exists(ClaudeSubscriptionClientFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class ClaudeSubscriptionClientFactory
    {
        /**
         * @param array $data
         * @return ClaudeSubscriptionClient
         */
        public function create(array $data = [])
        {
            return new ClaudeSubscriptionClient(...$data);
        }
    }
}
