<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiFlowLog;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/flowLog', name: 'ai.flowLog')]
class FlowLog extends Resources
{
    protected string $model = AiFlowLog::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        if (!empty($params['keyword'])) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('flow_code', 'like', "%{$keyword}%")
                    ->orWhere('workflow_id', 'like', "%{$keyword}%")
                    ->orWhere('message', 'like', "%{$keyword}%");
            });
        }

        if (!empty($params['flow_id'])) {
            $query->where('flow_id', (int)$params['flow_id']);
        }

        if (!empty($params['workflow_id'])) {
            $query->where('workflow_id', (string)$params['workflow_id']);
        }

        if (!empty($params['status'])) {
            $query->where('status', (int)$params['status']);
        }

        $query->with('flow');
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        return $item->transform();
    }
}
