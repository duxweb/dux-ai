<?php

use Core\App;
use Illuminate\Database\Schema\Blueprint;
use App\Ai\Models\AiSkill;
use App\Ai\Service\Skill\ImportService;

beforeEach(function () {
    $schema = App::db()->schema();
    $schema->dropIfExists('ai_skills');
    $schema->create('ai_skills', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('title')->nullable();
        $table->text('description')->nullable();
        $table->longText('content')->nullable();
        $table->json('frontmatter')->nullable();
        $table->string('source_type')->nullable();
        $table->text('source_url')->nullable();
        $table->text('source_path')->nullable();
        $table->string('storage_path')->nullable();
        $table->string('compatibility')->default('full');
        $table->boolean('enabled')->default(true);
        $table->timestamps();
    });
});

it('技能导入：支持从本地目录导入 skills 仓库', function () {
    $root = tempnam(sys_get_temp_dir(), 'skill_repo_');
    @unlink($root);
    mkdir($root, 0777, true);
    mkdir($root . '/skills/demo-skill', 0777, true);
    file_put_contents($root . '/skills/demo-skill/SKILL.md', <<<'MD'
---
name: demo-skill
description: 本地目录导入测试
---

# Demo

Run files from {baseDir}.
MD);

    $result = (new ImportService())->import($root, true);

    /** @var AiSkill|null $skill */
    $skill = AiSkill::query()->where('name', 'demo-skill')->first();

    expect($result['count'])->toBe(1)
        ->and($result['imported'])->toBe(['demo-skill'])
        ->and($skill)->not->toBeNull()
        ->and($skill?->storage_path)->toBe('ai/skills/demo-skill')
        ->and(is_file(data_path('ai/skills/demo-skill/SKILL.md')))->toBeTrue();
});

it('技能导入：overwrite 关闭时会跳过已有技能', function () {
    AiSkill::query()->create([
        'name' => 'demo-skill',
        'description' => 'existing',
        'content' => 'existing',
        'frontmatter' => ['name' => 'demo-skill', 'description' => 'existing'],
        'source_type' => 'manual',
        'compatibility' => 'full',
        'enabled' => true,
    ]);

    $root = tempnam(sys_get_temp_dir(), 'skill_repo_');
    @unlink($root);
    mkdir($root, 0777, true);
    file_put_contents($root . '/SKILL.md', <<<'MD'
---
name: demo-skill
description: overwrite skip
---

content
MD);

    $result = (new ImportService())->import($root, false);
    $skill = AiSkill::query()->where('name', 'demo-skill')->first();

    expect($result['skipped'])->toBe(['demo-skill'])
        ->and($skill?->description)->toBe('existing');
});
