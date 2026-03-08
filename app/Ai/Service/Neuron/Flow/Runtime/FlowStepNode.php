<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Runtime;

use App\Ai\Service\Capability;
use App\Ai\Service\FlowPersistence\FlowResumeService;
use App\Ai\Service\Neuron\Flow\FlowErrorFormatter;
use App\Ai\Service\Neuron\Flow\FlowSchemaPayloadBuilder;
use App\Ai\Service\Neuron\Flow\StateTemplate;
use App\Ai\Service\Neuron\Flow\Interrupt\AsyncWaitInterruptRequest;
use App\Ai\Service\Neuron\Flow\Interrupt\AsyncWaitResumeRequest;
use App\Ai\Service\Neuron\Flow\WorkflowToolContext;
use App\Ai\Service\Usage\UsageResolver;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

final class FlowStepNode extends Node
{
    public function __invoke(FlowStepEvent $event, WorkflowState $state): FlowStepEvent|StopEvent
    {
        $runtime = $state->get('flow_runtime', []);
        if (!is_array($runtime)) {
            throw new ExceptionBusiness('流程运行时状态无效');
        }
        $runtime['workflow_id'] = (string)$state->get('__workflowId', $runtime['workflow_id'] ?? '');

        $orderedNodes = is_array($runtime['ordered_nodes'] ?? null) ? ($runtime['ordered_nodes'] ?? []) : [];
        $index = (int)($runtime['index'] ?? 0);

        $this->applyResumeCompletionIfNeeded($state, $runtime, $orderedNodes, $index);

        if (!isset($orderedNodes[$index]) || !is_array($orderedNodes[$index])) {
            return $this->stop($state, $runtime);
        }

        $node = $orderedNodes[$index];
        $nodeId = (string)($node['id'] ?? '');
        $nodeType = (string)($node['type'] ?? '');
        $nodeLabel = (string)($node['name'] ?? $nodeId);
        $config = is_array($node['config'] ?? null) ? ($node['config'] ?? []) : [];

        $flow = is_array($runtime['flow'] ?? null) ? ($runtime['flow'] ?? []) : [];
        $env = is_array($runtime['env'] ?? null) ? ($runtime['env'] ?? []) : [];
        $nodeInput = $flow['last'] ?? ($flow['input'] ?? null);

        $maxAttempts = max(1, (int)$state->get('__flow_retry_max_attempts', 1));
        $timeoutMs = (int)$state->get('__flow_timeout_ms', 0);

        $execution = $this->executeNodeWithRetry(
            flowId: (int)($runtime['flow_id'] ?? 0),
            node: $node,
            config: $config,
            flow: $flow,
            env: $env,
            nodeInput: $nodeInput,
            timeoutMs: $timeoutMs,
            maxAttempts: $maxAttempts,
            options: is_array($runtime['options'] ?? null) ? ($runtime['options'] ?? []) : [],
        );

        $resultStatus = (int)($execution['status'] ?? 0);
        $resultMessage = (string)($execution['message'] ?? ($resultStatus === 1 ? 'ok' : '节点执行异常'));
        $nodeOutput = $execution['output'] ?? null;
        $nodeMeta = is_array($execution['meta'] ?? null) ? ($execution['meta'] ?? []) : [];

        $flow['nodes'][$nodeId] = [
            'status' => $resultStatus,
            'message' => $resultMessage,
            'input' => $nodeInput,
            'resolved_input' => $nodeMeta['resolved_input'] ?? null,
            'output' => $nodeOutput,
            'meta' => $nodeMeta,
        ];
        $flow['last'] = $nodeOutput ?? $nodeInput;
        if ($nodeType === 'ai_end' && $resultStatus === 1) {
            $flow['output'] = $nodeOutput ?? $flow['last'];
        }

        $runtime['flow'] = $flow;
        $runtime['index'] = $index + 1;
        $this->applyRuntimeStatus($runtime, $resultStatus, $resultMessage, $nodeType);

        $runtime['logs'][] = $this->buildNodeLog(
            nodeId: $nodeId,
            nodeLabel: $nodeLabel,
            nodeType: $nodeType,
            nodeInput: $nodeInput,
            nodeOutput: $nodeOutput,
            nodeMeta: $nodeMeta,
            resultStatus: $resultStatus,
            resultMessage: $resultMessage,
            maxAttempts: $maxAttempts,
        );

        $state->set('__flow_last_usage', is_array($nodeMeta['usage'] ?? null) ? ($nodeMeta['usage'] ?? []) : null);
        $state->set('__flow_last_track_token', (bool)($nodeMeta['track_token'] ?? false));

        $this->emitOnNode($runtime, $nodeId, $nodeLabel, $nodeType, $resultStatus, $resultMessage, $nodeInput, $nodeOutput);

        if ($resultStatus === 2) {
            $runtimeForPersist = $this->withoutOnNode($runtime);
            $state->set('flow_runtime', $runtimeForPersist);

            $suspendMeta = is_array($nodeMeta['suspend'] ?? null) ? ($nodeMeta['suspend'] ?? []) : [];
            $scheduled = FlowResumeService::suspendAndSchedule($runtime, $suspendMeta);
            $request = new AsyncWaitInterruptRequest('流程挂起等待异步任务完成', [
                ...$suspendMeta,
                ...$scheduled,
                'node_id' => $nodeId,
            ]);

            // 首次执行会在这里抛出 WorkflowInterrupt，后续恢复后由节点入口统一回填恢复结果。
            $this->interrupt($request);
        } else {
            $state->set('flow_runtime', $runtime);
        }

        if ($resultStatus === 0 || $nodeType === 'ai_end') {
            return $this->stop($state, $runtime);
        }

        return new FlowStepEvent([
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'status' => $resultStatus,
        ]);
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function stop(WorkflowState $state, array $runtime): StopEvent
    {
        $flow = is_array($runtime['flow'] ?? null) ? ($runtime['flow'] ?? []) : [];
        $status = (int)($runtime['status'] ?? 1);
        $message = $runtime['message'] ?? null;

        if (!array_key_exists('output', $flow) || $flow['output'] === null) {
            $status = 0;
            $message = $message ?: '流程未执行到结束节点 ai_end';
            $flow['output'] = $flow['last'] ?? null;
        }

        $result = [
            'status' => $status,
            'message' => $message ?: ($status === 1 ? '流程执行完成' : '流程执行异常'),
            'data' => $flow['output'] ?? null,
            'messages' => is_array($runtime['messages'] ?? null) ? ($runtime['messages'] ?? []) : [],
            'logs' => is_array($runtime['logs'] ?? null) ? ($runtime['logs'] ?? []) : [],
        ];

        $runtime['flow'] = $flow;
        $runtime['status'] = $status;
        $runtime['message'] = $result['message'];
        $runtimeForPersist = $this->withoutOnNode($runtime);
        $state->set('flow_runtime', $runtimeForPersist);
        $state->set('result', $result);

        return new StopEvent($result);
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<int, array<string, mixed>> $orderedNodes
     */
    private function applyResumeCompletionIfNeeded(WorkflowState $state, array &$runtime, array $orderedNodes, int $index): void
    {
        $resumePayload = $this->extractResumePayload($this->getResumeRequest());
        $resumeStatus = trim((string)($resumePayload['resume_status'] ?? ''));
        if ($resumeStatus !== 'completed') {
            return;
        }

        $prevIndex = $index - 1;
        if ($prevIndex < 0 || !isset($orderedNodes[$prevIndex]) || !is_array($orderedNodes[$prevIndex])) {
            return;
        }

        $prevNodeId = trim((string)($orderedNodes[$prevIndex]['id'] ?? ''));
        if ($prevNodeId === '') {
            return;
        }

        $flow = is_array($runtime['flow'] ?? null) ? ($runtime['flow'] ?? []) : [];
        $nodes = is_array($flow['nodes'] ?? null) ? ($flow['nodes'] ?? []) : [];
        $prevNodeState = is_array($nodes[$prevNodeId] ?? null) ? ($nodes[$prevNodeId] ?? []) : [];
        if ((int)($prevNodeState['status'] ?? 0) !== 2) {
            return;
        }

        $resumeOutput = $resumePayload['resume_node_output'] ?? null;
        $resumeMessage = trim((string)($resumePayload['resume_node_message'] ?? '')) ?: '异步任务已完成';
        $nodes[$prevNodeId] = [
            ...$prevNodeState,
            'status' => 1,
            'message' => $resumeMessage,
            'output' => $resumeOutput,
        ];

        $flow['nodes'] = $nodes;
        $flow['last'] = $resumeOutput ?? ($flow['last'] ?? null);

        $logs = is_array($runtime['logs'] ?? null) ? ($runtime['logs'] ?? []) : [];
        for ($i = count($logs) - 1; $i >= 0; $i--) {
            $entry = is_array($logs[$i] ?? null) ? ($logs[$i] ?? []) : [];
            $entryMeta = is_array($entry['meta'] ?? null) ? ($entry['meta'] ?? []) : [];
            if ((string)($entryMeta['node'] ?? '') !== $prevNodeId) {
                continue;
            }
            $entryMeta['node_status'] = 1;
            $entryMeta['node_message'] = $resumeMessage;
            $entryMeta['final_output'] = $resumeOutput;
            $entry['status'] = 1;
            $entry['message'] = 'ok';
            $entry['data'] = $resumeOutput;
            $entry['meta'] = $entryMeta;
            $logs[$i] = $entry;
            break;
        }

        $runtime['logs'] = $logs;
        $runtime['flow'] = $flow;
        $runtime['status'] = 1;
        $runtime['message'] = 'ok';
        $state->set('flow_runtime', $this->withoutOnNode($runtime));
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function applyRuntimeStatus(array &$runtime, int $resultStatus, string $resultMessage, string $nodeType): void
    {
        if ($resultStatus === 0) {
            $runtime['status'] = 0;
            $runtime['message'] = $resultMessage;
            return;
        }
        if ($resultStatus === 2) {
            $runtime['status'] = 2;
            $runtime['message'] = $resultMessage !== '' ? $resultMessage : '流程已挂起等待异步任务';
            return;
        }
        if ($nodeType === 'ai_end') {
            // 结束节点成功即视为流程成功，避免挂起状态残留。
            $runtime['status'] = 1;
            $runtime['message'] = 'ok';
            return;
        }
        $runtime['status'] = (int)($runtime['status'] ?? 1);
    }

    /**
     * @param array<string, mixed> $nodeMeta
     * @return array<string, mixed>
     */
    private function buildNodeLog(
        string $nodeId,
        string $nodeLabel,
        string $nodeType,
        mixed $nodeInput,
        mixed $nodeOutput,
        array $nodeMeta,
        int $resultStatus,
        string $resultMessage,
        int $maxAttempts,
    ): array {
        $executedAtMs = (int)round(microtime(true) * 1000);
        $executedAt = date('Y-m-d H:i:s', (int)floor($executedAtMs / 1000));

        return [
            'created_at' => $executedAt,
            'created_at_ms' => $executedAtMs,
            'status' => $resultStatus === 0 ? 0 : 1,
            'message' => $resultStatus === 0 ? $resultMessage : 'ok',
            'data' => $nodeOutput,
            'meta' => [
                'node' => $nodeId,
                'label' => $nodeLabel,
                'type' => $nodeType,
                'input' => $nodeMeta['resolved_input'] ?? $nodeInput,
                'raw_input' => $nodeInput,
                'resolved_input' => $nodeMeta['resolved_input'] ?? null,
                'final_output' => $nodeOutput,
                'node_status' => $resultStatus,
                'node_message' => $resultMessage,
                'attempts' => is_numeric($nodeMeta['attempts_used'] ?? null) ? (int)$nodeMeta['attempts_used'] : $maxAttempts,
                'max_attempts' => $maxAttempts,
                'raw_output' => $nodeMeta['raw_output'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function emitOnNode(
        array $runtime,
        string $nodeId,
        string $nodeLabel,
        string $nodeType,
        int $resultStatus,
        string $resultMessage,
        mixed $nodeInput,
        mixed $nodeOutput,
    ): void {
        $onNode = $runtime['on_node'] ?? null;
        if (!is_callable($onNode)) {
            return;
        }

        $onNode(
            is_array($runtime['messages'] ?? null) ? ($runtime['messages'] ?? []) : [],
            is_array($runtime['logs'] ?? null) ? ($runtime['logs'] ?? []) : [],
            [
                'node_id' => $nodeId,
                'node_label' => $nodeLabel,
                'node' => $nodeLabel,
                'type' => $nodeType,
                'status' => $resultStatus,
                'message' => $resultMessage,
                'input' => $nodeInput,
                'output' => $nodeOutput,
            ],
        );
    }

    /**
     * @param array<string, mixed> $runtime
     * @return array<string, mixed>
     */
    private function withoutOnNode(array $runtime): array
    {
        unset($runtime['on_node']);
        return $runtime;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $config
     * @param array<string, mixed> $flow
     * @param array<string, mixed> $env
     * @param array<string, mixed> $options
     * @return array{status:int,message:string,output:mixed,meta?:array<string,mixed>}
     */
    private function executeNodeWithRetry(
        int $flowId,
        array $node,
        array $config,
        array $flow,
        array $env,
        mixed $nodeInput,
        int $timeoutMs,
        int $maxAttempts,
        array $options,
    ): array {
        $lastError = null;
        $payload = [
            'status' => 0,
            'message' => '节点执行异常',
            'output' => null,
        ];
        $delayMs = max(0, (int)($options['node_delay_ms'] ?? 0));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $payload = $this->executeNode(
                    flowId: $flowId,
                    node: $node,
                    config: $config,
                    flow: $flow,
                    env: $env,
                    nodeInput: $nodeInput,
                    timeoutMs: $timeoutMs,
                    options: $options,
                );
                if (in_array((int)($payload['status'] ?? 0), [1, 2], true)) {
                    $meta = is_array($payload['meta'] ?? null) ? ($payload['meta'] ?? []) : [];
                    $meta['attempts_used'] = $attempt;
                    $payload['meta'] = $meta;
                    return $payload;
                }
            } catch (Throwable $throwable) {
                $lastError = $throwable;
                $payload = [
                    'status' => 0,
                    'message' => FlowErrorFormatter::formatNodeThrowableMessage($throwable, $timeoutMs),
                    'output' => null,
                    'meta' => [
                        'raw_output' => ['error' => $throwable->getMessage()],
                        'final_output' => null,
                    ],
                ];
            }
        }

        $meta = is_array($payload['meta'] ?? null) ? ($payload['meta'] ?? []) : [];
        if ($lastError) {
            $meta['error'] = $lastError->getMessage();
        }
        $meta['attempts_used'] = $maxAttempts;
        $payload['meta'] = $meta;

        return $payload;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $config
     * @param array<string, mixed> $flow
     * @param array<string, mixed> $env
     * @param array<string, mixed> $options
     * @return array{status:int,message:string,output:mixed,meta?:array<string,mixed>}
     */
    private function executeNode(
        int $flowId,
        array $node,
        array $config,
        array $flow,
        array $env,
        mixed $nodeInput,
        int $timeoutMs,
        array $options,
    ): array {
        $nodeType = (string)($node['type'] ?? '');
        $runtimeState = [
            'input' => $flow['input'] ?? null,
            'env' => $flow['env'] ?? $env,
            'nodes' => $flow['nodes'] ?? [],
            'last' => $nodeInput,
            'output' => $flow['output'] ?? null,
        ];

        $resolvedConfig = StateTemplate::resolve($config, $runtimeState);
        $inputs = is_array($resolvedConfig) ? $resolvedConfig : [];
        if (isset($inputs['schema']) && is_array($inputs['schema'])) {
            $schemaPayload = FlowSchemaPayloadBuilder::build($inputs['schema'], $runtimeState);
            unset($inputs['schema']);
            if ($schemaPayload !== []) {
                $inputs = array_merge($inputs, $schemaPayload);
            }
        }
        if ($timeoutMs > 0) {
            $inputs['timeout_ms'] = $timeoutMs;
        }
        $inputs['debug'] = (bool)($env['debug'] ?? false);

        $context = new WorkflowToolContext($flowId, (string)($node['id'] ?? ''), $runtimeState);
        $capability = Capability::get($nodeType);
        $trackToken = (bool)($capability['track_token'] ?? false);
        try {
            $result = Capability::execute($nodeType, $inputs, $context);
        } catch (Throwable $throwable) {
            return [
                'status' => 0,
                'message' => FlowErrorFormatter::formatNodeThrowableMessage($throwable, $timeoutMs),
                'output' => null,
                'meta' => [
                    'track_token' => $trackToken,
                    'usage' => null,
                    'raw' => ['error' => $throwable->getMessage()],
                    'raw_output' => ['error' => $throwable->getMessage()],
                    'final_output' => null,
                    'resolved_input' => $inputs,
                ],
            ];
        }

        if (!is_array($result) || !array_key_exists('status', $result)) {
            return [
                'status' => 1,
                'message' => 'ok',
                'output' => $result,
                'meta' => [
                    'track_token' => $trackToken,
                    'usage' => null,
                    'raw_output' => $result,
                    'final_output' => $result,
                    'resolved_input' => $inputs,
                ],
            ];
        }

        $status = (int)($result['status'] ?? 0);
        $message = $status === 1
            ? 'ok'
            : ($status === 2
                ? (string)($result['message'] ?? '流程挂起等待异步任务完成')
                : (string)($result['message'] ?? $result['content'] ?? '节点执行异常'));
        $usageRaw = $result['usage'] ?? ($result['data']['usage'] ?? null);
        $usage = is_array($usageRaw) ? UsageResolver::normalizeUsage($usageRaw) : null;
        $meta = [
            'track_token' => $trackToken,
            'usage' => $usage,
            'raw_output' => $result,
            'final_output' => $result['data'] ?? null,
            'resolved_input' => $inputs,
        ];
        if ($status === 2) {
            $meta['suspend'] = is_array($result['meta']['suspend'] ?? null) ? ($result['meta']['suspend'] ?? []) : [];
        }

        return [
            'status' => in_array($status, [1, 2], true) ? $status : 0,
            'message' => $message,
            'output' => $result['data'] ?? null,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractResumePayload(mixed $resumeRequest): array
    {
        if ($resumeRequest instanceof AsyncWaitResumeRequest) {
            return $resumeRequest->payload();
        }
        if ($resumeRequest === null || !is_object($resumeRequest) || !method_exists($resumeRequest, 'jsonSerialize')) {
            return [];
        }
        $serialized = $resumeRequest->jsonSerialize();
        if (!is_array($serialized)) {
            return [];
        }
        $payload = [];
        $candidate = $serialized['payload'] ?? [];
        if (is_array($candidate)) {
            $payload = $candidate;
        }
        if ((string)($serialized['type'] ?? '') === 'async_wait_resume' && !isset($payload['resume_status'])) {
            $payload['resume_status'] = 'completed';
        }
        return $payload;
    }
}
