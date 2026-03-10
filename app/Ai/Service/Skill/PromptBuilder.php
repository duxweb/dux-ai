<?php

declare(strict_types=1);

namespace App\Ai\Service\Skill;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiSkill;

final class PromptBuilder
{
    public static function buildForAgent(AiAgent $agent): string
    {
        $settings = is_array($agent->settings ?? null) ? ($agent->settings ?? []) : [];
        $skillCodes = self::normalizeSkillCodes($settings['skill_codes'] ?? []);
        if ($skillCodes === []) {
            return '';
        }

        $skills = AiSkill::query()
            ->where('enabled', true)
            ->whereIn('name', $skillCodes)
            ->get()
            ->keyBy('name');

        $blocks = [];
        foreach ($skillCodes as $code) {
            /** @var AiSkill|null $skill */
            $skill = $skills->get($code);
            if (!$skill) {
                continue;
            }

            $frontmatter = is_array($skill->frontmatter ?? null) ? ($skill->frontmatter ?? []) : [];
            if ((bool)($frontmatter['disable-model-invocation'] ?? false)) {
                continue;
            }

            $content = trim((string)$skill->content);
            if ($content === '') {
                continue;
            }

            $location = '';
            if ($skill->storage_path) {
                $location = data_path(trim((string)$skill->storage_path, '/'));
                if (is_dir($location)) {
                    $content = str_replace('{baseDir}', $location, $content);
                } else {
                    $location = '';
                }
            }

            $lines = [
                sprintf('### %s', $skill->title ?: $skill->name),
                sprintf('技能标识：%s', $skill->name),
                sprintf('技能描述：%s', $skill->description ?: '无'),
            ];
            if ($location !== '') {
                $lines[] = sprintf('技能目录：%s', $location);
            }
            $lines[] = "技能内容：\n" . $content;

            $blocks[] = implode("\n", $lines);
        }

        if ($blocks === []) {
            return '';
        }

        return trim(implode("\n\n", [
            '以下是当前会话可用技能。只有当用户请求与技能描述匹配时，才遵循相应技能中的流程、约束和操作步骤。',
            implode("\n\n---\n\n", $blocks),
        ]));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function normalizeSkillCodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $name = trim((string)$item);
            if ($name === '') {
                continue;
            }
            $result[] = $name;
        }

        return array_values(array_unique($result));
    }
}
