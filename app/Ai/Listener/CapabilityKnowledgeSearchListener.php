<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\KnowledgeSearchCapability;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

final class CapabilityKnowledgeSearchListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'kb_search';

        $event->register($code, [
            'label' => '知识库检索',
            'name' => '知识库检索',
            'description' => '按语义检索知识库内容',
            'tool' => ['type' => 'function', 'function' => 'kb_search'],
            'category' => 'integration',
            'nodeType' => 'process',
            'icon' => 'i-tabler:database-search',
            'color' => 'primary',
            'style' => ['iconBgClass' => 'bg-sky-500'],
            'defaults' => [
                'message_role' => 'system',
                'limit' => 5,
                'content_length' => 0,
            ],
            'settings' => [
                [
                    'name' => 'knowledge_id',
                    'label' => '知识库',
                    'component' => 'dux-select',
                    'required' => true,
                    'componentProps' => [
                        'path' => 'ai/ragKnowledge/options',
                        'label-field' => 'label',
                        'value-field' => 'value',
                        'desc-field' => 'desc',
                    ],
                ],
                [
                    'name' => 'limit',
                    'label' => '返回条数',
                    'component' => 'number',
                    'componentProps' => [
                        'min' => 1,
                        'max' => 20,
                    ],
                ],
                [
                    'name' => 'content_length',
                    'label' => '内容长度（0 不截断）',
                    'component' => 'number',
                    'componentProps' => [
                        'min' => 0,
                        'max' => 20000,
                    ],
                ],
            ],
        ]);

        $event->type($code, ['flow', 'agent']);
        $event->output($code, [
            ['name' => 'items', 'label' => '检索结果', 'type' => 'array', 'description' => 'title/summary/score/type/meta'],
            ['name' => 'keyword', 'label' => '检索词', 'type' => 'text'],
            ['name' => 'limit', 'label' => '返回条数', 'type' => 'number'],
            ['name' => 'content_length', 'label' => '内容长度', 'type' => 'number'],
            ['name' => 'hits', 'label' => '命中数量', 'type' => 'number'],
            ['name' => 'knowledge_id', 'label' => '知识库 ID', 'type' => 'number'],
            ['name' => 'knowledge_name', 'label' => '知识库名称', 'type' => 'text'],
            ['name' => 'knowledge_base_id', 'label' => '知识库远端 ID', 'type' => 'text'],
            ['name' => 'knowledge_provider', 'label' => '知识库服务商', 'type' => 'text'],
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
        ]);
        $event->schema($code, [
            'type' => 'object',
            'description' => '输入字段：query（检索关键词）',
            'properties' => [
                'query' => ['type' => 'string'],
            ],
            'required' => ['query'],
        ]);
        $event->handler($code, new KnowledgeSearchCapability());
    }
}
