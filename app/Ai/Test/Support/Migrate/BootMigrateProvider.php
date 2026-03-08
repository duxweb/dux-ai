<?php

declare(strict_types=1);

namespace App\Ai\Test\Support\Migrate;

final class BootMigrateProvider
{
    /**
     * @return array<int, class-string>
     */
    public static function models(): array
    {
        return [
            \App\Boot\Models\BootBot::class,
            \App\Boot\Models\BootMessageLog::class,
        ];
    }
}

