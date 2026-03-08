<?php

use App\Ai\Models\AiAgent;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\Agent\ToolConfigBuilder;
use App\Ai\Service\Tool;
use App\Ai\Service\Tool\Service as ToolService;
use App\Ai\Support\AiRuntime;

function newAiAgentWithoutCtor(): AiAgent
{
    $ref = new ReflectionClass(AiAgent::class);
    /** @var AiAgent $agent */
    $agent = $ref->newInstanceWithoutConstructor();
    return $agent;
}

function setToolRegistry(array $registry): void
{
    // Build a fake capability registry (agent scope) so ToolService can derive tools.
    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);

    $capabilityItems = [];
    foreach ($registry as $code => $meta) {
        $capabilityItems[$code] = array_merge($meta, [
            'code' => $code,
            'types' => ['agent'],
            'handler' => $meta['handler'] ?? static fn () => null,
        ]);
    }
    $capRegistry->setValue($capabilityService, $capabilityItems);

    Tool::setService(new ToolService($capabilityService));
}

it('工具配置：根据注册信息生成 OpenAI tools defs 与 map，并清洗 function 名称', function () {
    Tool::reset();
    setToolRegistry([
        'search' => [
            'code' => 'search',
            'label' => '搜索',
            'tool' => ['function' => 'Search Tool!!'],
            'schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
        ],
    ]);

    $agent = newAiAgentWithoutCtor();
    $agent->tools = [
        ['code' => 'search', 'description' => 'desc'],
    ];

    $built = ToolConfigBuilder::build($agent);

    expect($built)->toHaveKeys(['defs', 'map'])
        ->and($built['defs'][0]['type'])->toBe('function')
        ->and($built['defs'][0]['function']['name'])->toBe('search_tool')
        ->and($built['map'])->toHaveKey('search_tool')
        ->and($built['map']['search_tool']['code'])->toBe('search')
        ->and($built['map']['search_tool']['description'])->toBe('desc');

    Tool::reset();
});

it('工具配置：保留结构化输出配置供工具运行时使用', function () {
    Tool::reset();
    setToolRegistry([
        'ai_task' => [
            'code' => 'ai_task',
            'label' => '大模型',
            'tool' => ['function' => 'ai_task'],
            'schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
        ],
    ]);

    $agent = newAiAgentWithoutCtor();
    $agent->tools = [
        [
            'code' => 'ai_task',
            'description' => '结构化提取',
            'output_mode' => 'auto',
            'structured_schema' => [
                ['name' => 'name', 'type' => 'string', 'params' => ['required' => true]],
            ],
        ],
    ];

    $built = ToolConfigBuilder::build($agent);

    expect($built['map'])->toHaveKey('ai_task')
        ->and($built['map']['ai_task']['output_mode'])->toBe('auto')
        ->and($built['map']['ai_task']['structured_schema'])->toBeArray()
        ->and($built['map']['ai_task']['structured_schema'][0]['name'] ?? null)->toBe('name');

    Tool::reset();
});
