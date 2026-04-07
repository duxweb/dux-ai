<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Capability\ImageGenerateCapability;
use App\Ai\Interface\NullCapabilityContext;
use App\Ai\Models\AiModel as AiModelEntity;
use App\Ai\Models\AiProvider;
use App\Ai\Service\Agent\AttachmentConfig;
use App\Ai\Service\AI;
use App\Ai\Service\CodeGenerator;
use App\Ai\Service\Neuron\Video\VideoProvider;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/model', name: 'ai.model')]
class Model extends Resources
{
    protected string $model = AiModelEntity::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'keyword' => null,
            'type' => null,
            'tab' => null,
        ];
        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
        }
        if ($params['type']) {
            $query->where('type', (string)$params['type']);
        } elseif ($params['tab'] && $params['tab'] !== 'all') {
            $query->where('type', (string)$params['tab']);
        }
        $query->with('provider');
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiModelEntity $item */
        $item->loadMissing('provider');
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'provider_id' => ['required', '请选择服务商'],
            'name' => ['required', '请输入模型名称'],
            'model' => ['required', '请输入远端模型 ID'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $type = (string)($data->type ?: AiModelEntity::TYPE_CHAT);
        if (!in_array($type, [
            AiModelEntity::TYPE_CHAT,
            AiModelEntity::TYPE_EMBEDDING,
            AiModelEntity::TYPE_IMAGE,
            AiModelEntity::TYPE_VIDEO,
        ], true)) {
            $type = AiModelEntity::TYPE_CHAT;
        }
        $dimensions = $data->dimensions === null || $data->dimensions === '' ? null : (int)$data->dimensions;
        if ($type !== AiModelEntity::TYPE_EMBEDDING) {
            $dimensions = null;
        } elseif ($dimensions !== null && $dimensions <= 0) {
            $dimensions = null;
        }

        $quotaType = (string)($data->quota_type ?: AiModelEntity::QUOTA_TYPE_ONCE);
        if (!in_array($quotaType, [AiModelEntity::QUOTA_TYPE_ONCE, AiModelEntity::QUOTA_TYPE_DAILY, AiModelEntity::QUOTA_TYPE_MONTHLY], true)) {
            $quotaType = AiModelEntity::QUOTA_TYPE_ONCE;
        }
        $quotaTokens = $data->quota_tokens === null || $data->quota_tokens === '' ? 0 : (int)$data->quota_tokens;
        if ($quotaTokens < 0) {
            $quotaTokens = 0;
        }
        $supportsStructuredOutput = (bool)($data->supports_structured_output ?? false);

        $options = is_array($data->options ?? null) ? ($data->options ?? []) : [];
        $mediaStorageName = trim((string)($options['media_storage_name'] ?? ''));
        $options['media_storage_name'] = $mediaStorageName !== '' ? $mediaStorageName : null;
        $options['video_compress'] = $this->normalizeVideoCompress($options['video_compress'] ?? []);
        $options['attachments'] = AttachmentConfig::normalize($options['attachments'] ?? []);
        $options['rate_limit'] = $this->normalizeRateLimit($options['rate_limit'] ?? []);
        $batchSize = isset($options['batch_size']) && is_numeric($options['batch_size'])
            ? (int)$options['batch_size']
            : null;
        if ($type !== AiModelEntity::TYPE_EMBEDDING) {
            unset($options['batch_size']);
        } else {
            $options['batch_size'] = $batchSize !== null && $batchSize > 0 ? min(50, $batchSize) : null;
        }
        if (!in_array($type, [AiModelEntity::TYPE_IMAGE, AiModelEntity::TYPE_VIDEO], true)) {
            unset($options['media_storage_name']);
        }
        if ($type !== AiModelEntity::TYPE_VIDEO) {
            unset($options['video_compress']);
        }
        if ($type !== AiModelEntity::TYPE_CHAT) {
            unset($options['rate_limit']);
            unset($options['max_output_tokens']);
        } else {
            $maxOutputTokens = isset($options['max_output_tokens']) && is_numeric($options['max_output_tokens'])
                ? (int)$options['max_output_tokens']
                : 600;
            $options['max_output_tokens'] = max(128, min(8192, $maxOutputTokens));
        }

        $id = (int)($args['id'] ?? 0);
        $inputCode = trim((string)$data->code);
        $code = $inputCode !== ''
            ? $inputCode
            : CodeGenerator::unique(
                static function (string $value) use ($id): bool {
                    $query = AiModelEntity::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        return [
            'provider_id' => fn() => (int)$data->provider_id,
            'name' => fn() => (string)$data->name,
            'code' => fn() => $code,
            'model' => fn() => (string)$data->model,
            'type' => fn() => $type,
            'dimensions' => fn() => $dimensions,
            'icon' => fn() => $data->icon ? (string)$data->icon : null,
            'options' => fn() => $options,
            'active' => fn() => (bool)$data->active,
            'supports_structured_output' => fn() => $supportsStructuredOutput,
            'description' => fn() => $data->description ?: null,
            'quota_type' => fn() => $quotaType,
            'quota_tokens' => fn() => $quotaTokens,
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeVideoCompress(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $maxMb = (int)($source['max_mb'] ?? 10);
        $maxWidth = (int)($source['max_width'] ?? 720);
        $maxHeight = (int)($source['max_height'] ?? 1280);
        $fps = (int)($source['fps'] ?? 24);
        $audioKbps = (int)($source['audio_kbps'] ?? 48);
        $timeout = (int)($source['timeout'] ?? 120);
        $preset = trim((string)($source['preset'] ?? 'veryfast'));
        if ($preset === '') {
            $preset = 'veryfast';
        }

        return [
            'enabled' => (bool)($source['enabled'] ?? true),
            'max_mb' => max(1, min(100, $maxMb)),
            'max_width' => max(160, min(4096, $maxWidth)),
            'max_height' => max(160, min(4096, $maxHeight)),
            'fps' => max(12, min(60, $fps)),
            'audio_kbps' => max(16, min(192, $audioKbps)),
            'timeout' => max(10, min(600, $timeout)),
            'preset' => $preset,
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, int|null>
     */
    private function normalizeRateLimit(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $tpm = isset($source['tpm']) && is_numeric($source['tpm']) ? (int)$source['tpm'] : 0;
        $concurrency = isset($source['concurrency']) && is_numeric($source['concurrency']) ? (int)$source['concurrency'] : 0;
        $maxWaitMs = isset($source['max_wait_ms']) && is_numeric($source['max_wait_ms']) ? (int)$source['max_wait_ms'] : 8000;

        return [
            'tpm' => $tpm > 0 ? max(1000, min(10000000, $tpm)) : null,
            'concurrency' => $concurrency > 0 ? max(1, min(1000, $concurrency)) : null,
            'max_wait_ms' => max(0, min(60000, $maxWaitMs)),
        ];
    }

    #[Action(methods: 'GET', route: '/providers')]
    public function providers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = AiProvider::query()->orderBy('name')->get()
            ->map(fn (AiProvider $provider) => [
                'label' => $provider->name,
                'value' => $provider->id,
                'code' => $provider->code,
            ])->all();
        return send($response, 'ok', $data);
    }

    #[Action(methods: 'POST', route: '/{id}/test')]
    public function test(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        /** @var AiModelEntity|null $model */
        $model = AiModelEntity::query()->with('provider')->find($id);
        if (!$model) {
            throw new \Core\Handlers\ExceptionBusiness('模型不存在');
        }
        if (!$model->provider instanceof AiProvider) {
            throw new \Core\Handlers\ExceptionBusiness('模型未绑定服务商');
        }

        $body = (array)$request->getParsedBody();
        $type = (string)($model->type ?? AiModelEntity::TYPE_CHAT);

        $data = match ($type) {
            AiModelEntity::TYPE_EMBEDDING => $this->testEmbeddingModel($model, $body),
            AiModelEntity::TYPE_IMAGE => $this->testImageModel($model, $body),
            AiModelEntity::TYPE_VIDEO => $this->testVideoModel($model, $body),
            default => $this->testChatModel($model, $body),
        };

        return send($response, 'ok', $data);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function testChatModel(AiModelEntity $model, array $body): array
    {
        $prompt = trim((string)($body['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \Core\Handlers\ExceptionBusiness('请输入运行内容');
        }

        $provider = AI::forModel($model);
        $outputMode = strtolower(trim((string)($body['output_mode'] ?? 'text')));
        $structuredSchema = is_array($body['structured_schema'] ?? null) ? ($body['structured_schema'] ?? []) : [];
        if (!in_array($outputMode, ['text', 'structured', 'auto'], true)) {
            $outputMode = 'text';
        }

        $modeUsed = 'text';
        $result = null;
        if (
            $outputMode !== 'text'
            && $structuredSchema !== []
            && (bool)($model->supports_structured_output ?? false)
        ) {
            try {
                $modeUsed = 'structured';
                $result = $provider->structured(UserMessage::make($prompt), \stdClass::class, \App\Ai\Service\Neuron\Structured\StructuredOutputService::treeToJsonSchema($structuredSchema));
            } catch (\Throwable $e) {
                if ($outputMode === 'structured') {
                    throw new \Core\Handlers\ExceptionBusiness('结构化输出失败：' . $e->getMessage());
                }
                $modeUsed = 'text';
            }
        } elseif ($outputMode === 'structured') {
            throw new \Core\Handlers\ExceptionBusiness('当前模型未开启结构化输出或缺少结构化 Schema');
        }

        if (!$result) {
            $result = $provider->chat(UserMessage::make($prompt));
        }
        $usage = $result->getUsage()?->jsonSerialize();

        return [
            'type' => AiModelEntity::TYPE_CHAT,
            'summary' => '聊天运行成功',
            'request' => [
                'prompt' => $prompt,
            ],
            'mode_used' => $modeUsed,
            'content' => $result->getContent(),
            'usage' => is_array($usage) ? $usage : null,
            'response' => $result->jsonSerialize(),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function testEmbeddingModel(AiModelEntity $model, array $body): array
    {
        $text = trim((string)($body['text'] ?? $body['prompt'] ?? ''));
        if ($text === '') {
            throw new \Core\Handlers\ExceptionBusiness('请输入运行文本');
        }

        $embedder = AI::forEmbeddingsModel($model);
        $docs = $embedder->embedDocuments([new Document($text)]);
        $first = $docs[0] ?? null;
        $vector = [];
        if ($first instanceof Document) {
            $value = $first->getEmbedding();
            if (is_array($value)) {
                $vector = $value;
            }
        }

        return [
            'type' => AiModelEntity::TYPE_EMBEDDING,
            'summary' => sprintf('向量生成成功（维度 %d）', count($vector)),
            'request' => [
                'text' => $text,
            ],
            'dimensions' => count($vector),
            'vector_preview' => array_slice($vector, 0, 12),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function testImageModel(AiModelEntity $model, array $body): array
    {
        $capability = new ImageGenerateCapability();
        $payload = [
            'model_id' => (int)$model->id,
            'prompt' => (string)($body['prompt'] ?? ''),
            'image_url' => (string)($body['image_url'] ?? ''),
            'image_urls' => is_array($body['image_urls'] ?? null) ? ($body['image_urls'] ?? []) : [],
            'mask_url' => (string)($body['mask_url'] ?? ''),
            'size' => (string)($body['size'] ?? ''),
            'n' => (int)($body['n'] ?? 1),
            'quality' => (string)($body['quality'] ?? ''),
            'style' => (string)($body['style'] ?? ''),
            'negative_prompt' => (string)($body['negative_prompt'] ?? ''),
        ];
        $result = $capability($payload, new NullCapabilityContext());
        $result['type'] = AiModelEntity::TYPE_IMAGE;
        return $result;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function testVideoModel(AiModelEntity $model, array $body): array
    {
        $prompt = trim((string)($body['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \Core\Handlers\ExceptionBusiness('请输入视频生成提示词');
        }

        $overrides = [];
        foreach (['image_url', 'resolution', 'ratio', 'duration', 'frames', 'seed', 'return_last_frame'] as $field) {
            $value = trim((string)($body[$field] ?? ''));
            if ($value !== '') {
                $overrides[$field] = $value;
            }
        }

        $provider = AI::forVideoModel($model, $overrides);
        $message = VideoProvider::make($provider)->createTask($prompt);
        $meta = $message->getMetadata('video_task');
        $taskMeta = is_array($meta) ? $meta : [];
        $taskId = trim((string)($taskMeta['task_id'] ?? ''));
        $status = trim((string)($taskMeta['status'] ?? ''));
        $statusUrl = trim((string)($taskMeta['status_url'] ?? ''));

        return [
            'type' => AiModelEntity::TYPE_VIDEO,
            'summary' => $taskId !== '' ? sprintf('视频任务已提交（%s）', $taskId) : '视频任务已提交',
            'request' => [
                'prompt' => $prompt,
                'overrides' => $overrides,
            ],
            'task_id' => $taskId !== '' ? $taskId : null,
            'provider_status' => $status !== '' ? $status : null,
            'status_url' => $statusUrl !== '' ? $statusUrl : null,
            'response' => $message->jsonSerialize(),
        ];
    }
}
