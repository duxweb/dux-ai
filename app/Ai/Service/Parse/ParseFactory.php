<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse;

use App\Ai\Event\ParseDriverEvent;
use App\Ai\Models\ParseProvider;
use App\Ai\Support\AiRuntime;
use App\Ai\Service\Parse\Contracts\DriverInterface;
use App\Ai\Service\Parse\Drivers\BaiduPaddleCloudDriver;
use App\Ai\Service\Parse\Drivers\BigModelDriver;
use App\Ai\Service\Parse\Drivers\LocalDriver;
use App\Ai\Service\Parse\Drivers\MoonshotDriver;
use App\Ai\Service\Parse\Drivers\VolcengineDriver;
use Core\Handlers\ExceptionBusiness;
use Throwable;

final class ParseFactory
{
    /** @var array<string, class-string<DriverInterface>> */
    private const BUILTIN_DRIVERS = [
        'local' => LocalDriver::class,
        'baidu_paddle_cloud' => BaiduPaddleCloudDriver::class,
        'moonshot' => MoonshotDriver::class,
        'volcengine_doc' => VolcengineDriver::class,
        'bigmodel' => BigModelDriver::class,
    ];

    /**
     * @return array{drivers: array<string, class-string<DriverInterface>>, meta: array<string, array<string, mixed>>}
     */
    private static function registryData(): array
    {
        $event = new ParseDriverEvent();
        foreach (self::BUILTIN_DRIVERS as $name => $class) {
            $event->register($name, $class, $class::meta());
        }
        AiRuntime::instance()->event()->dispatch($event, 'ai.parse.driver');

        return [
            'drivers' => $event->getDrivers(),
            'meta' => $event->getMeta(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function registry(): array
    {
        $items = [];
        $data = self::registryData();
        return array_values($data['meta']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function providerMeta(string $provider): array
    {
        foreach (self::registry() as $item) {
            if ((string)($item['value'] ?? '') === $provider) {
                return $item;
            }
        }

        return [
            'label' => $provider,
            'value' => $provider,
        ];
    }

    public static function driver(ParseProvider $provider): DriverInterface
    {
        $name = (string)$provider->provider;
        $data = self::registryData();
        $class = $data['drivers'][$name] ?? null;
        if (!$class) {
            throw new ExceptionBusiness(sprintf('解析驱动 [%s] 未注册', $name));
        }

        return new $class();
    }

    public static function migrateLegacyProviders(): int
    {
        try {
            return (int)ParseProvider::query()
                ->where('provider', 'rapidocr_pdf')
                ->update(['provider' => 'local']);
        } catch (Throwable) {
            return 0;
        }
    }
}
