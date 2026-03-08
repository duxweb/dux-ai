<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

final class KnowledgeId
{
    public static function parse(string $remoteId): int
    {
        if (preg_match('/:(\\d+)$/', trim($remoteId), $m)) {
            return (int)($m[1] ?? 0);
        }
        return 0;
    }
}

