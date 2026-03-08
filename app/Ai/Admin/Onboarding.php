<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Models\AiVector;
use App\Ai\Models\ParseProvider;
use App\Ai\Models\RegProvider;
use App\Ai\Service\CodeGenerator;
use App\Ai\Service\Tool;
use App\Boot\Models\BootBot;
use App\Boot\Service\BotService;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/onboarding', name: 'ai.onboarding', actions: [])]
class Onboarding extends Resources
{
    protected string $model = AiAgent::class;

    #[Action(methods: 'GET', route: '/meta')]
    public function meta(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $providers = AiProvider::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'code', 'protocol', 'base_url', 'active'])
            ->map(static fn (AiProvider $item): array => [
                'id' => (int)$item->id,
                'name' => (string)$item->name,
                'code' => (string)$item->code,
                'protocol' => (string)$item->protocol,
                'base_url' => (string)$item->base_url,
                'active' => (bool)$item->active,
            ])->all();

        $models = AiModel::query()
            ->with('provider')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $item): array => [
                'id' => (int)$item->id,
                'name' => (string)$item->name,
                'code' => (string)$item->code,
                'type' => (string)$item->type,
                'model' => (string)$item->model,
                'provider_id' => (int)$item->provider_id,
                'provider_name' => (string)($item->provider?->name ?? ''),
                'active' => (bool)$item->active,
            ])->all();

        $tools = array_map(static function (array $item): array {
            $settings = is_array($item['settings'] ?? null) ? ($item['settings'] ?? []) : [];
            $defaults = is_array($item['defaults'] ?? null) ? ($item['defaults'] ?? []) : [];
            return [
                'code' => (string)($item['code'] ?? ''),
                'name' => (string)($item['label'] ?? $item['name'] ?? $item['code'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'type' => (string)($item['type'] ?? 'function'),
                'needs_config' => $settings !== [] || $defaults !== [],
                'settings_count' => count($settings),
            ];
        }, Tool::list());

        $vectors = AiVector::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'code', 'driver'])
            ->map(static fn (AiVector $item): array => [
                'id' => (int)$item->id,
                'name' => (string)$item->name,
                'code' => (string)$item->code,
                'driver' => (string)$item->driver,
            ])->all();

        $parsers = ParseProvider::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'code', 'provider'])
            ->map(static fn (ParseProvider $item): array => [
                'id' => (int)$item->id,
                'name' => (string)$item->name,
                'code' => (string)$item->code,
                'provider' => (string)$item->provider,
            ])->all();

        $storages = $this->loadStorageOptions();
        $bots = $this->loadBotOptions();
        $botPlatforms = class_exists(BotService::class) ? (new BotService())->platformOptions() : [];

        return send($response, 'ok', [
            'scenes' => [
                ['value' => 'agent_only', 'label' => '先聊聊天'],
                ['value' => 'im', 'label' => '接入聊天平台'],
                ['value' => 'knowledge', 'label' => '搭建知识问答'],
            ],
            'protocols' => AiProvider::protocolRegistry(),
            'providers' => $providers,
            'models' => $models,
            'tools' => array_values(array_filter($tools, static fn (array $item): bool => $item['code'] !== '')),
            'bots' => $bots,
            'bot_platforms' => $botPlatforms,
            'vectors' => $vectors,
            'storages' => $storages,
            'parse_providers' => $parsers,
        ]);
    }

    #[Action(methods: 'POST', route: '/submit')]
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = (array)$request->getParsedBody();
        $scene = strtolower(trim((string)($payload['scene'] ?? 'agent_only')));
        if (!in_array($scene, ['agent_only', 'im', 'knowledge'], true)) {
            throw new ExceptionBusiness('不支持的配置场景');
        }

        $db = App::db()->getConnection();
        $db->beginTransaction();
        try {
            $result = (function () use ($payload, $scene): array {
                $provider = $this->resolveProvider(is_array($payload['provider'] ?? null) ? ($payload['provider'] ?? []) : []);

                $chatModel = $this->resolveModel(
                    payload: is_array($payload['chat_model'] ?? null) ? ($payload['chat_model'] ?? []) : [],
                    providerId: (int)$provider->id,
                    type: AiModel::TYPE_CHAT,
                    defaultName: 'Chat Model',
                );

                $embeddingModel = null;
                if ($scene === 'knowledge') {
                    $embeddingModel = $this->resolveModel(
                        payload: is_array($payload['embedding_model'] ?? null) ? ($payload['embedding_model'] ?? []) : [],
                        providerId: (int)$provider->id,
                        type: AiModel::TYPE_EMBEDDING,
                        defaultName: 'Embedding Model',
                    );
                }

                $agentPayload = is_array($payload['agent'] ?? null) ? ($payload['agent'] ?? []) : [];
                $imPayload = is_array($payload['im'] ?? null) ? ($payload['im'] ?? []) : [];

                $botCodes = [];
                if ($scene === 'im') {
                    $botMode = strtolower(trim((string)($imPayload['bot_mode'] ?? 'reuse')));
                    if (!in_array($botMode, ['reuse', 'create'], true)) {
                        throw new ExceptionBusiness('机器人模式不支持');
                    }
                    if ($botMode === 'create') {
                        $botPayload = is_array($imPayload['bot'] ?? null) ? ($imPayload['bot'] ?? []) : [];
                        $createdBot = $this->createBot($botPayload);
                        $botCodes = [(string)$createdBot->code];
                    } else {
                        $botCodes = $this->normalizeStringList($imPayload['bot_codes'] ?? []);
                    }
                    if ($botCodes === []) {
                        throw new ExceptionBusiness('IM 线路至少选择一个机器人');
                    }
                }
                $this->assertBotBindingsUnique(0, $botCodes);

                $agentName = trim((string)($agentPayload['name'] ?? ''));
                if ($agentName === '') {
                    throw new ExceptionBusiness('请输入智能体名称');
                }

                $agent = AiAgent::query()->create([
                    'name' => $agentName,
                    'code' => CodeGenerator::unique(static fn (string $code): bool => AiAgent::query()->where('code', $code)->exists()),
                    'model_id' => (int)$chatModel->id,
                    'instructions' => trim((string)($agentPayload['instructions'] ?? '')) ?: null,
                    'tools' => $this->buildAgentTools($this->normalizeStringList($agentPayload['tool_codes'] ?? [])),
                    'settings' => [
                        'temperature' => 0.7,
                        'summary_max_tokens' => 50000,
                        'summary_messages_keep' => 5,
                        'debug_enabled' => false,
                        'bot_codes' => $botCodes,
                    ],
                    'active' => true,
                    'description' => trim((string)($agentPayload['description'] ?? '')) ?: null,
                ]);

                $ragProvider = null;
                if ($scene === 'knowledge') {
                    $knowledge = is_array($payload['knowledge'] ?? null) ? ($payload['knowledge'] ?? []) : [];
                    $storageId = (int)($knowledge['storage_id'] ?? 0);
                    $vectorId = (int)($knowledge['vector_id'] ?? 0);
                    if ($storageId <= 0 || $vectorId <= 0) {
                        throw new ExceptionBusiness('知识库线路需要选择存储驱动和向量库');
                    }

                    $parseProviderId = (int)($knowledge['parse_provider_id'] ?? 0);
                    $ragProvider = RegProvider::query()->create([
                        'name' => trim((string)($knowledge['name'] ?? ($agentName . ' 知识库配置'))),
                        'code' => CodeGenerator::unique(static fn (string $code): bool => RegProvider::query()->where('code', $code)->exists()),
                        'provider' => 'neuron',
                        'storage_id' => $storageId,
                        'vector_id' => $vectorId,
                        'embedding_model_id' => (int)$embeddingModel?->id,
                        'description' => trim((string)($knowledge['description'] ?? '')) ?: null,
                        'config' => [
                            'parse_provider_id' => $parseProviderId > 0 ? $parseProviderId : null,
                        ],
                    ]);
                }

                return [
                    'scene' => $scene,
                    'provider_id' => (int)$provider->id,
                    'chat_model_id' => (int)$chatModel->id,
                    'embedding_model_id' => $embeddingModel ? (int)$embeddingModel->id : null,
                    'agent_id' => (int)$agent->id,
                    'agent_code' => (string)$agent->code,
                    'rag_provider_id' => $ragProvider ? (int)$ragProvider->id : null,
                    'chat_entry_url' => '/ai/agent/chat/' . rawurlencode((string)$agent->code),
                ];
            })();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return send($response, 'ok', $result);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveProvider(array $payload): AiProvider
    {
        $reuseId = (int)($payload['reuse_id'] ?? 0);
        if ($reuseId > 0) {
            $provider = AiProvider::query()->find($reuseId);
            if (!$provider) {
                throw new ExceptionBusiness('服务商不存在');
            }
            return $provider;
        }

        $protocol = strtolower(trim((string)($payload['protocol'] ?? AiProvider::PROTOCOL_OPENAI_LIKE)));
        $protocolMeta = AiProvider::protocolMeta($protocol);
        if ((string)($protocolMeta['value'] ?? '') !== $protocol) {
            throw new ExceptionBusiness('不支持的服务商协议');
        }

        $apiKey = trim((string)($payload['api_key'] ?? ''));
        $requiresApiKey = (bool)($protocolMeta['requires_api_key'] ?? true);
        if ($requiresApiKey && $apiKey === '') {
            throw new ExceptionBusiness('API Key 不能为空');
        }

        $baseUrl = trim((string)($payload['base_url'] ?? AiProvider::defaultBaseUrl($protocol)));
        if ($baseUrl === '') {
            throw new ExceptionBusiness('接口地址不能为空');
        }

        return AiProvider::query()->create([
            'name' => trim((string)($payload['name'] ?? '')) ?: ('Provider - ' . strtoupper($protocol)),
            'code' => CodeGenerator::unique(static fn (string $code): bool => AiProvider::query()->where('code', $code)->exists()),
            'protocol' => $protocol,
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'organization' => null,
            'project' => null,
            'timeout' => max(1, (int)($payload['timeout'] ?? 30)),
            'headers' => [],
            'query_params' => [],
            'active' => true,
            'description' => trim((string)($payload['description'] ?? '')) ?: null,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveModel(array $payload, int $providerId, string $type, string $defaultName): AiModel
    {
        $reuseId = (int)($payload['reuse_id'] ?? 0);
        if ($reuseId > 0) {
            $model = AiModel::query()->find($reuseId);
            if (!$model) {
                throw new ExceptionBusiness('模型不存在');
            }
            if ((string)$model->type !== $type) {
                throw new ExceptionBusiness('所选复用模型类型不匹配');
            }
            return $model;
        }

        $remoteModel = trim((string)($payload['model'] ?? ''));
        if ($remoteModel === '') {
            throw new ExceptionBusiness(sprintf('%s 远端模型 ID 不能为空', $defaultName));
        }

        $dimensions = null;
        if ($type === AiModel::TYPE_EMBEDDING) {
            $raw = (int)($payload['dimensions'] ?? 0);
            $dimensions = $raw > 0 ? $raw : null;
        }

        return AiModel::query()->create([
            'provider_id' => $providerId,
            'name' => trim((string)($payload['name'] ?? '')) ?: $defaultName,
            'code' => CodeGenerator::unique(static fn (string $code): bool => AiModel::query()->where('code', $code)->exists()),
            'model' => $remoteModel,
            'type' => $type,
            'dimensions' => $dimensions,
            'icon' => null,
            'options' => [],
            'active' => true,
            'supports_structured_output' => false,
            'description' => trim((string)($payload['description'] ?? '')) ?: null,
            'quota_type' => AiModel::QUOTA_TYPE_ONCE,
            'quota_tokens' => 0,
        ]);
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

    /**
     * @param array<int, string> $codes
     * @return array<int, array<string, mixed>>
     */
    private function buildAgentTools(array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $meta = Tool::get($code);
            if (!$meta) {
                continue;
            }
            $schema = is_array($meta['schema'] ?? null) ? ($meta['schema'] ?? []) : [
                'type' => 'object',
                'properties' => [],
            ];
            $payload = [
                'code' => $code,
                'name' => (string)($meta['function'] ?? $code),
                'label' => (string)($meta['label'] ?? $meta['name'] ?? $code),
                'description' => (string)($meta['description'] ?? ''),
                'schema' => $schema,
            ];
            $defaults = is_array($meta['defaults'] ?? null) ? ($meta['defaults'] ?? []) : [];
            $result[] = $defaults !== [] ? array_merge($payload, $defaults) : $payload;
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[$text] = $text;
            }
        }
        return array_values($items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStorageOptions(): array
    {
        if (!class_exists(\App\System\Models\SystemStorage::class)) {
            return [];
        }
        $class = \App\System\Models\SystemStorage::class;
        /** @var \Illuminate\Database\Eloquent\Collection<int, mixed> $rows */
        $rows = $class::query()->orderByDesc('id')->get(['id', 'name', 'title', 'type']);

        $result = [];
        foreach ($rows as $item) {
            $result[] = [
                'id' => (int)$item->id,
                'name' => (string)$item->name,
                'title' => (string)($item->title ?: $item->name),
                'type' => (string)$item->type,
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadBotOptions(): array
    {
        if (!class_exists(BootBot::class)) {
            return [];
        }
        $query = BootBot::query()->orderByDesc('id')->where('enabled', true);
        /** @var \Illuminate\Database\Eloquent\Collection<int, BootBot> $rows */
        $rows = $query->get(['id', 'name', 'code', 'platform']);

        $result = [];
        foreach ($rows as $item) {
            $code = trim((string)($item->code ?? ''));
            if ($code === '') {
                continue;
            }
            $result[] = [
                'value' => $code,
                'label' => trim((string)($item->name ?? '')) ?: $code,
                'platform' => (string)($item->platform ?? ''),
                'id' => (int)$item->id,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createBot(array $payload): BootBot
    {
        if (!class_exists(BootBot::class)) {
            throw new ExceptionBusiness('Boot 模块未安装，无法创建机器人');
        }
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new ExceptionBusiness('机器人名称不能为空');
        }

        $platform = strtolower(trim((string)($payload['platform'] ?? 'dingtalk')));
        if (!in_array($platform, ['dingtalk', 'feishu', 'qq_bot', 'wecom'], true)) {
            throw new ExceptionBusiness('不支持的机器人平台');
        }

        $code = trim((string)($payload['code'] ?? ''));
        if ($code === '') {
            $code = 'bot_' . CodeGenerator::unique(static fn (string $value): bool => BootBot::query()->where('code', 'bot_' . $value)->exists(), 10);
        }
        if (BootBot::query()->where('code', $code)->exists()) {
            throw new ExceptionBusiness('机器人编码已存在');
        }

        $config = is_array($payload['config'] ?? null) ? ($payload['config'] ?? []) : [];
        $normalizedConfig = [
            'app_key' => trim((string)($config['app_key'] ?? '')),
            'app_id' => trim((string)($config['app_id'] ?? '')),
            'app_secret' => trim((string)($config['app_secret'] ?? '')),
            'corp_id' => trim((string)($config['corp_id'] ?? '')),
            'agent_id' => (int)($config['agent_id'] ?? 0) ?: null,
            'token' => trim((string)($config['token'] ?? '')),
            'aes_key' => trim((string)($config['aes_key'] ?? '')),
            'webhook' => trim((string)($config['webhook'] ?? '')),
            'sign_secret' => trim((string)($config['sign_secret'] ?? '')),
            'verification_token' => trim((string)($config['verification_token'] ?? '')),
            'encrypt_key' => trim((string)($config['encrypt_key'] ?? '')),
        ];

        return BootBot::query()->create([
            'name' => $name,
            'code' => $code,
            'platform' => $platform,
            'enabled' => true,
            'config' => $normalizedConfig,
            'verify_secret' => trim((string)($payload['verify_secret'] ?? '')) ?: null,
            'timeout_ms' => 10000,
            'remark' => trim((string)($payload['remark'] ?? '')) ?: null,
        ]);
    }
}
