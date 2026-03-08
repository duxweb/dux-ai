<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\VideoTaskQueryCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityVideoTaskQueryListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'video_task_query';

        $event->register($code, [
            'label' => '视频任务查询',
            'name' => '视频任务查询',
            'description' => '查询当前会话中视频生成任务状态',
            'tool' => ['type' => 'function', 'function' => 'video_task_query'],
            'category' => 'ai',
            'nodeType' => 'process',
            'icon' => 'i-tabler:video',
            'color' => 'primary',
        ]);

        $event->type($code, ['agent']);
        $event->output($code, [
            ['name' => 'task_id', 'label' => '任务ID', 'type' => 'text'],
            ['name' => 'provider_status', 'label' => '任务状态', 'type' => 'text'],
            ['name' => 'video_url', 'label' => '视频链接', 'type' => 'text'],
            ['name' => 'last_frame_url', 'label' => '尾帧链接', 'type' => 'text'],
            ['name' => 'schedule_id', 'label' => '调度ID', 'type' => 'number'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '可选传入 task_id，未传时默认查询当前会话最近进行中的视频任务',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => '视频任务 ID（可选）'],
            ],
        ]);
        $event->handler($code, new VideoTaskQueryCapability());
    }
}
