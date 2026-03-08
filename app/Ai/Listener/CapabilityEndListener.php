<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Event\AiCapabilityEvent;
use App\Ai\Service\Neuron\Flow\FlowSchemaPayloadBuilder;
use App\Ai\Service\Neuron\Flow\WorkflowToolContext;
use Core\Event\Attribute\Listener;

class CapabilityEndListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'ai_end';

        $defaultConfig = [
            'message_role' => 'assistant',
            'output_schema' => [],
        ];
        $settingFields = [
            [
                'name' => 'output_schema',
                'label' => '输出配置',
                'component' => 'schema-tree',
                'preview' => false,
                'defaultValue' => [],
                'description' => '通过 Schema 配置最终输出字段，支持 {{input.xxx}} / {{nodes.xxx}} / {{env.xxx}} 模板引用',
            ],
        ];

        $event->register($code, [
            'label' => '结束节点',
            'name' => '结束节点',
            'description' => '配置最终输出字段，结果将写入流程输出',
            'category' => 'end',
            'nodeType' => 'end',
            'icon' => 'i-tabler:flag',
            'color' => 'warning',
            'style' => ['iconBgClass' => 'bg-amber-500'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow']);
        $event->output($code, [
            ['name' => 'value', 'label' => '结束输出', 'type' => 'object', 'description' => '按 output_schema 构造的最终输出对象'],
            ['name' => 'content', 'label' => '输出文本', 'type' => 'text', 'description' => '结束输出的文本化内容'],
        ]);
        $event->handler($code, static function (array $input, CapabilityContextInterface $context): array {
            $runtime = [];
            if ($context instanceof WorkflowToolContext) {
                $runtime = $context->state();
            }
            $outputSchema = is_array($input['output_schema'] ?? null) ? ($input['output_schema'] ?? []) : null;
            if ($outputSchema === null) {
                return [
                    'status' => 0,
                    'message' => '结束节点输出配置格式无效',
                    'data' => null,
                    'input' => [
                        'output_schema' => $input['output_schema'] ?? null,
                    ],
                ];
            }

            if ($outputSchema === []) {
                return [
                    'status' => 0,
                    'message' => '结束节点未配置输出字段',
                    'data' => null,
                    'input' => [
                        'output_schema' => [],
                    ],
                ];
            }

            $built = FlowSchemaPayloadBuilder::buildWithValidation($outputSchema, $runtime);
            $value = $built['payload'];
            $missing = $built['missing'];

            if ($missing !== []) {
                return [
                    'status' => 0,
                    'message' => sprintf('结束节点缺少必填输出字段：%s', implode('，', $missing)),
                    'data' => null,
                    'input' => [
                        'output_schema' => $outputSchema,
                    ],
                ];
            }

            $content = self::buildContentFromValue($value);

            return [
                'status' => 1,
                'content' => $content,
                'data' => $value,
                'input' => [
                    'output_schema' => $outputSchema,
                ],
                'output' => $value,
            ];
        });
    }

    private static function buildContentFromValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            if (array_key_exists('value', $value) && is_scalar($value['value'])) {
                return (string)$value['value'];
            }

            try {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return $encoded !== false ? $encoded : '';
            } catch (\Throwable) {
                return '';
            }
        }

        if ($value === null) {
            return '';
        }

        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        } catch (\Throwable) {
            return '';
        }
    }
}
