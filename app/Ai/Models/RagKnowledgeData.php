<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[AutoMigrate]
class RagKnowledgeData extends Model
{
    protected $table = 'rag_knowledge_data';

    protected $casts = [
        'is_async' => 'boolean',
        'file_size' => 'int',
        'doc_ids' => 'array',
        'meta' => 'array',
    ];

    protected $appends = [
        'type_name',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('knowledge_id')->comment('知识库 ID');
        $table->string('type', 30)->default('document')->comment('文档类型（document/qa/sheet）');
        $table->string('source_type')->nullable()->comment('NeuronAI Document sourceType（用于删除/重建）');
        $table->string('source_name')->nullable()->comment('NeuronAI Document sourceName（用于删除/重建）');
        $table->json('doc_ids')->nullable()->comment('可选：向量库内部文档 ID 列表（若支持返回）');
        $table->json('meta')->nullable()->comment('入库元数据（解析信息等）');
        $table->string('storage_name')->nullable()->comment('存储驱动标识');
        $table->string('url')->nullable()->comment('文件链接或保存路径');
        $table->string('file_path')->nullable()->comment('文件存储路径');
        $table->string('file_name')->nullable()->comment('文件原始名称');
        $table->unsignedBigInteger('file_size')->nullable()->comment('文件大小（字节）');
        $table->string('file_type')->nullable()->comment('文件类型');
        $table->boolean('is_async')->default(false)->comment('是否已同步远端');
        $table->timestamps();

        $table->index('knowledge_id');
        // Keep index name short for MySQL (identifier length limit).
        $table->index(['knowledge_id', 'source_type', 'source_name'], 'rk_k_src_idx');
    }

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(RagKnowledge::class, 'knowledge_id');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'knowledge_id' => $this->knowledge_id,
            'knowledge_name' => $this->knowledge?->name,
            'knowledge_base_id' => $this->knowledge?->base_id,
            'type' => $this->type,
            'type_name' => $this->type_name,
            'source_type' => $this->source_type,
            'source_name' => $this->source_name,
            'doc_ids' => $this->doc_ids ?? [],
            'meta' => $this->meta ?? [],
            'storage_name' => $this->storage_name,
            'url' => $this->url,
            'file_path' => $this->file_path,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'file_type' => $this->file_type,
            'is_async' => (bool)$this->is_async,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            'document' => '文档',
            'qa' => '问答',
            'sheet' => '表格',
            default => $this->type ?? '',
        };
    }
}
