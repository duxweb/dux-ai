<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiAgentSession extends Model
{
    protected $table = 'ai_agent_sessions';

    protected $casts = [
        'state' => 'array',
        'memory' => 'array',
        'last_result' => 'array',
        'active' => 'boolean',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('agent_id')->comment('智能体 ID');
        $table->string('title')->nullable()->comment('会话标题');
        $table->string('external_id')->nullable()->comment('外部会话标识（例如 chat_id）');
        $table->string('user_type')->nullable()->comment('多态用户类型，例如 App\\User');
        $table->unsignedBigInteger('user_id')->nullable()->comment('多态用户 ID');
        $table->json('state')->nullable()->comment('运行时状态/变量');
        $table->json('memory')->nullable()->comment('记忆摘要/向量引用');
        $table->json('last_result')->nullable()->comment('最近一次推理结果');
        $table->boolean('active')->default(true)->comment('是否活跃');
        $table->timestamp('last_message_at')->nullable()->comment('最后消息时间');
        $table->unsignedBigInteger('prompt_tokens')->default(0)->comment('提示词 Token 累计');
        $table->unsignedBigInteger('completion_tokens')->default(0)->comment('回复 Token 累计');
        $table->unsignedBigInteger('total_tokens')->default(0)->comment('总 Token 累计');
        $table->timestamps();

        $table->index(['agent_id', 'user_type', 'user_id', 'external_id'], 'ai_agent_session_ext_idx');
    }

    public function agent()
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    public function user()
    {
        return $this->morphTo();
    }

    public function messages()
    {
        return $this->hasMany(AiAgentMessage::class, 'session_id');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'title' => $this->title,
            'external_id' => $this->external_id,
            'user_type' => $this->user_type,
            'user_id' => $this->user_id,
            'state' => $this->state ?? [],
            'memory' => $this->memory ?? [],
            'last_result' => $this->last_result ?? [],
            'active' => (bool)$this->active,
            'last_message_at' => $this->last_message_at?->toDateTimeString(),
            'prompt_tokens' => (int)($this->prompt_tokens ?? 0),
            'completion_tokens' => (int)($this->completion_tokens ?? 0),
            'total_tokens' => (int)($this->total_tokens ?? 0),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
