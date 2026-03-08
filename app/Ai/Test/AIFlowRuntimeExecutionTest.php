<?php

use App\Ai\Models\AiFlow;
use App\Ai\Models\AiFlowLog;
use App\Ai\Service\AIFlow\Service as AIFlowService;
use App\Ai\Service\Capability;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\Neuron\Flow\FlowSchemaPayloadBuilder;
use App\Ai\Support\AiRuntime;

/**
 * @param array<string, array<string, mixed>> $registry
 */
function mockCapabilities(array $registry): void
{
    $service = new CapabilityService(AiRuntime::instance());
    $booted = new ReflectionProperty($service, 'booted');
    $entries = new ReflectionProperty($service, 'registry');
    $booted->setValue($service, true);
    $entries->setValue($service, $registry);
    Capability::setService($service);
}

it('Flow runtime：支持 retry + timeout 透传', function () {
    $attempts = 0;
    mockCapabilities([
        'ai_start' => [
            'code' => 'ai_start',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context) => ['status' => 1, 'data' => ['ok' => true]],
        ],
        'task_retry' => [
            'code' => 'task_retry',
            'types' => ['flow'],
            'handler' => static function (array $input, mixed $context) use (&$attempts): array {
                $attempts++;
                if ($attempts === 1) {
                    return ['status' => 0, 'message' => 'first failed', 'data' => null];
                }

                return [
                    'status' => 1,
                    'data' => [
                        'attempts' => $attempts,
                        'timeout_ms' => $input['timeout_ms'] ?? null,
                    ],
                ];
            },
        ],
        'ai_end' => [
            'code' => 'ai_end',
            'types' => ['flow'],
            'handler' => static function (array $input, mixed $context): array {
                $state = method_exists($context, 'state') ? (array)$context->state() : [];
                $last = is_array($state['last'] ?? null) ? ($state['last'] ?? []) : [];
                return ['status' => 1, 'data' => ['result' => $last]];
            },
        ],
    ]);

    $flow = AiFlow::query()->create([
        'name' => 'flow-v3-retry',
        'code' => 'flow_v3_retry',
        'status' => true,
        'flow' => [
            'schema_version' => 1,
            'engine' => 'neuron-ai',
            'nodes' => [
                ['id' => 'start', 'type' => 'ai_start', 'name' => '开始', 'config' => []],
                ['id' => 'task', 'type' => 'task_retry', 'name' => '任务', 'config' => [
                    'retry' => ['max_attempts' => 2],
                    'timeout_ms' => 3210,
                ]],
                ['id' => 'end', 'type' => 'ai_end', 'name' => '结束', 'config' => []],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'task'],
                ['source' => 'task', 'target' => 'end'],
            ],
        ],
        'global_settings' => [],
    ]);

    $service = new AIFlowService();
    $result = $service->execute((string)$flow->code, ['foo' => 'bar']);

    expect($attempts)->toBe(2)
        ->and($result['status'])->toBe(1)
        ->and($result['data']['result']['attempts'])->toBe(2)
        ->and($result['data']['result']['timeout_ms'])->toBe(3210);

    $log = AiFlowLog::query()->where('flow_id', $flow->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log?->status)->toBe(1);

    Capability::reset();
});

it('Flow runtime：track_token 节点会累计并落库 token', function () {
    mockCapabilities([
        'ai_start' => [
            'code' => 'ai_start',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context) => ['status' => 1, 'data' => ['ok' => true]],
        ],
        'ai_task' => [
            'code' => 'ai_task',
            'types' => ['flow'],
            'track_token' => true,
            'handler' => static fn (array $input, mixed $context): array => [
                'status' => 1,
                'data' => [
                    'content' => 'ok',
                    'usage' => [
                        'input_tokens' => 7,
                        'output_tokens' => 3,
                    ],
                ],
            ],
        ],
        'ai_end' => [
            'code' => 'ai_end',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context): array => ['status' => 1, 'data' => ['result' => $input]],
        ],
    ]);

    $flow = AiFlow::query()->create([
        'name' => 'flow-v3-usage',
        'code' => 'flow_v3_usage',
        'status' => true,
        'flow' => [
            'schema_version' => 1,
            'engine' => 'neuron-ai',
            'nodes' => [
                ['id' => 'start', 'type' => 'ai_start', 'name' => '开始', 'config' => []],
                ['id' => 'task', 'type' => 'ai_task', 'name' => 'LLM', 'config' => []],
                ['id' => 'end', 'type' => 'ai_end', 'name' => '结束', 'config' => []],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'task'],
                ['source' => 'task', 'target' => 'end'],
            ],
        ],
        'global_settings' => [],
    ]);

    $service = new AIFlowService();
    $result = $service->execute((string)$flow->code, ['foo' => 'bar']);

    expect($result['status'])->toBe(1);

    $log = AiFlowLog::query()->where('flow_id', $flow->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and((int)$log?->prompt_tokens)->toBe(7)
        ->and((int)$log?->completion_tokens)->toBe(3)
        ->and((int)$log?->total_tokens)->toBe(10);

    Capability::reset();
});

it('Flow runtime：结束节点支持 output_schema 结构化输出', function () {
    mockCapabilities([
        'ai_start' => [
            'code' => 'ai_start',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context) => ['status' => 1, 'data' => ['ok' => true]],
        ],
        'task_data' => [
            'code' => 'task_data',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context): array => [
                'status' => 1,
                'data' => [
                    'title' => '报告A',
                    'score' => 95,
                ],
            ],
        ],
        'ai_end' => [
            'code' => 'ai_end',
            'types' => ['flow'],
            'handler' => static function (array $input, mixed $context): array {
                $state = method_exists($context, 'state') ? (array)$context->state() : [];
                $schema = is_array($input['output_schema'] ?? null) ? ($input['output_schema'] ?? []) : [];
                $built = FlowSchemaPayloadBuilder::buildWithValidation($schema, $state);
                if ($built['missing'] !== []) {
                    return [
                        'status' => 0,
                        'message' => sprintf('missing: %s', implode(',', $built['missing'])),
                        'data' => null,
                    ];
                }

                return ['status' => 1, 'data' => $built['payload']];
            },
        ],
    ]);

    $flow = AiFlow::query()->create([
        'name' => 'flow-v3-end-schema-ok',
        'code' => 'flow_v3_end_schema_ok',
        'status' => true,
        'flow' => [
            'schema_version' => 1,
            'engine' => 'neuron-ai',
            'nodes' => [
                ['id' => 'start', 'type' => 'ai_start', 'name' => '开始', 'config' => []],
                ['id' => 'task', 'type' => 'task_data', 'name' => '任务', 'config' => []],
                ['id' => 'end', 'type' => 'ai_end', 'name' => '结束', 'config' => [
                    'output_schema' => [
                        ['name' => 'title', 'type' => 'string', 'params' => ['required' => true, 'default' => '{{input.title}}']],
                        ['name' => 'score', 'type' => 'number', 'params' => ['required' => true, 'default' => '{{input.score}}']],
                        ['name' => 'meta', 'type' => 'object', 'children' => [
                            ['name' => 'source', 'type' => 'string', 'params' => ['required' => true, 'default' => '{{origin.source}}']],
                        ]],
                    ],
                ]],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'task'],
                ['source' => 'task', 'target' => 'end'],
            ],
        ],
        'global_settings' => [],
    ]);

    $service = new AIFlowService();
    $result = $service->execute((string)$flow->code, ['source' => 'manual']);

    expect($result['status'])->toBe(1)
        ->and($result['data'])->toBe([
            'title' => '报告A',
            'score' => 95,
            'meta' => [
                'source' => 'manual',
            ],
        ]);

    Capability::reset();
});

it('Flow runtime：结束节点 output_schema 必填缺失时失败', function () {
    mockCapabilities([
        'ai_start' => [
            'code' => 'ai_start',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context) => ['status' => 1, 'data' => ['ok' => true]],
        ],
        'task_data' => [
            'code' => 'task_data',
            'types' => ['flow'],
            'handler' => static fn (array $input, mixed $context): array => [
                'status' => 1,
                'data' => [
                    'title' => '',
                ],
            ],
        ],
        'ai_end' => [
            'code' => 'ai_end',
            'types' => ['flow'],
            'handler' => static function (array $input, mixed $context): array {
                $state = method_exists($context, 'state') ? (array)$context->state() : [];
                $schema = is_array($input['output_schema'] ?? null) ? ($input['output_schema'] ?? []) : [];
                $built = FlowSchemaPayloadBuilder::buildWithValidation($schema, $state);
                if ($built['missing'] !== []) {
                    return [
                        'status' => 0,
                        'message' => sprintf('missing: %s', implode(',', $built['missing'])),
                        'data' => null,
                    ];
                }

                return ['status' => 1, 'data' => $built['payload']];
            },
        ],
    ]);

    $flow = AiFlow::query()->create([
        'name' => 'flow-v3-end-schema-missing',
        'code' => 'flow_v3_end_schema_missing',
        'status' => true,
        'flow' => [
            'schema_version' => 1,
            'engine' => 'neuron-ai',
            'nodes' => [
                ['id' => 'start', 'type' => 'ai_start', 'name' => '开始', 'config' => []],
                ['id' => 'task', 'type' => 'task_data', 'name' => '任务', 'config' => []],
                ['id' => 'end', 'type' => 'ai_end', 'name' => '结束', 'config' => [
                    'output_schema' => [
                        ['name' => 'title', 'type' => 'string', 'params' => ['required' => true, 'default' => '{{input.title}}']],
                    ],
                ]],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'task'],
                ['source' => 'task', 'target' => 'end'],
            ],
        ],
        'global_settings' => [],
    ]);

    $service = new AIFlowService();
    $result = $service->execute((string)$flow->code, []);

    expect($result['status'])->toBe(0)
        ->and((string)$result['message'])->toContain('missing: title');

    Capability::reset();
});
