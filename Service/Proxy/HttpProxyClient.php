<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Service\Proxy;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Posts OpenAI-style chat completions to the host and reads back the reply, buffered or streamed.
 */
class HttpProxyClient implements ProxyClientInterface
{
    /**
     * Seconds a single completion may take. Long replies through `claude --print` are slow.
     */
    private const TIMEOUT = 600;

    /**
     * @var HttpClientInterface
     */
    private readonly HttpClientInterface $http;

    /**
     * @param LoggerInterface $logger
     * @param HttpClientInterface|null $http Symfony's default client when none is given, as
     *        AiBase's own ClientFactory does. A test passes a MockHttpClient here.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /**
     * @inheritdoc
     */
    public function complete(
        string $baseUrl,
        string $model,
        array $messages,
        ?int $maxTokens
    ): array {
        $url = $this->url($baseUrl);
        $response = $this->open($url, $model, $messages, $maxTokens, false);
        try {
            $payload = $response->toArray(false);
        } catch (\Exception $exception) {
            $this->fail($url, 200, $this->notACompletion(), $exception);
        }
        if (!isset($payload['choices']) || !is_array($payload['choices'])) {
            $this->fail($url, 200, $this->notACompletion());
        }
        $content = $payload['choices'][0]['message']['content'] ?? '';
        return [
            'content' => is_string($content) ? $content : '',
            'prompt_tokens' => $this->token($payload, 'prompt_tokens'),
            'completion_tokens' => $this->token($payload, 'completion_tokens'),
        ];
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
        $url = $this->url($baseUrl);
        $response = $this->open($url, $model, $messages, $maxTokens, true);
        $usage = ['content' => '', 'prompt_tokens' => 0, 'completion_tokens' => 0];
        $pending = '';
        try {
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                $pending .= $chunk->getContent();
                while (($split = strpos($pending, "\n\n")) !== false) {
                    $event = substr($pending, 0, $split);
                    $pending = substr($pending, $split + 2);
                    $delta = $this->consumeEvent($event, $usage, $url);
                    if ($delta === false) {
                        return $usage;
                    }
                    if (is_string($delta) && $delta !== '') {
                        yield $delta;
                    }
                }
            }
            if (trim($pending) !== '') {
                $delta = $this->consumeEvent($pending, $usage, $url);
                if (is_string($delta) && $delta !== '') {
                    yield $delta;
                }
            }
        } catch (TransportExceptionInterface $exception) {
            $this->fail($url, 0, $this->unreachable($url), $exception);
        }
        return $usage;
    }

    /**
     * Chat-completions URL for the configured OpenAI base.
     *
     * @param string $baseUrl
     * @return string
     */
    private function url(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/chat/completions';
    }

    /**
     * Send the request and reject a non-2xx status.
     *
     * @param string   $url
     * @param string   $model
     * @param array    $messages Role and content pairs
     * @param int|null $maxTokens
     * @param bool     $stream
     * @return ResponseInterface
     * @throws LocalizedException
     */
    private function open(
        string $url,
        string $model,
        array $messages,
        ?int $maxTokens,
        bool $stream
    ): ResponseInterface {
        $json = ['model' => $model, 'messages' => $messages, 'stream' => $stream];
        if ($maxTokens !== null && $maxTokens > 0) {
            $json['max_tokens'] = $maxTokens;
        }
        $options = [
            'timeout' => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
            'headers' => ['Accept' => $stream ? 'text/event-stream' : 'application/json'],
            'json' => $json,
        ];
        try {
            $response = $this->http->request('POST', $url, $options);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $this->fail($url, 0, $this->unreachable($url), $exception);
        }
        if ($status < 200 || $status >= 300) {
            $this->reject($response, $status, $url);
        }
        return $response;
    }

    /**
     * Turn an error status into an admin-readable exception.
     *
     * @param ResponseInterface $response
     * @param int $status
     * @param string $url
     * @return never
     * @throws LocalizedException
     */
    private function reject(ResponseInterface $response, int $status, string $url): never
    {
        $message = null;
        try {
            $body = $response->toArray(false);
            if (isset($body['error']['message']) && is_string($body['error']['message'])) {
                $message = $body['error']['message'];
            }
        } catch (\Throwable) {
            $message = null;
        }
        if ($message !== null) {
            $this->fail($url, $status, __('Claude Subscription host reported: %1', $message));
        }
        $this->fail($url, $status, __('Claude Subscription host returned HTTP %1.', $status));
    }

    /**
     * One SSE event. False means the stream is done.
     *
     * @param string $event
     * @param array  $usage Token counters, passed by reference
     * @param string $url
     * @return string|false|null
     * @throws LocalizedException
     */
    private function consumeEvent(string $event, array &$usage, string $url): string|false|null
    {
        $done = false;
        $delta = null;
        $lines = preg_split("/\r?\n/", $event);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') {
                $done = true;
                continue;
            }
            try {
                $json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                $this->fail($url, 200, $this->notACompletion(), $exception);
            }
            if (!is_array($json)) {
                $this->fail($url, 200, $this->notACompletion());
            }
            $piece = $json['choices'][0]['delta']['content'] ?? null;
            if (is_string($piece) && $piece !== '') {
                $delta = ($delta ?? '') . $piece;
            }
            $this->readUsage($json, $usage);
        }
        return $done ? false : $delta;
    }

    /**
     * Copy integer usage fields off one JSON object.
     *
     * @param array $json
     * @param array $usage Token counters, passed by reference
     * @return void
     */
    private function readUsage(array $json, array &$usage): void
    {
        foreach (['prompt_tokens', 'completion_tokens'] as $key) {
            if (isset($json['usage'][$key]) && is_int($json['usage'][$key])) {
                $usage[$key] = $json['usage'][$key];
            }
        }
    }

    /**
     * Token count from a buffered body, or zero when the proxy omitted it.
     *
     * @param array  $payload
     * @param string $key
     * @return int
     */
    private function token(array $payload, string $key): int
    {
        $value = $payload['usage'][$key] ?? 0;
        return is_int($value) ? $value : 0;
    }

    /**
     * Admin text when the host process is not accepting connections.
     *
     * @param string $url
     * @return Phrase
     */
    private function unreachable(string $url): Phrase
    {
        return __(
            'Claude Subscription host is not reachable at %1. On the Docker host, run claude auth login once '
            . 'and then app/code/MagoAssistant/ClaudeSubscription/bin/claude-subscription start.',
            $url
        );
    }

    /**
     * Admin text when the body is not an OpenAI-style completion.
     *
     * @return Phrase
     */
    private function notACompletion(): Phrase
    {
        return __('Claude Subscription host returned a response that is not a chat completion.');
    }

    /**
     * Log the URL and status, then throw. The prompt is never logged.
     *
     * @param string $url
     * @param int $status
     * @param Phrase $message
     * @param \Exception|null $previous
     * @return never
     * @throws LocalizedException
     */
    private function fail(string $url, int $status, Phrase $message, ?\Exception $previous = null): never
    {
        $exception = new LocalizedException($message, $previous);
        $this->logger->error($exception->getMessage(), ['url' => $url, 'status' => $status]);
        throw $exception;
    }
}
