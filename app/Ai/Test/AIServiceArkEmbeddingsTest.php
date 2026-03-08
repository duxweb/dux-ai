<?php

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI\Service as AIService;
use App\Ai\Service\Neuron\Provider\Embedding\ArkEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

it('ARK embeddings：视觉模型默认使用 embeddings/multimodal', function () {
    $provider = AiProvider::query()->create([
        'name' => 'ARK',
        'code' => 'ark_provider',
        'protocol' => AiProvider::PROTOCOL_ARK,
        'api_key' => 'ark-key',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Vision Embedding',
        'code' => 'vision_embedding',
        'model' => 'doubao-embedding-vision-250615',
        'type' => AiModel::TYPE_EMBEDDING,
        'options' => [],
        'active' => true,
    ]);

    $embedder = (new AIService())->forEmbeddingsModel($model);
    expect($embedder)->toBeInstanceOf(ArkEmbeddingsProvider::class);

    $ref = new ReflectionProperty($embedder, 'endpoint');
    $ref->setAccessible(true);
    expect($ref->getValue($embedder))->toBe('embeddings/multimodal');
});

it('ARK embeddings：文本模型默认也使用 embeddings/multimodal', function () {
    $provider = AiProvider::query()->create([
        'name' => 'ARK',
        'code' => 'ark_provider_text',
        'protocol' => AiProvider::PROTOCOL_ARK,
        'api_key' => 'ark-key',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Text Embedding',
        'code' => 'text_embedding',
        'model' => 'doubao-embedding-text-240715',
        'type' => AiModel::TYPE_EMBEDDING,
        'options' => [],
        'active' => true,
    ]);

    $embedder = (new AIService())->forEmbeddingsModel($model);
    expect($embedder)->toBeInstanceOf(ArkEmbeddingsProvider::class);

    $ref = new ReflectionProperty($embedder, 'endpoint');
    $ref->setAccessible(true);
    expect($ref->getValue($embedder))->toBe('embeddings/multimodal');
});

it('BigModel embeddings：复用 OpenAI Compatible 嵌入驱动', function () {
    $provider = AiProvider::query()->create([
        'name' => 'BigModel',
        'code' => 'bigmodel_provider_embedding',
        'protocol' => AiProvider::PROTOCOL_BIGMODEL,
        'api_key' => 'bigmodel-key',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'BigModel Text Embedding',
        'code' => 'bigmodel_text_embedding',
        'model' => 'embedding-3',
        'type' => AiModel::TYPE_EMBEDDING,
        'options' => [],
        'active' => true,
    ]);

    $embedder = (new AIService())->forEmbeddingsModel($model);
    expect($embedder)->toBeInstanceOf(OpenAILikeEmbeddings::class);
});
