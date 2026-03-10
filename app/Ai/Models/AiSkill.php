<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiSkill extends Model
{
    protected $table = 'ai_skills';

    protected $casts = [
        'frontmatter' => 'array',
        'enabled' => 'boolean',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->unique()->comment('技能标识');
        $table->string('title')->nullable()->comment('技能标题');
        $table->text('description')->nullable()->comment('技能描述');
        $table->longText('content')->nullable()->comment('技能正文');
        $table->json('frontmatter')->nullable()->comment('前置元数据');
        $table->string('source_type')->nullable()->comment('来源类型');
        $table->text('source_url')->nullable()->comment('来源地址');
        $table->text('source_path')->nullable()->comment('来源路径');
        $table->string('storage_path')->nullable()->comment('本地存储路径');
        $table->string('compatibility')->default('full')->comment('兼容等级');
        $table->boolean('enabled')->default(true)->comment('启用状态');
        $table->timestamps();
    }

    public function transform(): array
    {
        $frontmatter = is_array($this->frontmatter ?? null) ? ($this->frontmatter ?? []) : [];
        $metadata = is_array($frontmatter['metadata'] ?? null) ? ($frontmatter['metadata'] ?? []) : [];
        $storageAbsolute = '';
        if ($this->storage_path) {
            $storageAbsolute = data_path(trim((string)$this->storage_path, '/'));
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title ?: $this->name,
            'description' => $this->description,
            'content' => $this->content,
            'frontmatter' => $frontmatter,
            'metadata' => $metadata,
            'source_type' => $this->source_type,
            'source_type_name' => match ((string)$this->source_type) {
                'manual' => '手动维护',
                'local' => '本地目录',
                'url' => '远程导入',
                default => '未知来源',
            },
            'source_url' => $this->source_url,
            'source_path' => $this->source_path,
            'source_label' => $this->source_url ?: $this->source_path,
            'storage_path' => $this->storage_path,
            'storage_absolute_path' => $storageAbsolute,
            'compatibility' => $this->compatibility,
            'compatibility_name' => match ((string)$this->compatibility) {
                'partial' => '部分兼容',
                'manual' => '手动处理',
                default => '完整兼容',
            },
            'disable_model_invocation' => (bool)($frontmatter['disable-model-invocation'] ?? false),
            'user_invocable' => array_key_exists('user-invocable', $frontmatter) ? (bool)$frontmatter['user-invocable'] : true,
            'command_dispatch' => (string)($frontmatter['command-dispatch'] ?? ''),
            'command_tool' => (string)($frontmatter['command-tool'] ?? ''),
            'has_assets' => $storageAbsolute !== '' && is_dir($storageAbsolute),
            'enabled' => (bool)$this->enabled,
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
