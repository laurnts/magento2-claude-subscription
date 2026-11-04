<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Client;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Service\Proxy\ProxyClientInterface;
use MagoAssistant\ClaudeSubscription\Service\Translation\RequestTranslator;
use MagoAssistant\ClaudeSubscription\Service\Translation\StreamParser;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;

/**
 * MageOS client that speaks the host proxy's text chat.
 */
class ClaudeSubscriptionClient implements AiClientInterface
{
    /**
     * @param ProxyClientInterface $proxy
     * @param RequestTranslator $requests
     * @param string $baseUrl
     * @param string $model
     * @param string $serviceId
     * @param string $consumer
     */
    public function __construct(
        private readonly ProxyClientInterface $proxy,
        private readonly RequestTranslator $requests,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $serviceId,
        private readonly string $consumer,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface
    {
        $result = $this->proxy->complete(
            $this->baseUrl,
            $this->wireModel($options),
            $this->requests->translate($request),
            $this->maxTokens($options)
        );
        $parsed = (new StreamParser())->parse($result['content']);
        return $this->response(
            $parsed['text'],
            $parsed['calls'],
            $result['prompt_tokens'],
            $result['completion_tokens']
        );
    }

    /**
     * @inheritdoc
     */
    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator
    {
        $parser = new StreamParser();
        $stream = $this->proxy->stream(
            $this->baseUrl,
            $this->wireModel($options),
            $this->requests->translate($request),
            $this->maxTokens($options)
        );
        $text = '';
        $calls = [];
        foreach ($stream as $delta) {
            foreach ($parser->push($delta) as $event) {
                [$text, $calls] = $this->yieldEvent($event, $text, $calls);
                yield $this->chunk($event);
            }
        }
        foreach ($parser->finish() as $event) {
            [$text, $calls] = $this->yieldEvent($event, $text, $calls);
            yield $this->chunk($event);
        }
        $usage = $stream->getReturn();
        $prompt = is_array($usage) ? (int) $usage['prompt_tokens'] : 0;
        $completion = is_array($usage) ? (int) $usage['completion_tokens'] : 0;
        yield new StreamChunk(
            StreamChunkType::Usage,
            '',
            null,
            new TokenUsage($prompt, $completion, $prompt + $completion)
        );
        return $this->response(trim($text), $calls, $prompt, $completion);
    }

    /**
     * @inheritdoc
     */
    public function complete(string $prompt, array $options = []): string
    {
        $request = new ChatRequest([new ChatMessage(MessageRole::User, $prompt)]);
        return $this->chat($request, $options)->getText();
    }

    /**
     * @inheritdoc
     */
    public function getServiceCode(): string
    {
        return ClaudeSubscription::CODE;
    }

    /**
     * @inheritdoc
     */
    public function getServiceId(): string
    {
        return $this->serviceId;
    }

    /**
     * @inheritdoc
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @inheritdoc
     */
    public function getConsumer(): string
    {
        return $this->consumer !== '' ? $this->consumer : UsageRecordInterface::CONSUMER_UNKNOWN;
    }

    /**
     * Model for this call. OPTION_MODEL does not change getModel().
     *
     * @param array $options
     * @return string
     */
    private function wireModel(array $options): string
    {
        $model = $options[self::OPTION_MODEL] ?? '';
        if (is_string($model) && $model !== '') {
            return $model;
        }
        return $this->model;
    }

    /**
     * Positive integer max_tokens, or null when the caller did not set one.
     *
     * @param array $options
     * @return int|null
     */
    private function maxTokens(array $options): ?int
    {
        $max = $options['max_tokens'] ?? null;
        if (is_int($max) && $max > 0) {
            return $max;
        }
        return null;
    }

    /**
     * Accumulate one parser event.
     *
     * @param array  $event
     * @param string $text
     * @param array  $calls
     * @return array Text so far and the calls so far
     */
    private function yieldEvent(array $event, string $text, array $calls): array
    {
        if ($event['type'] === 'text') {
            $text .= $event['text'];
            return [$text, $calls];
        }
        $calls[] = ['id' => $event['id'], 'name' => $event['name'], 'arguments' => $event['arguments']];
        return [$text, $calls];
    }

    /**
     * One stream chunk for a parser event.
     *
     * @param array $event
     * @return StreamChunk
     */
    private function chunk(array $event): StreamChunk
    {
        if ($event['type'] === 'tool') {
            return new StreamChunk(
                StreamChunkType::ToolCall,
                '',
                new ToolCall($event['id'], $event['name'], $event['arguments'])
            );
        }
        return new StreamChunk(StreamChunkType::Text, $event['text']);
    }

    /**
     * Buffered response. Text is trimmed here and only here.
     *
     * @param string $text
     * @param array  $calls
     * @param int    $prompt
     * @param int    $completion
     * @return ChatResponse
     */
    private function response(string $text, array $calls, int $prompt, int $completion): ChatResponse
    {
        $toolCalls = [];
        foreach ($calls as $call) {
            $toolCalls[] = new ToolCall($call['id'], $call['name'], $call['arguments']);
        }
        $reason = $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCall;
        $raw = $toolCalls === [] ? 'stop' : 'tool_calls';
        return new ChatResponse(
            trim($text),
            $toolCalls,
            new TokenUsage($prompt, $completion, $prompt + $completion),
            $reason,
            $raw
        );
    }
}
