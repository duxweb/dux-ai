<?php

use App\Ai\Service\AIFlow;
use App\Ai\Service\AIFlow\Service as AIFlowService;
use App\Ai\Service\Capability;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Support\AiRuntime;

it('流程编辑器：可从 Capability(flow) 生成节点定义', function () {
    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);
    $capRegistry->setValue($capabilityService, [
        'mcp_call' => [
            'code' => 'mcp_call',
            'label' => 'MCP 调用',
            'description' => '调用 MCP 工具',
            'types' => ['flow'],
            'category' => 'integration',
            'nodeType' => 'process',
            'icon' => 'i-tabler:plug',
            'color' => 'primary',
            'defaults' => ['a' => 1],
            'settings' => [
                ['name' => 'url', 'label' => 'URL', 'component' => 'input'],
            ],
            'schema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]],
            'output' => [
                'fields' => [
                    ['name' => 'data', 'type' => 'object', 'label' => '返回数据'],
                ],
                'desc' => 'data（返回数据）',
            ],
            'handler' => static fn () => null,
        ],
    ]);

    Capability::setService($capabilityService);
    AIFlow::setService(new AIFlowService());

    $nodes = AIFlow::getEditorNodes();

    expect($nodes)->toBeArray()->and($nodes)->not->toBeEmpty();

    $node = collect($nodes)->firstWhere('type', 'mcp_call');
    expect($node)->toBeArray()
        ->and($node['label'])->toBe('MCP 调用')
        ->and($node['description'])->toBe('调用 MCP 工具')
        ->and($node['category'])->toBe('integration')
        ->and($node['nodeType'])->toBe('process')
        ->and($node['icon'])->toBe('i-tabler:plug')
        ->and($node['color'])->toBe('primary')
        ->and($node['defaultConfig'])->toBe(['a' => 1])
        ->and($node['settingFields'])->toBe([
            ['name' => 'url', 'label' => 'URL', 'component' => 'input'],
        ])
        ->and($node['schema'])->toBe(['type' => 'object', 'properties' => ['url' => ['type' => 'string']]])
        ->and($node['output'])->toBe([
            'fields' => [
                ['name' => 'data', 'type' => 'object', 'label' => '返回数据'],
            ],
            'desc' => 'data（返回数据）',
        ]);

    Capability::reset();
});

it('流程编辑器：结束节点使用 output_schema 配置且移除旧 output 模板', function () {
    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);
    $capRegistry->setValue($capabilityService, [
        'ai_end' => [
            'code' => 'ai_end',
            'label' => '结束节点',
            'description' => '结束',
            'types' => ['flow'],
            'category' => 'end',
            'nodeType' => 'end',
            'settings' => [
                ['name' => 'output_schema', 'label' => '输出配置', 'component' => 'json'],
            ],
            'handler' => static fn () => null,
        ],
    ]);

    Capability::setService($capabilityService);
    AIFlow::setService(new AIFlowService());
    $nodes = AIFlow::getEditorNodes();
    $endNode = collect($nodes)->firstWhere('type', 'ai_end');

    expect($endNode)->toBeArray();
    $fields = is_array($endNode['settingFields'] ?? null) ? ($endNode['settingFields'] ?? []) : [];
    $fieldNames = array_map(static fn (array $field): string => (string)($field['name'] ?? ''), $fields);

    expect($fieldNames)->toContain('output_schema')
        ->and($fieldNames)->not->toContain('output');

    Capability::reset();
});
