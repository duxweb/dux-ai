<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\HttpRequestCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

class CapabilityHttpTaskListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'http_task';

        $outputFields = [
            ['name' => 'body', 'label' => '响应体', 'type' => 'object', 'description' => '解析后的 JSON/文本'],
            ['name' => 'status', 'label' => '状态码', 'type' => 'number'],
            ['name' => 'headers', 'label' => '响应头', 'type' => 'object'],
            ['name' => 'method', 'label' => '请求方法', 'type' => 'text'],
            ['name' => 'url', 'label' => '请求地址', 'type' => 'text'],
            ['name' => 'timeout', 'label' => '请求超时', 'type' => 'number'],
            ['name' => 'request_body_type', 'label' => '请求体类型', 'type' => 'text'],
            ['name' => 'request_body_raw', 'label' => '请求原始体', 'type' => 'text'],
            ['name' => 'request_body_json', 'label' => '请求 JSON 体', 'type' => 'object'],
            ['name' => 'request_body_form', 'label' => '请求 Form 体', 'type' => 'object'],
            ['name' => 'request_headers', 'label' => '请求头', 'type' => 'object'],
            ['name' => 'request_query', 'label' => '请求 Query', 'type' => 'object'],
        ];

        $defaultConfig = [
            'message_role' => 'system',
            'method' => 'GET',
            'bodyType' => 'json',
            'timeout' => 30,
        ];
        $settingFields = [
            [
                'name' => 'method',
                'label' => '请求方法',
                'component' => 'select',
                'options' => [
                    ['label' => 'GET', 'value' => 'GET'],
                    ['label' => 'POST', 'value' => 'POST'],
                    ['label' => 'PUT', 'value' => 'PUT'],
                    ['label' => 'PATCH', 'value' => 'PATCH'],
                    ['label' => 'DELETE', 'value' => 'DELETE'],
                ],
                'required' => true,
            ],
            [
                'name' => 'url',
                'label' => '请求 URL',
                'component' => 'text',
                'required' => true,
                'preview' => ['label' => '请求地址', 'type' => 'text'],
            ],
            [
                'name' => 'query',
                'label' => 'Query 参数',
                'component' => 'kv-input',
                'componentProps' => [
                    'namePlaceholder' => '参数名',
                    'valuePlaceholder' => '参数值',
                ],
            ],
            [
                'name' => 'headers',
                'label' => 'Headers',
                'component' => 'kv-input',
                'componentProps' => [
                    'namePlaceholder' => 'Header',
                    'valuePlaceholder' => '值',
                ],
            ],
            [
                'name' => 'bodyType',
                'label' => 'Body 类型',
                'component' => 'select',
                'options' => [
                    ['label' => 'JSON', 'value' => 'json'],
                    ['label' => 'Form Data', 'value' => 'form'],
                    ['label' => 'Raw', 'value' => 'raw'],
                ],
                'defaultValue' => 'json',
            ],
            [
                'name' => 'body',
                'label' => 'Body 内容',
                'component' => 'textarea',
                'description' => '当 Body 类型为 JSON/Raw 时填写，支持模板变量',
                'preview' => false,
            ],
            [
                'name' => 'bodyForm',
                'label' => 'Form Data',
                'component' => 'kv-input',
                'componentProps' => [
                    'namePlaceholder' => '字段名',
                    'valuePlaceholder' => '字段值',
                ],
            ],
            [
                'name' => 'timeout',
                'label' => '请求超时（秒）',
                'component' => 'number',
                'defaultValue' => 30,
                'componentProps' => [
                    'min' => 1,
                    'max' => 300,
                    'step' => 1,
                ],
            ],
        ];

        $event->register($code, [
            'label' => 'HTTP 请求',
            'name' => 'HTTP 请求',
            'description' => '调用外部 HTTP 接口',
            'tool' => ['type' => 'http', 'function' => 'http_request'],
            'category' => 'integration',
            'nodeType' => 'process',
            'icon' => 'i-tabler:plug-connected',
            'color' => 'error',
            'style' => ['iconBgClass' => 'bg-orange-500'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow', 'agent']);
        $event->output($code, $outputFields);
        $event->schema($code, [
            'type' => 'object',
            'properties' => [],
        ]);
        $event->handler($code, new HttpRequestCapability());
    }
}
