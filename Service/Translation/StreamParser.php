<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Translation;

/**
 * Pulls complete tool-call tags out of a reply that may arrive in pieces.
 */
class StreamParser
{
    /**
     * Bytes received so far that are not yet a complete tag.
     *
     * @var string
     */
    private string $buffer = '';

    /**
     * How many tool calls this parser has accepted.
     *
     * @var int
     */
    private int $validCalls = 0;

    /**
     * Prefix for ids this parser assigns.
     *
     * @var string
     */
    private string $idPrefix;

    /**
     * @param string|null $idPrefix Prefix for auto-assigned ids. Defaults to a random hex string.
     */
    public function __construct(?string $idPrefix = null)
    {
        $this->idPrefix = $idPrefix ?? bin2hex(random_bytes(4));
    }

    /**
     * Append one chunk and return the events that are now complete.
     *
     * @param string $delta
     * @return list<array<string, mixed>>
     */
    public function push(string $delta): array
    {
        $this->buffer .= $delta;
        $events = [];
        while (true) {
            $openAt = strpos($this->buffer, RequestTranslator::OPEN_TAG);
            if ($openAt === false) {
                $hold = $this->heldPrefixLength($this->buffer);
                $emitLength = strlen($this->buffer) - $hold;
                if ($emitLength > 0) {
                    $events[] = ['type' => 'text', 'text' => substr($this->buffer, 0, $emitLength)];
                    $this->buffer = substr($this->buffer, $emitLength);
                }
                break;
            }
            if ($openAt > 0) {
                $events[] = ['type' => 'text', 'text' => substr($this->buffer, 0, $openAt)];
                $this->buffer = substr($this->buffer, $openAt);
            }
            $closeAt = strpos($this->buffer, RequestTranslator::CLOSE_TAG);
            if ($closeAt === false) {
                break;
            }
            $bodyStart = strlen(RequestTranslator::OPEN_TAG);
            $body = substr($this->buffer, $bodyStart, $closeAt - $bodyStart);
            $end = $closeAt + strlen(RequestTranslator::CLOSE_TAG);
            $tool = $this->decode($body);
            if ($tool === null) {
                $events[] = ['type' => 'text', 'text' => substr($this->buffer, 0, $end)];
            } else {
                $events[] = $tool;
            }
            $this->buffer = substr($this->buffer, $end);
        }
        return $events;
    }

    /**
     * Emit whatever was still buffered when the stream ended.
     *
     * @return list<array<string, mixed>>
     */
    public function finish(): array
    {
        if ($this->buffer === '') {
            return [];
        }
        $text = $this->buffer;
        $this->buffer = '';
        return [['type' => 'text', 'text' => $text]];
    }

    /**
     * Parse a whole reply.
     *
     * @param string $content
     * @return array{text: string, calls: list<array{id: string, name: string, arguments: array}>}
     */
    public function parse(string $content): array
    {
        $events = array_merge($this->push($content), $this->finish());
        $text = '';
        $calls = [];
        foreach ($events as $event) {
            if ($event['type'] === 'text') {
                $text .= $event['text'];
                continue;
            }
            $calls[] = [
                'id' => $event['id'],
                'name' => $event['name'],
                'arguments' => $event['arguments'],
            ];
        }
        return ['text' => trim($text), 'calls' => $calls];
    }

    /**
     * How many trailing bytes are a proper prefix of the opening tag.
     *
     * @param string $buffer
     * @return int
     */
    private function heldPrefixLength(string $buffer): int
    {
        $open = RequestTranslator::OPEN_TAG;
        $max = min(strlen($buffer), strlen($open) - 1);
        for ($length = $max; $length > 0; $length--) {
            if (substr($buffer, -$length) === substr($open, 0, $length)) {
                return $length;
            }
        }
        return 0;
    }

    /**
     * A tool event, or null when the tag body is not a call object.
     *
     * @param string $body
     * @return array{type: string, id: string, name: string, arguments: array<string, mixed>}|null
     */
    private function decode(string $body): ?array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $name = is_array($data) ? ($data['name'] ?? null) : null;
        $isObject = is_array($data) && !array_is_list($data);
        if (!$isObject || !is_string($name) || $name === '') {
            return null;
        }
        $arguments = $data['arguments'] ?? [];
        if (!is_array($arguments) || array_is_list($arguments)) {
            $arguments = [];
        }
        $this->validCalls++;
        $id = $data['id'] ?? '';
        if (!is_string($id) || $id === '') {
            $id = 'call_' . $this->idPrefix . '_' . $this->validCalls;
        }
        return ['type' => 'tool', 'id' => $id, 'name' => $name, 'arguments' => $arguments];
    }
}
