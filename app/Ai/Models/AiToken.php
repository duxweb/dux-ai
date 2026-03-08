<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiToken extends Model
{
    protected $table = 'ai_token';

    protected $casts = [
        'active' => 'boolean',
        'last_used_at' => 'datetime',
        'expired_at' => 'datetime',
        'models' => 'array',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->comment('Token 标识');
        $table->string('token')->unique()->comment('访问 Token 值');
        $table->boolean('active')->default(true)->comment('启用状态');
        $table->json('models')->nullable()->comment('允许访问的智能体 ID 列表');
        $table->timestamp('last_used_at')->nullable()->comment('最后使用时间');
        $table->timestamp('expired_at')->nullable()->comment('过期时间');
        $table->unsignedBigInteger('prompt_tokens')->default(0)->comment('提示词 Token 累计');
        $table->unsignedBigInteger('completion_tokens')->default(0)->comment('回复 Token 累计');
        $table->unsignedBigInteger('total_tokens')->default(0)->comment('总 Token 累计');
        $table->timestamps();
    }

    public static function generateToken(int $bytes = 24): string
    {
        $bytes = max(16, $bytes);
        return 'sk-' . bin2hex(random_bytes($bytes));
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'token' => $this->token,
            'active' => (bool)$this->active,
            'last_used_at' => $this->last_used_at?->toDateTimeString(),
            'expired_at' => $this->expired_at?->toDateTimeString(),
            'models' => $this->models ?? [],
            'prompt_tokens' => (int)($this->prompt_tokens ?? 0),
            'completion_tokens' => (int)($this->completion_tokens ?? 0),
            'total_tokens' => (int)($this->total_tokens ?? 0),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
