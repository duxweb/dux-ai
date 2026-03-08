<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Models\RagKnowledgeData;
use App\Ai\Support\AiRuntime;
use App\System\Service\Storage as StorageService;
use Throwable;

final class FileCleaner
{
    public static function purgeLocalFile(RagKnowledgeData $content): void
    {
        if (!$content->file_path) {
            return;
        }

        $storageName = $content->storage_name;
        if (!$storageName) {
            $content->loadMissing('knowledge.config.storage');
            $storageName = $content->knowledge?->config?->storage?->name;
        }
        if (!$storageName) {
            return;
        }

        try {
            $object = StorageService::getObject($storageName);
            if ($object->exists($content->file_path)) {
                $object->delete($content->file_path);
            }
        } catch (Throwable $throwable) {
            AiRuntime::instance()->log('ai.rag')->warning('Delete RAG file failed', [
                'content_id' => $content->id,
                'path' => $content->file_path,
                'storage' => $storageName,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
