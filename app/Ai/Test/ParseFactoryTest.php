<?php

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Drivers\BaiduPaddleCloudDriver;
use App\Ai\Service\Parse\Drivers\BigModelDriver;
use App\Ai\Service\Parse\Drivers\LocalDriver;
use App\Ai\Service\Parse\Drivers\MoonshotDriver;
use App\Ai\Service\Parse\Drivers\VolcengineDriver;
use App\Ai\Service\Parse\ParseFactory;
use Core\Handlers\ExceptionBusiness;

it('ParseFactory：能返回内置驱动实例', function () {
    $provider = new ParseProvider();

    $provider->provider = 'local';
    expect(ParseFactory::driver($provider))->toBeInstanceOf(LocalDriver::class);

    $provider->provider = 'baidu_paddle_cloud';
    expect(ParseFactory::driver($provider))->toBeInstanceOf(BaiduPaddleCloudDriver::class);

    $provider->provider = 'moonshot';
    expect(ParseFactory::driver($provider))->toBeInstanceOf(MoonshotDriver::class);

    $provider->provider = 'volcengine_doc';
    expect(ParseFactory::driver($provider))->toBeInstanceOf(VolcengineDriver::class);

    $provider->provider = 'bigmodel';
    expect(ParseFactory::driver($provider))->toBeInstanceOf(BigModelDriver::class);
});

it('ParseFactory：未知驱动会抛出业务异常', function () {
    $provider = new ParseProvider();
    $provider->provider = 'unknown_driver';

    expect(fn () => ParseFactory::driver($provider))
        ->toThrow(ExceptionBusiness::class, '未注册');
});

it('ParseFactory：registry 与 providerMeta 可用', function () {
    $registry = ParseFactory::registry();
    expect($registry)->toBeArray()->not->toBeEmpty();

    $local = ParseFactory::providerMeta('local');
    expect($local['value'] ?? null)->toBe('local');

    $baidu = ParseFactory::providerMeta('baidu_paddle_cloud');
    expect($baidu['value'] ?? null)->toBe('baidu_paddle_cloud');

    $meta = ParseFactory::providerMeta('moonshot');
    expect($meta['value'] ?? null)->toBe('moonshot');

    $bigmodel = ParseFactory::providerMeta('bigmodel');
    expect($bigmodel['value'] ?? null)->toBe('bigmodel');

    $unknown = ParseFactory::providerMeta('abc');
    expect($unknown['value'] ?? null)->toBe('abc');
});

it('ParseFactory：可迁移旧 rapidocr_pdf 解析配置到 local', function () {
    $provider = new ParseProvider();
    $provider->name = 'legacy-rapidocrpdf';
    $provider->code = 'legacy_rapidocrpdf_' . str_replace('.', '', uniqid('', true));
    $provider->provider = 'rapidocr_pdf';
    $provider->save();

    $count = ParseFactory::migrateLegacyProviders();

    $provider->refresh();
    expect($count)->toBeGreaterThanOrEqual(1)
        ->and($provider->provider)->toBe('local');
});
