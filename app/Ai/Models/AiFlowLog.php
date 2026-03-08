<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[AutoMigrate]
class AiFlowLog extends Model
{
    protected $table = 'ai_flow_log';

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'logs' => 'array',
        'context' => 'array',
        'duration' => 'float',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('flow_id')->nullable()->comment('流程 ID');
        $table->string('flow_code')->comment('流程标识');
        $table->string('workflow_id')->nullable()->comment('工作流执行ID');
        $table->tinyInteger('status')->default(1)->comment('执行状态');
        $table->text('message')->nullable()->comment('错误信息');
        $table->json('input')->nullable()->comment('执行输入');
        $table->json('output')->nullable()->comment('执行输出');
        $table->json('logs')->nullable()->comment('节点日志');
        $table->json('context')->nullable()->comment('上下文数据');
        $table->unsignedBigInteger('prompt_tokens')->default(0)->comment('提示词 Token');
        $table->unsignedBigInteger('completion_tokens')->default(0)->comment('回复 Token');
        $table->unsignedBigInteger('total_tokens')->default(0)->comment('总 Token');
        $table->decimal('duration', 11, 5)->default(0)->comment('用时（秒）');
        $table->timestamps();

        $table->index('flow_code');
        $table->unique('workflow_id');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'flow_code' => $this->flow_code,
            'workflow_id' => $this->workflow_id,
            'status' => (int)$this->status,
            'message' => $this->message,
            'input' => $this->input ?? [],
            'output' => $this->output ?? [],
            'logs' => $this->logs ?? [],
            'context' => $this->context ?? [],
            'prompt_tokens' => (int)($this->prompt_tokens ?? 0),
            'completion_tokens' => (int)($this->completion_tokens ?? 0),
            'total_tokens' => (int)($this->total_tokens ?? 0),
            'token_total' => (float)($this->total_tokens ?? 0),
            'duration' => $this->duration,
            'flow_name' => $this->flow?->name,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(AiFlow::class, 'flow_id');
    }
}
