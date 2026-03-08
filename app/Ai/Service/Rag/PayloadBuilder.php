<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Models\RagKnowledgeData;
use Core\Handlers\ExceptionBusiness;

final class PayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRecord(RagKnowledgeData $content): array
    {
        if (!$content->url || !$content->file_name) {
            throw new ExceptionBusiness('文件链接不存在，请重新上传');
        }

        $meta = is_array($content->meta ?? null) ? ($content->meta ?? []) : [];
        $options = is_array($meta['options'] ?? null) ? ($meta['options'] ?? []) : [];

        return [
            'file_name' => $content->file_name,
            'file_type' => $content->file_type ? strtolower($content->file_type) : null,
            'file_url' => $content->url,
            'file_storage_path' => $content->file_path ?: null,
            'file_storage' => $content->storage_name ?: null,
            'file_size' => $content->file_size ?: null,
            'data_id' => $content->id ? (int)$content->id : null,
            'knowledge_id' => $content->knowledge_id ? (int)$content->knowledge_id : null,
            'content_type' => $content->type ?: null,
            'options' => $options !== [] ? $options : null,
        ];
    }
}

