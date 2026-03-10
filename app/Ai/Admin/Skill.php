<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiSkill;
use App\Ai\Service\Skill\ImportService;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/skill', name: 'ai.skill')]
class Skill extends Resources
{
    protected string $model = AiSkill::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'keyword' => null,
            'tab' => null,
        ];

        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('title', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        if ($params['tab'] === 'enabled') {
            $query->where('enabled', true);
        }
        if ($params['tab'] === 'partial') {
            $query->where('compatibility', 'partial');
        }
        if ($params['tab'] === 'manual') {
            $query->where('source_type', 'manual');
        }

        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiSkill $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入技能标识'],
            'description' => ['required', '请输入技能描述'],
            'content' => ['required', '请输入技能内容'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $info = $id > 0 ? AiSkill::query()->find($id) : null;
        $frontmatter = is_array($info?->frontmatter ?? null) ? ($info?->frontmatter ?? []) : [];
        $frontmatter['name'] = trim((string)$data->name);
        $frontmatter['description'] = trim((string)$data->description);

        return [
            'name' => fn () => trim((string)$data->name),
            'title' => fn () => $data->title ? trim((string)$data->title) : null,
            'description' => fn () => trim((string)$data->description),
            'content' => fn () => trim((string)$data->content),
            'frontmatter' => fn () => $frontmatter,
            'source_type' => fn () => $info?->source_type ?: 'manual',
            'source_url' => fn () => $info?->source_url,
            'source_path' => fn () => $info?->source_path,
            'storage_path' => fn () => $info?->storage_path,
            'compatibility' => fn () => $info?->compatibility ?: 'full',
            'enabled' => fn () => (bool)$data->enabled,
        ];
    }

    public function delAfter(mixed $info): void
    {
        if (!$info instanceof AiSkill) {
            return;
        }
        ImportService::deleteStoredDirectory($info);
    }

    #[Action(methods: 'GET', route: '/options')]
    public function options(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $data = AiSkill::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (AiSkill $skill) => [
                'label' => $skill->title ?: $skill->name,
                'value' => $skill->name,
                'name' => $skill->name,
                'title' => $skill->title ?: $skill->name,
                'description' => $skill->description,
                'compatibility' => $skill->compatibility,
                'compatibility_name' => $skill->transform()['compatibility_name'],
                'source_type' => $skill->source_type,
                'source_type_name' => $skill->transform()['source_type_name'],
                'disable_model_invocation' => (bool)($skill->frontmatter['disable-model-invocation'] ?? false),
                'user_invocable' => array_key_exists('user-invocable', $skill->frontmatter ?? [])
                    ? (bool)$skill->frontmatter['user-invocable']
                    : true,
            ]);

        return send($response, 'ok', $data);
    }

    #[Action(methods: 'POST', route: '/import')]
    public function import(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $source = trim((string)($body['source'] ?? ''));
        if ($source === '') {
            throw new ExceptionBusiness('请输入导入来源');
        }

        $result = (new ImportService())->import($source, (bool)($body['overwrite'] ?? true));

        return send($response, 'ok', $result);
    }
}
