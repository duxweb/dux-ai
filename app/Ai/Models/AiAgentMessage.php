<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiAgentMessage extends Model
{
    protected $table = 'ai_agent_messages';

    protected $casts = [
        'payload' => 'array',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('agent_id')->comment('智能体 ID');
        $table->unsignedBigInteger('session_id')->comment('会话 ID');
        $table->string('role')->comment('user/assistant/tool/system');
        $table->string('tool')->nullable()->comment('工具名称（若为工具响应）');
        $table->string('tool_call_id')->nullable()->comment('工具调用标识');
        $table->text('content')->nullable()->comment('消息内容（纯文本/给模型使用）');
        $table->json('payload')->nullable()->comment('结构化内容（parts/attachments/tool_calls/tool_raw 等）');
        $table->timestamps();
    }

    public function session()
    {
        return $this->belongsTo(AiAgentSession::class, 'session_id');
    }

    public function agent()
    {
        return $this->belongsTo(AiAgent::class, 'agent_id');
    }

    public function transform(): array
    {
        $payload = $this->payload ?? [];
        if (is_string($payload)) {
            $trimmed = trim($payload);
            if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_validate($payload)) {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'session_id' => $this->session_id,
            'role' => $this->role,
            'tool' => $this->tool,
            'tool_call_id' => $this->tool_call_id,
            'content' => (string)($this->content ?? ''),
            'payload' => $payload,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
