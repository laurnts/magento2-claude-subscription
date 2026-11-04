<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\AiService;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\FieldDescriptor;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Admin configuration for a Claude Subscription proxy reached over HTTP.
 */
class ClaudeSubscription implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    public const CODE = 'claude_subscription';

    public const DEFAULT_BASE_URL = 'http://host.docker.internal:3456/v1';

    public const DEFAULT_MODEL = 'sonnet';

    /**
     * @param HttpClientInterface $http
     */
    public function __construct(
        private readonly HttpClientInterface $http,
    ) {
    }

    /**
     * Machine code stored on the AI service row.
     *
     * @return string
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * Name shown in the AI Configuration provider list.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Claude Subscription';
    }

    /**
     * Fields rendered for a Claude Subscription row.
     *
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array
    {
        return [
            new FieldDescriptor('base_url', 'Base URL', 'text', [], self::DEFAULT_BASE_URL),
            new FieldDescriptor('model', 'Model', 'select', $this->selectOptions(), self::DEFAULT_MODEL),
        ];
    }

    /**
     * Models the reference proxy advertises, used before Refresh Models has been clicked.
     *
     * @return array<string, string>
     */
    public function getSupportedModels(): array
    {
        $models = [];
        foreach ($this->selectOptions() as $option) {
            $models[$option['value']] = $option['label'];
        }
        return $models;
    }

    /**
     * Ask the host proxy for the model ids it currently serves.
     *
     * @param array $configuration
     * @return array Model id to label
     * @throws LocalizedException
     */
    public function fetchModels(array $configuration): array
    {
        $base = $this->baseUrl($configuration);
        $url = rtrim($base, '/') . '/models';
        $options = ['timeout' => 20, 'headers' => ['Accept' => 'application/json']];
        try {
            $response = $this->http->request('GET', $url, $options);
            $status = $response->getStatusCode();
        } catch (TransportException $exception) {
            throw new LocalizedException(__(
                'Claude Subscription proxy is not reachable at %1.',
                $url
            ), $exception);
        }
        if ($status < 200 || $status >= 300) {
            throw new LocalizedException(__('Claude Subscription proxy returned HTTP %1 for %2.', $status, $url));
        }
        try {
            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            throw new LocalizedException(__(
                'Claude Subscription proxy returned a response that is not a model list from %1.',
                $url
            ), $exception);
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new LocalizedException(__(
                'Claude Subscription proxy returned a response that is not a model list from %1.',
                $url
            ));
        }
        $models = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
                $models[$row['id']] = $row['id'];
            }
        }
        return $models;
    }

    /**
     * Configured base URL, or the Docker-host default.
     *
     * @param array $configuration
     * @return string
     */
    private function baseUrl(array $configuration): string
    {
        $base = $configuration['base_url'] ?? '';
        if (!is_string($base) || $base === '') {
            return self::DEFAULT_BASE_URL;
        }
        return $base;
    }

    /**
     * The aliases `claude --model` accepts. The saved value is passed through as-is.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function selectOptions(): array
    {
        return [
            ['value' => 'opus', 'label' => 'Opus'],
            ['value' => 'fable', 'label' => 'Fable'],
            ['value' => 'sonnet', 'label' => 'Sonnet'],
            ['value' => 'haiku', 'label' => 'Haiku'],
        ];
    }
}
