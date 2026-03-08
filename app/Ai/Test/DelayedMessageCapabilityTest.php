<?php

use App\Ai\Capability\DelayedMessageCapability;
use App\Ai\Interface\CapabilityContextInterface;
use Core\Handlers\ExceptionBusiness;

it('延迟消息能力：缺少 content 时抛出异常', function () {
    $capability = new DelayedMessageCapability();

    $context = new class implements CapabilityContextInterface {
        public function scope(): string
        {
            return 'agent';
        }
    };

    expect(fn () => $capability([], $context))
        ->toThrow(ExceptionBusiness::class, '消息内容不能为空');
});

it('延迟消息能力：返回 summary 供调度写回会话', function () {
    $capability = new DelayedMessageCapability();

    $context = new class implements CapabilityContextInterface {
        public function scope(): string
        {
            return 'agent';
        }
    };

    $result = $capability(['content' => '喝水'], $context);

    expect($result['status'] ?? null)->toBe(1)
        ->and($result['summary'] ?? null)->toBe('喝水')
        ->and($result['data']['content'] ?? null)->toBe('喝水');
});
