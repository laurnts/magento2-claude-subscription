<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\AiService;

use MagoAssistant\ClaudeSubscription\AiService\ClaudeSubscription;
use MagoAssistant\ClaudeSubscription\Test\Unit\Fakes\FieldFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClaudeSubscriptionTest extends TestCase
{
    #[Test]
    public function itAdvertisesTheCodeTheCuratedModelsAndNoApiKey(): void
    {
        $service = new ClaudeSubscription(new FieldFactory(), $this->createStub(JsonFetcherInterface::class));

        self::assertSame('claude_subscription', $service->getCode());
        self::assertSame('Claude Subscription', $service->getName());
        self::assertSame(['opus', 'fable', 'sonnet', 'haiku'], array_keys($service->getSupportedModels()));
        self::assertSame([], $service->getPlatformArguments(['base_url' => 'http://x']));

        $fields = [];
        foreach ($service->getConfigurationFields() as $field) {
            $fields[$field->getName()] = $field;
        }
        self::assertSame(['base_url', 'model'], array_keys($fields));
        self::assertSame(ClaudeSubscription::DEFAULT_BASE_URL, $fields['base_url']->getDefault());
        self::assertTrue($fields['base_url']->isEndpoint());
        self::assertSame('select', $fields['model']->getType());
        self::assertSame('sonnet', $fields['model']->getDefault());
        self::assertCount(4, $fields['model']->getOptions());
    }

    #[Test]
    public function fetchModelsAsksTheHostForItsModelList(): void
    {
        $fetcher = $this->createMock(JsonFetcherInterface::class);
        $fetcher->expects(self::once())
            ->method('getJson')
            ->with('http://host.docker.internal:3456/v1/models', [])
            ->willReturn(['data' => [['id' => 'opus'], ['id' => 'custom'], ['nope' => 1]]]);
        $service = new ClaudeSubscription(new FieldFactory(), $fetcher);

        $models = $service->fetchModels(['base_url' => 'http://host.docker.internal:3456/v1/']);

        self::assertSame(['opus' => 'opus', 'custom' => 'custom'], $models);
    }

    #[Test]
    public function baseUrlIsTrimmedAndDefaulted(): void
    {
        $service = new ClaudeSubscription(new FieldFactory(), $this->createStub(JsonFetcherInterface::class));

        self::assertSame('http://p/v1', $service->baseUrl(['base_url' => ' http://p/v1/ ']));
        self::assertSame(ClaudeSubscription::DEFAULT_BASE_URL, $service->baseUrl([]));
        self::assertSame(ClaudeSubscription::DEFAULT_BASE_URL, $service->baseUrl(['base_url' => 42]));
    }

    #[Test]
    public function fetchModelsFallsBackToTheDockerHostDefault(): void
    {
        $fetcher = $this->createMock(JsonFetcherInterface::class);
        $fetcher->expects(self::once())
            ->method('getJson')
            ->with(ClaudeSubscription::DEFAULT_BASE_URL . '/models')
            ->willReturn(['data' => []]);

        self::assertSame([], (new ClaudeSubscription(new FieldFactory(), $fetcher))->fetchModels([]));
    }

    #[Test]
    public function fetchModelsRejectsABodyWithoutAList(): void
    {
        $fetcher = $this->createStub(JsonFetcherInterface::class);
        $fetcher->method('getJson')->willReturn(['nope' => true]);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "data" list');

        (new ClaudeSubscription(new FieldFactory(), $fetcher))->fetchModels([]);
    }
}
