<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow;

final class StateTemplate
{
    public static function render(string $template, array $state): string
    {
        if ($template === '') {
            return '';
        }

        $pattern = '/{{\s*(.*?)\s*}}/';

        return preg_replace_callback($pattern, static function (array $matches) use ($state) {
            $path = trim((string)($matches[1] ?? ''));
            if ($path === '') {
                return '';
            }
            $value = self::value($state, $path);
            if (is_scalar($value)) {
                return (string)$value;
            }
            if (is_array($value) || is_object($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return $encoded === false ? '' : $encoded;
            }
            return '';
        }, $template) ?? $template;
    }

    public static function resolve(mixed $value, array $state): mixed
    {
        if (is_string($value)) {
            $rendered = self::render($value, $state);
            $trimmed = ltrim($rendered);
            if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))) {
                $decoded = json_decode($rendered, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
            return $rendered;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::resolve($v, $state);
            }
            return $result;
        }
        return $value;
    }

    public static function value(array $state, string $path): mixed
    {
        $segments = explode('.', $path);
        $value = $state;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === 'input') {
                $value = $state['last'] ?? ($state['input'] ?? null);
                continue;
            }

            if ($segment === 'origin' || $segment === 'origin_input') {
                $value = $state['input'] ?? null;
                continue;
            }

            if ($segment === 'output') {
                $value = $state['last'] ?? ($state['input'] ?? null);
                continue;
            }

            if ($segment === 'env') {
                $value = $state['env'] ?? [];
                continue;
            }

            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            return null;
        }

        return $value;
    }
}

