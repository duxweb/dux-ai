<?php

declare(strict_types=1);

use App\Ai\Capability\AsyncWaitCapability;
use App\Ai\Interface\NullCapabilityContext;

it('异步等待能力：返回挂起状态与 suspend 元信息', function () {
    $capability = new AsyncWaitCapability();
    $result = $capability([
        'task_id' => 'task_123',
        'status_url' => 'https://example.com/task/status',
        'poll_interval_minutes' => 2,
        'timeout_minutes' => 15,
        'response_path' => 'data.status',
    ], new NullCapabilityContext());

    expect($result['status'])->toBe(2)
        ->and($result['data']['task_id'])->toBe('task_123')
        ->and($result['meta']['suspend']['poll_interval_minutes'])->toBe(2)
        ->and($result['meta']['suspend']['timeout_minutes'])->toBe(15);
});
