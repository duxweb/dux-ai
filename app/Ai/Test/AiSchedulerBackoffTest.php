<?php

declare(strict_types=1);

use App\Ai\Service\Scheduler\BackoffPolicy;
use Carbon\Carbon;

it('AI 调度退避策略：按 1/2/4 分钟增长', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-03 10:00:00'));

    expect(BackoffPolicy::nextExecuteAt(1)->format('Y-m-d H:i:s'))->toBe('2026-03-03 10:01:00')
        ->and(BackoffPolicy::nextExecuteAt(2)->format('Y-m-d H:i:s'))->toBe('2026-03-03 10:02:00')
        ->and(BackoffPolicy::nextExecuteAt(3)->format('Y-m-d H:i:s'))->toBe('2026-03-03 10:04:00')
        ->and(BackoffPolicy::nextExecuteAt(10)->format('Y-m-d H:i:s'))->toBe('2026-03-03 10:04:00');

    Carbon::setTestNow();
});
