<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Service\Tool;

final class ToolConfigBuilder
{
    private static function sanitizeToolName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        $name = preg_replace('/[^a-z0-9_\\-]/', '_', $name) ?: '';
        $name = preg_replace('/_+/', '_', $name) ?: '';
        $name = trim($name, '_');
        return $name;
    }

    /**
     * @return array{defs: array<int, array<string, mixed>>, map: array<string, array<string, mixed>>}
     */
    public static function build(AiAgent $agent): array
    {
        $tools = is_array($agent->tools) ? $agent->tools : [];
        $defs = [];
        $map = [];
        foreach ($tools as $tool) {
            $toolCode = (string)($tool['code'] ?? '');
            if ($toolCode === '') {
                continue;
            }

            $registered = Tool::get($toolCode);
            if (!$registered) {
                continue;
            }

            $registeredFunction = $registered['function'] ?? null;
            $rawName = is_string($registeredFunction) && trim($registeredFunction) !== ''
                ? trim($registeredFunction)
                : (string)($tool['name'] ?? $toolCode);
            $name = self::sanitizeToolName($rawName);
            if ($name === '') {
                continue;
            }

            $schema = is_array($registered['schema'] ?? null)
                ? $registered['schema']
                : (is_array($tool['schema'] ?? null) ? $tool['schema'] : null);
            if ($schema === null) {
                $schema = [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ];
            }

            $description = (string)($tool['description'] ?? $registered['description'] ?? '');
            $defs[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $description,
                    'parameters' => $schema,
                ],
            ];

            $map[$name] = array_merge($tool, [
                'code' => $toolCode,
                'description' => $description,
                'schema' => $schema,
                'label' => $tool['label'] ?? $registered['label'] ?? $name,
            ]);
        }

        return ['defs' => $defs, 'map' => $map];
    }
}
