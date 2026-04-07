<?php

use App\Ai\Service\Agent\HistoryBuilder;

it('历史转换：stringifyMessageContent 支持 parts JSON 字符串', function () {
    $content = json_encode([
        ['type' => 'text', 'text' => '第一段'],
        ['type' => 'text', 'text' => '第二段'],
    ], JSON_UNESCAPED_UNICODE);

    expect(HistoryBuilder::stringifyMessageContent($content))->toBe("第一段\n第二段");
});

it('历史转换：过滤错误占位 assistant 消息', function () {
    $history = [
        ['role' => 'assistant', 'content' => 'should skip', 'payload' => ['error' => true]],
        ['role' => 'assistant', 'content' => 'ok', 'payload' => []],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, true, true);
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['role'])->toBe('assistant')
        ->and($messages[0]['content'])->toBe('ok');
});

it('历史转换：用户 parts 会按 supportImage/supportFile 过滤附件', function () {
    $history = [
        [
            'role' => 'user',
            'content' => 'ignored when parts present',
            'payload' => [
                'parts' => [
                    ['type' => 'text', 'text' => 'hi'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://img']],
                    ['type' => 'file_url', 'file_url' => ['url' => 'https://file']],
                ],
            ],
        ],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, false, true);
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['role'])->toBe('user')
        ->and($messages[0]['content'])->toBe([
            ['type' => 'text', 'text' => 'hi'],
            ['type' => 'file_url', 'file_url' => ['url' => 'https://file']],
        ]);
});

it('历史转换：assistant tool_calls 会强制 content 为空字符串', function () {
    $history = [
        [
            'role' => 'assistant',
            'content' => 'ignored',
            'payload' => [
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']],
                ],
            ],
        ],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, true, true);
    expect($messages[0]['content'])->toBe('')
        ->and($messages[0])->toHaveKey('tool_calls');
});

it('历史转换：tool 消息必须包含 tool_call_id，并且 content 会序列化为字符串', function () {
    $history = [
        [
            'role' => 'assistant',
            'content' => '',
            'payload' => [
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']],
                ],
            ],
        ],
        [
            'role' => 'tool',
            'content' => ['ok' => true],
            'payload' => ['raw' => ['ok' => true]],
            'tool_call_id' => 'call_1',
        ],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, true, true);
    expect($messages)->toHaveCount(2)
        ->and($messages[1]['role'])->toBe('tool')
        ->and($messages[1]['tool_call_id'])->toBe('call_1')
        ->and($messages[1]['content'])->toBe('{"ok":true}');
});

it('历史转换：tool raw 中含 data.type 也必须保留到上下文', function () {
    $history = [
        [
            'role' => 'assistant',
            'content' => '',
            'payload' => [
                'tool_calls' => [
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'image_generate', 'arguments' => '{}']],
                ],
            ],
        ],
        [
            'role' => 'tool',
            'content' => '已生成图片 1 张',
            'payload' => [
                'raw' => [
                    'data' => [
                        'type' => 'image',
                        'url' => 'https://example.com/a.jpg',
                    ],
                    'summary' => '已生成图片 1 张',
                ],
            ],
            'tool_call_id' => 'call_2',
        ],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, true, true);
    expect($messages)->toHaveCount(2)
        ->and($messages[1]['role'])->toBe('tool')
        ->and($messages[1]['tool_call_id'])->toBe('call_2')
        ->and($messages[1]['content'])->toBe('{"data":{"type":"image","url":"https://example.com/a.jpg"},"summary":"已生成图片 1 张"}');
});

it('历史转换：会折叠重复连续 user 并移除孤立 tool', function () {
    $history = [
        [
            'role' => 'user',
            'content' => '重复问题',
            'payload' => ['parts' => [['type' => 'text', 'text' => '重复问题']]],
        ],
        [
            'role' => 'user',
            'content' => '重复问题',
            'payload' => ['parts' => [['type' => 'text', 'text' => '重复问题']]],
        ],
        [
            'role' => 'tool',
            'content' => '孤立工具结果',
            'tool_call_id' => 'call_orphan',
            'payload' => ['raw' => ['ok' => true]],
        ],
        [
            'role' => 'assistant',
            'content' => '',
            'payload' => [
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']],
                ],
            ],
        ],
        [
            'role' => 'tool',
            'content' => ['ok' => true],
            'tool_call_id' => 'call_1',
            'payload' => ['raw' => ['ok' => true]],
        ],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, true, true);

    expect($messages)->toHaveCount(3)
        ->and($messages[0]['role'])->toBe('user')
        ->and($messages[0]['content'])->toBe([
            ['type' => 'text', 'text' => '重复问题'],
        ])
        ->and($messages[1]['role'])->toBe('assistant')
        ->and($messages[2]['role'])->toBe('tool')
        ->and($messages[2]['tool_call_id'])->toBe('call_1');
});

it('历史转换：不同内容的连续 user 必须保留', function () {
    $history = [
        [
            'role' => 'user',
            'content' => '第一个问题',
            'payload' => ['parts' => [['type' => 'text', 'text' => '第一个问题']]],
        ],
        [
            'role' => 'user',
            'content' => '第二个问题',
            'payload' => ['parts' => [['type' => 'text', 'text' => '第二个问题']]],
        ],
    ];

    $messages = HistoryBuilder::buildOpenAIMessagesFromHistory($history, true, true);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['content'])->toBe([
            ['type' => 'text', 'text' => '第一个问题'],
        ])
        ->and($messages[1]['content'])->toBe([
            ['type' => 'text', 'text' => '第二个问题'],
        ]);
});
