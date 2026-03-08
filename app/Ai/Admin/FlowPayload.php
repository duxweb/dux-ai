<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use Psr\Http\Message\ServerRequestInterface;

final class FlowPayload
{
    public static function resolveInputPayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_object($body)) {
            $body = (array)$body;
        }
        if (is_array($body) && array_key_exists('input', $body)) {
            return is_array($body['input']) ? $body['input'] : (array)$body['input'];
        }

        $params = $request->getQueryParams();
        if (isset($params['input'])) {
            if (is_array($params['input'])) {
                return $params['input'];
            }

            $decoded = json_decode((string)$params['input'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public static function resolveOptionsPayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_object($body)) {
            $body = (array)$body;
        }
        if (is_array($body) && array_key_exists('options', $body)) {
            return is_array($body['options']) ? $body['options'] : (array)$body['options'];
        }

        $params = $request->getQueryParams();
        if (isset($params['options'])) {
            if (is_array($params['options'])) {
                return $params['options'];
            }

            $decoded = json_decode((string)$params['options'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array{
     *     nodes?: array<int, mixed>,
     *     edges?: array<int, mixed>,
     *     globalSettings?: array<string, mixed>
     * } $flowData
     */
    public static function injectGlobalSettingsIntoFlow(array $flowData, array $globalSettings): array
    {
        $flowData['globalSettings'] = $globalSettings;
        $flowData['schema_version'] = 1;
        $flowData['engine'] = 'neuron-ai';
        $flowData['defaults'] = [
            'timeout_ms' => isset($globalSettings['timeout_ms']) ? (int)$globalSettings['timeout_ms'] : 0,
            'retry' => [
                'max_attempts' => isset($globalSettings['retry']['max_attempts'])
                    ? (int)$globalSettings['retry']['max_attempts']
                    : 1,
            ],
        ];

        return $flowData;
    }

    public static function filterGlobalSettingsPayload(array $payload): array
    {
        $payload = is_array($payload) ? $payload : [];

        $variablesRaw = $payload['variables'] ?? [];
        $variablesRaw = is_array($variablesRaw) ? $variablesRaw : [];

        $variables = [];
        foreach ($variablesRaw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['name']) ? trim((string)$item['name']) : '';
            $description = isset($item['description']) ? trim((string)$item['description']) : '';
            $value = array_key_exists('value', $item) ? (string)$item['value'] : '';

            if ($name === '' && $description === '' && $value === '') {
                continue;
            }

            $variables[] = [
                'name' => $name,
                'description' => $description,
                'value' => $value,
            ];
        }

        $debug = isset($payload['debug']) ? (bool)$payload['debug'] : false;
        $timeoutMs = isset($payload['timeout_ms']) ? (int)$payload['timeout_ms'] : 0;

        $retry = $payload['retry'] ?? [];
        $retry = is_array($retry) ? $retry : [];
        $maxAttempts = isset($retry['max_attempts']) ? (int)$retry['max_attempts'] : 1;

        return [
            'debug' => $debug,
            'timeout_ms' => max(0, $timeoutMs),
            'retry' => [
                'max_attempts' => max(1, min(10, $maxAttempts)),
            ],
            'variables' => $variables,
        ];
    }

    public static function resolveFieldValue(array $body, array $settings, string $key): mixed
    {
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return null;
    }

    public static function hasFieldValue(array $body, array $settings, string $key): bool
    {
        return array_key_exists($key, $body) || array_key_exists($key, $settings);
    }

    public static function normalizeNonEmptyString(mixed $value): ?string
    {
        $trimmed = trim((string)($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    public static function normalizeNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        return $trimmed === '' ? null : $trimmed;
    }

    public static function normalizeBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }

            $value = strtolower(trim($value));
            if ($value === '') {
                return null;
            }

            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return null;
    }
}
