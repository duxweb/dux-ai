<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Capability\InstructionCapability;
use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Event\AiCapabilityEvent;
use Core\Event\Attribute\Listener;

class CapabilityInstructionListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $outputFields = [
            ['name' => 'decisions', 'label' => '决策列表', 'type' => 'array', 'description' => '包含 tool/reason/arguments'],
            ['name' => 'decision_count', 'label' => '决策数量', 'type' => 'number'],
            ['name' => 'strict_mode', 'label' => '严格模式', 'type' => 'boolean'],
            ['name' => 'mode_used', 'label' => '输出模式', 'type' => 'text', 'description' => 'structured 或 text'],
        ];

        $defaultConfig = [
            'message_role' => 'system',
            'analysis_prompt' => '',
            'output_mode' => 'auto',
        ];
        $settingFields = [
            [
                'name' => 'provider',
                'label' => '服务商',
                'required' => true,
                'component' => 'dux-select',
                'componentProps' => [
                    'path' => 'ai/flow/providerOptions',
                    'labelField' => 'label',
                    'valueField' => 'value',
                    'descField' => 'desc',
                    'pagination' => false,
                ],
                'preview' => ['label' => '服务商'],
            ],
            [
                'name' => 'model',
                'label' => '模型',
                'component' => 'dux-select',
                'componentProps' => [
                    'path' => 'ai/flow/modelOptions',
                    'labelField' => 'label',
                    'valueField' => 'value',
                    'descField' => 'desc',
                    'pagination' => true,
                ],
                'description' => '为空则使用服务商默认模型',
                'preview' => ['label' => '模型'],
            ],
            [
                'name' => 'analysis_prompt',
                'label' => '指令提示词',
                'component' => 'prompt-editor',
                'defaultValue' => '',
                'description' => '支持 {{TOOL_LIST}} 占位符，用于插入可用工具列表',
                'preview' => false,
            ],
            [
                'name' => 'systemPrompt',
                'label' => '系统提示词',
                'component' => 'prompt-editor',
                'preview' => false,
            ],
            [
                'name' => 'userPrompt',
                'label' => '用户提示词',
                'component' => 'prompt-editor',
                'preview' => false,
            ],
            [
                'name' => 'temperature',
                'label' => '温度',
                'component' => 'number',
                'defaultValue' => 1,
                'componentProps' => [
                    'min' => 0,
                    'max' => 1.5,
                    'step' => 0.1,
                ],
            ],
            [
                'name' => 'output_mode',
                'label' => '输出模式',
                'component' => 'select',
                'defaultValue' => 'auto',
                'options' => [
                    ['label' => '自动', 'value' => 'auto'],
                    ['label' => '结构化', 'value' => 'structured'],
                    ['label' => '文本', 'value' => 'text'],
                ],
                'description' => 'auto：有 schema 且模型支持时优先结构化，否则文本',
            ],
        ];

        $event->register('instruction', [
            'label' => '指令分析',
            'name' => '指令分析',
            'description' => '由大模型分析输入并选择后续节点要调用的工具节点',
            'category' => 'ai',
            'nodeType' => 'process',
            'track_token' => true,
            'icon' => 'i-tabler:list-search',
            'color' => 'info',
            'style' => ['iconBgClass' => 'bg-sky-500'],
            'showOutputPreview' => false,
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type('instruction', ['flow']);
        $event->output('instruction', $outputFields);
        $event->schema('instruction', [
            'type' => 'object',
            'description' => '由 SchemaTreeField 统一配置入参，例如 tools/content',
            'properties' => [
                'tools' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'tool' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                ],
                                'required' => ['tool'],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
                'content' => ['type' => 'string'],
                'output_mode' => ['type' => 'string', 'enum' => ['text', 'structured', 'auto']],
            ],
        ]);
        $event->handler('instruction', new InstructionCapability());
    }
}
