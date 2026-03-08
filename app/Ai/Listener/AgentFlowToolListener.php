<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Event\AiCapabilityEvent;
use App\Ai\Models\AiFlow;
use App\Ai\Service\AIFlow as AIFlowService;
use App\Ai\Service\Tool;
use Core\Event\Attribute\Listener;
use Core\Handlers\ExceptionBusiness;

class AgentFlowToolListener
{
    #[Listener(name: 'ai.capability')]
    public function handle(AiCapabilityEvent $event): void
    {
        $flowOptions = AiFlow::query()
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(static fn (AiFlow $flow) => [
                'label' => $flow->name,
                'value' => $flow->id,
                'code' => $flow->code,
            ])
            ->values()
            ->all();

        $event->register('flow_runner', [
            'label' => 'AI 工作流',
            'name' => 'AI 工作流',
            'description' => '执行已配置的工作流',
            'tool' => ['type' => 'flow'],
            'defaults' => [
                'payload' => [],
            ],
            'settings' => [
                [
                    'name' => 'flow_id',
                    'label' => '流程',
                    'component' => 'select',
                    'options' => $flowOptions,
                    'required' => true,
                ],
                [
                    'name' => 'flow_code',
                    'label' => '流程别名',
                    'component' => 'text',
                    'description' => '可选，作为工具调用别名',
                ],
                [
                    'name' => 'payload',
                    'label' => '流程入参（JSON）',
                    'component' => 'textarea',
                    'componentProps' => [
                        'rows' => 3,
                        'placeholder' => '{"foo":"bar"}',
                    ],
                ],
            ],
        ]);
        $event->type('flow_runner', ['agent']);
        $event->output('flow_runner', [
            ['name' => 'summary', 'label' => '摘要', 'type' => 'text'],
            ['name' => 'result', 'label' => '结果', 'type' => 'object'],
        ]);
        $event->handler('flow_runner', static function (array $input, CapabilityContextInterface $context) {
            $flowId = $input['flow_id'] ?? null;
            $flowCode = $input['flow_code'] ?? null;
            if (!$flowId && !$flowCode) {
                throw new ExceptionBusiness('请配置 flow_id 或 flow_code');
            }

            $payload = Tool::preparePayload($input['payload'] ?? [], $input);
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                }
            }

            $result = AIFlowService::execute($flowId ?: (string)$flowCode, is_array($payload) ? $payload : []);
            if (is_array($result)) {
                $result['summary'] = $result['summary'] ?? '流程执行完成';
            } else {
                $result = [
                    'summary' => '流程执行完成',
                    'result' => $result,
                ];
            }
            return $result;
        });
        $event->schema('flow_runner', [
            'type' => 'object',
            'description' => '输入字段：payload（可选，用于覆盖工具默认 payload）',
            'properties' => [
                'payload' => ['type' => 'object'],
            ],
        ]);
    }
}
