<?php

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI\Service as AIService;
use App\Ai\Service\Neuron\Image\ImageProvider;
use App\Ai\Service\Neuron\Provider\Image\ArkImage;
use App\Ai\Service\Neuron\Provider\Image\OpenAICompatibleImage;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use NeuronAI\Testing\FakeAIProvider;

it('图片 provider：ark 协议返回 ArkImage，并默认 response_format=url', function () {
    $provider = AiProvider::query()->create([
        'name' => 'ARK',
        'code' => 'ark_provider_for_image',
        'protocol' => AiProvider::PROTOCOL_ARK,
        'api_key' => 'ark-key',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Ark Image',
        'code' => 'ark_image_model',
        'model' => 'doubao-seedream-4-5',
        'type' => AiModel::TYPE_IMAGE,
        'options' => [
            'size' => '1024x1024',
        ],
        'active' => true,
    ]);

    $imageProvider = (new AIService())->forImageModel($model, [
        'n' => 1,
    ]);

    expect($imageProvider)->toBeInstanceOf(ArkImage::class);

    $paramsRef = new ReflectionProperty($imageProvider, 'parameters');
    $paramsRef->setAccessible(true);
    $parameters = $paramsRef->getValue($imageProvider);

    expect($parameters['response_format'] ?? null)->toBe('url');
});

it('图片 provider：openai_like 协议返回 OpenAICompatibleImage', function () {
    $provider = AiProvider::query()->create([
        'name' => 'Compatible',
        'code' => 'compatible_provider_for_image',
        'protocol' => AiProvider::PROTOCOL_OPENAI_LIKE,
        'api_key' => 'test-key',
        'base_url' => 'https://example.com/v1',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'Compatible Image',
        'code' => 'compatible_image_model',
        'model' => 'gpt-image-1',
        'type' => AiModel::TYPE_IMAGE,
        'options' => [
            'image_output_format' => 'jpeg',
        ],
        'active' => true,
    ]);

    $imageProvider = (new AIService())->forImageModel($model, [
        'n' => 2,
    ]);

    expect($imageProvider)->toBeInstanceOf(OpenAICompatibleImage::class);

    $outputRef = new ReflectionProperty($imageProvider, 'outputFormat');
    $outputRef->setAccessible(true);
    expect($outputRef->getValue($imageProvider))->toBe('jpeg');
});

it('图片 provider：bigmodel 协议返回 OpenAICompatibleImage 且使用默认图片端点', function () {
    $provider = AiProvider::query()->create([
        'name' => 'BigModel',
        'code' => 'bigmodel_provider_for_image',
        'protocol' => AiProvider::PROTOCOL_BIGMODEL,
        'api_key' => 'bigmodel-key',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'BigModel Image',
        'code' => 'bigmodel_image_model',
        'model' => 'cogview-4',
        'type' => AiModel::TYPE_IMAGE,
        'active' => true,
    ]);

    $imageProvider = (new AIService())->forImageModel($model);

    expect($imageProvider)->toBeInstanceOf(OpenAICompatibleImage::class);

    $endpointRef = new ReflectionProperty($imageProvider, 'endpoint');
    $endpointRef->setAccessible(true);
    expect($endpointRef->getValue($imageProvider))->toBe('images/generations');
});

it('图片 provider：openai 协议优先使用官方 OpenAIImage', function () {
    $provider = AiProvider::query()->create([
        'name' => 'OpenAI',
        'code' => 'openai_provider_for_image',
        'protocol' => AiProvider::PROTOCOL_OPENAI,
        'api_key' => 'openai-key',
        'base_url' => 'https://api.openai.com/v1',
        'active' => true,
    ]);

    $model = AiModel::query()->create([
        'provider_id' => $provider->id,
        'name' => 'OpenAI Image',
        'code' => 'openai_image_model',
        'model' => 'gpt-image-1',
        'type' => AiModel::TYPE_IMAGE,
        'active' => true,
    ]);

    $imageProvider = (new AIService())->forImageModel($model, [
        'n' => 1,
        'image_output_format' => 'jpeg',
    ]);

    expect($imageProvider)->toBeInstanceOf(OpenAIImage::class);
});

it('图片 Agent：可按 Neuron Agent 方式 chat 后 getMessage', function () {
    $png1x1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO6pWQ0AAAAASUVORK5CYII=';
    $assistant = new AssistantMessage(
        new ImageContent($png1x1, SourceType::BASE64, 'image/png')
    );
    $provider = new FakeAIProvider($assistant);

    $message = ImageProvider::make($provider)
        ->chat(new UserMessage('生成图片'))
        ->getMessage();

    expect($message->getImage()?->content)->toBe($png1x1);
});
