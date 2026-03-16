<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiAgentSession;
use App\AiDesktop\Models\AiNodeSessionBinding;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Resource;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/session', name: 'ai.session', actions: ['list', 'show'])]
class Session extends Resources
{
    protected string $model = AiAgentSession::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'keyword' => null,
            'user_type' => null,
            'user_id' => null,
            'device_id' => null,
        ];

        if ($params['keyword']) {
            $keyword = trim((string)$params['keyword']);
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('title', 'like', "%{$keyword}%")
                    ->orWhere('id', 'like', "%{$keyword}%")
                    ->orWhereHas('agent', static function (Builder $agentQuery) use ($keyword) {
                        $agentQuery->where('name', 'like', "%{$keyword}%")
                            ->orWhere('code', 'like', "%{$keyword}%");
                    });
            });
        }

        if (!empty($params['user_type'])) {
            $query->where('user_type', (string)$params['user_type']);
        }

        if (!empty($params['user_id']) && is_numeric((string)$params['user_id'])) {
            $query->where('user_id', (int)$params['user_id']);
        }

        if (!empty($params['device_id']) && is_numeric((string)$params['device_id'])) {
            $deviceId = (int)$params['device_id'];
            $table = (new AiNodeSessionBinding())->getTable();
            $query->whereExists(function ($builder) use ($deviceId, $table) {
                $builder->selectRaw('1')
                    ->from($table)
                    ->whereColumn($table . '.session_id', 'ai_agent_sessions.id')
                    ->where($table . '.node_id', $deviceId);
            });
        }

        $query->with(['agent']);
        $query->orderByDesc('last_message_at')->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiAgentSession $item */
        $data = $item->transform();
        $data['agent_code'] = $item->agent?->code;
        $data['agent_name'] = $item->agent?->name;
        return $data;
    }
}
