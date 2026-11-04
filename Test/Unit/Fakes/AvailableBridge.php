<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Fakes;

/**
 * Installed bridge stand-in. BridgeRegistry::isAvailable() requires createPlatform().
 */
final class AvailableBridge
{
    /**
     * Present so the registry treats openai as installed.
     *
     * @return void
     */
    public static function createPlatform(): void
    {
    }
}
