<?php

declare(strict_types=1);

namespace App\Ai\Service\FlowPersistence;

use App\Ai\Service\AIFlow;
use App\Ai\Service\Scheduler\AiSchedulerService;
use App\Ai\Service\Neuron\Flow\Interrupt\AsyncWaitResumeRequest;
use Carbon\Carbon;

final class FlowResumeService
{
    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $suspendMeta
     */
    public static function suspendAndSchedule(array $runtime, array $suspendMeta): array
    {
        $workflowId = trim((string)($runtime['workflow_id'] ?? ''));
        if ($workflowId === '') {
            return [];
        }

        $flowCode = (string)($runtime['flow_code'] ?? '');
        $flowInput = is_array($runtime['input'] ?? null) ? ($runtime['input'] ?? []) : [];
        $currentIndex = (int)($runtime['index'] ?? 0);
        $orderedNodes = is_array($runtime['ordered_nodes'] ?? null) ? ($runtime['ordered_nodes'] ?? []) : [];
        $currentNode = isset($orderedNodes[$currentIndex]) && is_array($orderedNodes[$currentIndex]) ? $orderedNodes[$currentIndex] : [];

        $taskId = trim((string)($suspendMeta['task_id'] ?? ''));
        $pollSeconds = max(1, (int)($suspendMeta['poll_interval_seconds'] ?? ((int)($suspendMeta['poll_interval_minutes'] ?? 1) * 60)));
        $timeoutMinutes = max(1, (int)($suspendMeta['timeout_minutes'] ?? 30));
        $scheduledAt = Carbon::now()->addSeconds($pollSeconds);

        $job = AiSchedulerService::createJob([
            'callback_type' => 'flow',
            'callback_code' => 'resume_poll',
            'callback_name' => '流程恢复轮询',
            'callback_action' => 'poll',
            'workflow_id' => $workflowId,
            'dedupe_key' => sprintf('flow:resume_poll:%s:%s', $workflowId, (string)($currentNode['id'] ?? 'node')),
            'status' => 'pending',
            'execute_at' => $scheduledAt,
            'max_attempts' => 3,
            'callback_params' => [
                'workflow_id' => $workflowId,
                'flow_code' => $flowCode,
                'flow_input' => $flowInput,
                'node_id' => (string)($currentNode['id'] ?? ''),
                'task_id' => $taskId,
                'capability' => (string)($suspendMeta['capability'] ?? ''),
                'provider' => (string)($suspendMeta['provider'] ?? ''),
                'status_url' => (string)($suspendMeta['status_url'] ?? ''),
                'status_base_url' => (string)($suspendMeta['status_base_url'] ?? ''),
                'status_method' => (string)($suspendMeta['status_method'] ?? 'GET'),
                'status_headers' => is_array($suspendMeta['status_headers'] ?? null) ? ($suspendMeta['status_headers'] ?? []) : [],
                'status_query' => is_array($suspendMeta['status_query'] ?? null) ? ($suspendMeta['status_query'] ?? []) : [],
                'status_body' => is_array($suspendMeta['status_body'] ?? null) ? ($suspendMeta['status_body'] ?? []) : [],
                'response_path' => (string)($suspendMeta['response_path'] ?? 'data.status'),
                'completed_values' => is_array($suspendMeta['completed_values'] ?? null) ? ($suspendMeta['completed_values'] ?? []) : ['succeeded', 'completed', 'success'],
                'failed_values' => is_array($suspendMeta['failed_values'] ?? null) ? ($suspendMeta['failed_values'] ?? []) : ['failed', 'error', 'canceled'],
                'poll_interval_seconds' => $pollSeconds,
                'timeout_minutes' => $timeoutMinutes,
                'suspended_at' => Carbon::now()->toDateTimeString(),
            ],
            'source_type' => 'flow',
            'source_id' => null,
        ]);

        return [
            'schedule_id' => (int)$job->id,
            'workflow_id' => $workflowId,
            'task_id' => $taskId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function resumeByWorkflowId(array $payload): array
    {
        $workflowId = trim((string)($payload['workflow_id'] ?? ''));
        $flowCode = trim((string)($payload['flow_code'] ?? ''));
        if ($workflowId === '' || $flowCode === '') {
            throw new \Core\Handlers\ExceptionBusiness('恢复流程缺少 workflow_id 或 flow_code');
        }

        $flowInput = is_array($payload['flow_input'] ?? null) ? ($payload['flow_input'] ?? []) : [];

        $result = AIFlow::execute($flowCode, $flowInput, [
            'workflow_id' => $workflowId,
            'resume_request' => new AsyncWaitResumeRequest('resume-by-scheduler', [
                'schedule_id' => (int)($payload['schedule_id'] ?? 0),
                'task_id' => (string)($payload['task_id'] ?? ''),
                'resume_status' => (string)($payload['resume_status'] ?? ''),
                'resume_node_output' => $payload['resume_node_output'] ?? null,
                'resume_node_message' => (string)($payload['resume_node_message'] ?? ''),
                'provider_status' => (string)($payload['provider_status'] ?? ''),
                'raw_output' => is_array($payload['raw_output'] ?? null) ? ($payload['raw_output'] ?? []) : null,
            ]),
        ]);

        return $result;
    }
}
