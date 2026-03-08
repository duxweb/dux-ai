<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AgentResolver
{
    public static function findByCode(string $agentCode, bool $withModelProvider = false): ?AiAgent
    {
        $query = AiAgent::query();
        if ($withModelProvider) {
            $query->with('model.provider');
        }
        return $query->where('code', $agentCode)->first();
    }

    public static function requireByCode(string $agentCode, bool $withModelProvider = false): AiAgent
    {
        $agent = self::findByCode($agentCode, $withModelProvider);
        if (!$agent) {
            throw new ModelNotFoundException('未找到智能体');
        }
        return $agent;
    }
}

