<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\RagKnowledge;
use App\Ai\Models\RagKnowledgeData;
use App\Ai\Service\Rag\Service as RagService;
use Psr\Http\Message\UploadedFileInterface;
use Core\App;

final class Rag
{
    public const DI_KEY = 'ai.rag.service';

    public const CONTENT_TYPES = RagService::CONTENT_TYPES;
    public const DEFAULT_CONTENT_TYPE = RagService::DEFAULT_CONTENT_TYPE;

    private static ?RagService $service = null;

    public static function setService(?RagService $service): void
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

    public static function syncKnowledge(string|int|RagKnowledge $knowledge): RagKnowledge
    {
        return self::service()->syncKnowledge($knowledge);
    }

    public static function deleteKnowledge(string|int|RagKnowledge $knowledge, bool $deleteRecord = true): bool
    {
        return self::service()->deleteKnowledge($knowledge, $deleteRecord);
    }

    public static function clearKnowledge(string|int|RagKnowledge $knowledge): bool
    {
        return self::service()->clearKnowledge($knowledge);
    }

    public static function importContent(string|int|RagKnowledge $knowledge, UploadedFileInterface $file, string $type = 'document', array $options = []): RagKnowledgeData
    {
        return self::service()->importContent($knowledge, $file, $type, $options);
    }

    public static function query(string|int|RagKnowledge $knowledge, string $query, int $limit = 5, array $options = []): array
    {
        return self::service()->query($knowledge, $query, $limit, $options);
    }

    public static function deleteContent(RagKnowledgeData|int $record, bool $deleteRecord = true): bool
    {
        return self::service()->deleteContent($record, $deleteRecord);
    }

    private static function service(): RagService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof RagService) {
                return self::$service = $resolved;
            }
        }

        $instance = new RagService();
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
