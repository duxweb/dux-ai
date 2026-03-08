<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiAgent extends Model
{
    protected $table = 'ai_agents';

    protected $casts = [
        'tools' => 'array',
        'settings' => 'array',
        'active' => 'boolean',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('model_id')->nullable()->comment('绑定的底层模型 ID');
        $table->string('name')->comment('智能体名称');
        $table->string('code')->unique()->comment('智能体标识');
        $table->text('instructions')->nullable()->comment('系统提示/角色设定');
        $table->json('tools')->nullable()->comment('可用工具列表（function、http、mcp 等）');
        $table->json('settings')->nullable()->comment('调度/记忆等配置');
        $table->boolean('active')->default(true)->comment('启用状态');
        $table->text('description')->nullable()->comment('说明');
        $table->timestamps();
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function sessions()
    {
        return $this->hasMany(AiAgentSession::class, 'agent_id');
    }

    public function transform(): array
    {
        $settings = is_array($this->settings ?? null) ? ($this->settings ?? []) : [];
        if (!is_array($settings['bot_codes'] ?? null)) {
            $settings['bot_codes'] = [];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'model_id' => $this->model_id,
            'model' => $this->model?->transform(),
            'instructions' => $this->instructions,
            'tools' => $this->tools ?? [],
            'settings' => $settings,
            'active' => (bool)$this->active,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
