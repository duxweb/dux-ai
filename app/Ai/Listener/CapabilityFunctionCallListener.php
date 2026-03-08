<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Event\AiCapabilityEvent;
use App\Ai\Service\FunctionCall;
use App\Ai\Service\Neuron\Flow\StateTemplate;
use App\Ai\Service\Neuron\Flow\WorkflowToolContext;
use Core\Event\Attribute\Listener;
use Core\Handlers\ExceptionBusiness;

class CapabilityFunctionCallListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $code = 'function_task';

        $outputFields = [
            ['name' => 'result', 'label' => '执行结果', 'type' => 'object', 'description' => '函数的返回内容'],
            ['name' => 'function', 'label' => '函数标识', 'type' => 'text', 'description' => '执行的函数 code'],
            ['name' => 'mcp', 'label' => 'MCP 标识（兼容）', 'type' => 'text', 'description' => '兼容旧流程字段'],
        ];

        $defaultConfig = [
            'message_role' => 'system',
            'payload' => [],
        ];
        $settingFields = [
            [
                'name' => 'mcp',
                'label' => '选择函数',
                'required' => true,
                'component' => 'dux-select',
                'componentProps' => [
                    'path' => 'ai/flow/functionOptions',
                    'labelField' => 'label',
                    'valueField' => 'value',
                    'descField' => 'description',
                    'pagination' => false,
                ],
            ],
            [
                'name' => 'payload',
                'label' => '函数入参（JSON）',
                'component' => 'textarea',
                'preview' => false,
                'defaultValue' => '{}',
                'componentProps' => [
                    'rows' => 4,
                    'placeholder' => '{"foo":"bar"}',
                ],
            ],
        ];

        $event->register($code, [
            'label' => '函数调用',
            'name' => '函数调用',
            'description' => '调用函数执行自定义操作',
            'category' => 'integration',
            'nodeType' => 'process',
            'icon' => 'i-tabler:code',
            'color' => 'info',
            'style' => ['iconBgClass' => 'bg-sky-500'],
            'defaults' => $defaultConfig,
            'settings' => $settingFields,
        ]);
        $event->type($code, ['flow']);
        $event->output($code, $outputFields);
        $event->handler($code, static function (array $input, CapabilityContextInterface $context): array {
            $functionCode = trim((string)($input['function'] ?? ($input['mcp'] ?? '')));
            if ($functionCode === '') {
                throw new ExceptionBusiness('函数调用节点必须选择函数');
            }

            $runtime = [];
            if ($context instanceof WorkflowToolContext) {
                $runtime = $context->state();
            }

            $payload = $input['payload'] ?? [];
            if (is_string($payload)) {
                $payload = StateTemplate::render($payload, $runtime);
                $decoded = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                }
            } else {
                $payload = StateTemplate::resolve($payload, $runtime);
            }

            $result = FunctionCall::call($functionCode, [
                'context' => $runtime,
                'inputs' => is_array($payload) ? $payload : ['value' => $payload],
                'config' => $input,
                'node' => [
                    'id' => $context instanceof WorkflowToolContext ? $context->nodeId() : '',
                    'type' => 'function_task',
                ],
            ]);

            return [
                'status' => 1,
                'content' => '',
                'data' => [
                    'result' => $result,
                    'function' => $functionCode,
                    'mcp' => $functionCode,
                ],
                'input' => [
                    'function' => $functionCode,
                    'mcp' => $functionCode,
                    'payload' => $payload,
                ],
                'output' => [
                    'result' => $result,
                ],
            ];
        });
    }
}
