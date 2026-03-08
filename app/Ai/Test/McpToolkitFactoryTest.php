<?php

use App\Ai\Service\Neuron\Mcp\McpToolkitFactory;

it('MCP 工厂：HTTP 配置固定使用 streamable_http', function () {
    $config = McpToolkitFactory::connectorConfig([
        'server_url' => 'https://example.com/mcp',
        'headers' => [
            ['name' => 'X-Token', 'value' => 'abc'],
        ],
        'token' => 'bearer-token',
        'timeout' => 12,
    ]);

    expect($config)->toBe([
        'url' => 'https://example.com/mcp',
        'async' => false,
        'headers' => ['X-Token' => 'abc'],
        'token' => 'bearer-token',
        'timeout' => 12,
    ]);
});

it('MCP 工厂：SSE 配置会启用 async', function () {
    $config = McpToolkitFactory::connectorConfig([
        'server_url' => 'https://example.com/mcp',
        'transport' => 'sse',
    ]);

    expect($config)->toBe([
        'url' => 'https://example.com/mcp',
        'async' => true,
    ]);
});

it('MCP 工厂：未配置 URL 时抛出异常', function () {
    McpToolkitFactory::connectorConfig([
    ]);
})->throws(\Core\Handlers\ExceptionBusiness::class, '请配置 MCP 服务 URL');

it('MCP 工厂：HTTP 配置会过滤空值字段', function () {
    $config = McpToolkitFactory::connectorConfig([
        'server_url' => 'https://example.com/mcp',
        'headers' => [],
        'token' => '',
    ]);

    expect($config)->toBe([
        'url' => 'https://example.com/mcp',
        'async' => false,
    ]);
});
