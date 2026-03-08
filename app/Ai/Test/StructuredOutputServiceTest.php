<?php

declare(strict_types=1);

use App\Ai\Service\Neuron\Structured\StructuredOutputService;

it('StructuredOutputService: treeToJsonSchema 可转换基础字段与 required', function () {
    $tree = [
        [
            'name' => 'name',
            'type' => 'string',
            'params' => ['required' => true],
        ],
        [
            'name' => 'age',
            'type' => 'integer',
            'params' => ['required' => false],
        ],
        [
            'name' => 'enabled',
            'type' => 'boolean',
            'params' => ['required' => true],
        ],
    ];

    $schema = StructuredOutputService::treeToJsonSchema($tree);

    expect($schema)->toBeArray()
        ->and($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties']['name']['type'] ?? null)->toBe('string')
        ->and($schema['properties']['age']['type'] ?? null)->toBe('integer')
        ->and($schema['properties']['enabled']['type'] ?? null)->toBe('boolean')
        ->and($schema['required'] ?? [])->toContain('name')
        ->and($schema['required'] ?? [])->toContain('enabled')
        ->and($schema['required'] ?? [])->not->toContain('age');
});

it('StructuredOutputService: treeToJsonSchema 支持对象嵌套与数组', function () {
    $tree = [
        [
            'name' => 'patient',
            'type' => 'object',
            'params' => ['required' => true],
            'children' => [
                ['name' => 'name', 'type' => 'string', 'params' => ['required' => true]],
                ['name' => 'gender', 'type' => 'string', 'params' => ['required' => false]],
            ],
        ],
        [
            'name' => 'abnormal_items',
            'type' => 'array',
            'params' => ['required' => false],
            'children' => [
                ['name' => 'item', 'type' => 'string', 'params' => ['required' => true]],
                ['name' => 'level', 'type' => 'string', 'params' => ['required' => false]],
            ],
        ],
    ];

    $schema = StructuredOutputService::treeToJsonSchema($tree);

    expect($schema['properties']['patient']['type'] ?? null)->toBe('object')
        ->and($schema['properties']['patient']['properties']['name']['type'] ?? null)->toBe('string')
        ->and($schema['properties']['patient']['required'] ?? [])->toContain('name')
        ->and($schema['properties']['abnormal_items']['type'] ?? null)->toBe('array')
        ->and($schema['properties']['abnormal_items']['items']['type'] ?? null)->toBe('object')
        ->and($schema['properties']['abnormal_items']['items']['properties']['item']['type'] ?? null)->toBe('string');
});

