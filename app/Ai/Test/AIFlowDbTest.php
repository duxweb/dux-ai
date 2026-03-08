<?php

use App\Ai\Models\AiFlow;
use App\Ai\Service\AIFlow as AIFlowService;
use Core\Handlers\ExceptionBusiness;

it('流程：可通过 code 解析并排序节点（需 status=true）', function () {
    AiFlow::query()->create([
        'name' => 't',
        'code' => 'flow_a',
        'status' => true,
        'flow' => [
            'nodes' => [
                ['id' => 'a', 'ui' => ['position' => ['x' => 20]]],
                ['id' => 'b', 'ui' => ['position' => ['x' => 10]]],
                ['id' => 'c', 'ui' => ['position' => ['x' => 30]]],
            ],
            'edges' => [
                ['source' => 'a', 'target' => 'c'],
                ['source' => 'b', 'target' => 'c'],
            ],
        ],
        'global_settings' => [],
    ]);

    $ordered = AIFlowService::orderedNodes('flow_a');
    $ids = array_map(static fn (array $n) => (string)($n['id'] ?? ''), $ordered);
    expect($ids)->toBe(['b', 'a', 'c']);
});

it('流程：status=false 时通过 code 解析会报错', function () {
    AiFlow::query()->create([
        'name' => 't',
        'code' => 'flow_off',
        'status' => false,
        'flow' => ['nodes' => [], 'edges' => []],
        'global_settings' => [],
    ]);

    expect(fn () => AIFlowService::orderedNodes('flow_off'))
        ->toThrow(ExceptionBusiness::class, '不存在或未启用');
});
