<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Reader;

final class ParseReaderContext
{
    /**
     * @var array<string, mixed>
     */
    private static array $context = [];

    /**
     * @param array<string, mixed> $context
     */
    public static function set(array $context): void
    {
        self::$context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return self::$context;
    }

    public static function clear(): void
    {
        self::$context = [];
    }
}
