<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\RagKnowledge as RagKnowledgeModel;
use App\Ai\Service\Rag;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

#[Resource(app: 'admin', route: '/ai/ragKnowledge', name: 'ai.ragKnowledge', actions: ['list', 'show', 'create', 'edit', 'store', 'delete'])]
class RagKnowledge extends Resources
{
    protected string $model = RagKnowledgeModel::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'keyword' => null,
            'config_id' => null,
        ];

        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('base_id', 'like', "%{$keyword}%");
            });
        }

        if ($params['config_id']) {
            $query->where('config_id', (int)$params['config_id']);
        }

        $query->with('config')->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var RagKnowledgeModel $item */
        $item->loadMissing('config');
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'config_id' => ['required', '请选择知识库配置'],
            'name' => ['required', '请输入知识库名称'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'config_id' => (int)$data->config_id,
            'name' => (string)$data->name,
            'description' => $data->description ?: null,
            'settings' => is_array($data->settings) ? $data->settings : [],
            'status' => !!$data->status,
        ];
    }

    #[Action(methods: 'GET', route: '/{id}/query')]
    public function queryDocs(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $knowledgeId = (int)($args['id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('知识库不存在');
        }

        $params = $request->getQueryParams() + [
            'keyword' => null,
            'limit' => 5,
            'content_length' => 3000,
        ];

        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword === '') {
            throw new ExceptionBusiness('请输入查询关键词');
        }
        $limit = (int)$params['limit'] ?: 5;
        $contentLength = (int)($params['content_length'] ?? 3000);
        if ($contentLength <= 0) {
            $contentLength = 3000;
        }

        $knowledge = RagKnowledgeModel::query()->with('config')->find($knowledgeId);
        if (!$knowledge) {
            throw new ExceptionBusiness('知识库不存在');
        }

        try {
            $result = Rag::query($knowledge, $keyword, $limit, [
                'content_length' => $contentLength,
            ]);
        } catch (Throwable $throwable) {
            throw new ExceptionBusiness('查询知识库失败：' . $throwable->getMessage(), 0, $throwable);
        }

        return send($response, 'ok', [
            'items' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'keyword' => $keyword,
            'limit' => $limit,
            'knowledge' => [
                'id' => $knowledge->id,
                'name' => $knowledge->name,
                'base_id' => $knowledge->base_id,
            ],
        ]);
    }

    #[Action(methods: 'GET', route: '/options')]
    public function options(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $list = RagKnowledgeModel::query()
            ->where('status', true)
            ->orderByDesc('id')
            ->get(['id', 'name'])
            ->map(static fn (RagKnowledgeModel $model) => [
                'label' => $model->name,
                'value' => $model->id,
            ]);

        return send($response, 'ok', $list);
    }

    #[Action(methods: 'POST', route: '/{id}/clear')]
    public function clear(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $knowledgeId = (int)($args['id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('知识库不存在');
        }

        $knowledge = RagKnowledgeModel::query()->with('config')->find($knowledgeId);
        if (!$knowledge) {
            throw new ExceptionBusiness('知识库不存在');
        }

        try {
            Rag::clearKnowledge($knowledge);
        } catch (Throwable $throwable) {
            throw new ExceptionBusiness('清空知识库失败：' . $throwable->getMessage(), 0, $throwable);
        }

        return send($response, 'ok');
    }

    public function createAfter(Data $data, mixed $info): void
    {
        if ($info instanceof RagKnowledgeModel) {
            Rag::syncKnowledge($info);
        }
    }

    public function editAfter(Data $data, mixed $info): void
    {
        if ($info instanceof RagKnowledgeModel) {
            Rag::syncKnowledge($info);
        }
    }

    public function delBefore(mixed $info): void
    {
        if ($info instanceof RagKnowledgeModel) {
            Rag::deleteKnowledge($info, false);
        }
    }
}
