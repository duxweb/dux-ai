<?php

declare(strict_types=1);

namespace App\Ai\Event;

use App\Ai\Models\AiAgentMessage;
use Symfony\Contracts\EventDispatcher\Event;

final class AgentMessagePersistedEvent extends Event
{
    public function __construct(
        private readonly AiAgentMessage $message,
    ) {
    }

    public function getMessage(): AiAgentMessage
    {
        return $this->message;
    }
}

