<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgentSession;
use Core\Handlers\ExceptionBusiness;
use Illuminate\Support\Collection;

final class SessionGuard
{
    /**
     * @param Collection<int, mixed> $agents
     */
    public static function resolve(Collection $agents, int $sessionId, ?string $userType, ?int $userId): AiAgentSession
    {
        if ($sessionId <= 0) {
            throw new ExceptionBusiness('会话不存在');
        }

        $agentIds = $agents->pluck('id')->all();
        if (!$agentIds) {
            throw new ExceptionBusiness('当前未配置可用模型');
        }

        $query = AiAgentSession::query()
            ->where('id', $sessionId)
            ->whereIn('agent_id', $agentIds);

        if ($userType !== null && $userId !== null) {
            $query->where('user_type', $userType)
                ->where('user_id', $userId);
        }

        $session = $query->first();
        if (!$session) {
            throw new ExceptionBusiness('会话不存在或无访问权限');
        }

        return $session;
    }
}

