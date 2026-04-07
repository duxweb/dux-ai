<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiScheduler;
use App\Ai\Service\Scheduler\AiSchedulerService;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/scheduler', name: 'ai.scheduler', actions: ['list', 'show'])]
class Scheduler extends Resources
{
    protected string $model = AiScheduler::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'status' => null,
            'tab' => null,
            'callback_type' => null,
            'callback_code' => null,
            'keyword' => null,
        ];

        if ($params['status']) {
            $query->where('status', (string)$params['status']);
        } elseif ($params['tab'] && $params['tab'] !== 'all') {
            $query->where('status', (string)$params['tab']);
        }
        if ($params['callback_type']) {
            $query->where('callback_type', (string)$params['callback_type']);
        }
        if ($params['callback_code']) {
            $query->where('callback_code', (string)$params['callback_code']);
        }
        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('dedupe_key', 'like', "%{$keyword}%")
                    ->orWhere('callback_name', 'like', "%{$keyword}%")
                    ->orWhere('callback_code', 'like', "%{$keyword}%")
                    ->orWhere('last_error', 'like', "%{$keyword}%");
            });
        }

        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiScheduler $item */
        return $item->transform();
    }

    #[Action(methods: 'POST', route: '/{id}/retry')]
    public function retry(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ExceptionBusiness('任务ID无效');
        }

        $ok = AiSchedulerService::retryNow($id);
        if (!$ok) {
            throw new ExceptionBusiness('任务不存在');
        }

        return send($response, 'ok');
    }

    #[Action(methods: 'POST', route: '/{id}/cancel')]
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ExceptionBusiness('任务ID无效');
        }

        $ok = AiSchedulerService::cancel($id);
        if (!$ok) {
            throw new ExceptionBusiness('任务不存在或当前状态不可取消');
        }

        return send($response, 'ok');
    }
}
