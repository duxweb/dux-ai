<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[AutoMigrate]
class RagKnowledge extends Model
{
    protected $table = 'rag_knowledge';

    protected $casts = [
        'is_async' => 'boolean',
        'status' => 'boolean',
        'settings' => 'array',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('config_id')->comment('配置 ID');
        $table->string('name')->comment('知识库名称');
        $table->string('base_id')->nullable()->comment('远端知识库 ID');
        $table->text('description')->nullable()->comment('说明');
        $table->json('settings')->nullable()->comment('知识库默认入库/解析参数（可被导入时覆盖）');
        $table->boolean('is_async')->default(false)->comment('是否已同步远端');
        $table->boolean('status')->default(true)->comment('状态：1 启用');
        $table->timestamps();

        $table->index('config_id');
        $table->index('base_id');
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(RegProvider::class, 'config_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RagKnowledgeData::class, 'knowledge_id');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'config_id' => $this->config_id,
            'config_name' => $this->config?->name,
            'config_code' => $this->config?->code,
            'config_provider' => $this->config?->provider,
            'config_storage_id' => $this->config?->storage_id,
            'config_storage_title' => $this->config?->storage?->title,
            'name' => $this->name,
            'base_id' => $this->base_id,
            'description' => $this->description,
            'settings' => $this->settings ?? [],
            'is_async' => (bool)$this->is_async,
            'status' => (bool)$this->status,
            'document_count' => $this->entries()->count(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
