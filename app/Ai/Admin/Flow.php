<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiFlow;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AIFlow as ServiceAIFlow;
use App\Ai\Service\Agent\SseGeneratorStream;
use App\Ai\Service\Agent\Sse as AgentSse;
use App\Ai\Service\CodeGenerator;
use App\Ai\Service\FunctionCall;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/flow', name: 'ai.flow')]
class Flow extends Resources
{
    protected string $model = AiFlow::class;

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
        /** @var AiFlow $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入流程名称'],
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
                    $query = AiFlow::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        return [
            'name' => (string)$data->name,
            'code' => $code,
            'description' => $data->description ?: null,
            'flow' => $data->flow ?: ['nodes' => [], 'edges' => []],
            'global_settings' => $data->global_settings ?: [],
            'status' => (bool)$data->status,
        ];
    }

    #[Action(methods: 'GET', route: '/options')]
    public function options(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $providers = AiProvider::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (AiProvider $provider) => [
                'label' => $provider->name,
                'value' => $provider->code,
                'id' => $provider->id,
            ]);

        $models = AiModel::query()
            ->with('provider')
            ->orderBy('name')
            ->get()
            ->map(static fn (AiModel $model) => self::transformModelOption($model, true));

        $functions = FunctionCall::list();
        $editorNodes = ServiceAIFlow::getEditorNodes();

        return send($response, 'ok', [
            'providers' => $providers,
            'models' => $models,
            'functions' => $functions,
            'nodes' => array_values($editorNodes),
        ]);
    }

    #[Action(methods: 'GET', route: '/providerOptions')]
    public function providerOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $providers = AiProvider::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (AiProvider $provider) => [
                'label' => $provider->name,
                'value' => $provider->code,
                'desc' => $provider->description,
            ]);

        return send($response, 'ok', $providers);
    }

    #[Action(methods: 'GET', route: '/modelOptions')]
    public function modelOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $params = $request->getQueryParams() + [
            'keyword' => null,
            'provider' => null,
            'type' => null,
        ];

        $query = AiModel::query()->with('provider')->orderByDesc('id');
        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%")
                    ->orWhere('model', 'like', "%{$keyword}%");
            });
        }
        if ($params['provider']) {
            $providerCode = (string)$params['provider'];
            $query->whereHas('provider', static function (Builder $builder) use ($providerCode) {
                $builder->where('code', $providerCode);
            });
        }
        if ($params['type']) {
            $query->where('type', (string)$params['type']);
        }

        $models = $query->get()->map(static fn (AiModel $model) => self::transformModelOption($model, false));

        return send($response, 'ok', $models);
    }

    private static function transformModelOption(AiModel $model, bool $includeProviderCode): array
    {
        $providerName = $model->provider?->name ?? '';
        $remoteModel = $model->model;
        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $videoCapabilities = is_array($options['video_capabilities'] ?? null) ? ($options['video_capabilities'] ?? []) : [];

        $data = [
            'id' => $model->id,
            'label' => $model->name,
            'value' => $model->code,
            'code' => $model->code,
            'type' => $model->type,
            'remote_model' => $remoteModel,
            'desc' => $remoteModel . ($providerName ? sprintf(' · %s', $providerName) : ''),
            'meta' => [
                'video_capabilities' => $videoCapabilities,
            ],
            'video_capabilities' => $videoCapabilities,
        ];

        if ($includeProviderCode) {
            $data['provider'] = $model->provider?->code;
        }

        return $data;
    }

    #[Action(methods: 'GET', route: '/functionOptions')]
    public function functionOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $functions = array_map(static fn (array $item) => [
            'label' => $item['label'] ?? $item['value'],
            'value' => $item['value'],
            'description' => $item['description'] ?? '',
        ], FunctionCall::list());

        return send($response, 'ok', $functions);
    }

    #[Action(methods: 'PUT', route: '/{id}/flow')]
    public function saveFlow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        /** @var AiFlow|null $flow */
        $flow = AiFlow::query()->find($id);
        if (!$flow) {
            throw new ExceptionBusiness('流程不存在');
        }

        $body = (array)$request->getParsedBody();
        $flowData = $body['flow'] ?? ['nodes' => [], 'edges' => []];
        $globalSettings = $body['global_settings'] ?? [];
        $globalSettings = is_array($globalSettings) ? $globalSettings : [];

        $flowData = is_array($flowData) ? $flowData : ['nodes' => [], 'edges' => []];
        $filteredGlobalSettings = FlowPayload::filterGlobalSettingsPayload($globalSettings);
        $flow->flow = FlowPayload::injectGlobalSettingsIntoFlow($flowData, $filteredGlobalSettings);
        $flow->global_settings = $filteredGlobalSettings;

        $nameValue = FlowPayload::resolveFieldValue($body, $globalSettings, 'name');
        $resolvedName = FlowPayload::normalizeNonEmptyString($nameValue);
        if ($resolvedName !== null) {
            $flow->name = $resolvedName;
        }

        $codeValue = FlowPayload::resolveFieldValue($body, $globalSettings, 'code');
        $resolvedCode = FlowPayload::normalizeNonEmptyString($codeValue);
        if ($resolvedCode !== null) {
            $flow->code = $resolvedCode;
        }

        if (FlowPayload::hasFieldValue($body, $globalSettings, 'description')) {
            $descriptionValue = FlowPayload::resolveFieldValue($body, $globalSettings, 'description');
            $flow->description = FlowPayload::normalizeNullableText($descriptionValue);
        }

        if (FlowPayload::hasFieldValue($body, $globalSettings, 'status')) {
            $statusValue = FlowPayload::resolveFieldValue($body, $globalSettings, 'status');
            $normalizedStatus = FlowPayload::normalizeBool($statusValue);
            if ($normalizedStatus !== null) {
                $flow->status = $normalizedStatus;
            }
        }

        $flow->save();

        return send($response, 'ok', $flow->transform());
    }

    #[Action(methods: 'GET', route: '/{id}/orderedNodes')]
    public function orderedNodes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        /** @var AiFlow|null $flow */
        $flow = AiFlow::query()->find($id);
        if (!$flow) {
            throw new ExceptionBusiness('流程不存在');
        }

        $nodes = ServiceAIFlow::orderedNodes($flow);

        return send($response, 'ok', [
            'nodes' => $nodes,
        ]);
    }

    #[Action(methods: 'POST', route: '/execute/{code}')]
    public function execute(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = $args['code'] ?? null;
        if (!$code) {
            throw new ExceptionBusiness('Flow code required');
        }

        $body = (array)$request->getParsedBody();
        $input = $body['input'] ?? [];

        $result = ServiceAIFlow::executeFinal((string)$code, (array)$input);

        return send($response, 'ok', $result);
    }

    #[Action(methods: ['GET', 'POST'], route: '/execute/{code}/stream')]
    public function executeStream(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = $args['code'] ?? null;
        if (!$code) {
            throw new ExceptionBusiness('Flow code required');
        }
        AgentSse::prepareStreaming();

        try {
            $input = FlowPayload::resolveInputPayload($request);
            $options = FlowPayload::resolveOptionsPayload($request);
            $options['keepalive_padding'] = true;
            $generator = ServiceAIFlow::stream((string)$code, $input, $options);
            $stream = SseGeneratorStream::fromGenerator($generator);
        } catch (\Throwable $e) {
            $stream = new SseGeneratorStream(function () use ($code, $e) {
                static $sent = false;
                if ($sent) {
                    return false;
                }
                $sent = true;
                return AgentSse::format([
                    'status' => 0,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'meta' => [
                        'event' => 'error',
                        'flow' => (string)$code,
                    ],
                ]) . AgentSse::done();
            });
        }

        return $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-transform')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('Content-Encoding', 'none')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($stream);
    }
}
