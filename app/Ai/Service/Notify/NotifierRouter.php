<?php

declare(strict_types=1);

namespace App\Ai\Service\Notify;

use Core\Handlers\ExceptionBusiness;

final class NotifierRouter
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function send(string $channel, array $payload): array
    {
        $channel = trim($channel);
        if ($channel === '') {
            $channel = 'boot_session';
        }

        return match ($channel) {
            'boot_session' => (new BootSessionNotifier())->send($payload),
            default => throw new ExceptionBusiness(sprintf('不支持的通知通道 [%s]', $channel)),
        };
    }
}
