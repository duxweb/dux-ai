<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Runtime;

use App\Ai\Service\Usage\UsageResolver;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

final class FlowTelemetryMiddleware implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if (!$node instanceof FlowStepNode) {
            return;
        }

        $usage = $state->get('__flow_last_usage');
        $trackToken = (bool)$state->get('__flow_last_track_token', false);
        if (!$trackToken || !is_array($usage)) {
            return;
        }

        $normalized = UsageResolver::normalizeUsage($usage);
        if ($normalized === null) {
            return;
        }

        $totals = $state->get('__flow_usage_totals', [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);
        if (!is_array($totals)) {
            $totals = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];
        }

        $totals['prompt_tokens'] = (int)($totals['prompt_tokens'] ?? 0) + (int)$normalized['prompt_tokens'];
        $totals['completion_tokens'] = (int)($totals['completion_tokens'] ?? 0) + (int)$normalized['completion_tokens'];
        $totals['total_tokens'] = (int)($totals['total_tokens'] ?? 0) + (int)$normalized['total_tokens'];
        $state->set('__flow_usage_totals', $totals);
    }
}
