<?php

use App\Ai\Models\AiAgent;
use App\Ai\Service\Agent\SessionManager;

it('会话：根据 code 创建会话，并可重命名/删除', function () {
    $agent = AiAgent::query()->create([
        'name' => '测试智能体',
        'code' => 'agent_test',
        'active' => true,
        'tools' => [],
        'settings' => [],
    ]);

    $created = SessionManager::createSessionByCode('agent_test', 'Tests\\User', 1);

    expect($created['agent_id'])->toBe($agent->id)
        ->and($created['user_type'])->toBe('Tests\\User')
        ->and($created['user_id'])->toBe(1);

    $renamed = SessionManager::renameSession((int)$created['id'], '新标题');
    expect($renamed['title'])->toBe('新标题');

    SessionManager::deleteSession((int)$created['id']);
    expect(\App\Ai\Models\AiAgentSession::query()->count())->toBe(0);
});

it('会话：listSessionsByCode 支持按 agentCode 和用户过滤', function () {
    AiAgent::query()->create(['name' => 'A', 'code' => 'a', 'active' => true]);
    AiAgent::query()->create(['name' => 'B', 'code' => 'b', 'active' => true]);

    $s1 = SessionManager::createSessionByCode('a', 'Tests\\User', 1);
    $s2 = SessionManager::createSessionByCode('a', 'Tests\\User', 2);
    $s3 = SessionManager::createSessionByCode('b', 'Tests\\User', 1);

    $listA1 = SessionManager::listSessionsByCode('a', 20, 'Tests\\User', 1);
    expect(array_column($listA1, 'id'))->toBe([(int)$s1['id']]);

    $listAllUser1 = SessionManager::listSessionsByCode(null, 20, 'Tests\\User', 1);
    $ids = array_map('intval', array_column($listAllUser1, 'id'));
    sort($ids);
    expect($ids)->toBe([ (int)$s1['id'], (int)$s3['id'] ]);
});

