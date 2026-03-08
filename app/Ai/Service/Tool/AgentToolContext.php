<?php

declare(strict_types=1);

namespace App\Ai\Service\Tool;

use App\Ai\Interface\AgentCapabilityContextInterface;

final class AgentToolContext implements AgentCapabilityContextInterface
{
    public function __construct(
        private readonly int $sessionId = 0,
        private readonly int $agentId = 0,
    ) {
    }

    public function scope(): string
    {
        return 'agent';
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    public function agentId(): int
    {
        return $this->agentId;
    }
}
