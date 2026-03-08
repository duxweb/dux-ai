<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Runtime;

use NeuronAI\Workflow\Events\Event;

final class FlowStepEvent implements Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
