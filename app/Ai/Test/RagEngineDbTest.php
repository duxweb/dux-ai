<?php

use App\Ai\Models\RegProvider;
use App\Ai\Models\RagKnowledge;
use App\Ai\Service\RagEngine;

it('RAG：ensureSynced 会补齐 base_id 并强制 is_async=true', function () {
    $cfg = RegProvider::query()->create([
        'name' => 'cfg',
        'code' => 'cfg',
        'provider' => 'neuron',
        'storage_id' => 0,
        'config' => ['x' => 1],
        'vector_id' => 0,
        'embedding_model_id' => 0,
    ]);

    $knowledge = RagKnowledge::query()->create([
        'config_id' => $cfg->id,
        'name' => 'k',
        'base_id' => null,
        'is_async' => false,
        'status' => true,
        'settings' => [],
    ]);

    RagEngine::ensureSynced($knowledge->fresh());

    $updated = $knowledge->fresh();
    expect($updated->base_id)->toBe('neuron:' . $updated->id)
        ->and((bool)$updated->is_async)->toBeTrue();
});
