<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Fakes;

use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;

/**
 * In-memory service list.
 */
final class FakeSelector implements AiServiceSelectorInterface
{
    /**
     * @param list<AiServiceInterface> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @inheritdoc
     */
    public function getAll(): array
    {
        return $this->rows;
    }

    /**
     * @inheritdoc
     */
    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (AiServiceInterface $row): bool => $row->getCode() === $code
        ));
    }

    /**
     * @inheritdoc
     */
    public function getById(string $id): ?AiServiceInterface
    {
        foreach ($this->rows as $row) {
            if ($row->getId() === $id) {
                return $row;
            }
        }
        return null;
    }
}
