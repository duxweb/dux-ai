<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Support\AiRuntime;
use Core\App;

final class Capability
{
    public const DI_KEY = 'ai.capability.service';

    private static ?CapabilityService $service = null;

    public static function setService(?CapabilityService $service): void
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
        // Don't set null in DI to avoid has()==true with null; just leave it.
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list(?string $scope = null): array
    {
        return self::service()->list($scope);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $code): ?array
    {
        return self::service()->get($code);
    }

    /**
     * @param array<string, mixed> $input
     * @return mixed
     */
    public static function execute(string $code, array $input, CapabilityContextInterface $context)
    {
        return self::service()->execute($code, $input, $context);
    }

    private static function service(): CapabilityService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof CapabilityService) {
                return self::$service = $resolved;
            }
        }

        $instance = new CapabilityService(AiRuntime::instance());
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
