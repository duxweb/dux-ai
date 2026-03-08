<?php

declare(strict_types=1);

namespace App\Ai\Service\AIFlow;

use App\Ai\Models\AiFlow as AiFlowModel;
use App\Ai\Service\Agent\Sse as AgentSse;
use App\Ai\Service\Capability;
use App\Ai\Service\FlowPersistence\FlowPersistenceStore;
use App\Ai\Service\Neuron\Flow\Runtime\FlowObserver;
use App\Ai\Service\Neuron\Flow\Runtime\FlowRetryMiddleware;
use App\Ai\Service\Neuron\Flow\Runtime\FlowStartNode;
use App\Ai\Service\Neuron\Flow\Runtime\FlowStepNode;
use App\Ai\Service\Neuron\Flow\Runtime\FlowTelemetryMiddleware;
use App\Ai\Service\Neuron\Flow\Runtime\FlowTimeoutMiddleware;
use Core\Handlers\ExceptionBusiness;
use Fiber;
use Godruoyi\Snowflake\Snowflake;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

final class Service
{
    private bool $booted = false;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $editorNodes = [];

    public function execute(string|AiFlowModel $flow, array $input = [], array $options = [], ?callable $onNode = null): array
    {
        $flowModel = $this->resolveFlow($flow);
        [$orderedNodes, $env] = $this->resolveOrderedNodesAndEnv($flowModel);
        $workflowId = trim((string)($options['workflow_id'] ?? ''));
        if ($workflowId === '') {
            $workflowId = $this->generateWorkflowId();
        }

        $state = new WorkflowState();
        $workflow = Workflow::make(
            persistence: FlowPersistenceStore::make(),
            resumeToken: $workflowId,
            state: $state,
        )
            ->observe(new FlowObserver($flowModel))
            ->addGlobalMiddleware(new FlowRetryMiddleware())
            ->addGlobalMiddleware(new FlowTimeoutMiddleware())
            ->addGlobalMiddleware(new FlowTelemetryMiddleware());

        $workflow->addNode(new FlowStartNode(
            flowModel: $flowModel,
            orderedNodes: $orderedNodes,
            env: $env,
            input: $input,
            options: $options,
            onNode: $onNode,
        ));
        $workflow->addNode(new FlowStepNode());

        $resumeRequest = $options['resume_request'] ?? null;
        $resumeRequest = $resumeRequest instanceof InterruptRequest ? $resumeRequest : null;

        try {
            $resultState = $workflow->init($resumeRequest)->run();
            $result = $resultState->get('result');
            if (is_array($result)) {
                return $result;
            }
        } catch (WorkflowInterrupt $interrupt) {
            $runtime = $interrupt->getState()->get('flow_runtime', []);
            $runtime = is_array($runtime) ? $runtime : [];
            $logs = is_array($runtime['logs'] ?? null) ? ($runtime['logs'] ?? []) : [];
            $messages = is_array($runtime['messages'] ?? null) ? ($runtime['messages'] ?? []) : [];

            return [
                'status' => 2,
                'message' => '流程已挂起，等待异步任务完成',
                'data' => [
                    'workflow_id' => $workflowId,
                    'resume_token' => $interrupt->getResumeToken(),
                ],
                'messages' => $messages,
                'logs' => $logs,
            ];
        }

        throw new ExceptionBusiness('流程执行失败：未返回结果');
    }

    /**
     * @return array{status: int, message: string, data: mixed}
     */
    public function executeFinal(string|AiFlowModel $flow, array $input = [], array $options = []): array
    {
        $result = $this->execute($flow, $input, $options);
        $status = (int)($result['status'] ?? 0);

        return [
            'status' => $status,
            'message' => $status === 1 ? 'ok' : (string)($result['message'] ?? '流程执行异常'),
            'data' => $status === 1 ? ($result['data'] ?? null) : null,
        ];
    }

    /**
     * Each SSE message payload is: {status, message, data, meta}.
     *
     * @return \Generator<string>
     */
    public function stream(string|AiFlowModel $flow, array $input = [], array $options = []): \Generator
    {
        $flowModel = $this->resolveFlow($flow);
        $flowCode = (string)($flowModel->code ?? '');
        $workflowId = trim((string)($options['workflow_id'] ?? ''));
        if ($workflowId === '') {
            $workflowId = $this->generateWorkflowId();
        }
        $options = [
            ...$options,
            'workflow_id' => $workflowId,
        ];
        $keepalivePadding = (bool)($options['keepalive_padding'] ?? false);

        if ($keepalivePadding) {
            // 注释 padding：提高小包在代理/缓冲链路中的实时可见性。
            yield AgentSse::comment(str_repeat(' ', 4096));
        }

        yield $this->formatStreamChunk([
            'status' => 1,
            'message' => 'ok',
            'data' => (object)[],
                'meta' => [
                    'event' => 'start',
                    'flow' => $flowCode,
                    'workflow_id' => $workflowId,
                    'input' => $input,
                ],
            ]);

        try {
            $fiber = new Fiber(function () use ($flowModel, $input, $options) {
                return $this->execute($flowModel, $input, $options, static function (array $messages, array $logs, array $meta = []) {
                    $lastLog = null;
                    if ($logs !== []) {
                        $lastLog = $logs[array_key_last($logs)];
                    }

                    return Fiber::suspend([
                        'messages' => $messages,
                        'log' => $lastLog,
                        'meta' => $meta,
                    ]);
                });
            });

            $payload = $fiber->start();
            while (true) {
                if ($fiber->isTerminated()) {
                    $final = $fiber->getReturn();
                    if (is_array($final)) {
                        $meta = [
                            'event' => 'finish',
                            'flow' => $flowCode,
                        ];
                        if (is_array($final['data'] ?? null) && !empty($final['data']['workflow_id'])) {
                            $meta['workflow_id'] = (string)$final['data']['workflow_id'];
                        }
                        yield $this->formatStreamChunk([
                            'status' => (int)($final['status'] ?? 0),
                            'message' => (string)($final['message'] ?? 'ok'),
                            'data' => $final['data'] ?? null,
                            'meta' => $meta,
                        ]);
                    }
                    yield AgentSse::done();
                    break;
                }

                if (is_array($payload)) {
                    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
                    $nodeStatus = (int)($meta['status'] ?? 1);
                    $status = $nodeStatus === 0 ? 0 : 1;
                    $message = $status === 1 ? 'ok' : (string)($meta['message'] ?? '节点执行异常');

                    $chunkMeta = array_filter([
                        'event' => 'node',
                        'node' => $meta['node_id'] ?? ($meta['node'] ?? null),
                        'label' => $meta['node_label'] ?? ($meta['node'] ?? null),
                        'type' => $meta['type'] ?? null,
                        'input' => $meta['input'] ?? null,
                        'node_message' => $meta['message'] ?? null,
                        'node_status' => $nodeStatus,
                    ], static fn (mixed $value): bool => $value !== null);

                    yield $this->formatStreamChunk([
                        'status' => $status,
                        'message' => $message,
                        'data' => $meta['output'] ?? null,
                        'meta' => $chunkMeta === [] ? (object)[] : $chunkMeta,
                    ]);
                    if ($keepalivePadding) {
                        yield AgentSse::comment(str_repeat(' ', 4096));
                    }
                }

                $payload = $fiber->resume();
            }
        } catch (\Throwable $throwable) {
            yield $this->formatStreamChunk([
                'status' => 0,
                'message' => $throwable->getMessage(),
                'data' => null,
                'meta' => [
                    'event' => 'error',
                    'flow' => $flowCode,
                ],
            ]);
            yield AgentSse::done();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function orderedNodes(string|AiFlowModel $flow): array
    {
        $flowModel = $this->resolveFlow($flow);
        $definition = is_array($flowModel->flow ?? null) ? ($flowModel->flow ?? []) : [];
        $nodes = is_array($definition['nodes'] ?? null) ? ($definition['nodes'] ?? []) : [];
        $edges = is_array($definition['edges'] ?? null) ? ($definition['edges'] ?? []) : [];

        $normalized = NodeSorter::normalizeNodes($nodes);
        return NodeSorter::orderNodes($normalized['map'], $normalized['orders'], $edges);
    }

    private function formatStreamChunk(array $payload): string
    {
        return AgentSse::format($payload);
    }

    private function generateWorkflowId(): string
    {
        $datacenter = (int)getenv('AI_WORKFLOW_SNOWFLAKE_DATACENTER');
        $workerId = (int)getenv('AI_WORKFLOW_SNOWFLAKE_WORKER_ID');
        return (new Snowflake($datacenter, $workerId))->id();
    }

    private function resolveFlow(string|AiFlowModel $flow): AiFlowModel
    {
        if ($flow instanceof AiFlowModel) {
            return $flow;
        }

        $model = AiFlowModel::query()
            ->where('code', $flow)
            ->where('status', true)
            ->first();

        if (!$model) {
            throw new ExceptionBusiness(sprintf('AI Flow [%s] 不存在或未启用', $flow));
        }

        return $model;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function resolveOrderedNodesAndEnv(AiFlowModel $flowModel): array
    {
        $definition = is_array($flowModel->flow ?? null) ? ($flowModel->flow ?? []) : [];
        $schemaVersion = (int)($definition['schema_version'] ?? 0);
        $engine = (string)($definition['engine'] ?? '');
        if ($schemaVersion !== 1 || $engine !== 'neuron-ai') {
            throw new ExceptionBusiness('流程未升级到新版流程格式（请重新保存流程）');
        }

        $nodes = is_array($definition['nodes'] ?? null) ? ($definition['nodes'] ?? []) : [];
        $edges = is_array($definition['edges'] ?? null) ? ($definition['edges'] ?? []) : [];
        $defaults = is_array($definition['defaults'] ?? null) ? ($definition['defaults'] ?? []) : [];
        $globalSettings = array_merge(
            is_array($definition['globalSettings'] ?? null) ? ($definition['globalSettings'] ?? []) : [],
            is_array($flowModel->global_settings ?? null) ? ($flowModel->global_settings ?? []) : [],
        );

        $env = array_merge($globalSettings, [
            'timeout_ms' => (int)($defaults['timeout_ms'] ?? ($globalSettings['timeout_ms'] ?? 0)),
            'retry' => is_array($defaults['retry'] ?? null)
                ? ($defaults['retry'] ?? [])
                : (is_array($globalSettings['retry'] ?? null) ? ($globalSettings['retry'] ?? []) : []),
        ]);

        $normalized = NodeSorter::normalizeNodes($nodes);
        $orderedNodes = NodeSorter::orderNodes($normalized['map'], $normalized['orders'], $edges);
        $hasEndNode = false;
        foreach ($orderedNodes as $item) {
            if (($item['type'] ?? null) === 'ai_end') {
                $hasEndNode = true;
                break;
            }
        }
        if (!$hasEndNode) {
            throw new ExceptionBusiness('流程缺少结束节点 ai_end');
        }

        return [$orderedNodes, $env];
    }

    private function bootNodes(): void
    {
        if ($this->booted) {
            return;
        }

        $editors = [];
        foreach (Capability::list('flow') as $definition) {
            $code = (string)($definition['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $editors[$code] = $this->buildEditorNodeFromCapability($definition);
        }

        $this->editorNodes = $editors;
        $this->booted = true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEditorNodes(): array
    {
        $this->bootNodes();
        return array_values($this->editorNodes);
    }

    /**
     * @param array<string, mixed> $capability
     * @return array<string, mixed>
     */
    private function buildEditorNodeFromCapability(array $capability): array
    {
        $code = (string)($capability['code'] ?? '');
        $label = (string)($capability['label'] ?? $capability['name'] ?? $code);
        $description = (string)($capability['description'] ?? '');

        $defaultConfig = is_array($capability['defaults'] ?? null) ? $capability['defaults'] : [];
        $settingFields = is_array($capability['settings'] ?? null) ? $capability['settings'] : [];

        return [
            'type' => $code,
            'label' => $label !== '' ? $label : $code,
            'description' => $description,
            'category' => (string)($capability['category'] ?? 'integration'),
            'nodeType' => (string)($capability['nodeType'] ?? 'process'),
            'icon' => (string)($capability['icon'] ?? 'i-tabler:box'),
            'color' => (string)($capability['color'] ?? 'primary'),
            'style' => is_array($capability['style'] ?? null) ? $capability['style'] : [],
            'defaultConfig' => $defaultConfig,
            'settingFields' => $settingFields,
            'schema' => is_array($capability['schema'] ?? null) ? $capability['schema'] : null,
            'output' => is_array($capability['output'] ?? null) ? $capability['output'] : null,
        ];
    }
}
