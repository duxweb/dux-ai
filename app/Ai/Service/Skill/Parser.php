<?php

declare(strict_types=1);

namespace App\Ai\Service\Skill;

use Core\Handlers\ExceptionBusiness;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class Parser
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '') {
            throw new ExceptionBusiness('SKILL.md 不能为空');
        }

        $frontmatter = [];
        $content = $markdown;
        if (preg_match("/\A---\n(.*?)\n---\n?(.*)\z/s", $markdown, $matches)) {
            try {
                $frontmatter = Yaml::parse(trim((string)$matches[1]));
            } catch (Throwable $e) {
                throw new ExceptionBusiness('SKILL.md frontmatter 格式错误');
            }
            if (!is_array($frontmatter)) {
                throw new ExceptionBusiness('SKILL.md frontmatter 格式错误');
            }
            $content = ltrim((string)$matches[2]);
        }

        $name = trim((string)($frontmatter['name'] ?? ''));
        if ($name === '') {
            throw new ExceptionBusiness('SKILL.md 缺少 name');
        }

        $description = trim((string)($frontmatter['description'] ?? ''));
        if ($description === '') {
            throw new ExceptionBusiness('SKILL.md 缺少 description');
        }

        $metadata = $frontmatter['metadata'] ?? [];
        if (is_string($metadata) && trim($metadata) !== '' && json_validate($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $frontmatter['metadata'] = $metadata;

        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            $title = trim((string)$matches[1]);
        }

        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'content' => trim($content),
            'frontmatter' => $frontmatter,
            'metadata' => $metadata,
            'disable_model_invocation' => (bool)($frontmatter['disable-model-invocation'] ?? false),
            'user_invocable' => array_key_exists('user-invocable', $frontmatter) ? (bool)$frontmatter['user-invocable'] : true,
            'command_dispatch' => trim((string)($frontmatter['command-dispatch'] ?? '')),
            'command_tool' => trim((string)($frontmatter['command-tool'] ?? '')),
        ];
    }
}
