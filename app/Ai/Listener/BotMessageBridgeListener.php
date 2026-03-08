<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Service\Agent\BotBridgeService;
use App\Boot\Event\BotMessageReceivedEvent;
use Core\Event\Attribute\Listener;

final class BotMessageBridgeListener
{
    #[Listener(name: 'boot.message.received')]
    public function handle(BotMessageReceivedEvent $event): void
    {
        $reply = (new BotBridgeService())->handleInbound($event->getBot(), $event->getMessage());
        if (($reply['mode'] ?? '') === BotBridgeService::REPLY_MODE_ACK_ONLY) {
            $event->setAckOnly();
            return;
        }
        $text = trim((string)($reply['text'] ?? ''));
        if ($text !== '') {
            $event->setReplyText($text);
        }
    }
}
