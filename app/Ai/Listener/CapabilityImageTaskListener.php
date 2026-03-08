<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\ImageGenerateCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityImageTaskListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'image_generate';

        $event->register($code, [
            'label' => '图片生成',
            'name' => '图片生成',
            'description' => '调用图片模型执行文生图/图生图',
            'tool' => ['type' => 'function', 'function' => 'image_generate'],
            'category' => 'ai',
            'nodeType' => 'process',
            'icon' => 'i-tabler:photo-spark',
            'color' => 'warning',
            'style' => ['iconBgClass' => 'bg-amber-500'],
            'async' => [
                'policy' => 'optional',
                'enabled' => true,
                'delay_minutes' => 0,
                'max_attempts' => 3,
            ],
            'defaults' => [
                'message_role' => 'assistant',
                'n' => 1,
                'size' => '1920x1920',
            ],
            'settings' => [
                [
                    'name' => 'model_id',
                    'label' => '图片模型',
                    'required' => true,
                    'component' => 'dux-select',
                    'componentProps' => [
                        'path' => 'ai/flow/modelOptions',
                        'params' => ['type' => 'image'],
                        'labelField' => 'label',
                        'valueField' => 'id',
                        'descField' => 'desc',
                    ],
                    'preview' => ['label' => '图片模型'],
                ],
                [
                    'name' => 'n',
                    'label' => '默认生成数量',
                    'component' => 'number',
                    'defaultValue' => 1,
                    'componentProps' => [
                        'min' => 1,
                        'max' => 4,
                        'step' => 1,
                    ],
                ],
                [
                    'name' => 'size',
                    'label' => '默认尺寸',
                    'defaultValue' => '1920x1920',
                    'component' => 'text',
                    'description' => '例如 1920x1920（至少 3686400 像素）',
                ],
                [
                    'name' => 'negative_prompt',
                    'label' => '默认负向提示词',
                    'component' => 'textarea',
                    'description' => '可选：用于约束不要出现的内容',
                    'preview' => false,
                ],
            ],
        ]);

        $event->type($code, ['flow', 'agent']);
        $event->output($code, [
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
            ['name' => 'count', 'label' => '生成数量', 'type' => 'number'],
            ['name' => 'image', 'label' => '首张图片链接', 'type' => 'image'],
            ['name' => 'images', 'label' => '图片链接', 'type' => 'array'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '输入字段：prompt（必填）；可选 image（单图 URL 或 URL 数组，图生图）、mask_url、size、n、negative_prompt',
            'properties' => [
                'prompt' => ['type' => 'string', 'description' => '图片提示词'],
                'image' => ['description' => '图生图输入，支持单图 URL 字符串或 URL 数组'],
                'mask_url' => ['type' => 'string', 'description' => '编辑遮罩 URL（可选）'],
                'size' => ['type' => 'string', 'description' => '尺寸，例如 1024x1024'],
                'n' => ['type' => 'integer', 'description' => '生成数量 1-4'],
                'negative_prompt' => ['type' => 'string', 'description' => '负向提示词（可选）'],
            ],
            'required' => ['prompt'],
        ]);
        $event->handler($code, new ImageGenerateCapability());
    }
}
