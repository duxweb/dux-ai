<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

final class Json
{
    public static function isJsonLikeString(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        return in_array($value[0], ['[', '{', '"'], true);
    }

    public static function tryDecode(string $value): mixed
    {
        if (!self::isJsonLikeString($value)) {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}

