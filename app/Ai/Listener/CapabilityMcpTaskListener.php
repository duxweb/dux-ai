<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\McpCallerCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

class CapabilityMcpTaskListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'mcp_task';

        $defaultConfig = [
            'message_role' => 'system',
            'transport' => 'streamable_http',
            'tool' => '',
            'server_url' => '',
            'headers' => [],
            'token' => '',
        ];
        $outputFields = [
            ['name' => 'result', 'label' => '执行结果', 'type' => 'object'],
            ['name' => 'tool', 'label' => '工具名称', 'type' => 'text'],
            ['name' => 'transport', 'label' => '传输类型', 'type' => 'text'],
            ['name' => 'service_url', 'label' => '服务地址', 'type' => 'text'],
        ];
        $settingFields = [
            [
                'name' => 'tool',
                'label' => 'MCP 工具名',
                'component' => 'text',
                'required' => true,
                'description' => '手动输入远端 MCP Server 暴露的工具名，如 fetch',
            ],
            [
                'name' => 'server_url',
                'label' => 'MCP 服务 URL',
                'component' => 'text',
                'required' => true,
                'description' => '填写 MCP 服务端点地址',
                'preview' => ['label' => '服务 URL', 'type' => 'text'],
            ],
            [
                'name' => 'transport',
                'label' => '传输类型',
                'component' => 'select',
                'options' => [
                    ['label' => 'Streamable HTTP', 'value' => 'streamable_http'],
                    ['label' => 'SSE', 'value' => 'sse'],
                ],
                'defaultValue' => 'streamable_http',
                'description' => '根据 MCP 服务支持协议选择，默认 streamable_http',
            ],
            [
                'name' => 'token',
                'label' => 'Bearer Token',
                'component' => 'text',
                'preview' => false,
            ],
            [
                'name' => 'headers',
                'label' => 'Headers',
                'component' => 'kv-input',
                'preview' => false,
                'componentProps' => [
                    'namePlaceholder' => 'Header',
                    'valuePlaceholder' => '值',
                ],
            ],
        ];

        $event->register($code, [
            'label' => 'MCP 调用',
            'name' => 'MCP 调用',
            'description' => '通过 MCP（HTTP/SSE）调用远端工具',
            'tool' => ['type' => 'mcp', 'function' => 'mcp_caller'],
            'category' => 'integration',
            'nodeType' => 'process',
            'icon' => 'i-tabler:cpu',
            'color' => 'primary',
            'style' => ['iconBgClass' => 'bg-blue-500'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow', 'agent']);
        $event->output($code, $outputFields);
        $event->schema($code, [
            'type' => 'object',
            'properties' => [
                'arguments' => ['type' => 'object'],
            ],
            'required' => ['arguments'],
        ]);
        $event->handler($code, new McpCallerCapability());
    }
}
