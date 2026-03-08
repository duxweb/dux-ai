<?php

use App\Ai\Models\AiFlow as AiFlowModel;
use App\Ai\Service\AIFlow;

function makeAiFlowModelForStream(array $flow, string $code = 'bad_flow'): AiFlowModel
{
    $ref = new ReflectionClass(AiFlowModel::class);
    /** @var AiFlowModel $model */
    $model = $ref->newInstanceWithoutConstructor();
    $model->code = $code;
    $model->flow = $flow;
    return $model;
}

function sseJsonPayload(string $chunk): array
{
    $chunk = trim($chunk);
    expect($chunk)->toStartWith('data: ');
    $json = substr($chunk, 6);
    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

it('流式输出：流程 schema 非法时输出 start -> error -> done', function () {
    $model = makeAiFlowModelForStream([
        'schema_version' => 0,
        'engine' => '',
        'nodes' => [],
        'edges' => [],
    ]);

    $chunks = iterator_to_array(AIFlow::stream($model, ['q' => 'x']));

    expect($chunks)->toHaveCount(3);

    $start = sseJsonPayload($chunks[0]);
    expect($start['meta']['event'])->toBe('start')
        ->and($start['meta']['flow'])->toBe('bad_flow')
        ->and($start['meta']['input'])->toBe(['q' => 'x']);

    $error = sseJsonPayload($chunks[1]);
    expect($error['meta']['event'])->toBe('error')
        ->and($error['meta']['flow'])->toBe('bad_flow')
        ->and($error['status'])->toBe(0)
        ->and($error['message'])->toContain('流程未升级');

    expect($chunks[2])->toBe("data: [DONE]\n\n");
});
