<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Translation;

use MagoAssistant\ClaudeSubscription\Service\Translation\StreamParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamParserTest extends TestCase
{
    #[Test]
    public function aTagSplitAcrossPushesIsNotText(): void
    {
        $parser = new StreamParser('ab12cd34');

        $first = $parser->push('Sure. <mago');
        $second = $parser->push('_tool_call>{"name":"sales","arguments":{"period":"week"}}</mago_tool_call>');
        $tail = $parser->finish();

        self::assertSame([['type' => 'text', 'text' => 'Sure. ']], $first);
        self::assertSame([
            ['type' => 'tool', 'id' => 'call_ab12cd34_1', 'name' => 'sales', 'arguments' => ['period' => 'week']],
        ], $second);
        self::assertSame([], $tail);
        $encoded = json_encode($first);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('<mago', $encoded);
    }

    #[Test]
    public function proseAroundATagIsKeptAndABrokenTagStaysText(): void
    {
        $parsed = (new StreamParser('ab12cd34'))->parse(
            'Before <mago_tool_call>not-json</mago_tool_call> after '
            . '<mago_tool_call>{"name":"sales","arguments":{}}</mago_tool_call> tail'
        );

        self::assertSame('Before <mago_tool_call>not-json</mago_tool_call> after  tail', $parsed['text']);
        self::assertSame([['id' => 'call_ab12cd34_1', 'name' => 'sales', 'arguments' => []]], $parsed['calls']);
    }

    #[Test]
    public function omittedIdsCountValidCallsAndAListOfArgumentsBecomesEmpty(): void
    {
        $parsed = (new StreamParser('ab12cd34'))->parse(
            '<mago_tool_call>{"id":"keep","name":"first","arguments":{"a":1}}</mago_tool_call>'
            . '<mago_tool_call>{"name":"second","arguments":["nope"]}</mago_tool_call>'
        );

        self::assertSame('', $parsed['text']);
        self::assertSame('keep', $parsed['calls'][0]['id']);
        self::assertSame(['a' => 1], $parsed['calls'][0]['arguments']);
        self::assertSame('call_ab12cd34_2', $parsed['calls'][1]['id']);
        self::assertSame([], $parsed['calls'][1]['arguments']);
    }

    #[Test]
    public function twoParsersWithNoArgProduceDifferentPrefixes(): void
    {
        $body = '<mago_tool_call>{"name":"sales","arguments":{}}</mago_tool_call>';

        $id1 = (new StreamParser())->parse($body)['calls'][0]['id'];
        $id2 = (new StreamParser())->parse($body)['calls'][0]['id'];

        self::assertNotSame($id1, $id2);
    }

    #[Test]
    public function anUnclosedTagIsFlushedAsText(): void
    {
        $parser = new StreamParser();
        self::assertSame(
            [['type' => 'text', 'text' => 'Hello ']],
            $parser->push('Hello <mago_tool_call>{"name":"sales"')
        );
        self::assertSame(
            [['type' => 'text', 'text' => '<mago_tool_call>{"name":"sales"']],
            $parser->finish()
        );
    }
}
