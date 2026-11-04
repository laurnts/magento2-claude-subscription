<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\AiService;

use MageOS\AiBase\AiServices\AbstractAiService;
use MageOS\AiBase\AiServices\ModelListTrait;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

/**
 * Admin configuration for the Claude Subscription host reached over HTTP.
 */
class ClaudeSubscription extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    public const CODE = 'claude_subscription';

    public const DEFAULT_BASE_URL = 'http://host.docker.internal:3456/v1';

    public const DEFAULT_MODEL = 'sonnet';

    /**
     * Aliases `claude --model` accepts. The saved value is passed through as-is.
     */
    private const MODELS = [
        'opus' => 'Opus',
        'fable' => 'Fable',
        'sonnet' => 'Sonnet',
        'haiku' => 'Haiku',
    ];

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param JsonFetcherInterface $modelListFetcher
     */
    public function __construct(
        FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly JsonFetcherInterface $modelListFetcher,
    ) {
        parent::__construct($fieldFactory);
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Claude Subscription';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return self::MODELS;
    }

    /**
     * A base URL and a model. There is no API key: the host owns the Claude login.
     *
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField(self::DEFAULT_BASE_URL),
            $this->modelFieldWithDefault(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $baseUrl = $this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL);

        return $this->parseDataModelList($this->modelListFetcher->getJson($baseUrl . '/models'));
    }

    /**
     * The row's base URL without surrounding space or trailing slash, or the Docker-host default.
     *
     * The same normalisation fetchModels() applies, so Refresh Models and chat reach one address.
     *
     * @param array<string,mixed> $configuration
     * @return string
     */
    public function baseUrl(#[\SensitiveParameter] array $configuration): string
    {
        return $this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL);
    }

    /**
     * No bridge arguments: ClientFactoryPlugin builds the client from the row itself.
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array
    {
        return [];
    }

    /**
     * The model select, preselecting the alias the host script defaults to.
     *
     * @return FieldDescriptorInterface
     */
    private function modelFieldWithDefault(): FieldDescriptorInterface
    {
        $options = [];
        foreach (self::MODELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $this->fieldFactory->create([
            'name' => self::FIELD_MODEL,
            'label' => 'Model',
            'type' => FieldDescriptorInterface::TYPE_SELECT,
            'options' => $options,
            'default' => self::DEFAULT_MODEL,
        ]);
    }
}
