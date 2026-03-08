<?php

use App\Ai\Service\Tool;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\Tool\Service as ToolService;
use App\Ai\Support\AiRuntime;

it('占位符：支持 {{input.xxx}} 读取嵌套路径', function () {
    $text = 'hello {{ input.user.name }} / {{input.user.age}} / {{input.missing}}';
    $out = Tool::replacePlaceholders($text, [
        'user' => ['name' => '张三', 'age' => 18],
    ]);

    expect($out)->toBe('hello 张三 / 18 / ');
});

it('占位符：复杂值会 JSON 编码', function () {
    $text = '{{input.user}}';
    $out = Tool::replacePlaceholders($text, [
        'user' => ['name' => '张三', 'tags' => ['a', 'b']],
    ]);
    expect($out)->toBe('{"name":"张三","tags":["a","b"]}');
});

it('payload 预处理：字符串替换后是 JSON 则自动 decode', function () {
    $payload = '{"q":"{{input.q}}","meta":{"a":1}}';
    $out = Tool::preparePayload($payload, ['q' => '你好']);
    expect($out)->toBe(['q' => '你好', 'meta' => ['a' => 1]]);
});

it('payload 预处理：数组递归处理', function () {
    $payload = [
        'q' => '{{input.q}}',
        'arr' => ['{{input.a}}', '{{input.b}}'],
    ];
    $out = Tool::preparePayload($payload, ['q' => 'x', 'a' => 1, 'b' => 2]);
    expect($out)->toBe(['q' => 'x', 'arr' => [1, 2]]);
});

it('工具执行：会合并配置与调用参数，并剔除保留字段', function () {
    Tool::reset();

    $capabilityService = new CapabilityService(AiRuntime::instance());
    $capBooted = new ReflectionProperty($capabilityService, 'booted');
    $capRegistry = new ReflectionProperty($capabilityService, 'registry');
    $capBooted->setValue($capabilityService, true);
    $capRegistry->setValue($capabilityService, [
        'echo' => [
            'code' => 'echo',
            'label' => '回显',
            'types' => ['agent'],
            'handler' => static fn (array $params, $context) => $params,
        ],
    ]);

    Tool::setService(new ToolService($capabilityService));

    $result = Tool::execute('echo', [
        'code' => 'echo',
        'label' => '不会传给 handler',
        'api_key' => 'k',
        'nested' => ['a' => 1],
    ], [
        'q' => 'hi',
        'api_key' => 'override',
    ]);

    expect($result)->toBe([
        'api_key' => 'override',
        'nested' => ['a' => 1],
        'q' => 'hi',
    ]);

    Tool::reset();
});
