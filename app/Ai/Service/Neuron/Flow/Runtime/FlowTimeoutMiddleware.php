<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Runtime;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

final class FlowTimeoutMiddleware implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$node instanceof FlowStepNode) {
            return;
        }

        $runtime = $state->get('flow_runtime', []);
        if (!is_array($runtime)) {
            $runtime = [];
        }

        $orderedNodes = is_array($runtime['ordered_nodes'] ?? null) ? ($runtime['ordered_nodes'] ?? []) : [];
        $index = (int)($runtime['index'] ?? 0);
        $nodeConfig = isset($orderedNodes[$index]['config']) && is_array($orderedNodes[$index]['config'])
            ? $orderedNodes[$index]['config']
            : [];

        $env = is_array($runtime['env'] ?? null) ? ($runtime['env'] ?? []) : [];
        $timeoutMs = (int)($nodeConfig['timeout_ms'] ?? ($env['timeout_ms'] ?? 0));
        if ($timeoutMs < 0) {
            $timeoutMs = 0;
        }

        $state->set('__flow_timeout_ms', $timeoutMs);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }
}
