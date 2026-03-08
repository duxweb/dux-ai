<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\NotifySendCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityNotifySendListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'notify_send';

        $event->register($code, [
            'label' => '发送通知',
            'name' => '发送通知',
            'description' => '发送消息通知，可选延迟发送',
            'category' => 'flow',
            'nodeType' => 'process',
            'icon' => 'i-tabler:bell-ringing',
            'color' => 'primary',
            'style' => ['iconBgClass' => 'bg-blue-500'],
            'async' => [
                'policy' => 'optional',
                'enabled' => true,
                'delay_minutes' => 0,
                'max_attempts' => 3,
            ],
            'tool' => ['type' => 'function', 'function' => 'notify_send'],
            'defaults' => [
                'channel' => 'boot_session',
                'title' => '提醒通知',
            ],
            'settings' => [
                [
                    'name' => 'channel',
                    'label' => '通知通道',
                    'component' => 'select',
                    'defaultValue' => 'boot_session',
                    'description' => '通知投递目标，当前支持 Boot 会话消息',
                    'options' => [['label' => 'Boot 会话消息', 'value' => 'boot_session']],
                ],
                [
                    'name' => 'bot_code',
                    'label' => '机器人编码',
                    'component' => 'dux-select',
                    'required' => true,
                    'description' => '选择发送通知使用的机器人',
                    'componentProps' => [
                        'path' => 'boot/bot/options',
                        'params' => ['enabled' => 1],
                        'labelField' => 'label',
                        'valueField' => 'value',
                        'descField' => 'desc',
                    ],
                ],
                ['name' => 'title', 'label' => '标题', 'component' => 'text', 'defaultValue' => '提醒通知', 'description' => '通知标题，未传时使用默认标题'],
                ['name' => 'content', 'label' => '通知内容', 'component' => 'textarea', 'description' => '通知正文，可与 image_url 二选一或同时填写'],
                ['name' => 'image_url', 'label' => '图片链接', 'component' => 'text', 'description' => '可选：发送图片通知时填写图片 URL'],
            ],
        ]);

        $event->type($code, ['flow', 'agent']);
        $event->output($code, [
            ['name' => 'mode', 'label' => '发送模式', 'type' => 'text'],
            ['name' => 'schedule_id', 'label' => '调度ID', 'type' => 'number'],
            ['name' => 'execute_at', 'label' => '计划发送时间', 'type' => 'text'],
            ['name' => 'channel', 'label' => '通道', 'type' => 'text'],
            ['name' => 'title', 'label' => '标题', 'type' => 'text'],
            ['name' => 'content', 'label' => '内容', 'type' => 'text'],
            ['name' => 'image_url', 'label' => '图片链接', 'type' => 'image'],
            ['name' => 'images', 'label' => '图片链接列表', 'type' => 'array'],
            ['name' => 'result', 'label' => '结果', 'type' => 'json'],
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '输入字段：content、image_url/images 至少填写一个，title 可选',
            'properties' => [
                'title' => ['type' => 'string', 'description' => '通知标题，可选'],
                'content' => ['type' => 'string', 'description' => '通知正文内容，可选（与图片至少一项）'],
                'image_url' => ['type' => 'string', 'description' => '单张图片链接，可选（与内容至少一项）'],
                'images' => [
                    'type' => 'array',
                    'description' => '多张图片链接，可选，优先取第一张发送',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [],
        ]);
        $event->handler($code, new NotifySendCapability());
    }
}
