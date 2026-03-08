<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\DocParseCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

class CapabilityDocParseListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'doc_parse';

        $defaultConfig = [
            'message_role' => 'system',
        ];
        $outputFields = [
            ['name' => 'content', 'label' => '解析内容', 'type' => 'text'],
            ['name' => 'file_name', 'label' => '文件名', 'type' => 'text'],
            ['name' => 'file_url', 'label' => '文件地址', 'type' => 'text'],
            ['name' => 'provider', 'label' => '解析配置', 'type' => 'text'],
        ];
        $settingFields = [
            [
                'name' => 'provider',
                'label' => '解析配置',
                'component' => 'dux-select',
                'required' => false,
                'componentProps' => [
                    'path' => 'ai/parseProvider',
                    'label-field' => 'name',
                    'value-field' => 'id',
                ],
                'description' => '图片/PDF 建议选择；普通文档可留空',
            ],
        ];

        $event->register($code, [
            'label' => '文档解析',
            'name' => '文档解析',
            'description' => '上传/提交文档到解析服务，返回解析内容',
            'category' => 'integration',
            'nodeType' => 'process',
            'icon' => 'i-tabler:file-text-ai',
            'color' => 'primary',
            'style' => ['iconBgClass' => 'bg-blue-500'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow', 'agent']);
        $event->output($code, $outputFields);
        $event->schema($code, [
            'type' => 'object',
            'description' => '输入字段：url/path/file（支持远程链接、本地路径、上传对象）',
            'properties' => [
                'url' => ['type' => 'string'],
                'path' => ['type' => 'string'],
                'file' => ['type' => 'object'],
                'file_name' => ['type' => 'string'],
            ],
        ]);
        $event->handler($code, new DocParseCapability());
    }
}
