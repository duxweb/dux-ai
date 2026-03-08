<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use App\Ai\Service\Agent\AttachmentConfig;
use App\Ai\Support\AiRuntime;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiModel extends Model
{
    protected $table = 'ai_models';

    public const TYPE_CHAT = 'chat';
    public const TYPE_EMBEDDING = 'embedding';
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const QUOTA_TYPE_ONCE = 'once';
    public const QUOTA_TYPE_DAILY = 'daily';
    public const QUOTA_TYPE_MONTHLY = 'monthly';

    protected $casts = [
        'active' => 'boolean',
        'options' => 'array',
        'supports_structured_output' => 'boolean',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'dimensions' => 'int',
        'quota_tokens' => 'int',
        'quota_used' => 'int',
        'quota_reset_at' => 'datetime',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('provider_id')->comment('服务商 ID');
        $table->string('name')->comment('模型名称');
        $table->string('code')->unique()->comment('调用标识');
        $table->string('model')->comment('远端模型 ID');
        $table->string('type')->default(self::TYPE_CHAT)->comment('模型类型(chat/embedding/image/video)');
        $table->unsignedInteger('dimensions')->nullable()->comment('Embedding 维度');
        $table->string('icon')->nullable()->comment('图标');
        $table->json('options')->nullable()->comment('扩展配置');
        $table->boolean('active')->default(true)->comment('启用状态');
        $table->boolean('supports_structured_output')->default(false)->comment('支持结构化输出');
        $table->text('description')->nullable()->comment('说明');
        $table->unsignedBigInteger('prompt_tokens')->default(0)->comment('提示词 Token 累计');
        $table->unsignedBigInteger('completion_tokens')->default(0)->comment('回复 Token 累计');
        $table->unsignedBigInteger('total_tokens')->default(0)->comment('总 Token 累计');
        $table->string('quota_type')->default(self::QUOTA_TYPE_ONCE)->comment('额度类型(once/daily/monthly)');
        $table->unsignedBigInteger('quota_tokens')->default(0)->comment('额度 Token');
        $table->unsignedBigInteger('quota_used')->default(0)->comment('已用额度 Token');
        $table->timestamp('quota_reset_at')->nullable()->comment('额度重置时间');
        $table->timestamps();
    }

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function transform(): array
    {
        $type = $this->type ?: self::TYPE_CHAT;
        $options = is_array($this->options ?? null) ? ($this->options ?? []) : [];
        $attachments = AttachmentConfig::normalize($options['attachments'] ?? []);
        $options['attachments'] = $attachments;

        return [
            'id' => $this->id,
            'provider_id' => $this->provider_id,
            'provider' => $this->provider?->name,
            'provider_code' => $this->provider?->code,
            'name' => $this->name,
            'code' => $this->code,
            'model' => $this->model,
            'type' => $type,
            'type_name' => match ($type) {
                self::TYPE_EMBEDDING => 'Embeddings',
                self::TYPE_IMAGE => 'Image',
                self::TYPE_VIDEO => 'Video',
                default => 'Chat',
            },
            'dimensions' => $this->dimensions ? (int)$this->dimensions : null,
            'icon' => $this->icon,
            'options' => $options,
            'attachments' => $attachments,
            'active' => $this->active,
            'supports_structured_output' => (bool)($this->supports_structured_output ?? false),
            'description' => $this->description,
            'prompt_tokens' => (int)($this->prompt_tokens ?? 0),
            'completion_tokens' => (int)($this->completion_tokens ?? 0),
            'total_tokens' => (int)($this->total_tokens ?? 0),
            'quota_type' => $this->quota_type ?: self::QUOTA_TYPE_ONCE,
            'quota_tokens' => (int)($this->quota_tokens ?? 0),
            'quota_used' => (int)($this->quota_used ?? 0),
            'quota_reset_at' => $this->quota_reset_at?->toDateTimeString(),
            'quota_remaining' => $this->quotaRemaining(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    // 计算当前剩余额度，null 表示不限额
    public function quotaRemaining(?Carbon $now = null): ?int
    {
        $limit = (int)($this->quota_tokens ?? 0);
        if ($limit <= 0) {
            return null;
        }

        $type = $this->quota_type ?: self::QUOTA_TYPE_ONCE;
        $now = $now ?: Carbon::now();
        $used = 0;

        if ($type === self::QUOTA_TYPE_DAILY || $type === self::QUOTA_TYPE_MONTHLY) {
            $resetAt = $this->quota_reset_at;
            if (!$resetAt instanceof Carbon) {
                $used = 0;
            } elseif ($resetAt->lessThanOrEqualTo($now)) {
                $used = 0;
            } else {
                $used = (int)($this->quota_used ?? 0);
            }
        } else {
            $used = (int)($this->total_tokens ?? 0);
        }

        $remaining = $limit - $used;
        return $remaining > 0 ? $remaining : 0;
    }

    // 记录模型用量，并在按天/按月策略下自动重置
    public static function recordUsage(int $modelId, int $promptTokens, int $completionTokens, int $totalTokens): void
    {
        if ($totalTokens <= 0) {
            return;
        }

        /** @var AiModel|null $model */
        $model = self::query()->find($modelId);
        if (!$model) {
            return;
        }

        $now = Carbon::now();
        $db = AiRuntime::instance()->db()->getConnection();
        $updates = [
            'prompt_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(prompt_tokens,0) + %d)', $promptTokens)),
            'completion_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(completion_tokens,0) + %d)', $completionTokens)),
            'total_tokens' => $db->raw(sprintf('GREATEST(0, COALESCE(total_tokens,0) + %d)', $totalTokens)),
        ];

        $type = $model->quota_type ?: self::QUOTA_TYPE_ONCE;
        if ($model->quota_tokens && in_array($type, [self::QUOTA_TYPE_DAILY, self::QUOTA_TYPE_MONTHLY], true)) {
            $resetAt = $model->quota_reset_at;
            $needReset = $resetAt instanceof Carbon ? $resetAt->lessThanOrEqualTo($now) : true;
            if ($needReset) {
                $resetAt = $type === self::QUOTA_TYPE_MONTHLY
                    ? $now->copy()->endOfMonth()
                    : $now->copy()->endOfDay();
                self::query()
                    ->where('id', $modelId)
                    ->update([
                        'quota_used' => 0,
                        'quota_reset_at' => $resetAt,
                    ]);
            }

            $updates['quota_used'] = $db->raw(sprintf('GREATEST(0, COALESCE(quota_used,0) + %d)', $totalTokens));
            if ($resetAt instanceof Carbon) {
                $updates['quota_reset_at'] = $resetAt;
            }
        }

        self::query()->where('id', $modelId)->update($updates);
    }
}
