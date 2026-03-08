<?php

declare(strict_types=1);

namespace App\Ai\Models;

use App\Ai\Service\Parse;
use App\System\Models\SystemStorage;
use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class ParseProvider extends Model
{
    protected $table = "parse_provider";

    protected $casts = [
        "config" => "array",
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string("name")->comment("配置名称");
        $table->string("code")->unique()->comment("配置标识");
        $table->string("provider")->comment("解析驱动");
        $table->unsignedBigInteger("storage_id")->nullable()->comment("存储驱动 ID");
        $table->text("description")->nullable()->comment("说明");
        $table->json("config")->nullable()->comment("驱动配置");
        $table->timestamps();

        $table->index("provider");
        $table->index("code");
        $table->index("storage_id");
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(SystemStorage::class, "storage_id");
    }

    public function transform(): array
    {
        $providerMeta = Parse::providerMeta($this->provider);
        $providerTitle = $providerMeta["label"] ?? $providerMeta["name"] ?? $this->provider;

        return [
            "id" => $this->id,
            "name" => $this->name,
            "code" => $this->code,
            "provider" => $this->provider,
            "provider_name" => $providerTitle,
            "storage_id" => $this->storage_id,
            "storage" => $this->storage ? [
                "id" => $this->storage->id,
                "name" => $this->storage->name,
                "title" => $this->storage->title,
                "type" => $this->storage->type,
                "type_name" => $this->storage->type_name,
            ] : null,
            "description" => $this->description,
            "config" => $this->config ?? [],
            "created_at" => $this->created_at?->toDateTimeString(),
            "updated_at" => $this->updated_at?->toDateTimeString(),
        ];
    }
}
