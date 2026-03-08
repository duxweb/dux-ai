<?php

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentMessage;
use App\Ai\Models\AiAgentSession;
use App\Ai\Service\Agent\BotBridgeService;
use App\Ai\Test\Support\Migrate\AiMigrateProvider;
use App\Ai\Test\Support\Migrate\BootMigrateProvider;
use App\System\Test\Support\Migrate\SystemMigrateProvider;
use App\System\Test\Support\TestApp;
use App\Boot\Models\BootBot;
use App\Boot\Models\BootMessageLog;

beforeEach(function () {
    TestApp::setMigrateProviders([
        SystemMigrateProvider::class,
        AiMigrateProvider::class,
        BootMigrateProvider::class,
    ]);
    TestApp::refreshDatabase();
});

it('机器人回写：assistant 错误占位消息不会转发到机器人通道', function () {
    $bot = BootBot::query()->create([
        'name' => '企微',
        'code' => 'wecom_test_bot',
        'platform' => 'wecom',
        'enabled' => true,
        'config' => [],
    ]);
    $agent = AiAgent::query()->create([
        'name' => '助手',
        'code' => 'agent_relay_test',
        'active' => true,
    ]);
    $session = AiAgentSession::query()->create([
        'agent_id' => (int)$agent->id,
        'title' => 's',
        'external_id' => 'wecom_test_bot:u1',
        'user_type' => 'boot_bot',
        'user_id' => (int)$bot->id,
        'state' => [
            'bridge' => [
                'bot_code' => 'wecom_test_bot',
                'platform' => 'wecom',
                'conversation_id' => 'u1',
            ],
        ],
        'active' => true,
    ]);

    $errorMessage = AiAgentMessage::query()->create([
        'agent_id' => (int)$agent->id,
        'session_id' => (int)$session->id,
        'role' => 'assistant',
        'content' => 'Network error during POST chat/completions: ...',
        'payload' => ['error' => true],
    ]);

    (new BotBridgeService())->relayAssistantMessage($errorMessage);
    expect(BootMessageLog::query()->count())->toBe(0);

    $normalMessage = AiAgentMessage::query()->create([
        'agent_id' => (int)$agent->id,
        'session_id' => (int)$session->id,
        'role' => 'assistant',
        'content' => '普通回复',
        'payload' => [],
    ]);

    (new BotBridgeService())->relayAssistantMessage($normalMessage);
    expect(BootMessageLog::query()->count())->toBe(1)
        ->and(BootMessageLog::query()->value('direction'))->toBe('outbound')
        ->and(BootMessageLog::query()->value('status'))->toBe('fail');
});

it('机器人回写：视频结果使用 video 消息类型转发', function () {
    $bot = BootBot::query()->create([
        'name' => '企微',
        'code' => 'wecom_video_test_bot',
        'platform' => 'wecom',
        'enabled' => true,
        'config' => [],
    ]);
    $agent = AiAgent::query()->create([
        'name' => '助手',
        'code' => 'agent_relay_video_test',
        'active' => true,
    ]);
    $session = AiAgentSession::query()->create([
        'agent_id' => (int)$agent->id,
        'title' => 's',
        'external_id' => 'wecom_video_test_bot:u1',
        'user_type' => 'boot_bot',
        'user_id' => (int)$bot->id,
        'state' => [
            'bridge' => [
                'bot_code' => 'wecom_video_test_bot',
                'platform' => 'wecom',
                'conversation_id' => 'u1',
            ],
        ],
        'active' => true,
    ]);

    $videoUrl = 'https://example.com/video.mp4';
    $videoMessage = AiAgentMessage::query()->create([
        'agent_id' => (int)$agent->id,
        'session_id' => (int)$session->id,
        'role' => 'assistant',
        'content' => "视频任务已完成\n{$videoUrl}",
        'payload' => [
            'parts' => [
                ['type' => 'text', 'text' => '视频任务已完成'],
                ['type' => 'video_url', 'video_url' => ['url' => $videoUrl]],
            ],
        ],
    ]);

    (new BotBridgeService())->relayAssistantMessage($videoMessage);

    $log = BootMessageLog::query()->first();
    expect($log)->not()->toBeNull()
        ->and((string)$log->direction)->toBe('outbound')
        ->and((string)$log->message_type)->toBe('video')
        ->and((string)$log->status)->toBe('fail');
});


it('机器人桥接：未绑定智能体时非企微渠道返回兜底消息', function () {
    $bot = BootBot::query()->create([
        'name' => '钉钉',
        'code' => 'dingtalk_unbound_bot',
        'platform' => 'dingtalk',
        'enabled' => true,
        'config' => [],
    ]);

    $message = new \App\Boot\Service\DTO\InboundMessage(
        platform: 'dingtalk',
        eventId: 'evt_1',
        conversationId: 'conv_1',
        senderId: 'u_1',
        senderName: 'Tester',
        text: '你好',
        timestamp: time(),
        raw: [],
    );

    $reply = (new BotBridgeService())->handleInbound($bot, $message);

    expect($reply)->toBe([
        'mode' => BotBridgeService::REPLY_MODE_REPLY_TEXT,
        'text' => '系统已收到您的消息',
    ]);
});

it('机器人桥接：未绑定智能体时企微渠道同样返回兜底消息', function () {
    $bot = BootBot::query()->create([
        'name' => '企微',
        'code' => 'wecom_unbound_bot',
        'platform' => 'wecom',
        'enabled' => true,
        'config' => [],
    ]);

    $message = new \App\Boot\Service\DTO\InboundMessage(
        platform: 'wecom',
        eventId: 'evt_wecom_1',
        conversationId: 'conv_wecom_1',
        senderId: 'u_wecom_1',
        senderName: 'Tester',
        text: '你好',
        timestamp: time(),
        raw: [],
    );

    $reply = (new BotBridgeService())->handleInbound($bot, $message);

    expect($reply)->toBe([
        'mode' => BotBridgeService::REPLY_MODE_REPLY_TEXT,
        'text' => '系统已收到您的消息',
    ]);
});
