<?php

declare(strict_types=1);

namespace App\Ai\Models;

use App\Ai\Service\AiConfig;
use App\Ai\Service\Capability;
use App\Ai\Service\Neuron\Agent\ModelRateLimiter;
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
        $table->string('callback_name')->nullable()->comment('回调名称');
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
        $schedule = $this->resolveScheduleMeta();
        $callbackName = $this->resolveCallbackName();
        $model = $this->resolveRelatedModel();
        $rateLimit = $model ? ModelRateLimiter::inspectForModel($model) : [
            'model_key' => '',
            'model_limit' => 0,
            'global_limit' => max(0, (int)AiConfig::getValue('rate_limit.tpm', 0)),
            'effective_limit' => max(0, (int)AiConfig::getValue('rate_limit.tpm', 0)),
            'model_concurrency' => 0,
            'global_concurrency' => max(0, (int)AiConfig::getValue('rate_limit.concurrency', 0)),
            'effective_concurrency' => max(0, (int)AiConfig::getValue('rate_limit.concurrency', 0)),
            'max_wait_ms' => max(0, (int)AiConfig::getValue('rate_limit.max_wait_ms', 8000)),
        ];

        return [
            'id' => $this->id,
            'callback_type' => (string)$this->callback_type,
            'callback_code' => (string)$this->callback_code,
            'callback_name' => $callbackName,
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
            'schedule' => $schedule,
            'schedule_cron' => $schedule['cron'],
            'schedule_interval_minutes' => $schedule['interval_minutes'],
            'schedule_recurring' => $schedule['recurring'],
            'schedule_desc' => $schedule['desc'],
            'model_id' => $model?->id,
            'model_name' => $model?->name,
            'model_code' => $model?->code,
            'provider_code' => $model?->provider?->code,
            'rate_limit' => $rateLimit,
            'locked_at' => $this->locked_at?->toDateTimeString(),
            'locked_by' => $this->locked_by,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function resolveCallbackName(): string
    {
        if ($this->callback_name) {
            return (string)$this->callback_name;
        }

        if ((string)$this->callback_type === 'capability') {
            $capability = Capability::get((string)$this->callback_code);
            if ($capability) {
                $label = trim((string)($capability['label'] ?? $capability['name'] ?? ''));
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return match ((string)$this->callback_type) {
            'video' => '视频任务轮询',
            'flow' => '流程恢复轮询',
            default => (string)$this->callback_code,
        };
    }

    /**
     * @return array{cron:?string,interval_minutes:int,recurring:bool,desc:string}
     */
    private function resolveScheduleMeta(): array
    {
        $params = is_array($this->callback_params ?? null) ? ($this->callback_params ?? []) : [];
        $schedule = is_array($params['__schedule'] ?? null) ? ($params['__schedule'] ?? []) : [];
        $cron = trim((string)($schedule['cron'] ?? '')) ?: null;
        $intervalMinutes = max(0, (int)($schedule['interval_minutes'] ?? 0));
        $recurring = (bool)($schedule['recurring'] ?? false);

        $desc = '单次执行';
        if ($cron) {
            $desc = sprintf('Cron: %s', $cron);
        } elseif ($intervalMinutes > 0) {
            $desc = sprintf('每 %d 分钟执行', $intervalMinutes);
        } elseif ($recurring) {
            $desc = '周期执行';
        }

        return [
            'cron' => $cron,
            'interval_minutes' => $intervalMinutes,
            'recurring' => $recurring,
            'desc' => $desc,
        ];
    }

    private function resolveRelatedModel(): ?AiModel
    {
        $params = is_array($this->callback_params ?? null) ? ($this->callback_params ?? []) : [];
        $modelId = (int)($params['model_id'] ?? 0);
        if ($modelId > 0) {
            return AiModel::query()->with('provider')->find($modelId);
        }

        if ((string)$this->source_type === 'agent' && $this->source_id) {
            $session = AiAgentSession::query()->with(['agent.model.provider'])->find((int)$this->source_id);
            if ($session?->agent?->model) {
                return $session->agent->model;
            }
        }

        return null;
    }
}
