<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Model\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Zero-argument HttpClient the object manager can construct.
 */
class SymfonyClient implements HttpClientInterface
{
    /**
     * @var HttpClientInterface
     */
    private HttpClientInterface $client;

    /**
     * Build the default Symfony client. Timeouts are set per request.
     */
    public function __construct()
    {
        $this->client = HttpClient::create();
    }

    /**
     * @inheritdoc
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $options);
    }

    /**
     * @inheritdoc
     */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @inheritdoc
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);
        return $clone;
    }
}
