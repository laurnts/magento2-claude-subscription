<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Setup;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Setup\ServiceCodeRenamer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServiceCodeRenamerTest extends TestCase
{
    #[Test]
    public function constantsMatchTheAdminService(): void
    {
        self::assertSame('claude_max', ServiceCodeRenamer::FROM);
        self::assertSame(ClaudeSubscription::CODE, ServiceCodeRenamer::TO);
        self::assertSame('claude_subscription', ServiceCodeRenamer::TO);
    }

    #[Test]
    public function renamesTheProviderKeyAndKeepsTheRow(): void
    {
        $raw = '{"row-9":{"claude_max":{"base_url":"http://host.docker.internal:3456/v1",'
            . '"model":"sonnet","api_key":"0:3:abc+/="}}}';
        $renamed = (new ServiceCodeRenamer())->rename($raw);
        $decoded = json_decode((string) $renamed, true);
        self::assertSame('http://host.docker.internal:3456/v1', $decoded['row-9']['claude_subscription']['base_url']);
        self::assertSame('sonnet', $decoded['row-9']['claude_subscription']['model']);
        self::assertSame('0:3:abc+/=', $decoded['row-9']['claude_subscription']['api_key']);
        self::assertArrayNotHasKey('claude_max', $decoded['row-9']);
    }

    #[Test]
    public function leavesAnotherProviderBesideIt(): void
    {
        $raw = '{"row-1":{"openai":{"model":"gpt"}},"row-2":{"claude_max":{"model":"haiku"}}}';
        $decoded = json_decode((string) (new ServiceCodeRenamer())->rename($raw), true);
        self::assertSame(['model' => 'gpt'], $decoded['row-1']['openai']);
        self::assertSame(['model' => 'haiku'], $decoded['row-2']['claude_subscription']);
    }

    #[Test]
    public function invalidJsonReturnsNull(): void
    {
        $renamer = new ServiceCodeRenamer();
        self::assertNull($renamer->rename(''));
        self::assertNull($renamer->rename('not-json'));
        self::assertNull($renamer->rename('[]'));
    }

    #[Test]
    public function anUnchangedDocumentReturnsNull(): void
    {
        self::assertNull((new ServiceCodeRenamer())->rename('{"row-1":{"openai":{"model":"gpt"}}}'));
    }

    #[Test]
    public function aSecondRunChangesNothing(): void
    {
        $renamer = new ServiceCodeRenamer();
        $once = $renamer->rename('{"row-2":{"claude_max":{"model":"sonnet"}}}');
        self::assertNull($renamer->rename((string) $once));
    }

    #[Test]
    public function aRowThatAlreadyHasTheNewCodeIsLeftAlone(): void
    {
        $raw = '{"row-3":{"claude_subscription":{"model":"opus"},"claude_max":{"model":"old"}}}';
        self::assertNull((new ServiceCodeRenamer())->rename($raw));
    }

    #[Test]
    public function aCollidingRowDoesNotBlockAnotherRow(): void
    {
        $raw = '{"row-3":{"claude_subscription":{"model":"opus"},"claude_max":{"model":"old"}},'
            . '"row-4":{"claude_max":{"model":"sonnet"}}}';
        $decoded = json_decode((string) (new ServiceCodeRenamer())->rename($raw), true);
        self::assertSame('old', $decoded['row-3']['claude_max']['model']);
        self::assertSame('opus', $decoded['row-3']['claude_subscription']['model']);
        self::assertSame('sonnet', $decoded['row-4']['claude_subscription']['model']);
        self::assertArrayNotHasKey('claude_max', $decoded['row-4']);
    }
}
