<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI\Service as AIService;
use Core\App;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final class AI
{
    public const DI_KEY = 'ai.llm.service';

    private static ?AIService $service = null;

    public static function setService(?AIService $service): void
    {
        self::$service = $service;
        if ($service) {
            App::di()->set(self::DI_KEY, $service);
        }
    }

    public static function reset(): void
    {
        self::$service = null;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function forModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        return self::service()->forModel($model, $overrides, $timeoutSeconds);
    }

    public static function forEmbeddingsModel(AiModel $model): EmbeddingsProviderInterface
    {
        return self::service()->forEmbeddingsModel($model);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function forImageModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        return self::service()->forImageModel($model, $overrides, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function forVideoModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        return self::service()->forVideoModel($model, $overrides, $timeoutSeconds);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function forProvider(AiProvider $provider, string $model, array $parameters = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        return self::service()->forProvider($provider, $model, $parameters, $timeoutSeconds);
    }

    private static function service(): AIService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof AIService) {
                return self::$service = $resolved;
            }
        }

        $instance = new AIService();
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
