<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;

final class AsyncWaitCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $taskId = trim((string)($input['task_id'] ?? $input['id'] ?? ''));
        $statusUrl = trim((string)($input['status_url'] ?? ''));
        $provider = trim((string)($input['provider'] ?? ''));
        $pollInterval = max(1, (int)($input['poll_interval_minutes'] ?? 1));
        $timeoutMinutes = max(1, (int)($input['timeout_minutes'] ?? 30));

        // 节点语义：默认挂起，由调度器轮询成功后再恢复。
        return [
            'status' => 2,
            'message' => '异步任务未完成，流程已挂起',
            'data' => [
                'summary' => '异步任务等待中',
                'task_id' => $taskId,
                'provider' => $provider,
                'poll_interval_minutes' => $pollInterval,
                'timeout_minutes' => $timeoutMinutes,
                'status_url' => $statusUrl,
            ],
            'meta' => [
                'suspend' => [
                    'task_id' => $taskId,
                    'provider' => $provider,
                    'status_url' => $statusUrl,
                    'status_method' => strtoupper(trim((string)($input['status_method'] ?? 'GET'))),
                    'status_headers' => is_array($input['status_headers'] ?? null) ? ($input['status_headers'] ?? []) : [],
                    'status_query' => is_array($input['status_query'] ?? null) ? ($input['status_query'] ?? []) : [],
                    'status_body' => is_array($input['status_body'] ?? null) ? ($input['status_body'] ?? []) : [],
                    'response_path' => (string)($input['response_path'] ?? 'data.status'),
                    'completed_values' => is_array($input['completed_values'] ?? null) ? ($input['completed_values'] ?? []) : ['succeeded', 'completed', 'success'],
                    'failed_values' => is_array($input['failed_values'] ?? null) ? ($input['failed_values'] ?? []) : ['failed', 'error', 'canceled'],
                    'poll_interval_minutes' => $pollInterval,
                    'timeout_minutes' => $timeoutMinutes,
                ],
            ],
        ];
    }
}
