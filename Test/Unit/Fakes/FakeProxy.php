<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Fakes;

use MagoAssistant\ClaudeSubscription\Service\Proxy\ProxyClientInterface;

/**
 * Records the last proxy call and returns a canned reply.
 */
final class FakeProxy implements ProxyClientInterface
{
    /**
     * Base URL of the last call.
     *
     * @var string
     */
    public string $baseUrl = '';

    /**
     * Model of the last call.
     *
     * @var string
     */
    public string $model = '';

    /**
     * @var list<array{role: string, content: string}>
     */
    public array $messages = [];

    /**
     * max_tokens of the last call.
     *
     * @var int|null
     */
    public ?int $maxTokens = null;

    /**
     * Canned completion text.
     *
     * @var string
     */
    public string $content = 'hello';

    /**
     * @var list<string>
     */
    public array $deltas = ['hello'];

    /**
     * @inheritdoc
     */
    public function complete(
        string $baseUrl,
        string $model,
        array $messages,
        ?int $maxTokens
    ): array {
        $this->remember($baseUrl, $model, $messages, $maxTokens);
        return ['content' => $this->content, 'prompt_tokens' => 3, 'completion_tokens' => 4];
    }

    /**
     * @inheritdoc
     */
    public function stream(
        string $baseUrl,
        string $model,
        array $messages,
        ?int $maxTokens
    ): \Generator {
        $this->remember($baseUrl, $model, $messages, $maxTokens);
        foreach ($this->deltas as $delta) {
            yield $delta;
        }
        return ['content' => '', 'prompt_tokens' => 3, 'completion_tokens' => 4];
    }

    /**
     * @param string $baseUrl
     * @param string $model
     * @param list<array{role: string, content: string}> $messages
     * @param int|null $maxTokens
     * @return void
     */
    private function remember(string $baseUrl, string $model, array $messages, ?int $maxTokens): void
    {
        $this->baseUrl = $baseUrl;
        $this->model = $model;
        $this->messages = $messages;
        $this->maxTokens = $maxTokens;
    }
}
