<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiScheduler extends Model
{
    protected $table = 'ai_scheduler';

    protected $casts = [
        'callback_params' => 'array',
        'result' => 'array',
        'execute_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('callback_type')->comment('回调类型');
        $table->string('callback_code')->comment('回调编码');
        $table->string('callback_action')->nullable()->comment('回调动作');
        $table->string('workflow_id')->nullable()->comment('流程工作流 ID');
        $table->string('dedupe_key')->unique()->comment('任务幂等键');
        $table->string('status')->default('pending')->comment('pending/running/retrying/success/failed/canceled');
        $table->timestamp('execute_at')->nullable()->comment('下次执行时间');
        $table->unsignedInteger('attempts')->default(0)->comment('已尝试次数');
        $table->unsignedInteger('max_attempts')->default(3)->comment('最大尝试次数');
        $table->json('callback_params')->nullable()->comment('回调参数');
        $table->json('result')->nullable()->comment('执行结果');
        $table->text('last_error')->nullable()->comment('最后错误');
        $table->string('source_type')->default('api')->comment('agent/flow/api');
        $table->unsignedBigInteger('source_id')->nullable()->comment('来源ID');
        $table->timestamp('locked_at')->nullable()->comment('锁定时间');
        $table->string('locked_by')->nullable()->comment('锁定实例');
        $table->timestamps();

        $table->index(['status', 'execute_at'], 'ai_sched_status_exec_idx');
        $table->index(['callback_type', 'callback_code'], 'ai_sched_callback_idx');
        $table->index(['workflow_id', 'status'], 'ai_sched_wf_status_idx');
        $table->index(['workflow_id'], 'ai_sched_wf_idx');
        $table->index(['source_type', 'source_id'], 'ai_sched_source_idx');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'callback_type' => (string)$this->callback_type,
            'callback_code' => (string)$this->callback_code,
            'callback_action' => $this->callback_action ? (string)$this->callback_action : null,
            'workflow_id' => $this->workflow_id ? (string)$this->workflow_id : null,
            'dedupe_key' => (string)$this->dedupe_key,
            'status' => (string)$this->status,
            'execute_at' => $this->execute_at?->toDateTimeString(),
            'attempts' => (int)$this->attempts,
            'max_attempts' => (int)$this->max_attempts,
            'callback_params' => is_array($this->callback_params) ? $this->callback_params : [],
            'result' => is_array($this->result) ? $this->result : null,
            'last_error' => $this->last_error,
            'source_type' => (string)$this->source_type,
            'source_id' => $this->source_id !== null ? (int)$this->source_id : null,
            'locked_at' => $this->locked_at?->toDateTimeString(),
            'locked_by' => $this->locked_by,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
