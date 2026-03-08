<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Event\AiCapabilityEvent;
use App\Ai\Service\Neuron\Flow\WorkflowToolContext;
use Core\Event\Attribute\Listener;

class CapabilityStartListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'ai_start';

        $inputFieldTypeOptions = [
            ['label' => '文本', 'value' => 'text'],
            ['label' => '文本框', 'value' => 'textarea'],
            ['label' => '数字', 'value' => 'number'],
            ['label' => '布尔', 'value' => 'boolean'],
            ['label' => '日期', 'value' => 'date'],
            ['label' => 'JSON', 'value' => 'json'],
            ['label' => '图片', 'value' => 'image'],
            ['label' => '多图片', 'value' => 'images'],
            ['label' => '文件', 'value' => 'file'],
            ['label' => '多文件', 'value' => 'files'],
        ];
        $defaultFields = [
            [
                'name' => 'input',
                'label' => '用户输入',
                'type' => 'text',
                'description' => '',
                'content' => '',
                'required' => true,
            ],
        ];

        $defaultConfig = [
            'message_role' => 'user',
            'fields' => [
                'items' => $defaultFields,
            ],
        ];
        $settingFields = [
            [
                'name' => 'fields',
                'label' => '输入字段配置',
                'component' => 'field-config',
                'defaultValue' => [
                    'items' => $defaultFields,
                ],
                'componentProps' => [
                    'fieldTypeOptions' => $inputFieldTypeOptions,
                    'textPlaceholder' => '可填写默认值，支持 {{env.xxx}} 变量',
                    'jsonDescription' => '字段用于生成执行表单，至少保留一个字段',
                    'showLabelInput' => true,
                    'labelPlaceholder' => '字段展示名称（默认同字段名）',
                    'showRequiredSwitch' => true,
                    'requiredLabel' => '是否必填',
                ],
                'preview' => false,
            ],
        ];

        $event->register($code, [
            'label' => '开始节点',
            'name' => '开始节点',
            'description' => '流程入口，配置可引用的输入字段',
            'category' => 'start',
            'nodeType' => 'start',
            'icon' => 'i-tabler:player-play',
            'color' => 'success',
            'style' => ['iconBgClass' => 'bg-green-500'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow']);
        $event->output($code, [
            ['name' => 'content', 'label' => '输入文本', 'type' => 'text', 'description' => '从输入中提取的文本内容'],
            ['name' => 'input', 'label' => '输入对象', 'type' => 'object', 'description' => '流程开始节点接收到的全部入参'],
        ]);
        $event->handler($code, static function (array $input, CapabilityContextInterface $context): array {
            $payload = $input;
            if ($context instanceof WorkflowToolContext) {
                $state = $context->state();
                if (isset($state['input']) && is_array($state['input'])) {
                    $payload = $state['input'];
                }
            }
            $content = self::buildContentFromInput($payload);

            return [
                'status' => 1,
                'content' => $content,
                'data' => $payload,
                'input' => $payload,
                'output' => $payload,
            ];
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function buildContentFromInput(array $input): string
    {
        if (isset($input['content']) && is_string($input['content'])) {
            return $input['content'];
        }

        if (isset($input['message']) && is_string($input['message'])) {
            return $input['message'];
        }

        if (isset($input['text']) && is_string($input['text'])) {
            return $input['text'];
        }

        if (isset($input['input']) && (is_string($input['input']) || is_scalar($input['input']))) {
            return (string)$input['input'];
        }

        if (isset($input['value']) && (is_string($input['value']) || is_scalar($input['value']))) {
            return (string)$input['value'];
        }

        try {
            $encoded = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return $encoded !== false ? $encoded : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
