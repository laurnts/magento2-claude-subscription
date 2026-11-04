<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Fakes;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Model\FieldDescriptor;

/**
 * Builds the real field value object without an object manager.
 */
final class FieldFactory extends FieldDescriptorInterfaceFactory
{
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @param array $data
     * @return FieldDescriptorInterface
     */
    public function create(array $data = [])
    {
        return new FieldDescriptor(...$data);
    }
}
