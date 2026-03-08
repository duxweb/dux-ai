<?php

declare(strict_types=1);

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI\Service as AIService;
use App\Ai\Service\Neuron\Provider\Video\ArkVideo;
use Core\Handlers\ExceptionBusiness;

it('视频 provider：ark 协议返回 ArkVideo 并携带默认轮询配置', function () {
    $provider = AiProvider::query()->create([
        'name' => 'ARK',
        'code' => 'ark_provider_for_video',
        'protocol' => AiProvider::PROTOCOL_ARK,
        'api_key' => 'ark-key',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Ark Video',
        'code' => 'ark_video_model',
        'model' => 'doubao-seedance-1-0-pro-250528',
        'type' => AiModel::TYPE_VIDEO,
        'active' => true,
    ]);

    $videoProvider = (new AIService())->forVideoModel($model, [
        'video_endpoint' => 'contents/generations/tasks',
        'video_status_endpoint' => 'contents/generations/tasks/{id}',
    ]);

    expect($videoProvider)->toBeInstanceOf(ArkVideo::class);

    $queryEndpoint = new ReflectionProperty($videoProvider, 'queryEndpoint');
    $queryEndpoint->setAccessible(true);
    expect($queryEndpoint->getValue($videoProvider))->toBe('contents/generations/tasks/{id}');

    $completedValues = new ReflectionProperty($videoProvider, 'completedValues');
    $completedValues->setAccessible(true);
    expect($completedValues->getValue($videoProvider))->toBe(['succeeded', 'completed', 'success']);
});

it('视频 provider：bigmodel 协议返回 ArkVideo 并使用异步结果端点', function () {
    $provider = AiProvider::query()->create([
        'name' => 'BigModel',
        'code' => 'bigmodel_provider_for_video',
        'protocol' => AiProvider::PROTOCOL_BIGMODEL,
        'api_key' => 'bigmodel-key',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'BigModel Video',
        'code' => 'bigmodel_video_model',
        'model' => 'cogvideox-flash',
        'type' => AiModel::TYPE_VIDEO,
        'active' => true,
    ]);

    $videoProvider = (new AIService())->forVideoModel($model);

    expect($videoProvider)->toBeInstanceOf(ArkVideo::class);

    $queryEndpoint = new ReflectionProperty($videoProvider, 'queryEndpoint');
    $queryEndpoint->setAccessible(true);
    expect($queryEndpoint->getValue($videoProvider))->toBe('async-result/{id}');

    $statusPath = new ReflectionProperty($videoProvider, 'statusPath');
    $statusPath->setAccessible(true);
    expect($statusPath->getValue($videoProvider))->toBe('task_status');
});

it('视频 provider：非 ark 协议抛出不支持错误', function () {
    $provider = AiProvider::query()->create([
        'name' => 'OpenAI Like',
        'code' => 'compatible_provider_for_video',
        'protocol' => AiProvider::PROTOCOL_OPENAI_LIKE,
        'api_key' => 'test-key',
        'base_url' => 'https://example.com/v1',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Compat Video',
        'code' => 'compat_video_model',
        'model' => 'demo-video-model',
        'type' => AiModel::TYPE_VIDEO,
        'active' => true,
    ]);

    expect(fn () => (new AIService())->forVideoModel($model))
        ->toThrow(ExceptionBusiness::class, '暂不支持视频生成');
});
