<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Translation;

use MagoAssistant\ClaudeSubscription\Service\Translation\RequestTranslator;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestTranslatorTest extends TestCase
{
    #[Test]
    public function systemMessagesJoinAndToolsBecomeASecondSystemMessage(): void
    {
        $request = new ChatRequest(
            [
                new ChatMessage(MessageRole::System, 'Be brief.'),
                new ChatMessage(MessageRole::System, 'Use the store currency.'),
                new ChatMessage(MessageRole::User, 'What sold best?'),
            ],
            [
                new ToolDefinition(
                    'sales',
                    'Read sales totals.',
                    ['type' => 'object', 'properties' => ['period' => ['type' => 'string']]]
                ),
            ],
        );

        $messages = (new RequestTranslator())->translate($request);

        self::assertSame('system', $messages[0]['role']);
        self::assertSame("Be brief.\n\nUse the store currency.", $messages[0]['content']);
        self::assertSame('system', $messages[1]['role']);
        self::assertStringContainsString(
            'Tools are not native function calls on this connection.',
            $messages[1]['content']
        );
        self::assertStringContainsString(
            '<mago_tool_call>{"name":"tool_name","arguments":{}}</mago_tool_call>',
            $messages[1]['content']
        );
        self::assertStringContainsString("## sales\nRead sales totals.\n", $messages[1]['content']);
        self::assertStringContainsString(
            '{"type":"object","properties":{"period":{"type":"string"}}}',
            $messages[1]['content']
        );
        self::assertSame(['role' => 'user', 'content' => 'What sold best?'], $messages[2]);
    }

    #[Test]
    public function aRequestWithoutToolsHasNoToolAppendix(): void
    {
        $request = new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);

        $messages = (new RequestTranslator())->translate($request);

        self::assertSame([['role' => 'user', 'content' => 'Hello']], $messages);
    }

    #[Test]
    public function anAssistantToolCallIsRewrittenAsATag(): void
    {
        $call = new ToolCall('call_9', 'sales', ['period' => 'week']);
        $request = new ChatRequest([
            new ChatMessage(MessageRole::Assistant, 'Checking.', [$call]),
        ]);

        $messages = (new RequestTranslator())->translate($request);

        self::assertSame('assistant', $messages[0]['role']);
        self::assertSame(
            "Checking.\n"
            . '<mago_tool_call>{"id":"call_9","name":"sales","arguments":{"period":"week"}}</mago_tool_call>',
            $messages[0]['content']
        );
    }

    #[Test]
    public function aToolResultBecomesAUserTurn(): void
    {
        $call = new ToolCall('call_9', 'sales', ['period' => 'week']);
        $request = new ChatRequest([
            new ChatMessage(MessageRole::Tool, '{"total":10}', [], $call),
        ]);

        $messages = (new RequestTranslator())->translate($request);

        self::assertSame(
            ['role' => 'user', 'content' => 'Tool result for sales (call_9): {"total":10}'],
            $messages[0]
        );
    }
}
