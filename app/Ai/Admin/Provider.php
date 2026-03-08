<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiProvider as ProviderEntity;
use App\Ai\Service\CodeGenerator;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Handlers\ExceptionBusiness;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/provider', name: 'ai.provider')]
class Provider extends Resources
{
    protected string $model = ProviderEntity::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        $keyword = $params['keyword'] ?? '';
        if ($keyword) {
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var ProviderEntity $item */
        return array_merge($item->transform(), [
            'api_key' => $item->api_key,
        ]);
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入服务商名称'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $protocol = (string)($data->protocol ?: ProviderEntity::PROTOCOL_OPENAI_LIKE);
        $protocolMeta = ProviderEntity::protocolMeta($protocol);
        if ((string)($protocolMeta['value'] ?? '') !== $protocol) {
            throw new ExceptionBusiness('不支持的协议类型');
        }

        $apiKey = (string)($data->api_key ?? '');
        $requiresApiKey = (bool)($protocolMeta['requires_api_key'] ?? true);
        if ($requiresApiKey && trim($apiKey) === '') {
            throw new \Core\Handlers\ExceptionBusiness('请输入 API Key');
        }

        $defaultBaseUrl = ProviderEntity::defaultBaseUrl($protocol);
        $id = (int)($args['id'] ?? 0);
        $inputCode = trim((string)$data->code);
        $code = $inputCode !== ''
            ? $inputCode
            : CodeGenerator::unique(
                static function (string $value) use ($id): bool {
                    $query = ProviderEntity::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        return [
            'name' => (string)$data->name,
            'code' => $code,
            'protocol' => $protocol,
            'api_key' => $apiKey,
            'base_url' => $data->base_url ?: $defaultBaseUrl,
            'organization' => $data->organization ?: null,
            'project' => $data->project ?: null,
            'timeout' => $data->timeout ? (int)$data->timeout : 30,
            'icon' => $data->icon ? (string)$data->icon : null,
            'headers' => $this->normalizePairs($data->headers ?? []),
            'query_params' => $this->normalizePairs($data->query_params ?? []),
            'active' => (bool)$data->active,
            'description' => $data->description ?: null,
        ];
    }

    #[Action(methods: 'GET', route: '/protocols')]
    public function protocols(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return send($response, 'ok', ProviderEntity::protocolRegistry());
    }

    /**
     * @param array<int, array{name?: string, value?: string|null}>|null $pairs
     * @return array<string, string>
     */
    private function normalizePairs(?array $pairs): array
    {
        if (!$pairs) {
            return [];
        }
        $result = [];
        foreach ($pairs as $pair) {
            $name = trim((string)($pair['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $result[$name] = (string)($pair['value'] ?? '');
        }
        return $result;
    }
}
