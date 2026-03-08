<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Event\AiCapabilityEvent;
use App\Ai\Models\AiFlowModel;
use App\Ai\Models\AiModel;
use App\Ai\Service\Neuron\Flow\StateTemplate;
use App\Ai\Service\Neuron\Flow\WorkflowToolContext;
use App\Ai\Service\Neuron\Structured\StructuredOutputService;
use Carbon\Carbon;
use Core\Event\Attribute\Listener;
use Core\Handlers\ExceptionBusiness;

class CapabilityAiTaskListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'ai_task';

        $outputFields = [
            ['name' => 'content', 'label' => '模型回复', 'type' => 'text', 'description' => '字符串或 Markdown 内容'],
            ['name' => 'value', 'label' => '结构化结果', 'type' => 'object', 'description' => 'output_mode 为 structured/auto 时可能返回'],
            ['name' => 'mode_used', 'label' => '输出模式', 'type' => 'text', 'description' => 'structured 或 text'],
        ];

        $defaultConfig = [
            'temperature' => 0.7,
            'message_role' => 'assistant',
            'models' => [],
            'balance' => 'quota_remaining',
            'reserve_percent' => 0,
            'output_mode' => 'auto',
            'structured_schema' => [],
        ];
        $settingFields = [
            [
                'name' => 'models',
                'label' => '模型',
                'required' => true,
                'component' => 'dux-select',
                'componentProps' => [
                    'path' => 'ai/flow/modelOptions',
                    'labelField' => 'label',
                    'valueField' => 'id',
                    'descField' => 'desc',
                    'pagination' => true,
                    'multiple' => true,
                ],
                'description' => '支持多模型轮询调用',
                'preview' => ['label' => '模型'],
            ],
            [
                'name' => 'balance',
                'label' => '轮询方案',
                'component' => 'select',
                'defaultValue' => 'quota_remaining',
                'options' => [
                    ['label' => '剩余额度优先', 'value' => 'quota_remaining'],
                    ['label' => '剩余额度比例优先', 'value' => 'quota_ratio'],
                    ['label' => '顺序轮询', 'value' => 'round_robin'],
                ],
                'description' => '根据额度或顺序选择模型',
            ],
            [
                'name' => 'reserve_percent',
                'label' => '保留额度比例(%)',
                'component' => 'number',
                'defaultValue' => 0,
                'componentProps' => [
                    'min' => 0,
                    'max' => 50,
                    'step' => 1,
                ],
                'description' => '剩余额度不足保留比例时不优先使用',
            ],
            [
                'name' => 'systemPrompt',
                'label' => '系统提示词',
                'component' => 'prompt-editor',
                'preview' => false,
            ],
            [
                'name' => 'userPrompt',
                'label' => '用户提示词',
                'component' => 'prompt-editor',
                'preview' => false,
            ],
            [
                'name' => 'temperature',
                'label' => '温度',
                'component' => 'number',
                'defaultValue' => 0.7,
                'componentProps' => [
                    'min' => 0,
                    'max' => 2,
                    'step' => 0.1,
                ],
            ],
            [
                'name' => 'output_mode',
                'label' => '输出模式',
                'component' => 'select',
                'defaultValue' => 'auto',
                'options' => [
                    ['label' => '自动', 'value' => 'auto'],
                    ['label' => '结构化', 'value' => 'structured'],
                    ['label' => '文本', 'value' => 'text'],
                ],
            ],
            [
                'name' => 'structured_schema',
                'label' => '结构化 Schema',
                'component' => 'schema-tree',
                'componentProps' => [
                    'modeField' => 'output_mode',
                    'disabledMode' => 'text',
                    'disabledText' => '文本模式可用字段为 content',
                ],
                'preview' => false,
            ],
        ];

        $event->register($code, [
            'label' => '大模型',
            'name' => '大模型',
            'description' => '调用大模型处理输入内容',
            'category' => 'ai',
            'nodeType' => 'process',
            'track_token' => true,
            'icon' => 'i-tabler:brain',
            'color' => 'primary',
            'style' => ['iconBgClass' => 'bg-primary'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow', 'agent']);
        $event->output($code, $outputFields);
        $event->schema($code, [
            'type' => 'object',
            'description' => '入参字段：text（文本）、image_url/images（图片链接）、file_url/files（文件链接）、audio_url、video_url',
            'properties' => [
                'text' => ['type' => 'string', 'description' => '文本内容', 'default' => '{{input}}'],
                'image_url' => ['type' => 'string', 'description' => '单张图片链接'],
                'images' => ['type' => 'array', 'description' => '图片链接数组'],
                'file_url' => ['type' => 'string', 'description' => '单个文件链接'],
                'files' => ['type' => 'array', 'description' => '文件链接数组'],
                'audio_url' => ['type' => 'string', 'description' => '音频链接'],
                'video_url' => ['type' => 'string', 'description' => '视频链接'],
                'output_mode' => ['type' => 'string', 'enum' => ['text', 'structured', 'auto']],
                'structured_schema' => ['type' => 'array'],
            ],
        ]);
        $event->handler($code, static function (array $input, CapabilityContextInterface $context): array {
            // 补全多模型配置
            $input = $input + ['models' => []];
            $rawModels = $input['models'];
            $modelIds = [];
            if (is_array($rawModels)) {
                foreach ($rawModels as $item) {
                    if (is_numeric($item)) {
                        $value = (int)$item;
                        if ($value > 0) {
                            $modelIds[] = $value;
                        }
                    }
                }
            } elseif (is_numeric($rawModels)) {
                $value = (int)$rawModels;
                if ($value > 0) {
                    $modelIds[] = $value;
                }
            }
            $modelIds = array_values(array_unique($modelIds));
            if ($modelIds === []) {
                throw new ExceptionBusiness('大模型节点必须选择模型');
            }

            // 根据轮询策略选中具体模型
            $selectedModelId = self::selectModelId($modelIds, $context, $input);
            /** @var AiModel|null $model */
            $model = AiModel::query()->where('id', $selectedModelId)->first();
            if (!$model) {
                throw new ExceptionBusiness(sprintf('模型 [%d] 不存在', $selectedModelId));
            }

            $runtime = [];
            if ($context instanceof WorkflowToolContext) {
                $runtime = $context->state();
            }

            $systemPrompt = trim((string)($input['systemPrompt'] ?? ''));
            $userPrompt = trim((string)($input['userPrompt'] ?? ''));
            if ($userPrompt === '') {
                $last = $runtime['last'] ?? null;
                if (is_string($last)) {
                    $userPrompt = $last;
                } elseif ($last !== null) {
                    $encoded = json_encode($last, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $userPrompt = $encoded === false ? '' : $encoded;
                }
            }

            $systemPrompt = StateTemplate::render($systemPrompt, $runtime);
            $userPrompt = StateTemplate::render($userPrompt, $runtime);

            $temperature = null;
            if (array_key_exists('temperature', $input) && $input['temperature'] !== null && $input['temperature'] !== '') {
                $temperature = (float)$input['temperature'];
            }

            $timeoutMs = (int)($input['timeout_ms'] ?? 0);
            $timeoutSeconds = $timeoutMs > 0 ? (int)ceil($timeoutMs / 1000) : null;
            $overrides = [];
            if ($temperature !== null) {
                $overrides['temperature'] = $temperature;
            }
            $structuredSchema = is_array($input['structured_schema'] ?? null) ? ($input['structured_schema'] ?? []) : [];
            $outputMode = (string)($input['output_mode'] ?? 'auto');

            $run = StructuredOutputService::run(
                model: $model,
                prompt: $userPrompt,
                systemPrompt: $systemPrompt !== '' ? $systemPrompt : null,
                outputMode: $outputMode,
                structuredSchema: $structuredSchema,
                providerOverrides: $overrides,
                timeoutSeconds: $timeoutSeconds,
            );
            $content = is_string($run['content'] ?? null) ? ($run['content'] ?? '') : '';
            $usage = is_array($run['usage'] ?? null) ? ($run['usage'] ?? []) : null;
            $modeUsed = (string)($run['mode_used'] ?? 'text');
            $value = $modeUsed === 'structured' && is_array($run['data'] ?? null) ? ($run['data'] ?? []) : null;

            return [
                'status' => 1,
                'content' => $content,
                'data' => [
                    'content' => $content,
                    'value' => $value,
                    'mode_used' => $modeUsed,
                ],
                'usage' => $usage,
                'input' => [
                    'provider' => $model->provider?->code,
                    'model_id' => $model->id,
                    'model_code' => $model->code,
                    'model_pool' => $modelIds,
                    'systemPrompt' => $systemPrompt,
                    'userPrompt' => $userPrompt,
                    'temperature' => $temperature,
                    'output_mode' => $outputMode,
                ],
                'output' => [
                    'content' => $content,
                    'value' => $value,
                    'mode_used' => $modeUsed,
                ],
                'meta' => [
                    'mode_used' => $modeUsed,
                    'structured_errors' => is_array($run['errors'] ?? null) ? ($run['errors'] ?? []) : [],
                ],
            ];
        });
    }

    /**
     * @param array<int, int> $modelIds
     */
    /**
     * @param array<string, mixed> $input
     */
    private static function selectModelId(array $modelIds, CapabilityContextInterface $context, array $input): int
    {
        $first = $modelIds[0];
        // 加载模型列表用于额度计算
        $models = AiModel::query()
            ->whereIn('id', $modelIds)
            ->get()
            ->keyBy('id');
        if ($models->isEmpty()) {
            return $first;
        }

        // 优先选择启用模型
        $activeIds = $models->filter(static fn (AiModel $model) => (bool)$model->active)->keys()->all();
        $candidateIds = $activeIds !== [] ? $activeIds : $models->keys()->all();
        $candidateIds = array_values(array_intersect($modelIds, $candidateIds));
        if ($candidateIds === []) {
            return $first;
        }

        $now = new Carbon();
        // 保留额度比例，避免某模型被打穿
        $reservePercent = $input['reserve_percent'] ?? 0;
        $reservePercent = is_numeric($reservePercent) ? (float)$reservePercent : 0;
        if ($reservePercent < 0) {
            $reservePercent = 0;
        } elseif ($reservePercent > 50) {
            $reservePercent = 50;
        }

        $remainingMap = [];
        $ratioMap = [];
        foreach ($candidateIds as $id) {
            $model = $models->get($id);
            if (!$model instanceof AiModel) {
                continue;
            }
            $remaining = $model->quotaRemaining($now);
            $quotaTokens = (int)($model->quota_tokens ?? 0);
            $reserve = 0;
            if ($reservePercent > 0 && $quotaTokens > 0) {
                $reserve = (int)ceil($quotaTokens * ($reservePercent / 100));
            }
            if ($remaining === null) {
                $remainingMap[$id] = PHP_INT_MAX;
                $ratioMap[$id] = 1.0;
                continue;
            }
            $effectiveRemaining = $remaining - $reserve;
            if ($effectiveRemaining < 0) {
                $effectiveRemaining = 0;
            }
            $remainingMap[$id] = $effectiveRemaining;
            if ($quotaTokens > 0) {
                $ratioMap[$id] = $effectiveRemaining / $quotaTokens;
            } else {
                $ratioMap[$id] = 0.0;
            }
        }

        if ($remainingMap === []) {
            return $first;
        }

        $strategy = (string)($input['balance'] ?? 'quota_remaining');
        $selectedIds = $candidateIds;

        // 顺序轮询不需要额度计算
        if ($strategy === 'round_robin') {
            return self::pickByRoundRobin($selectedIds, $context);
        }

        if ($strategy === 'quota_ratio') {
            $maxRatio = max($ratioMap);
            $selectedIds = array_keys(array_filter($ratioMap, static fn ($value) => $value === $maxRatio));
        } else {
            $maxRemaining = max($remainingMap);
            $selectedIds = array_keys(array_filter($remainingMap, static fn ($value) => $value === $maxRemaining));
        }

        // 无并列时直接返回
        if (count($selectedIds) === 1) {
            return $selectedIds[0];
        }

        // 并列时走顺序轮询
        return self::pickByRoundRobin($selectedIds, $context);
    }

    /**
     * @param array<int, int> $modelIds
     */
    private static function pickByRoundRobin(array $modelIds, CapabilityContextInterface $context): int
    {
        $first = $modelIds[0];
        if (!$context instanceof WorkflowToolContext) {
            return $first;
        }

        $nodeId = $context->nodeId();
        $flowId = $context->flowId();
        if ($nodeId === '' || $flowId <= 0) {
            return $first;
        }

        // 读取节点上一次使用的模型，用于轮询
        /** @var AiFlowModel|null $node */
        $node = AiFlowModel::query()
            ->where('flow_id', $flowId)
            ->where('node_id', $nodeId)
            ->first();
        if (!$node) {
            return $first;
        }

        $lastModelId = (int)($node->last_model_id ?? 0);
        if ($lastModelId <= 0) {
            return $first;
        }

        $index = array_search($lastModelId, $modelIds, true);
        if ($index === false) {
            return $first;
        }

        $nextIndex = $index + 1;
        if (!isset($modelIds[$nextIndex])) {
            return $first;
        }

        return $modelIds[$nextIndex];
    }
}
