<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentSession;
use App\Ai\Models\AiFlow;
use App\Ai\Models\AiModel;
use App\Ai\Service\Skill\PromptBuilder as SkillPromptBuilder;
use App\Ai\Service\Agent as AgentService;
use App\Ai\Service\Agent\Sse as AgentSse;
use App\Ai\Service\Agent\SseGeneratorStream;
use App\Ai\Service\AIFlow as ServiceAIFlow;
use App\Ai\Service\CodeGenerator;
use App\Ai\Service\FunctionCall;
use App\Ai\Service\Tool;
use App\Ai\Service\Toolkit;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/agent', name: 'ai.agent')]
class Agent extends Resources
{
    protected string $model = AiAgent::class;

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
        $query->with('model');
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiAgent $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'name' => ['required', '请输入智能体名称'],
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
                    $query = AiAgent::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        $modelId = $data->model_id ? (int)$data->model_id : null;
        if ($modelId) {
            /** @var AiModel|null $model */
            $model = AiModel::query()->find($modelId);
            if (!$model) {
                throw new ExceptionBusiness('绑定模型不存在');
            }
            if ((string)($model->type ?? AiModel::TYPE_CHAT) !== AiModel::TYPE_CHAT) {
                throw new ExceptionBusiness('智能体主模型仅支持 Chat 类型，图片/视频模型请通过工具调用');
            }
        }

        $settings = is_array($data->settings) ? $data->settings : [];
        $botCodes = [];
        if (is_array($settings['bot_codes'] ?? null)) {
            foreach (($settings['bot_codes'] ?? []) as $code) {
                $value = trim((string)$code);
                if ($value !== '') {
                    $botCodes[] = $value;
                }
            }
        }
        $settings['bot_codes'] = array_values(array_unique($botCodes));
        $settings['skill_codes'] = SkillPromptBuilder::normalizeSkillCodes($settings['skill_codes'] ?? []);
        $settings['toolkits'] = $this->normalizeToolkits($settings['toolkits'] ?? []);
        $this->assertBotBindingsUnique((int)($args['id'] ?? 0), $settings['bot_codes']);

        return [
            'name' => fn () => (string)$data->name,
            'code' => fn () => $code,
            'model_id' => fn () => $modelId,
            'instructions' => fn () => $data->instructions ?: null,
            'tools' => fn () => $data->tools ?: [],
            'settings' => fn () => $settings ?: [],
            'active' => fn () => (bool)$data->active,
            'description' => fn () => $data->description ?: null,
        ];
    }

    public function editBefore(Data $data, mixed $info): void
    {
        if (!$info instanceof AiAgent) {
            return;
        }
        $code = trim((string)$data->code);
        if ($code !== '') {
            return;
        }
        $info->code = CodeGenerator::unique(
            static function (string $value) use ($info): bool {
                return AiAgent::query()
                    ->where('code', $value)
                    ->where('id', '<>', (int)$info->id)
                    ->exists();
            },
        );
    }

    /**
     * @param array<int, string> $botCodes
     */
    private function assertBotBindingsUnique(int $currentId, array $botCodes): void
    {
        if ($botCodes === []) {
            return;
        }

        $query = AiAgent::query()->orderByDesc('id');
        if ($currentId > 0) {
            $query->where('id', '<>', $currentId);
        }

        /** @var \Illuminate\Support\Collection<int, AiAgent> $agents */
        $agents = $query->get();
        foreach ($agents as $agent) {
            $settings = is_array($agent->settings ?? null) ? ($agent->settings ?? []) : [];
            $otherCodes = [];
            if (is_array($settings['bot_codes'] ?? null)) {
                foreach (($settings['bot_codes'] ?? []) as $code) {
                    $value = trim((string)$code);
                    if ($value !== '') {
                        $otherCodes[] = $value;
                    }
                }
            }
            $duplicated = array_values(array_intersect($botCodes, array_values(array_unique($otherCodes))));
            if ($duplicated !== []) {
                throw new ExceptionBusiness(sprintf(
                    '机器人 [%s] 已绑定到智能体 [%s]',
                    implode(', ', $duplicated),
                    (string)($agent->name ?: $agent->code ?: ('#' . $agent->id)),
                ));
            }
        }
    }

    #[Action(methods: 'GET', route: '/options')]
    public function options(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $models = AiModel::query()
            ->where('type', AiModel::TYPE_CHAT)
            ->with('provider')
            ->orderBy('name')
            ->get()
            ->map(static fn (AiModel $model) => [
                'label' => $model->name,
                'value' => $model->id,
                'code' => $model->code,
                'provider' => $model->provider?->code,
                'remote_model' => $model->model,
            ]);

        $functions = FunctionCall::list();

        $flows = AiFlow::query()
            ->where('status', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (AiFlow $flow) => [
                'label' => $flow->name,
                'value' => $flow->id,
                'code' => $flow->code,
            ]);

        return send($response, 'ok', [
            'models' => $models,
            'functions' => $functions,
            'flows' => $flows,
        ]);
    }

    #[Action(methods: 'GET', route: '/tool')]
    public function toolOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $list = array_map(static function (array $item) {
            return [
                'label' => $item['label'] ?? $item['name'] ?? $item['code'] ?? '',
                'code' => $item['code'] ?? $item['value'] ?? '',
                'type' => $item['type'] ?? '',
                'description' => $item['description'] ?? '',
                'icon' => $item['icon'] ?? 'i-tabler:puzzle',
                'color' => $item['color'] ?? 'primary',
                'schema' => $item['schema'] ?? null,
                'function' => $item['function'] ?? null,
                'flow_id' => $item['flow_id'] ?? $item['flowId'] ?? null,
                'flow_code' => $item['flow_code'] ?? $item['flowCode'] ?? null,
                'url' => $item['url'] ?? null,
                'tool' => $item['tool'] ?? null,
                'transport' => $item['transport'] ?? null,
                'protocol_version' => $item['protocol_version'] ?? $item['protocolVersion'] ?? null,
                'headers' => $item['headers'] ?? null,
                'defaults' => $item['defaults'] ?? null,
                'settings' => $item['settings'] ?? null,
            ];
        }, Tool::list());

        return send($response, 'ok', $list);
    }

    #[Action(methods: 'GET', route: '/toolkit')]
    public function toolkitOptions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $list = array_values(array_filter(array_map(static function (array $item) {
            if (!($item['agent_selectable'] ?? false)) {
                return null;
            }

            return [
                'label' => $item['label'] ?? $item['code'] ?? '',
                'code' => $item['code'] ?? '',
                'description' => $item['description'] ?? '',
                'icon' => $item['icon'] ?? 'i-tabler:tool',
                'color' => $item['color'] ?? 'primary',
                'style' => $item['style'] ?? [],
                'defaults' => $item['defaults'] ?? [],
                'settings' => $item['settings'] ?? [],
                'items' => $item['items'] ?? [],
            ];
        }, Toolkit::list())));

        return send($response, 'ok', $list);
    }

    #[Action(methods: ['GET'], route: '/chat/{code}/sessions/{id}/messages')]
    public function sessionMessages(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $code = (string)($args['code'] ?? '');
        $sessionId = (int)($args['id'] ?? 0);
        if ($code === '' || $sessionId <= 0) {
            throw new ExceptionBusiness('参数无效');
        }

        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()
            ->where('id', $sessionId)
            ->whereHas('agent', static function (Builder $builder) use ($code) {
                $builder->where('code', $code);
            })
            ->first();
        if (!$session) {
            throw new ExceptionBusiness('会话不存在');
        }

        $params = $request->getQueryParams();
        $limit = (int)($params['limit'] ?? 0);
        $limit = $limit <= 0 ? 0 : min(200, $limit);
        $messages = AgentService::listMessages($sessionId, $limit);

        return send($response, 'ok', $messages);
    }

    #[Action(methods: ['GET', 'POST'], route: '/flow/execute/{code}/stream')]
    public function executeFlowStream(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeToolkits(mixed $toolkits): array
    {
        if (!is_array($toolkits)) {
            return [];
        }

        $result = [];
        foreach ($toolkits as $toolkit) {
            if (!is_array($toolkit)) {
                continue;
            }
            $code = strtolower(trim((string)($toolkit['toolkit'] ?? $toolkit['code'] ?? $toolkit['name'] ?? '')));
            if ($code === '') {
                continue;
            }

            $config = is_array($toolkit['config'] ?? null) ? ($toolkit['config'] ?? []) : [];
            $overrides = [];
            if (is_array($toolkit['overrides'] ?? null)) {
                foreach (($toolkit['overrides'] ?? []) as $key => $value) {
                    $overrideCode = trim((string)$key);
                    if ($overrideCode === '' || !is_array($value)) {
                        continue;
                    }
                    $overrides[$overrideCode] = $value;
                }
            }

            $result[] = [
                'toolkit' => $code,
                'label' => trim((string)($toolkit['label'] ?? '')),
                'description' => trim((string)($toolkit['description'] ?? '')),
                'config' => $config,
                'overrides' => $overrides,
            ];
        }

        return $result;
    }

}
