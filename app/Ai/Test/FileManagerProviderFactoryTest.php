<?php

use App\Ai\Models\AiProvider;
use App\Ai\Service\FileManager\FileManagerProviderFactory;
use App\Ai\Service\FileManager\Providers\ArkFileManagerProvider;
use App\Ai\Service\FileManager\Providers\OpenAILikeFileManagerProvider;

it('文件管理工厂：不支持文件协议时返回 null', function () {
    $provider = AiProvider::query()->create([
        'name' => 'Gemini',
        'code' => 'p_gemini',
        'protocol' => AiProvider::PROTOCOL_GEMINI,
        'api_key' => 'test-key',
        'base_url' => 'https://generativelanguage.googleapis.com',
        'active' => true,
    ]);

    expect(FileManagerProviderFactory::make($provider))->toBeNull();
});

it('文件管理工厂：auto + ark 协议返回 Ark provider', function () {
    $provider = AiProvider::query()->create([
        'name' => 'Ark',
        'code' => 'p_ark',
        'protocol' => AiProvider::PROTOCOL_ARK,
        'api_key' => 'ark-key',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'active' => true,
    ]);

    $manager = FileManagerProviderFactory::make($provider);

    expect($manager)->toBeInstanceOf(ArkFileManagerProvider::class);
});

it('文件管理工厂：moonshot base_url 按 openai_like provider 处理', function () {
    $provider = AiProvider::query()->create([
        'name' => 'Moonshot',
        'code' => 'p_moonshot',
        'protocol' => AiProvider::PROTOCOL_OPENAI_LIKE,
        'api_key' => 'moonshot-key',
        'base_url' => 'https://api.moonshot.cn/v1',
        'active' => true,
    ]);

    $manager = FileManagerProviderFactory::make($provider);

    expect($manager)->toBeInstanceOf(OpenAILikeFileManagerProvider::class);

    $ref = new ReflectionProperty($manager, 'options');
    $ref->setAccessible(true);
    $options = $ref->getValue($manager);
    expect($options['purpose'] ?? null)->toBe('file-extract');
});

it('文件管理工厂：openai_like 协议返回默认 provider', function () {
    $provider = AiProvider::query()->create([
        'name' => 'Compatible',
        'code' => 'p_compatible',
        'protocol' => AiProvider::PROTOCOL_OPENAI_LIKE,
        'api_key' => 'compatible-key',
        'base_url' => 'https://example.com/v1',
        'active' => true,
    ]);

    $manager = FileManagerProviderFactory::make($provider);

    expect($manager)->toBeInstanceOf(OpenAILikeFileManagerProvider::class);
});

it('文件管理工厂：bigmodel 协议返回 openai_like provider', function () {
    $provider = AiProvider::query()->create([
        'name' => 'BigModel',
        'code' => 'p_bigmodel',
        'protocol' => AiProvider::PROTOCOL_BIGMODEL,
        'api_key' => 'bigmodel-key',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'active' => true,
    ]);

    $manager = FileManagerProviderFactory::make($provider);

    expect($manager)->toBeInstanceOf(OpenAILikeFileManagerProvider::class);
});
