<?php
/**
 * Copyright © Mago Assistant
 *
 * Stand-in for the factory Magento generates at runtime. The class_exists guard keeps the real
 * generated class authoritative when generated/code already holds one.
 */
declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

if (!class_exists(FieldDescriptorInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class FieldDescriptorInterfaceFactory
    {
        /**
         * @param array $data
         * @return FieldDescriptorInterface
         */
        public function create(array $data = [])
        {
            return new \MageOS\AiBase\Model\FieldDescriptor(...$data);
        }
    }
}
