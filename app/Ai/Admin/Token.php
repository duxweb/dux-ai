<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiToken;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/token', name: 'ai.token')]
class Token extends Resources
{
    protected string $model = AiToken::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        if (!empty($params['keyword'])) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('token', 'like', "%{$keyword}%");
            });
        }
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiToken $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入名称'],
            'token' => ['required', '请输入 Token'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $models = $data->models ?? [];
        if (!is_array($models)) {
            $models = [];
        }
        $models = array_values(array_filter(array_map(static function ($id) {
            return is_numeric($id) ? (int)$id : null;
        }, $models), static fn ($id) => $id !== null));

        $expiredAt = $data->expired_at ?? null;
        if (is_string($expiredAt) && trim($expiredAt) === '') {
            $expiredAt = null;
        }

        return [
            'name' => (string)$data->name,
            'token' => (string)$data->token,
            'active' => (bool)$data->active,
            'models' => $models,
            'expired_at' => $expiredAt ?: null,
        ];
    }

    #[Action(methods: 'GET', route: '/options/agents')]
    public function agentOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = AiAgent::query()
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiAgent $agent) => [
                'label' => $agent->name,
                'value' => $agent->id,
                'code' => $agent->code,
            ])
            ->all();
        return send($response, 'ok', $data);
    }

    #[Action(methods: 'POST', route: '/generate')]
    public function generate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return send($response, 'ok', [
            'token' => AiToken::generateToken(),
        ]);
    }
}
