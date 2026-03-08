<?php

declare(strict_types=1);

namespace App\Ai\Interface;

interface AgentCapabilityContextInterface extends CapabilityContextInterface
{
    public function sessionId(): int;

    public function agentId(): int;
}

