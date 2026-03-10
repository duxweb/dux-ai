<?php

use App\Ai\Service\Skill\Parser;

it('技能解析：支持标准 frontmatter 与 OpenClaw 扩展字段', function () {
    $markdown = <<<'MD'
---
name: demo-skill
description: 用于测试导入
disable-model-invocation: false
command-dispatch: tool
command-tool: demo_runner
metadata:
  openclaw:
    requires:
      bins:
        - uv
---

# Demo Skill

Use {baseDir} to run helper files.
MD;

    $result = Parser::parse($markdown);

    expect($result['name'])->toBe('demo-skill')
        ->and($result['title'])->toBe('Demo Skill')
        ->and($result['description'])->toBe('用于测试导入')
        ->and($result['command_dispatch'])->toBe('tool')
        ->and($result['command_tool'])->toBe('demo_runner')
        ->and($result['metadata'])->toHaveKey('openclaw')
        ->and($result['content'])->toContain('{baseDir}');
});

it('技能解析：缺少必要字段时抛出异常', function () {
    Parser::parse("---\ndescription: x\n---\ncontent");
})->throws(\Core\Handlers\ExceptionBusiness::class, 'SKILL.md 缺少 name');
