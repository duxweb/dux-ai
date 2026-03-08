<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\DelayedMessageCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityDelayedMessageListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'delayed_message';

        $event->register($code, [
            'label' => '延迟消息',
            'name' => '延迟消息',
            'description' => '用于在一段时间后发送消息给当前会话用户',
            'category' => 'agent',
            'nodeType' => 'process',
            'icon' => 'i-tabler:clock-hour-4',
            'color' => 'warning',
            'style' => ['iconBgClass' => 'bg-amber-500'],
            'async' => [
                'policy' => 'force_on',
                'enabled' => true,
                'delay_minutes' => 20,
                'max_attempts' => 3,
            ],
            'tool' => ['type' => 'function', 'function' => 'delayed_message'],
        ]);

        $event->type($code, ['agent']);
        $event->output($code, [
            ['name' => 'content', 'label' => '内容', 'type' => 'text'],
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '用于创建延迟消息任务；通常结合 delay_minutes 使用',
            'properties' => [
                'content' => ['type' => 'string', 'description' => '消息内容，例如“喝水”'],
            ],
            'required' => ['content'],
        ]);
        $event->handler($code, new DelayedMessageCapability());
    }
}
