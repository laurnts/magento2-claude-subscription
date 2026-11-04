<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Client;

use MagoAssistant\ClaudeSubscription\Service\Client\ClaudeSubscriptionClient;
use MagoAssistant\ClaudeSubscription\Service\Translation\RequestTranslator;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FakeProxy;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClaudeSubscriptionClientTest extends TestCase
{
    #[Test]
    public function aTagOnlyReplyIsOneToolCall(): void
    {
        $proxy = new FakeProxy();
        $proxy->content = '<mago_tool_call>{"name":"sales","arguments":{"period":"week"}}</mago_tool_call>';
        $client = $this->client($proxy);
        $request = new ChatRequest([new ChatMessage(MessageRole::User, 'What sold best?')]);

        $response = $client->chat($request, ['max_tokens' => 50]);

        self::assertSame('', $response->getText());
        self::assertSame(FinishReason::ToolCall, $response->getFinishReason());
        self::assertSame('tool_calls', $response->getRawFinishReason());
        self::assertCount(1, $response->getToolCalls());
        self::assertMatchesRegularExpression('/^call_[0-9a-f]{8}_1$/', $response->getToolCalls()[0]->getId());
        self::assertSame('sales', $response->getToolCalls()[0]->getName());
        self::assertSame(['period' => 'week'], $response->getToolCalls()[0]->getArguments());
        self::assertSame(3, $response->getUsage()?->getPromptTokens());
        self::assertSame(4, $response->getUsage()?->getCompletionTokens());
        self::assertSame(50, $proxy->maxTokens);
        self::assertSame('claude-sonnet-4', $client->getModel());
    }

    #[Test]
    public function optionModelChangesTheWireModelOnly(): void
    {
        $proxy = new FakeProxy();
        $client = $this->client($proxy);

        $client->chat(
            new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]),
            [AiClientInterface::OPTION_MODEL => 'claude-haiku-4']
        );

        self::assertSame('claude-haiku-4', $proxy->model);
        self::assertSame('claude-sonnet-4', $client->getModel());
        self::assertSame('row-1', $client->getServiceId());
        self::assertSame('unknown', $client->getConsumer());
        self::assertSame('claude_subscription', $client->getServiceCode());
    }

    #[Test]
    public function aPlainReplyStreamsTextAndReturnsNoToolCall(): void
    {
        $proxy = new FakeProxy();
        $proxy->deltas = ['Hel', 'lo'];
        $client = $this->client($proxy);
        $stream = $client->streamChat(new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]));

        $types = [];
        $text = '';
        foreach ($stream as $chunk) {
            $types[] = $chunk->getType();
            if ($chunk->getType() === StreamChunkType::Text) {
                $text .= $chunk->getText();
            }
        }
        $done = $stream->getReturn();

        self::assertSame([StreamChunkType::Text, StreamChunkType::Text, StreamChunkType::Usage], $types);
        self::assertSame('Hello', $text);
        self::assertSame('Hello', $done->getText());
        self::assertSame(FinishReason::Stop, $done->getFinishReason());
        self::assertSame([], $done->getToolCalls());
    }

    #[Test]
    public function streamChatYieldsAToolCallChunkAndReturnsToolCallFinishReason(): void
    {
        $proxy = new FakeProxy();
        $proxy->deltas = ['<mago_tool_call>{"name":"sales","arguments":{"period":"week"}}</mago_tool_call>'];
        $client = $this->client($proxy);
        $stream = $client->streamChat(new ChatRequest([new ChatMessage(MessageRole::User, 'What sold?')]));

        $toolChunks = [];
        foreach ($stream as $chunk) {
            if ($chunk->getType() === StreamChunkType::ToolCall) {
                $toolChunks[] = $chunk;
            }
        }
        $done = $stream->getReturn();

        self::assertCount(1, $toolChunks);
        self::assertSame(StreamChunkType::ToolCall, $toolChunks[0]->getType());
        self::assertSame('sales', $toolChunks[0]->getToolCall()?->getName());
        self::assertSame(FinishReason::ToolCall, $done->getFinishReason());
    }

    #[Test]
    public function completeSendsOneUserMessage(): void
    {
        $proxy = new FakeProxy();
        $proxy->content = 'pong';

        self::assertSame('pong', $this->client($proxy)->complete('ping'));
        self::assertSame([['role' => 'user', 'content' => 'ping']], $proxy->messages);
    }

    #[Test]
    public function aStreamedToolCallYieldsTextThenToolCallThenUsageChunks(): void
    {
        $proxy = new FakeProxy();
        $proxy->deltas = [
            'Sure. <mago',
            '_tool_call>{"name":"sales","arguments":{"period":"week"}}</mago_tool_call>',
        ];
        $client = $this->client($proxy);
        $request = new ChatRequest([new ChatMessage(MessageRole::User, 'What sold best?')]);

        $stream = $client->streamChat($request);
        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }
        $done = $stream->getReturn();

        $types = array_map(static fn($c) => $c->getType(), $chunks);
        self::assertSame([StreamChunkType::Text, StreamChunkType::ToolCall, StreamChunkType::Usage], $types);
        self::assertSame('sales', $chunks[1]->getToolCall()?->getName());
        self::assertSame(FinishReason::ToolCall, $done->getFinishReason());
        self::assertSame('tool_calls', $done->getRawFinishReason());
    }

    private function client(FakeProxy $proxy): ClaudeSubscriptionClient
    {
        return new ClaudeSubscriptionClient(
            $proxy,
            new RequestTranslator(),
            'http://proxy.test/v1',
            'claude-sonnet-4',
            'row-1',
            ''
        );
    }
}
