<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow;

final class FlowSchemaPayloadBuilder
{
    /**
     * @param array<int, mixed> $tree
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function build(array $tree, array $state): array
    {
        $result = self::buildWithValidation($tree, $state);
        return $result['payload'];
    }

    /**
     * @param array<int, mixed> $tree
     * @param array<string, mixed> $state
     * @return array{payload: array<string, mixed>, missing: array<int, string>}
     */
    public static function buildWithValidation(array $tree, array $state): array
    {
        return self::buildInternal($tree, $state, '');
    }

    /**
     * @param array<int, mixed> $tree
     * @param array<string, mixed> $state
     * @return array{payload: array<string, mixed>, missing: array<int, string>}
     */
    private static function buildInternal(array $tree, array $state, string $pathPrefix): array
    {
        $result = [];
        $missing = [];

        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $path = $pathPrefix !== '' ? sprintf('%s.%s', $pathPrefix, $name) : $name;
            $required = (bool)($node['params']['required'] ?? false);
            $typeRaw = $node['type'] ?? 'string';
            $type = strtolower((string)(is_array($typeRaw) ? ($typeRaw[0] ?? 'string') : $typeRaw));

            if ($type === 'object') {
                $children = is_array($node['children'] ?? null) ? ($node['children'] ?? []) : [];
                if ($children !== []) {
                    $child = self::buildInternal($children, $state, $path);
                    $missing = [...$missing, ...$child['missing']];
                    if ($child['payload'] !== []) {
                        $result[$name] = $child['payload'];
                    }
                    if ($required && self::isMissingValue($child['payload'])) {
                        $missing[] = $path;
                    }
                    continue;
                }
            }

            $template = $node['params']['default'] ?? null;
            if ($template === null) {
                if ($required) {
                    $missing[] = $path;
                }
                continue;
            }

            $value = self::coerceValueByType(StateTemplate::resolve($template, $state), $type);
            if (self::isMissingValue($value)) {
                if ($required) {
                    $missing[] = $path;
                }
                continue;
            }

            $result[$name] = $value;
        }

        return [
            'payload' => $result,
            'missing' => array_values(array_unique($missing)),
        ];
    }

    private static function isMissingValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && $value === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    private static function coerceValueByType(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number', 'integer' => is_numeric($value) ? 0 + $value : $value,
            'boolean', 'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'array' => is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?: [$value]) : [$value]),
            'object' => is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?: ['value' => $value]) : ['value' => $value]),
            default => $value,
        };
    }
}
