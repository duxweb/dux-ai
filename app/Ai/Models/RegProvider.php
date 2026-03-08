<?php

declare(strict_types=1);

namespace App\Ai\Models;

use App\System\Models\SystemStorage;
use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[AutoMigrate]
class RegProvider extends Model
{
    protected $table = 'rag_provider';

    protected $casts = [
        'config' => 'array',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->comment('配置名称');
        $table->string('code')->unique()->comment('配置标识');
        $table->string('provider')->comment('服务商标识');
        $table->unsignedBigInteger('storage_id')->nullable()->comment('存储驱动 ID');
        $table->unsignedBigInteger('vector_id')->nullable()->comment('向量库 ID');
        $table->unsignedBigInteger('embedding_model_id')->nullable()->comment('Embedding 模型 ID');
        $table->text('description')->nullable()->comment('说明');
        $table->json('config')->nullable()->comment('服务商配置');
        $table->timestamps();

        $table->index('provider');
        $table->index('code');
        $table->index('storage_id');
        $table->index('vector_id');
        $table->index('embedding_model_id');
    }

    public function knowledges(): HasMany
    {
        return $this->hasMany(RagKnowledge::class, 'config_id');
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(SystemStorage::class, 'storage_id');
    }

    public function vector(): BelongsTo
    {
        return $this->belongsTo(AiVector::class, 'vector_id');
    }

    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'embedding_model_id');
    }

    public function transform(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'provider' => $this->provider ?: 'neuron',
            'provider_name' => '内置知识库引擎',
            'storage_id' => $this->storage_id,
            'storage' => $this->storage ? [
                'id' => $this->storage->id,
                'name' => $this->storage->name,
                'title' => $this->storage->title,
                'type' => $this->storage->type,
                'type_name' => $this->storage->type_name,
            ] : null,
            'vector_id' => $this->vector_id,
            'vector' => $this->vector ? [
                'id' => $this->vector->id,
                'name' => $this->vector->name,
                'code' => $this->vector->code,
                'driver' => $this->vector->driver,
            ] : null,
            'embedding_model_id' => $this->embedding_model_id,
            'embedding_model' => $this->embeddingModel ? [
                'id' => $this->embeddingModel->id,
                'name' => $this->embeddingModel->name,
                'code' => $this->embeddingModel->code,
                'model' => $this->embeddingModel->model,
                'dimensions' => $this->embeddingModel->dimensions ? (int)$this->embeddingModel->dimensions : null,
            ] : null,
            'description' => $this->description,
            'config' => $this->config ?? [],
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
