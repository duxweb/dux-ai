<?php

declare(strict_types=1);

use App\Ai\Capability\VideoTaskCancelCapability;
use App\Ai\Capability\VideoTaskQueryCapability;
use App\Ai\Interface\AgentCapabilityContextInterface;
use Core\Handlers\ExceptionBusiness;

it('视频任务查询能力：无会话上下文时抛出异常', function () {
    $capability = new VideoTaskQueryCapability();
    $context = new class implements AgentCapabilityContextInterface {
        public function scope(): string
        {
            return 'agent';
        }

        public function sessionId(): int
        {
            return 0;
        }

        public function agentId(): int
        {
            return 0;
        }
    };

    expect(fn () => $capability([], $context))
        ->toThrow(ExceptionBusiness::class, '当前会话无效，无法查询视频任务');
});

it('视频任务取消能力：无会话上下文时抛出异常', function () {
    $capability = new VideoTaskCancelCapability();
    $context = new class implements AgentCapabilityContextInterface {
        public function scope(): string
        {
            return 'agent';
        }

        public function sessionId(): int
        {
            return 0;
        }

        public function agentId(): int
        {
            return 0;
        }
    };

    expect(fn () => $capability([], $context))
        ->toThrow(ExceptionBusiness::class, '当前会话无效，无法取消视频任务');
});
