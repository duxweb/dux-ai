<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow;

use App\Ai\Interface\FlowCapabilityContextInterface;

final class WorkflowToolContext implements FlowCapabilityContextInterface
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private readonly int $flowId,
        private readonly string $nodeId,
        private array $state = [],
    ) {
    }

    public function scope(): string
    {
        return 'flow';
    }

    public function flowId(): int
    {
        return $this->flowId;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function setState(array $state): void
    {
        $this->state = $state;
    }
}
