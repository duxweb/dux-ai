<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentSession;

final class SessionManager
{
    public static function ensureSessionId(AiAgent $agent, ?int $sessionId = null, ?string $userType = null, ?int $userId = null): int
    {
        // 查找会话时过滤用户上下文
        if (!$sessionId) {
            $query = AiAgentSession::query()
                ->where('agent_id', $agent->id);

            if ($userType !== null && $userId !== null) {
                $query->where('user_type', $userType)
                    ->where('user_id', $userId);
            }

            $sessionId = (int)($query->orderByDesc('id')->value('id') ?? 0);
        }

        // 创建会话时绑定用户
        if (!$sessionId) {
            $sessionId = (int)AiAgentSession::query()->create([
                'agent_id' => $agent->id,
                'active' => true,
                'user_type' => $userType,
                'user_id' => $userId,
            ])->id;
        }

        return $sessionId;
    }

    /**
     * @return array<string, mixed>
     */
    public static function createSessionByCode(string $agentCode, ?string $userType = null, ?int $userId = null): array
    {
        $agent = AgentResolver::requireByCode($agentCode);
        $session = AiAgentSession::query()->create([
            'agent_id' => $agent->id,
            'active' => true,
            'user_type' => $userType,
            'user_id' => $userId,
        ]);
        return $session->transform();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listSessionsByCode(?string $agentCode = null, int $limit = 20, ?string $userType = null, ?int $userId = null): array
    {
        $query = AiAgentSession::query();

        if ($agentCode) {
            $agent = AgentResolver::findByCode($agentCode);
            $query->where('agent_id', $agent?->id ?? 0);
        } else {
            $query->with('agent');
        }

        if ($userType !== null && $userId !== null) {
            $query->where('user_type', $userType)
                ->where('user_id', $userId);
        }

        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static function (AiAgentSession $session) {
                $data = $session->transform();
                $agent = $session->agent;
                $data['agent_code'] = $agent?->code;
                $data['agent_name'] = $agent?->name;
                $data['agent_description'] = $agent?->description;
                return $data;
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function renameSession(int $sessionId, ?string $title): array
    {
        $session = AiAgentSession::query()->findOrFail($sessionId);
        $title = $title !== null ? trim($title) : null;
        $session->title = $title !== '' ? $title : null;
        $session->save();

        return $session->transform();
    }

    public static function deleteSession(int $sessionId): void
    {
        $session = AiAgentSession::query()->findOrFail($sessionId);
        $session->messages()->delete();
        $session->delete();
    }
}
