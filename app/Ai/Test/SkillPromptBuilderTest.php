<?php

use Core\App;
use Illuminate\Database\Schema\Blueprint;
use App\Ai\Models\AiAgent;
use App\Ai\Models\AiSkill;
use App\Ai\Service\Skill\PromptBuilder;

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
        $table->string('storage_path')->nullable();
        $table->string('compatibility')->default('full');
        $table->boolean('enabled')->default(true);
        $table->timestamps();
    });
});

it('技能提示词：按智能体绑定顺序拼接并替换 baseDir', function () {
    if (!is_dir(data_path('ai/skills/alpha-skill'))) {
        mkdir(data_path('ai/skills/alpha-skill'), 0777, true);
    }

    AiSkill::query()->create([
        'name' => 'alpha-skill',
        'title' => 'Alpha Skill',
        'description' => 'alpha desc',
        'content' => 'Use {baseDir}/run.sh',
        'frontmatter' => [
            'name' => 'alpha-skill',
            'description' => 'alpha desc',
        ],
        'storage_path' => 'ai/skills/alpha-skill',
        'compatibility' => 'full',
        'enabled' => true,
    ]);

    AiSkill::query()->create([
        'name' => 'hidden-skill',
        'description' => 'hidden',
        'content' => 'should not show',
        'frontmatter' => [
            'name' => 'hidden-skill',
            'description' => 'hidden',
            'disable-model-invocation' => true,
        ],
        'compatibility' => 'partial',
        'enabled' => true,
    ]);

    $agent = new AiAgent([
        'settings' => [
            'skill_codes' => ['alpha-skill', 'hidden-skill'],
        ],
    ]);

    $prompt = PromptBuilder::buildForAgent($agent);

    expect($prompt)->toContain('Alpha Skill')
        ->and($prompt)->toContain(data_path('ai/skills/alpha-skill'))
        ->and($prompt)->not->toContain('hidden-skill');
});
