<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow;

use App\Ai\Models\AiFlow as AiFlowModel;
use App\Ai\Models\AiFlowLog;
use App\Ai\Models\AiFlowModel as AiFlowModelStat;
use App\Ai\Models\AiModel;
use App\Ai\Support\AiRuntime;
use Carbon\Carbon;

final class FlowExecutionLogger
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $flow
     * @param array<int, mixed> $logs
     * @param array<int, array{role:string,content:mixed}> $messages
     */
    public static function persist(
        AiFlowModel $flowModel,
        string $workflowId,
        array $input,
        array $flow,
        array $logs,
        array $messages,
        int $status,
        ?string $message,
        float $duration
    ): void {
        [$promptTokens, $completionTokens, $totalTokens] = self::sumUsageFromFlow($flow);

        $payload = [
            'flow_id' => $flowModel->id,
            'flow_code' => $flowModel->code,
            'workflow_id' => $workflowId !== '' ? $workflowId : null,
            'status' => $status,
            'message' => $message,
            'input' => $input,
            'output' => self::normalizeOutputForLog($flow['output'] ?? null),
            'logs' => $logs,
            'context' => [
                'flow' => self::compactFlowForLog($flow),
                'messages' => $messages,
            ],
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'duration' => $duration,
        ];

        if ($workflowId === '') {
            AiFlowLog::query()->create($payload);
        } else {
            AiFlowLog::query()->updateOrCreate([
                'workflow_id' => $workflowId,
            ], $payload);
        }

        if ($totalTokens > 0) {
            $db = AiRuntime::instance()->db()->getConnection();
            AiFlowModel::query()
                ->where('id', $flowModel->id)
                ->update([
                    'prompt_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(prompt_tokens,0) + %d)', $promptTokens)),
                    'completion_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(completion_tokens,0) + %d)', $completionTokens)),
                    'total_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(total_tokens,0) + %d)', $totalTokens)),
                ]);
        }

        // 记录节点负载与模型用量
        self::persistNodeStats($flowModel, $flow, $logs);
    }

    private static function normalizeOutputForLog(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return [];
        }
        return ['value' => $value];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function sumUsageFromFlow(array $flow): array
    {
        $nodes = $flow['nodes'] ?? [];
        if (!is_array($nodes) || $nodes === []) {
            return [0, 0, 0];
        }

        $prompt = 0;
        $completion = 0;
        $total = 0;

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $meta = $node['meta'] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            if (($meta['track_token'] ?? false) !== true) {
                continue;
            }
            $usage = $meta['usage'] ?? null;
            if (!is_array($usage)) {
                continue;
            }
            $prompt += (int)($usage['prompt_tokens'] ?? 0);
            $completion += (int)($usage['completion_tokens'] ?? 0);
            $nodeTotal = $usage['total_tokens'] ?? null;
            $total += is_numeric($nodeTotal) ? (int)$nodeTotal : ((int)($usage['prompt_tokens'] ?? 0) + (int)($usage['completion_tokens'] ?? 0));
        }

        return [$prompt, $completion, $total];
    }

    private static function compactFlowForLog(array $flow): array
    {
        $nodes = is_array($flow['nodes'] ?? null) ? ($flow['nodes'] ?? []) : [];
        $compactNodes = [];
        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                continue;
            }
            $compactNodes[$id] = [
                'status' => $node['status'] ?? null,
                'message' => $node['message'] ?? null,
                'meta' => is_array($node['meta'] ?? null) ? array_filter([
                    'usage' => $node['meta']['usage'] ?? null,
                ], static fn ($v) => $v !== null) : [],
            ];
        }

        return [
            'env' => $flow['env'] ?? [],
            'input' => self::compactValueForLog($flow['input'] ?? null),
            'last' => self::compactValueForLog($flow['last'] ?? null),
            'output' => self::compactValueForLog($flow['output'] ?? null),
            'nodes' => $compactNodes,
        ];
    }

    public static function compactValueForLog(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value) || is_object($value)) {
            return $value;
        }
        return ['_type' => gettype($value)];
    }

    /**
     * @param array<string, mixed> $flow
     * @param array<int, mixed> $logs
     */
    private static function persistNodeStats(AiFlowModel $flowModel, array $flow, array $logs): void
    {
        $nodes = $flow['nodes'] ?? [];
        if (!is_array($nodes) || $nodes === []) {
            return;
        }

        $logMeta = [];
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $meta = $log['meta'] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            $nodeId = trim((string)($meta['node'] ?? $meta['node_id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $logMeta[$nodeId] = $meta;
        }

        $db = AiRuntime::instance()->db()->getConnection();
        $now = Carbon::now();
        $nodeIds = array_values(array_filter(array_map(
            static fn ($id): string => trim((string)$id),
            array_keys($nodes)
        ), static fn (string $id): bool => $id !== ''));
        $existing = AiFlowModelStat::query()
            ->where('flow_id', $flowModel->id)
            ->whereIn('node_id', $nodeIds)
            ->get()
            ->keyBy('node_id');

        foreach ($nodes as $nodeId => $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeId = trim((string)$nodeId);
            if ($nodeId === '') {
                continue;
            }

            $meta = $logMeta[$nodeId] ?? [];
            $nodeType = (string)($meta['type'] ?? '');
            $nodeName = (string)($meta['label'] ?? ($meta['node_label'] ?? ''));
            if ($nodeType === '') {
                $nodeType = (string)($node['type'] ?? '');
            }

            $nodeStatus = (int)($node['status'] ?? 0);
            $nodeMessage = (string)($node['message'] ?? '');

            $nodeMeta = is_array($node['meta'] ?? null) ? ($node['meta'] ?? []) : [];
            if (($nodeMeta['track_token'] ?? false) !== true) {
                continue;
            }
            $usage = is_array($nodeMeta['usage'] ?? null) ? ($nodeMeta['usage'] ?? []) : [];
            $usage = $usage + ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => null];
            $promptTokens = (int)$usage['prompt_tokens'];
            $completionTokens = (int)$usage['completion_tokens'];
            $totalTokens = $usage['total_tokens'];
            if (!is_numeric($totalTokens)) {
                $totalTokens = $promptTokens + $completionTokens;
            }

            $output = $node['output'] ?? null;
            $output = is_array($output) ? ($output + ['model_id' => 0]) : ['model_id' => 0];
            $lastModelId = is_numeric($output['model_id']) ? (int)$output['model_id'] : 0;

            $successInc = $nodeStatus === 1 ? 1 : 0;
            $failInc = $nodeStatus === 0 ? 1 : 0;
            /** @var AiFlowModelStat|null $record */
            $record = $existing->get($nodeId);
            if (!$record) {
                AiFlowModelStat::query()->create([
                    'flow_id' => $flowModel->id,
                    'node_id' => $nodeId,
                    'node_type' => $nodeType !== '' ? $nodeType : null,
                    'node_name' => $nodeName !== '' ? $nodeName : null,
                    'last_status' => $nodeStatus,
                    'last_message' => $nodeMessage !== '' ? $nodeMessage : null,
                    'last_model_id' => $lastModelId > 0 ? $lastModelId : null,
                    'last_used_at' => $now,
                    'call_count' => 1,
                    'success_count' => $successInc,
                    'fail_count' => $failInc,
                    'prompt_tokens' => (int)$promptTokens,
                    'completion_tokens' => (int)$completionTokens,
                    'total_tokens' => (int)$totalTokens,
                ]);
            } else {
                AiFlowModelStat::query()
                    ->where('id', $record->id)
                    ->update([
                        'node_type' => $nodeType !== '' ? $nodeType : $record->node_type,
                        'node_name' => $nodeName !== '' ? $nodeName : $record->node_name,
                        'last_status' => $nodeStatus,
                        'last_message' => $nodeMessage !== '' ? $nodeMessage : null,
                        'last_model_id' => $lastModelId > 0 ? $lastModelId : null,
                        'last_used_at' => $now,
                        'call_count' => $db->raw(sprintf('GREATEST(0, COALESCE(call_count,0) + %d)', 1)),
                        'success_count' => $db->raw(sprintf('GREATEST(0, COALESCE(success_count,0) + %d)', $successInc)),
                        'fail_count' => $db->raw(sprintf('GREATEST(0, COALESCE(fail_count,0) + %d)', $failInc)),
                        'prompt_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(prompt_tokens,0) + %d)', (int)$promptTokens)),
                        'completion_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(completion_tokens,0) + %d)', (int)$completionTokens)),
                        'total_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(total_tokens,0) + %d)', (int)$totalTokens)),
                    ]);
            }

            if ($lastModelId > 0 && $totalTokens > 0) {
                AiModel::recordUsage($lastModelId, $promptTokens, $completionTokens, (int)$totalTokens);
            }
        }
    }
}
