<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler;

use Carbon\Carbon;

final class BackoffPolicy
{
    public static function nextExecuteAt(int $attempt): Carbon
    {
        $minutes = match (true) {
            $attempt <= 1 => 1,
            $attempt === 2 => 2,
            default => 4,
        };

        return Carbon::now()->addMinutes($minutes);
    }
}
