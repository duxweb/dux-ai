<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

final class Token
{
    public static function estimateTokensForText(string $text): int
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return 0;
        }
        return (int)max(1, ceil(mb_strlen($normalized, 'UTF-8') / 4));
    }
}

