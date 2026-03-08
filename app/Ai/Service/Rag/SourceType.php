<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

final class SourceType
{
    public static function forAssetType(mixed $type): string
    {
        $type = strtolower(trim((string)$type));
        if ($type === '') {
            return 'rag_data';
        }
        return match ($type) {
            'qa' => 'rag_qa',
            'sheet' => 'rag_sheet',
            default => 'rag_doc',
        };
    }
}

