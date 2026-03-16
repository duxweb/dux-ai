<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use Core\App;
use Symfony\Component\Lock\LockInterface;

final class SessionExecutionGuard
{
    private const LOCK_TTL = 60;

    public static function acquire(int $sessionId): ?LockInterface
    {
        if ($sessionId <= 0) {
            return null;
        }

        $lock = App::lock()->createLock(self::key($sessionId), self::LOCK_TTL);
        if (!$lock->acquire(false)) {
            return null;
        }

        return $lock;
    }

    public static function release(?LockInterface $lock): void
    {
        if ($lock) {
            $lock->release();
        }
    }

    public static function refresh(?LockInterface $lock): void
    {
        if ($lock) {
            $lock->refresh(self::LOCK_TTL);
        }
    }

    private static function key(int $sessionId): string
    {
        return sprintf('ai:agent:session:%d', $sessionId);
    }
}
