<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\AsyncWaitCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityAsyncWaitTaskListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'async_wait_task';

        $event->register($code, [
            'label' => '异步等待',
            'name' => '异步等待',
            'description' => '挂起流程并由调度器轮询，完成后自动恢复执行',
            'category' => 'flow',
            'nodeType' => 'process',
            'icon' => 'i-tabler:hourglass-high',
            'color' => 'warning',
            'style' => ['iconBgClass' => 'bg-orange-500'],
            'defaults' => [
                'poll_interval_minutes' => 1,
                'timeout_minutes' => 30,
                'response_path' => 'data.status',
                'completed_values' => ['succeeded', 'completed', 'success'],
                'failed_values' => ['failed', 'error', 'canceled'],
            ],
            'settings' => [
                ['name' => 'task_id', 'label' => '任务ID', 'component' => 'text', 'required' => true, 'description' => '例如 {{nodes.video_generate.output.task_id}}'],
                ['name' => 'status_url', 'label' => '状态查询URL', 'component' => 'text', 'required' => true],
                ['name' => 'response_path', 'label' => '状态字段路径', 'component' => 'text', 'defaultValue' => 'data.status'],
                ['name' => 'completed_values', 'label' => '成功状态值(JSON数组)', 'component' => 'json', 'defaultValue' => ['succeeded', 'completed', 'success']],
                ['name' => 'failed_values', 'label' => '失败状态值(JSON数组)', 'component' => 'json', 'defaultValue' => ['failed', 'error', 'canceled']],
                ['name' => 'poll_interval_minutes', 'label' => '轮询间隔(分钟)', 'component' => 'number', 'defaultValue' => 1, 'componentProps' => ['min' => 1, 'step' => 1]],
                ['name' => 'timeout_minutes', 'label' => '超时分钟', 'component' => 'number', 'defaultValue' => 30, 'componentProps' => ['min' => 1, 'step' => 1]],
                ['name' => 'status_method', 'label' => '查询方法', 'component' => 'select', 'defaultValue' => 'GET', 'options' => [['label' => 'GET', 'value' => 'GET'], ['label' => 'POST', 'value' => 'POST']]],
                ['name' => 'status_headers', 'label' => '请求头(JSON对象)', 'component' => 'json', 'defaultValue' => []],
                ['name' => 'status_query', 'label' => '查询参数(JSON对象)', 'component' => 'json', 'defaultValue' => []],
                ['name' => 'status_body', 'label' => 'POST Body(JSON对象)', 'component' => 'json', 'defaultValue' => []],
            ],
        ]);

        $event->type($code, ['flow']);
        $event->output($code, [
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
            ['name' => 'task_id', 'label' => '任务ID', 'type' => 'text'],
            ['name' => 'poll_interval_minutes', 'label' => '轮询间隔', 'type' => 'number'],
            ['name' => 'timeout_minutes', 'label' => '超时时间', 'type' => 'number'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string'],
                'status_url' => ['type' => 'string'],
                'response_path' => ['type' => 'string'],
                'poll_interval_minutes' => ['type' => 'integer'],
                'timeout_minutes' => ['type' => 'integer'],
            ],
            'required' => ['task_id', 'status_url'],
        ]);
        $event->handler($code, new AsyncWaitCapability());
    }
}
