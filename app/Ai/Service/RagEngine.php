<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\RagKnowledge;
use App\Ai\Models\RagKnowledgeData;
use App\Ai\Models\RegProvider;
use App\Ai\Service\RagEngine\Service as RagEngineService;
use App\Ai\Support\AiRuntime;
use Core\App;

final class RagEngine
{
    public const DI_KEY = 'ai.ragEngine.service';

    private static ?RagEngineService $service = null;

    public static function setService(?RagEngineService $service): void
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
     * @return array<string, mixed>
     */
    public static function normalizeConfig(array|null $config): array
    {
        return self::service()->normalizeConfig($config);
    }

    public static function ensureSynced(RagKnowledge $knowledge): void
    {
        self::service()->ensureSynced($knowledge);
    }

    public static function deleteKnowledge(RegProvider $config, string $remoteId): bool
    {
        return self::service()->deleteKnowledge($config, $remoteId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function addContent(RagKnowledge $knowledge, RagKnowledgeData $record, array $payload): string
    {
        return self::service()->addContent($knowledge, $record, $payload);
    }

    /**
     * @param array<int, array{question: string, answer: string}> $qas
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public static function addQa(RagKnowledge $knowledge, RagKnowledgeData $record, array $qas, array $options = []): array
    {
        return self::service()->addQa($knowledge, $record, $qas, $options);
    }

    /**
     * @param array<int, string> $sourceIds
     */
    public static function deleteContent(RegProvider $config, string $remoteId, array $sourceIds): bool
    {
        return self::service()->deleteContent($config, $remoteId, $sourceIds);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public static function query(RegProvider $config, string $remoteId, string $query, int $limit = 5, array $options = []): array
    {
        return self::service()->query($config, $remoteId, $query, $limit, $options);
    }

    private static function service(): RagEngineService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof RagEngineService) {
                return self::$service = $resolved;
            }
        }

        $instance = new RagEngineService(AiRuntime::instance());
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
