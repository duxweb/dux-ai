<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Support\AiRuntime;

final class Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $type, array $context = []): void
    {
        AiRuntime::instance()->log('ai.agent')->debug($type, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(bool $enabled, string $type, array $context = []): void
    {
        if (!$enabled) {
            return;
        }
        self::log($type, $context);
    }
}
