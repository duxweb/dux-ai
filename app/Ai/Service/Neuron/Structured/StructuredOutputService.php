<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Structured;

use App\Ai\Models\AiModel;
use App\Ai\Service\AI;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Chat\Messages\UserMessage;

final class StructuredOutputService
{
    /**
     * @param array<string, mixed> $providerOverrides
     * @return array{
     *   mode_used: 'text'|'structured',
     *   content: string,
     *   data: mixed,
     *   usage: array<string, int>|null,
     *   errors: array<int, string>
     * }
     */
    public static function run(
        AiModel $model,
        string $prompt,
        ?string $systemPrompt = null,
        string $outputMode = 'auto',
        array $structuredSchema = [],
        array $providerOverrides = [],
        ?int $timeoutSeconds = null,
    ): array {
        $outputMode = self::normalizeOutputMode($outputMode);
        $supportsStructured = (bool)($model->supports_structured_output ?? false);
        $schemaExists = self::hasSchema($structuredSchema);

        $shouldTryStructured = match ($outputMode) {
            'text' => false,
            'structured' => $supportsStructured && $schemaExists,
            default => $supportsStructured && $schemaExists,
        };

        $errors = [];
        if ($outputMode === 'structured' && !$supportsStructured) {
            throw new ExceptionBusiness('结构化输出失败：模型未开启结构化输出');
        }
        if ($outputMode === 'structured' && !$schemaExists) {
            throw new ExceptionBusiness('结构化输出失败：缺少结构化 Schema 配置');
        }
        if ($shouldTryStructured) {
            $jsonSchema = self::treeToJsonSchema($structuredSchema);
            if ($jsonSchema === []) {
                if ($outputMode === 'structured') {
                    throw new ExceptionBusiness('结构化输出失败：结构化 Schema 格式无效');
                }
                $errors[] = '结构化 Schema 格式无效';
            } else {
                try {
                    $provider = AI::forModel($model, array_merge($providerOverrides, [
                        '__structured_strict' => true,
                    ]), $timeoutSeconds);
                    if ($systemPrompt !== null && trim($systemPrompt) !== '') {
                        $provider->systemPrompt($systemPrompt);
                    }
                    $response = $provider->structured(
                        UserMessage::make($prompt),
                        \stdClass::class,
                        $jsonSchema
                    );

                    $raw = $response->getContent();
                    $data = self::decodeAsArray($raw);
                    if (!is_array($data)) {
                        throw new ExceptionBusiness('结构化响应不是有效 JSON 对象');
                    }
                    $violations = self::validateByTree($structuredSchema, $data);
                    if ($violations !== []) {
                        throw new ExceptionBusiness('结构化校验失败：' . implode('；', $violations));
                    }

                    return [
                        'mode_used' => 'structured',
                        'content' => is_string($raw)
                            ? $raw
                            : (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                        'data' => $data,
                        'usage' => self::normalizeUsage($response->getUsage()?->jsonSerialize()),
                        'errors' => $errors,
                    ];
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    if ($outputMode === 'structured') {
                        throw new ExceptionBusiness('结构化输出失败：' . $e->getMessage());
                    }
                }
            }
        }

        $provider = AI::forModel($model, $providerOverrides, $timeoutSeconds);
        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $provider->systemPrompt($systemPrompt);
        }
        $response = $provider->chat(UserMessage::make($prompt));
        $content = $response->getContent();

        return [
            'mode_used' => 'text',
            'content' => is_string($content) ? $content : (json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'data' => $content,
            'usage' => self::normalizeUsage($response->getUsage()?->jsonSerialize()),
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, mixed> $tree
     * @return array<string, mixed>
     */
    public static function treeToJsonSchema(array $tree): array
    {
        $properties = [];
        $required = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $schema = self::nodeToSchema($node);
            if ($schema === []) {
                continue;
            }
            $properties[$name] = $schema;
            if ((bool)($node['params']['required'] ?? false)) {
                $required[] = $name;
            }
        }
        if ($properties === []) {
            return [];
        }
        $result = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $result['required'] = array_values(array_unique($required));
        }

        return $result;
    }

    private static function normalizeOutputMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['text', 'structured', 'auto'], true)) {
            return 'auto';
        }
        return $mode;
    }

    /**
     * @param mixed $schema
     */
    private static function hasSchema(mixed $schema): bool
    {
        if (!is_array($schema)) {
            return false;
        }
        if (($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null)) {
            return ($schema['properties'] ?? []) !== [];
        }
        return $schema !== [];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function nodeToSchema(array $node): array
    {
        $rawType = $node['type'] ?? 'string';
        $type = strtolower((string)(is_array($rawType) ? ($rawType[0] ?? 'string') : $rawType));
        $type = match ($type) {
            'integer', 'int' => 'integer',
            'bool' => 'boolean',
            default => $type,
        };
        if (!in_array($type, ['string', 'number', 'integer', 'boolean', 'object', 'array'], true)) {
            $type = 'string';
        }

        if ($type === 'object') {
            $children = is_array($node['children'] ?? null) ? ($node['children'] ?? []) : [];
            $childSchema = self::treeToJsonSchema($children);
            if ($childSchema === []) {
                return [
                    'type' => 'object',
                    'additionalProperties' => true,
                ];
            }
            return $childSchema;
        }

        if ($type === 'array') {
            $children = is_array($node['children'] ?? null) ? ($node['children'] ?? []) : [];
            if ($children !== []) {
                $items = self::treeToJsonSchema($children);
                if ($items !== []) {
                    return [
                        'type' => 'array',
                        'items' => $items,
                    ];
                }
            }
            return [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ];
        }

        return ['type' => $type];
    }

    private static function decodeAsArray(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $text = is_string($raw) ? trim($raw) : '';
        if ($text === '') {
            return null;
        }
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\\s*(\\{.*\\})\\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * @param array<int, mixed>|array<string, mixed> $tree
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private static function validateByTree(array $tree, array $data, string $prefix = ''): array
    {
        // 兼容直接传 JSON Schema
        if (($tree['type'] ?? null) === 'object' && is_array($tree['properties'] ?? null)) {
            $normalized = [];
            foreach (($tree['properties'] ?? []) as $name => $schema) {
                $normalized[] = [
                    'name' => (string)$name,
                    'type' => is_array($schema) ? (string)($schema['type'] ?? 'string') : 'string',
                    'params' => [
                        'required' => in_array((string)$name, is_array($tree['required'] ?? null) ? ($tree['required'] ?? []) : [], true),
                    ],
                    'children' => (is_array($schema) && ($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null))
                        ? array_map(static fn ($childName, $childSchema) => [
                            'name' => (string)$childName,
                            'type' => is_array($childSchema) ? (string)($childSchema['type'] ?? 'string') : 'string',
                            'params' => ['required' => false],
                            'children' => [],
                        ], array_keys($schema['properties']), $schema['properties'])
                        : [],
                ];
            }
            $tree = $normalized;
        }

        $errors = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $path = $prefix === '' ? $name : $prefix . '.' . $name;
            $required = (bool)($node['params']['required'] ?? false);
            $valueExists = array_key_exists($name, $data);
            $value = $valueExists ? $data[$name] : null;
            if ($required && (!$valueExists || self::isEmptyValue($value))) {
                $errors[] = $path . ' 为必填';
                continue;
            }
            if (!$valueExists) {
                continue;
            }
            $type = strtolower((string)($node['type'] ?? 'string'));
            if (!self::isTypeMatched($type, $value)) {
                $errors[] = $path . ' 类型错误';
                continue;
            }
            $children = is_array($node['children'] ?? null) ? ($node['children'] ?? []) : [];
            if ($children !== []) {
                if ($type === 'object' && is_array($value)) {
                    $errors = [...$errors, ...self::validateByTree($children, $value, $path)];
                } elseif ($type === 'array' && is_array($value)) {
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $errors = [...$errors, ...self::validateByTree($children, $item, $path . '[' . $index . ']')];
                        }
                    }
                }
            }
        }
        return $errors;
    }

    private static function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }
        return false;
    }

    private static function isTypeMatched(string $type, mixed $value): bool
    {
        return match ($type) {
            'number' => is_int($value) || is_float($value),
            'integer', 'int' => is_int($value),
            'boolean', 'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_array($value),
            default => is_string($value) || is_scalar($value) || $value === null,
        };
    }

    /**
     * @param mixed $usage
     * @return array<string, int>|null
     */
    private static function normalizeUsage(mixed $usage): ?array
    {
        if (!is_array($usage)) {
            return null;
        }
        if (isset($usage['input_tokens']) || isset($usage['output_tokens'])) {
            $prompt = (int)($usage['input_tokens'] ?? 0);
            $completion = (int)($usage['output_tokens'] ?? 0);
            return [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => (int)($usage['total_tokens'] ?? ($prompt + $completion)),
            ];
        }
        if (isset($usage['prompt_tokens']) || isset($usage['completion_tokens']) || isset($usage['total_tokens'])) {
            $prompt = (int)($usage['prompt_tokens'] ?? 0);
            $completion = (int)($usage['completion_tokens'] ?? 0);
            return [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => (int)($usage['total_tokens'] ?? ($prompt + $completion)),
            ];
        }
        return null;
    }
}
