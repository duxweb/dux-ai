<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Service as ParseService;
use Core\App;

final class Parse
{
    public const DI_KEY = 'ai.parse.service';

    private static ?ParseService $service = null;

    public static function setService(?ParseService $service): void
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function registry(): array
    {
        return self::service()->registry();
    }

    /**
     * @return array<string, mixed>
     */
    public static function providerMeta(string $provider): array
    {
        return self::service()->providerMeta($provider);
    }

    public static function resolveProvider(ParseProvider|string|int $identifier): ParseProvider
    {
        return self::service()->resolveProvider($identifier);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function parseFile(ParseProvider|string|int $identifier, string $filePath, string $fileType, array $options = []): string
    {
        return self::service()->parseFile($identifier, $filePath, $fileType, $options);
    }

    private static function service(): ParseService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof ParseService) {
                return self::$service = $resolved;
            }
        }

        $instance = new ParseService();
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
