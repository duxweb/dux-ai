<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Carbon\Carbon;
use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiFlowModel extends Model
{
    protected $table = 'ai_flow_model';

    protected $casts = [
        'call_count' => 'int',
        'success_count' => 'int',
        'fail_count' => 'int',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'last_status' => 'int',
        'last_model_id' => 'int',
        'last_used_at' => 'datetime',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('flow_id')->comment('流程 ID')->index();
        $table->string('node_id')->comment('节点 ID')->index();
        $table->string('node_type')->nullable()->comment('节点类型');
        $table->string('node_name')->nullable()->comment('节点名称');
        $table->unsignedBigInteger('call_count')->default(0)->comment('调用次数');
        $table->unsignedBigInteger('success_count')->default(0)->comment('成功次数');
        $table->unsignedBigInteger('fail_count')->default(0)->comment('失败次数');
        $table->unsignedBigInteger('prompt_tokens')->default(0)->comment('提示词 Token');
        $table->unsignedBigInteger('completion_tokens')->default(0)->comment('回复 Token');
        $table->unsignedBigInteger('total_tokens')->default(0)->comment('总 Token');
        $table->tinyInteger('last_status')->default(1)->comment('最后执行状态');
        $table->text('last_message')->nullable()->comment('最后异常信息');
        $table->unsignedBigInteger('last_model_id')->nullable()->comment('最后使用模型');
        $table->timestamp('last_used_at')->nullable()->comment('最后调用时间');
        $table->timestamps();

        $table->unique(['flow_id', 'node_id']);
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'node_id' => $this->node_id,
            'node_type' => $this->node_type,
            'node_name' => $this->node_name,
            'call_count' => (int)($this->call_count ?? 0),
            'success_count' => (int)($this->success_count ?? 0),
            'fail_count' => (int)($this->fail_count ?? 0),
            'prompt_tokens' => (int)($this->prompt_tokens ?? 0),
            'completion_tokens' => (int)($this->completion_tokens ?? 0),
            'total_tokens' => (int)($this->total_tokens ?? 0),
            'last_status' => (int)($this->last_status ?? 0),
            'last_message' => $this->last_message,
            'last_model_id' => $this->last_model_id ? (int)$this->last_model_id : null,
            'last_used_at' => $this->last_used_at instanceof Carbon ? $this->last_used_at->toDateTimeString() : null,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
