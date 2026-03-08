<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Event\AgentMessagePersistedEvent;
use App\Ai\Service\Agent\BotBridgeService;
use Core\Event\Attribute\Listener;

final class AgentMessageRelayListener
{
    #[Listener(name: 'ai.agent.message.persisted')]
    public function handle(AgentMessagePersistedEvent $event): void
    {
        (new BotBridgeService())->relayAssistantMessage($event->getMessage());
    }
}

