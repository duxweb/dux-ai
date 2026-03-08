<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\VideoGenerateCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityVideoGenerateTaskListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'video_generate';

        $event->register($code, [
            'label' => '视频生成',
            'name' => '视频生成',
            'description' => '提交视频生成任务并自动轮询，完成后自动回写',
            'tool' => ['type' => 'function', 'function' => 'video_generate'],
            'category' => 'ai',
            'nodeType' => 'process',
            'icon' => 'i-tabler:video-plus',
            'color' => 'primary',
            'defaults' => [
                'delay_minutes' => 0,
                'poll_interval_minutes' => 1,
                'timeout_minutes' => 30,
            ],
            'settings' => [
                [
                    'name' => 'model_id',
                    'label' => '视频模型',
                    'required' => true,
                    'component' => 'dux-select',
                    'componentProps' => [
                        'path' => 'ai/flow/modelOptions',
                        'params' => ['type' => 'video'],
                        'labelField' => 'label',
                        'valueField' => 'id',
                        'descField' => 'desc',
                    ],
                    'preview' => ['label' => '视频模型'],
                ],
                ['name' => 'image_url', 'label' => '首帧图片URL(可选)', 'component' => 'text'],
                ['name' => 'resolution', 'label' => '分辨率(可选)', 'component' => 'text', 'description' => '例如 720p / 1080p'],
                ['name' => 'ratio', 'label' => '宽高比(可选)', 'component' => 'text', 'description' => '例如 16:9 / 9:16'],
                ['name' => 'duration', 'label' => '时长秒(可选)', 'component' => 'number', 'componentProps' => ['min' => 1, 'step' => 1]],
                ['name' => 'frames', 'label' => '帧数(可选)', 'component' => 'number', 'componentProps' => ['min' => 1, 'step' => 1]],
                ['name' => 'seed', 'label' => '种子(可选)', 'component' => 'number'],
                ['name' => 'return_last_frame', 'label' => '返回尾帧图(可选)', 'component' => 'switch'],
                ['name' => 'delay_minutes', 'label' => '首次查询延迟(分钟)', 'component' => 'number', 'defaultValue' => 0, 'componentProps' => ['min' => 0, 'step' => 1]],
                ['name' => 'poll_interval_minutes', 'label' => '轮询间隔(分钟)', 'component' => 'number', 'defaultValue' => 1, 'componentProps' => ['min' => 1, 'step' => 1]],
                ['name' => 'timeout_minutes', 'label' => '超时(分钟)', 'component' => 'number', 'defaultValue' => 30, 'componentProps' => ['min' => 1, 'step' => 1]],
            ],
        ]);

        $event->type($code, ['flow', 'agent']);
        $event->output($code, [
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
            ['name' => 'task_id', 'label' => '任务ID', 'type' => 'text'],
            ['name' => 'provider_status', 'label' => '任务状态', 'type' => 'text'],
            ['name' => 'video_url', 'label' => '视频链接', 'type' => 'text'],
            ['name' => 'videos', 'label' => '视频列表', 'type' => 'array'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '输入字段：prompt（必填）；可选 image_url、resolution、ratio、duration、frames、seed、return_last_frame；可选 delay_minutes（首次查询延迟分钟）；可选轮询参数 poll_interval_minutes、timeout_minutes。模型由工具配置决定。',
            'properties' => [
                'prompt' => ['type' => 'string', 'description' => '视频提示词'],
                'image_url' => ['type' => 'string', 'description' => '首帧图片 URL（可选）'],
                'resolution' => ['type' => 'string', 'description' => '分辨率（可选，如 720p）'],
                'ratio' => ['type' => 'string', 'description' => '宽高比（可选，如 16:9）'],
                'duration' => ['type' => 'integer', 'description' => '时长秒（可选）'],
                'frames' => ['type' => 'integer', 'description' => '帧数（可选）'],
                'seed' => ['type' => 'integer', 'description' => '随机种子（可选）'],
                'return_last_frame' => ['type' => 'boolean', 'description' => '是否返回尾帧图（可选）'],
                'delay_minutes' => ['type' => 'integer', 'description' => '首次查询延迟分钟（可选，0=按轮询间隔开始）'],
                'poll_interval_minutes' => ['type' => 'integer', 'description' => '轮询间隔分钟'],
                'timeout_minutes' => ['type' => 'integer', 'description' => '任务超时分钟'],
            ],
            'required' => ['prompt'],
        ]);
        $event->handler($code, new VideoGenerateCapability());
    }
}
