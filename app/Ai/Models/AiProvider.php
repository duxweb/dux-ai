<?php

declare(strict_types=1);

namespace App\Ai\Models;

use App\Ai\Event\ProviderProtocolEvent;
use Core\App;
use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiProvider extends Model
{
    protected $table = 'ai_provider';

    public const PROTOCOL_OPENAI = 'openai';
    public const PROTOCOL_OPENAI_LIKE = 'openai_like';
    public const PROTOCOL_OPENAI_RESPONSES = 'openai_responses';
    public const PROTOCOL_AZURE_OPENAI = 'azure_openai';
    public const PROTOCOL_DEEPSEEK = 'deepseek';
    public const PROTOCOL_ANTHROPIC = 'anthropic';
    public const PROTOCOL_GEMINI = 'gemini';
    public const PROTOCOL_GROK = 'grok';
    public const PROTOCOL_MISTRAL = 'mistral';
    public const PROTOCOL_COHERE = 'cohere';
    public const PROTOCOL_OLLAMA = 'ollama';
    public const PROTOCOL_ARK = 'ark';
    public const PROTOCOL_BIGMODEL = 'bigmodel';

    protected $casts = [
        'headers' => 'array',
        'query_params' => 'array',
        'active' => 'boolean',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->comment('服务商名称');
        $table->string('code')->unique()->comment('调用标识');
        $table->string('protocol')->default(self::PROTOCOL_OPENAI_LIKE)->comment('协议');
        $table->string('api_key')->comment('API Key');
        $table->string('base_url')->default('https://api.openai.com/v1')->comment('接口地址');
        $table->string('icon')->nullable()->comment('图标');
        $table->string('organization')->nullable()->comment('Organization');
        $table->string('project')->nullable()->comment('Project');
        $table->unsignedInteger('timeout')->default(30)->comment('请求超时（秒）');
        $table->json('headers')->nullable()->comment('附加请求头');
        $table->json('query_params')->nullable()->comment('附加 Query');
        $table->boolean('supports_file_manager')->default(false)->comment('是否启用文件管理');
        $table->string('file_manager_driver')->default('auto')->comment('文件管理驱动(auto/openai_like/ark/moonshot/none)');
        $table->string('file_manager_base_url')->nullable()->comment('文件管理接口地址');
        $table->json('file_manager_options')->nullable()->comment('文件管理扩展配置');
        $table->boolean('active')->default(true)->comment('启用状态');
        $table->text('description')->nullable()->comment('说明');
        $table->timestamps();
    }

    public function transform(): array
    {
        $protocol = $this->protocol ?: self::PROTOCOL_OPENAI_LIKE;
        $protocolMeta = self::protocolMeta($protocol);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'protocol' => $protocol,
            'protocol_name' => (string)($protocolMeta['label'] ?? $protocol),
            'base_url' => $this->base_url,
            'organization' => $this->organization,
            'project' => $this->project,
            'timeout' => $this->timeout,
            'icon' => $this->icon,
            'headers' => $this->mapToPairs($this->headers),
            'query_params' => $this->mapToPairs($this->query_params),
            'active' => $this->active,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    public function clientConfig(): array
    {
        return [
            'protocol' => $this->protocol ?: self::PROTOCOL_OPENAI_LIKE,
            'api_key' => $this->api_key,
            'base_url' => $this->base_url,
            'organization' => $this->organization,
            'project' => $this->project,
            'timeout' => $this->timeout,
            'headers' => $this->headers ?? [],
            'query_params' => $this->query_params ?? [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function builtinProtocolRegistry(): array
    {
        return [
            [
                'value' => self::PROTOCOL_OPENAI,
                'label' => 'OpenAI',
                'description' => '官方 OpenAI 协议',
                'default_base_url' => 'https://api.openai.com/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_OPENAI_LIKE,
                'label' => 'OpenAI Compatible',
                'description' => '兼容 OpenAI Chat Completions 的通用协议',
                'default_base_url' => 'https://api.openai.com/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_OPENAI_RESPONSES,
                'label' => 'OpenAI Responses',
                'description' => 'OpenAI Responses API 协议',
                'default_base_url' => 'https://api.openai.com/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_AZURE_OPENAI,
                'label' => 'Azure OpenAI',
                'description' => 'Azure OpenAI 协议（base_url 填资源 endpoint，api-version 放 Query）',
                'default_base_url' => '',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_DEEPSEEK,
                'label' => 'DeepSeek',
                'description' => 'DeepSeek 官方协议',
                'default_base_url' => 'https://api.deepseek.com/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_ANTHROPIC,
                'label' => 'Anthropic',
                'description' => 'Claude 官方协议',
                'default_base_url' => 'https://api.anthropic.com/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_GEMINI,
                'label' => 'Gemini',
                'description' => 'Google Gemini 协议',
                'default_base_url' => 'https://generativelanguage.googleapis.com',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_GROK,
                'label' => 'Grok',
                'description' => 'xAI Grok 协议',
                'default_base_url' => 'https://api.x.ai/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_MISTRAL,
                'label' => 'Mistral',
                'description' => 'Mistral 官方协议',
                'default_base_url' => 'https://api.mistral.ai/v1',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_COHERE,
                'label' => 'Cohere',
                'description' => 'Cohere 官方协议',
                'default_base_url' => 'https://api.cohere.ai/v2',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_OLLAMA,
                'label' => 'Ollama',
                'description' => '本地 Ollama 协议',
                'default_base_url' => 'http://localhost:11434/api',
                'requires_api_key' => false,
            ],
            [
                'value' => self::PROTOCOL_ARK,
                'label' => 'Volcengine ARK',
                'description' => '火山方舟协议（聊天走 /api/v3，嵌入走 multimodal）',
                'default_base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'requires_api_key' => true,
            ],
            [
                'value' => self::PROTOCOL_BIGMODEL,
                'label' => 'BigModel',
                'description' => '智谱 BigModel 协议（/api/paas/v4）',
                'default_base_url' => 'https://open.bigmodel.cn/api/paas/v4',
                'requires_api_key' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function protocolRegistry(): array
    {
        $event = new ProviderProtocolEvent();
        foreach (self::builtinProtocolRegistry() as $item) {
            $event->register($item);
        }
        App::event()->dispatch($event, 'ai.provider.protocol');
        return array_values($event->getProtocols());
    }

    public static function protocolMeta(string $protocol): array
    {
        foreach (self::protocolRegistry() as $item) {
            if ((string)($item['value'] ?? '') === $protocol) {
                return $item;
            }
        }

        return [
            'value' => $protocol,
            'label' => $protocol,
            'description' => '',
            'default_base_url' => '',
            'requires_api_key' => true,
        ];
    }

    public static function defaultBaseUrl(string $protocol): string
    {
        return (string)(self::protocolMeta($protocol)['default_base_url'] ?? '');
    }

    public function mapToPairs(?array $items): array
    {
        if (!$items) {
            return [];
        }
        $result = [];
        foreach ($items as $key => $value) {
            $result[] = [
                'name' => (string)$key,
                'value' => $value,
            ];
        }
        return $result;
    }
}
