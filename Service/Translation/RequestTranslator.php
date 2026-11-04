<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Translation;

use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolDefinitionInterface;

/**
 * Rewrites a MageOS chat into the system, user, and assistant text the proxy keeps.
 */
class RequestTranslator
{
    public const OPEN_TAG = '<mago_tool_call>';

    public const CLOSE_TAG = '</mago_tool_call>';

    /**
     * Roles the reference proxy understands. Tool results are user turns.
     *
     * @param ChatRequestInterface $request
     * @return list<array{role: string, content: string}>
     */
    public function translate(ChatRequestInterface $request): array
    {
        $systemParts = [];
        $rest = [];
        foreach ($request->getMessages() as $message) {
            if ($message->getRole() === MessageRole::System) {
                $systemParts[] = $message->getContent();
                continue;
            }
            $rest[] = $this->one($message);
        }
        $messages = [];
        if ($systemParts !== []) {
            $messages[] = ['role' => 'system', 'content' => implode("\n\n", $systemParts)];
        }
        if ($request->getTools() !== []) {
            $messages[] = ['role' => 'system', 'content' => $this->appendix($request->getTools())];
        }
        return array_merge($messages, $rest);
    }

    /**
     * One non-system message.
     *
     * @param ChatMessageInterface $message
     * @return array{role: string, content: string}
     */
    private function one(ChatMessageInterface $message): array
    {
        if ($message->getRole() === MessageRole::Tool) {
            $answered = $message->getAnsweredToolCall();
            $name = $answered?->getName() ?? '';
            $id = $answered?->getId() ?? '';
            return [
                'role' => 'user',
                'content' => sprintf('Tool result for %s (%s): %s', $name, $id, $message->getContent()),
            ];
        }
        if ($message->getRole() === MessageRole::Assistant) {
            return ['role' => 'assistant', 'content' => $this->assistant($message)];
        }
        return [
            'role' => 'user',
            'content' => $message->getContent(),
        ];
    }

    /**
     * Assistant prose followed by one tag per tool call already in the history.
     *
     * @param ChatMessageInterface $message
     * @return string
     */
    private function assistant(ChatMessageInterface $message): string
    {
        $parts = [];
        if ($message->getContent() !== '') {
            $parts[] = $message->getContent();
        }
        foreach ($message->getToolCalls() as $call) {
            $encoded = json_encode(
                ['id' => $call->getId(), 'name' => $call->getName(), 'arguments' => $call->getArguments()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $parts[] = self::OPEN_TAG . $encoded . self::CLOSE_TAG;
        }
        return implode("\n", $parts);
    }

    /**
     * Instructions plus one schema block per tool.
     *
     * @param ToolDefinitionInterface[] $tools
     * @return string
     */
    private function appendix(array $tools): string
    {
        $lines = [
            'Tools are not native function calls on this connection.',
            'To call a tool, reply with only mago_tool_call tags and no other text.',
            'Each tag has this shape: ' . self::OPEN_TAG . '{"name":"tool_name","arguments":{}}' . self::CLOSE_TAG,
            'Do not include an id. One is assigned for you.',
            'The arguments object uses the parameter names from that tool\'s schema.',
            'To answer the user, reply in plain text and do not emit a tag.',
            'Available tools:',
        ];
        foreach ($tools as $tool) {
            $lines[] = '## ' . $tool->getName();
            $lines[] = $tool->getDescription();
            $lines[] = (string) json_encode($tool->getParameters(), JSON_UNESCAPED_SLASHES);
        }
        return implode("\n", $lines);
    }
}
