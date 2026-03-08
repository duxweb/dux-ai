<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Service\FunctionCall\Service as FunctionCallService;
use App\Ai\Support\AiRuntime;
use Core\App;

final class FunctionCall
{
    public const DI_KEY = 'ai.function.service';

    private static ?FunctionCallService $service = null;

    public static function setService(?FunctionCallService $service): void
    {
        self::$service = $service;
        if ($service) {
            App::di()->set(self::DI_KEY, $service);
        }
    }

    public static function reset(): void
    {
        self::$service?->reset();
        self::$service = null;
    }

    /**
     * @return array<int, array{label: string, value: string, description?: string}>
     */
    public static function list(): array
    {
        return self::service()->list();
    }

    /**
     * @return mixed
     */
    public static function call(string $code, array $input = [], array $options = [])
    {
        return self::service()->call($code, $input, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $code): array
    {
        return self::service()->get($code);
    }

    private static function service(): FunctionCallService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof FunctionCallService) {
                return self::$service = $resolved;
            }
        }

        $instance = new FunctionCallService(AiRuntime::instance());
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
