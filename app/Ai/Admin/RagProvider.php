<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\RegProvider as RegProviderModel;
use App\Ai\Service\CodeGenerator;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Resource;
use Core\Resources\Attribute\Action;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/ragProvider', name: 'ai.ragProvider')]
class RagProvider extends Resources
{
    protected string $model = RegProviderModel::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        if (!empty($params['keyword'])) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var RegProviderModel $item */
        $item->loadMissing(['storage', 'vector', 'embeddingModel']);
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入配置名称'],
            'storage_id' => ['required', '请选择存储驱动'],
            'vector_id' => ['required', '请选择向量库'],
            'embedding_model_id' => ['required', '请选择 Embeddings 模型'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $inputCode = trim((string)$data->code);
        $code = $inputCode !== ''
            ? $inputCode
            : CodeGenerator::unique(
                static function (string $value) use ($id): bool {
                    $query = RegProviderModel::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        return [
            'name' => (string)$data->name,
            'code' => $code,
            'provider' => 'neuron',
            'storage_id' => (int)$data->storage_id,
            'vector_id' => (int)$data->vector_id,
            'embedding_model_id' => (int)$data->embedding_model_id,
            'description' => $data->description ?: null,
            'config' => [],
        ];
    }

    #[Action(methods: 'GET', route: '/options')]
    public function options(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $configs = RegProviderModel::query()
            ->select(['id', 'name', 'code'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (RegProviderModel $config) => [
                'label' => $config->name,
                'value' => $config->id,
                'desc' => $config->code,
            ]);

        return send($response, 'ok', $configs);
    }

}
