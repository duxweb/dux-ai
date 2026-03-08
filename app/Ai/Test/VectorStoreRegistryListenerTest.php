<?php

use App\Ai\Event\VectorStoreEvent;
use App\Ai\Listener\VectorStoreRegistryListener;

it('向量库驱动：VectorStoreRegistryListener 注册包含 redis 与 mongodb', function () {
    $event = new VectorStoreEvent();
    (new VectorStoreRegistryListener())->handle($event);

    $meta = $event->getMeta();

    expect($meta)->toHaveKey('redis')
        ->and($meta['redis']['value'])->toBe('redis')
        ->and($meta)->toHaveKey('mongodb')
        ->and($meta['mongodb']['value'])->toBe('mongodb');
});

