<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Fakes;

use MageOS\AiBase\Api\Data\AiServiceInterface;

/**
 * Configured AI service row for unit tests.
 */
final class FakeService implements AiServiceInterface
{
    /**
     * @param string $id
     * @param string $code
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        private readonly string $id,
        private readonly string $code,
        private readonly array $configuration,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }
}
