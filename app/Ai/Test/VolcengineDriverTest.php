<?php

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Drivers\VolcengineDriver;
use App\System\Service\Config;

it('VolcengineDriver：优先使用解析配置的存储驱动', function () {
    Config::setValue('system', ['storage' => 9]);

    $provider = new ParseProvider();
    $provider->storage_id = 3;

    $driver = new VolcengineDriver();
    $resolver = Closure::bind(
        fn (ParseProvider $provider): int => $this->resolveStorageId($provider),
        $driver,
        VolcengineDriver::class
    );

    expect($resolver($provider))->toBe(3);
});

it('VolcengineDriver：未配置解析存储时回退系统默认存储', function () {
    Config::setValue('system', ['storage' => 7]);

    $provider = new ParseProvider();
    $provider->storage_id = null;

    $driver = new VolcengineDriver();
    $resolver = Closure::bind(
        fn (ParseProvider $provider): int => $this->resolveStorageId($provider),
        $driver,
        VolcengineDriver::class
    );

    expect($resolver($provider))->toBe(7);
});

it('VolcengineDriver：解析配置和系统默认存储都缺失时返回 0', function () {
    Config::setValue('system', []);

    $provider = new ParseProvider();
    $provider->storage_id = null;

    $driver = new VolcengineDriver();
    $resolver = Closure::bind(
        fn (ParseProvider $provider): int => $this->resolveStorageId($provider),
        $driver,
        VolcengineDriver::class
    );

    expect($resolver($provider))->toBe(0);
});
