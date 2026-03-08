<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[AutoMigrate]
class AiFlow extends Model
{
    protected $table = 'ai_flow';

    protected $casts = [
        'flow' => 'array',
        'global_settings' => 'array',
        'status' => 'boolean',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->comment('流程名称');
        $table->string('code')->unique()->comment('流程标识');
        $table->text('description')->nullable()->comment('说明');
        $table->json('flow')->nullable()->comment('流程数据');
        $table->json('global_settings')->nullable()->comment('全局设置');
        $table->boolean('status')->default(true)->comment('启用状态');
        $table->unsignedBigInteger('prompt_tokens')->default(0)->comment('提示词 Token 累计');
        $table->unsignedBigInteger('completion_tokens')->default(0)->comment('回复 Token 累计');
        $table->unsignedBigInteger('total_tokens')->default(0)->comment('总 Token 累计');
        $table->timestamps();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AiFlowLog::class, 'flow_id');
    }

    public function latestLog(): HasOne
    {
        return $this->hasOne(AiFlowLog::class, 'flow_id')->latestOfMany();
    }

    public function transform(): array
    {
        $logCount = $this->getAttribute('log_count');
        if ($logCount === null) {
            $logCount = $this->logs()->count();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'flow' => $this->flow ?? ['nodes' => [], 'edges' => []],
            'global_settings' => $this->global_settings ?? [],
            'status' => (bool)$this->status,
            'log_count' => (int)$logCount,
            'last_log_at' => $this->latestLog?->created_at?->toDateTimeString(),
            'prompt_tokens' => (int)($this->prompt_tokens ?? 0),
            'completion_tokens' => (int)($this->completion_tokens ?? 0),
            'total_tokens' => (int)($this->total_tokens ?? 0),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
