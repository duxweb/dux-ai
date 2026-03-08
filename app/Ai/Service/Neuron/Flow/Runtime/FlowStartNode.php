<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Runtime;

use App\Ai\Models\AiFlow as AiFlowModel;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class FlowStartNode extends Node
{
    /**
     * @param array<int, array<string, mixed>> $orderedNodes
     * @param array<string, mixed> $env
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly AiFlowModel $flowModel,
        private readonly array $orderedNodes,
        private readonly array $env,
        private readonly array $input,
        private readonly array $options,
        private readonly mixed $onNode = null,
    ) {
    }

    public function __invoke(StartEvent $event, WorkflowState $state): FlowStepEvent
    {
        $workflowId = (string)$state->get('__workflowId', '');
        $state->set('flow_runtime', [
            'flow_id' => (int)$this->flowModel->id,
            'flow_code' => (string)$this->flowModel->code,
            'workflow_id' => $workflowId,
            'ordered_nodes' => $this->orderedNodes,
            'index' => 0,
            'env' => $this->env,
            'input' => $this->input,
            'options' => $this->options,
            'on_node' => is_callable($this->onNode) ? $this->onNode : null,
            'logs' => [],
            'messages' => [],
            'status' => 1,
            'message' => null,
            'started_at' => microtime(true),
            'flow' => [
                'input' => $this->input,
                'env' => $this->env,
                'nodes' => [],
                'last' => $this->input,
                'output' => null,
            ],
        ]);

        return new FlowStepEvent();
    }
}
