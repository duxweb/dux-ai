<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiVector as AiVectorEntity;
use App\Ai\Service\CodeGenerator;
use App\Ai\Service\VectorStore;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/vector', name: 'ai.vector')]
class Vector extends Resources
{
    protected string $model = AiVectorEntity::class;

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
        if (!empty($params['driver'])) {
            $query->where('driver', (string)$params['driver']);
        }
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiVectorEntity $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入向量库名称'],
            'driver' => ['required', '请选择向量库驱动'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $driver = (string)($data->driver ?: 'file');
        $meta = VectorStore::driverMeta($driver);
        $driver = isset($meta['value']) ? (string)$meta['value'] : ($driver !== '' ? $driver : 'file');
        $options = is_array($data->options) ? $data->options : [];
        if ($options !== [] && array_is_list($options)) {
            $options = [];
        }
        $id = (int)($args['id'] ?? 0);
        $inputCode = trim((string)$data->code);
        $code = $inputCode !== ''
            ? $inputCode
            : CodeGenerator::unique(
                static function (string $value) use ($id): bool {
                    $query = AiVectorEntity::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        return [
            'name' => fn () => (string)$data->name,
            'code' => fn () => $code,
            'driver' => fn () => $driver,
            'options' => $options,
            'active' => (bool)$data->active,
            'description' => fn () => $data->description ?: null,
        ];
    }

    #[Action(methods: 'GET', route: '/drivers')]
    public function drivers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return send($response, 'ok', VectorStore::registry());
    }
}
