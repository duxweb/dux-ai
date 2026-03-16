<?php

use App\Ai\Service\Agent\SessionExecutionGuard;

it('SessionExecutionGuard：同一会话不可重复获取锁', function () {
    $first = SessionExecutionGuard::acquire(9527);
    expect($first)->not->toBeNull()
        ->and(SessionExecutionGuard::acquire(9527))->toBeNull();

    SessionExecutionGuard::release($first);

    $second = SessionExecutionGuard::acquire(9527);
    expect($second)->not->toBeNull();
    SessionExecutionGuard::release($second);
});

it('SessionExecutionGuard：refresh 后仍保持锁占用，释放后可再次获取', function () {
    $lock = SessionExecutionGuard::acquire(9528);
    expect($lock)->not->toBeNull();

    SessionExecutionGuard::refresh($lock);

    expect(SessionExecutionGuard::acquire(9528))->toBeNull();

    SessionExecutionGuard::release($lock);

    $next = SessionExecutionGuard::acquire(9528);
    expect($next)->not->toBeNull();
    SessionExecutionGuard::release($next);
});
