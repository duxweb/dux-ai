<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Runtime;

use App\Ai\Models\AiFlow as AiFlowModel;
use App\Ai\Service\Neuron\Flow\FlowExecutionLogger;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\ObserverInterface;
use Throwable;

final class FlowObserver implements ObserverInterface
{
    public function __construct(
        private readonly AiFlowModel $flowModel,
    ) {
    }

    public function onEvent(string $event, object $source, mixed $data = null): void
    {
        if ($event !== 'workflow-end' || !$data instanceof WorkflowEnd) {
            return;
        }

        $runtime = $data->state->get('flow_runtime', []);
        if (!is_array($runtime)) {
            return;
        }

        $flow = is_array($runtime['flow'] ?? null) ? ($runtime['flow'] ?? []) : [];
        $logs = is_array($runtime['logs'] ?? null) ? ($runtime['logs'] ?? []) : [];
        $messages = is_array($runtime['messages'] ?? null) ? ($runtime['messages'] ?? []) : [];
        $status = (int)($runtime['status'] ?? 1);
        $message = isset($runtime['message']) ? (string)$runtime['message'] : null;
        $input = is_array($runtime['input'] ?? null) ? ($runtime['input'] ?? []) : [];
        $workflowId = trim((string)($runtime['workflow_id'] ?? ''));
        $startedAt = (float)($runtime['started_at'] ?? microtime(true));
        $duration = max(microtime(true) - $startedAt, 0.0);

        try {
            FlowExecutionLogger::persist(
                flowModel: $this->flowModel,
                workflowId: $workflowId,
                input: $input,
                flow: $flow,
                logs: $logs,
                messages: $messages,
                status: $status,
                message: $message,
                duration: $duration,
            );
        } catch (Throwable) {
        }
    }
}
