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
    public function proxyClientInterfaceHasAPreferenceForHttpProxyClient(): void
    {
        $path = __DIR__ . '/../../etc/di.xml';
        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'Could not parse etc/di.xml');
        $prefs = $xml->xpath(
            '//preference[@for="MagoAssistant\ClaudeSubscription\Service\Proxy\ProxyClientInterface"]'
        );
        self::assertNotEmpty($prefs, 'No preference for ProxyClientInterface found in etc/di.xml');
        self::assertSame(
            'MagoAssistant\ClaudeSubscription\Service\Proxy\HttpProxyClient',
            (string) $prefs[0]['type']
        );
    }
}
