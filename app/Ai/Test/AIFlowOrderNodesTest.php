<?php

use App\Ai\Models\AiFlow as AiFlowModel;
use App\Ai\Service\AIFlow;

function makeAiFlowModel(array $flow, string $code = 't'): AiFlowModel
{
    $ref = new ReflectionClass(AiFlowModel::class);
    /** @var AiFlowModel $model */
    $model = $ref->newInstanceWithoutConstructor();
    $model->code = $code;
    $model->flow = $flow;
    return $model;
}

it('节点排序：没有有效连线时返回空数组', function () {
    $model = makeAiFlowModel([
        'nodes' => [
            ['id' => 'a', 'ui' => ['position' => ['x' => 10]]],
            ['id' => 'b', 'ui' => ['position' => ['x' => 20]]],
        ],
        'edges' => [],
    ]);

    expect(AIFlow::orderedNodes($model))->toBe([]);
});

it('节点排序：按拓扑顺序，并用 order/position.x 作为稳定排序', function () {
    $model = makeAiFlowModel([
        'nodes' => [
            ['id' => 'a', 'ui' => ['position' => ['x' => 20]]],
            ['id' => 'b', 'ui' => ['position' => ['x' => 10]]],
            ['id' => 'c', 'ui' => ['position' => ['x' => 30]]],
            ['id' => 'd', 'ui' => ['position' => ['x' => 40]]], // disconnected -> dropped
        ],
        'edges' => [
            ['source' => 'a', 'target' => 'c'],
            ['source' => 'b', 'target' => 'c'],
        ],
    ]);

    $ordered = AIFlow::orderedNodes($model);
    $ids = array_map(static fn (array $n) => (string)($n['id'] ?? ''), $ordered);

    expect($ids)->toBe(['b', 'a', 'c']);
});

it('节点排序：存在环时仍保留节点，并按 order 回退排序', function () {
    $model = makeAiFlowModel([
        'nodes' => [
            ['id' => 'a', 'ui' => ['position' => ['x' => 20]]],
            ['id' => 'b', 'ui' => ['position' => ['x' => 10]]],
        ],
        'edges' => [
            ['source' => 'a', 'target' => 'b'],
            ['source' => 'b', 'target' => 'a'],
        ],
    ]);

    $ordered = AIFlow::orderedNodes($model);
    $ids = array_map(static fn (array $n) => (string)($n['id'] ?? ''), $ordered);

    expect($ids)->toBe(['b', 'a']);
});
