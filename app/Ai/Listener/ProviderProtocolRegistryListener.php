<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Event\ProviderProtocolEvent;
use App\Ai\Models\AiProvider;
use Core\Event\Attribute\Listener;

final class ProviderProtocolRegistryListener
{
    #[Listener(name: 'ai.provider.protocol')]
    public function handle(ProviderProtocolEvent $event): void
    {
        foreach (AiProvider::builtinProtocolRegistry() as $item) {
            $event->register($item);
        }
    }
}
