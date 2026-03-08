<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow\Interrupt;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

final class AsyncWaitResumeRequest extends InterruptRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $message = 'resume', private readonly array $payload = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'async_wait_resume',
            'message' => $this->getMessage(),
            'payload' => $this->payload,
        ];
    }
}
