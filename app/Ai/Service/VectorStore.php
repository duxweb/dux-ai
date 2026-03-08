<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\AiVector;
use App\Ai\Service\VectorStore\Service as VectorStoreService;
use App\Ai\Service\VectorStore\VectorStoreInterface;
use App\Ai\Support\AiRuntime;
use Core\App;

final class VectorStore
{
    public const DI_KEY = 'ai.vectorStore.service';

    private static ?VectorStoreService $service = null;

    public static function registry(): array
    {
        return self::service()->registry();
    }

    /**
     * @return array<string, mixed>
     */
    public static function driverMeta(string $driver): array
    {
        return self::service()->driverMeta($driver);
    }

    public static function make(AiVector $vector, int $knowledgeId, ?int $dimensions = null): VectorStoreInterface
    {
        return self::service()->make($vector, $knowledgeId, $dimensions);
    }

    public static function setService(?VectorStoreService $service): void
    {
        self::$service = $service;
        if ($service) {
            App::di()->set(self::DI_KEY, $service);
        }
    }

    public static function reset(): void
    {
        self::$service?->reset();
        self::$service = null;
    }

    private static function service(): VectorStoreService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof VectorStoreService) {
                return self::$service = $resolved;
            }
        }

        $instance = new VectorStoreService(AiRuntime::instance());
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
