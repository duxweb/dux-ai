<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\VideoTaskCancelCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityVideoTaskCancelListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'video_task_cancel';

        $event->register($code, [
            'label' => '视频任务取消',
            'name' => '视频任务取消',
            'description' => '取消当前会话中的视频生成任务',
            'tool' => ['type' => 'function', 'function' => 'video_task_cancel'],
            'category' => 'ai',
            'nodeType' => 'process',
            'icon' => 'i-tabler:video-minus',
            'color' => 'error',
        ]);

        $event->type($code, ['agent']);
        $event->output($code, [
            ['name' => 'task_id', 'label' => '任务ID', 'type' => 'text'],
            ['name' => 'canceled', 'label' => '是否取消', 'type' => 'text'],
            ['name' => 'canceled_count', 'label' => '取消条数', 'type' => 'number'],
            ['name' => 'remote_canceled', 'label' => '远端取消结果', 'type' => 'text'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '可选传入 task_id，未传时默认取消当前会话最近进行中的视频任务',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => '视频任务 ID（可选）'],
            ],
        ]);
        $event->handler($code, new VideoTaskCancelCapability());
    }
}
