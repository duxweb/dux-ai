<?php

declare(strict_types=1);

namespace App\Ai\Models;

use App\Ai\Service\VectorStore;
use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiVector extends Model
{
    protected $table = 'ai_vectors';

    protected $casts = [
        'active' => 'boolean',
        'options' => 'array',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->comment('向量库名称');
        $table->string('code')->unique()->comment('调用标识');
        $table->string('driver')->comment('向量库驱动(file/memory/qdrant/chroma)');
        $table->json('options')->nullable()->comment('向量库参数（键值对）');
        $table->boolean('active')->default(true)->comment('启用状态');
        $table->text('description')->nullable()->comment('说明');
        $table->timestamps();

        $table->index('driver');
        $table->index('code');
        $table->index('active');
    }

    public function transform(): array
    {
        $driver = $this->driver ?: 'file';
        $meta = VectorStore::driverMeta($driver);
        $driverTitle = $meta['label'] ?? $meta['name'] ?? $driver;
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'driver' => $driver,
            'driver_name' => $driverTitle,
            'options' => $this->options ?? [],
            'active' => $this->active,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
