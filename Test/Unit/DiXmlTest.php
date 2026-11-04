<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiXmlTest extends TestCase
{
    #[Test]
    public function theModuleWiresByArgumentsAndPluginsOnly(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../etc/di.xml');
        self::assertNotFalse($xml, 'Could not parse etc/di.xml');

        self::assertSame([], $xml->xpath('//preference'), 'etc/di.xml must not declare a preference');
        self::assertCount(1, $xml->xpath('//plugin[@name="magoassistant_claude_subscription_client"]'));
        self::assertCount(
            1,
            $xml->xpath('//type[@name="MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClient"]'
                . '/arguments/argument[@name="proxy"]')
        );
    }
}
