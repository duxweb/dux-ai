<?php

use App\Ai\Models\AiAgent;
use App\Ai\Service\Agent\IncomingMessageHandler;
use App\Ai\Service\Agent\MessageStore;
use App\Ai\Service\Agent\SessionManager;

it('消息：appendMessage 会写入消息，并更新会话 last_message_at', function () {
    $agent = AiAgent::query()->create(['name' => 'A', 'code' => 'a', 'active' => true]);
    $sessionId = SessionManager::ensureSessionId($agent);

    expect(\App\Ai\Models\AiAgentSession::query()->find($sessionId)?->last_message_at)->toBeNull();

    $msg = MessageStore::appendMessage($agent->id, $sessionId, 'user', 'hi', ['x' => 1]);
    expect($msg->id)->toBeInt()
        ->and($msg->content)->toBe('hi')
        ->and(\App\Ai\Models\AiAgentSession::query()->find($sessionId)?->last_message_at)->not->toBeNull();
});

it('消息：persistAssistantMessage 内容/负载都为空会删除占位消息', function () {
    $agent = AiAgent::query()->create(['name' => 'A', 'code' => 'a', 'active' => true]);
    $sessionId = SessionManager::ensureSessionId($agent);

    $msg = MessageStore::appendMessage($agent->id, $sessionId, 'assistant', 'temp', []);
    expect(\App\Ai\Models\AiAgentMessage::query()->count())->toBe(1);

    MessageStore::persistAssistantMessage((int)$msg->id, '', []);
    expect(\App\Ai\Models\AiAgentMessage::query()->count())->toBe(0);
});

it('消息：IncomingMessageHandler 会提取最后一条 user 并入库', function () {
    $agent = AiAgent::query()->create(['name' => 'A', 'code' => 'a', 'active' => true]);
    $sessionId = SessionManager::ensureSessionId($agent);

    $messages = [
        ['role' => 'user', 'content' => 'old'],
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => '最新文本'],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://img']],
            ],
        ],
    ];

    $result = IncomingMessageHandler::appendLatestUserMessage($agent, $sessionId, $messages);
    expect($result['user_text'])->toBe('最新文本');

    $stored = \App\Ai\Models\AiAgentMessage::query()->orderByDesc('id')->first();
    expect($stored?->role)->toBe('user')
        ->and($stored?->content)->toBe('最新文本')
        ->and($stored?->payload)->toHaveKey('parts');
});

