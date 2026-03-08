<?php

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI\Service as AIService;
use App\Ai\Service\Neuron\Provider\LLM\Ark;

it('ARK provider：forModel 构建时会初始化 model/parameters，避免流式请求解包报错', function () {
    $provider = AiProvider::query()->create([
        'name' => 'ARK',
        'code' => 'ark_provider_for_model',
        'protocol' => AiProvider::PROTOCOL_ARK,
        'api_key' => 'ark-key',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Ark Chat',
        'code' => 'ark_chat_model',
        'model' => 'doubao-seed-1-6-250615',
        'type' => AiModel::TYPE_CHAT,
        'options' => [
            'temperature' => 0.6,
        ],
        'active' => true,
    ]);

    $aiProvider = (new AIService())->forModel($model);
    expect($aiProvider)->toBeInstanceOf(Ark::class);

    $modelRef = new ReflectionProperty($aiProvider, 'model');
    $modelRef->setAccessible(true);
    expect($modelRef->getValue($aiProvider))->toBe('doubao-seed-1-6-250615');

    $paramsRef = new ReflectionProperty($aiProvider, 'parameters');
    $paramsRef->setAccessible(true);
    expect($paramsRef->getValue($aiProvider))->toBeArray();
});

it('BigModel provider：forModel 可按 OpenAI 兼容聊天协议构建', function () {
    $provider = AiProvider::query()->create([
        'name' => 'BigModel',
        'code' => 'bigmodel_provider_for_model',
        'protocol' => AiProvider::PROTOCOL_BIGMODEL,
        'api_key' => 'bigmodel-key',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'BigModel Chat',
        'code' => 'bigmodel_chat_model',
        'model' => 'glm-4.5',
        'type' => AiModel::TYPE_CHAT,
        'options' => [
            'temperature' => 0.4,
        ],
        'active' => true,
    ]);

    $aiProvider = (new AIService())->forModel($model);
    expect($aiProvider)->toBeInstanceOf(Ark::class);

    $baseRef = new ReflectionProperty($aiProvider, 'baseUri');
    $baseRef->setAccessible(true);
    expect($baseRef->getValue($aiProvider))->toBe('https://open.bigmodel.cn/api/paas/v4');
});
