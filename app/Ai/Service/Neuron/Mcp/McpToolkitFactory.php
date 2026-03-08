<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Mcp;

use Core\Handlers\ExceptionBusiness;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;
use Throwable;

final class McpToolkitFactory
{
    /**
     * @param array<string, mixed> $config
     * @return ToolInterface[]
     */
    public static function tools(array $config): array
    {
        return self::withTransportFallback($config, static function (array $runConfig): array {
            $connector = McpConnector::make(self::connectorConfig($runConfig));
            self::applyFilter($connector, $runConfig);
            return $connector->tools();
        });
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function call(array $config, string $toolName, array $arguments = []): array
    {
        $toolName = trim($toolName);
        if ($toolName === '') {
            throw new ExceptionBusiness('请配置 MCP 工具名');
        }

        return self::withTransportFallback($config, static function (array $runConfig) use ($toolName, $arguments): array {
            $connector = McpConnector::make(self::connectorConfig($runConfig));
            self::applyFilter($connector, $runConfig);
            $tools = $connector->tools();
            $target = null;
            foreach ($tools as $tool) {
                if ($tool->getName() === $toolName) {
                    $target = $tool;
                    break;
                }
            }

            if (!$target instanceof ToolInterface) {
                throw new ExceptionBusiness(sprintf('MCP 工具 [%s] 不存在', $toolName));
            }

            $target->setInputs($arguments);
            $target->execute();
            $result = (string)$target->getResult();
            $decoded = null;
            if ($result !== '' && json_validate($result)) {
                $tmp = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded = $tmp;
                }
            }

            return [
                'tool' => $toolName,
                'result' => $decoded ?? $result,
                'raw' => $result,
            ];
        });
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function connectorConfig(array $config): array
    {
        $url = self::resolveServerUrl($config);
        if ($url === '') {
            throw new ExceptionBusiness('请配置 MCP 服务 URL');
        }
        $transport = strtolower(trim((string)($config['transport'] ?? 'streamable_http')));
        if (!in_array($transport, ['streamable_http', 'sse'], true)) {
            $transport = 'streamable_http';
        }
        $async = array_key_exists('async', $config)
            ? (bool)$config['async']
            : $transport === 'sse';

        return array_filter([
            'url' => $url,
            'async' => $async,
            'headers' => self::normalizeMap($config['headers'] ?? []),
            'token' => trim((string)($config['token'] ?? '')),
            'timeout' => self::normalizeTimeout($config),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function applyFilter(McpConnector $connector, array $config): void
    {
        $only = self::normalizeList($config['only'] ?? []);
        if ($only !== []) {
            $connector->only($only);
        }

        $exclude = self::normalizeList($config['exclude'] ?? []);
        if ($exclude !== []) {
            $connector->exclude($exclude);
        }
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private static function normalizeMap(mixed $value): array
    {
        if (is_string($value) && $value !== '' && json_validate($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_array($item) && isset($item['name'])) {
                $name = trim((string)$item['name']);
                if ($name === '') {
                    continue;
                }
                $result[$name] = (string)($item['value'] ?? '');
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $name = trim($key);
            if ($name === '') {
                continue;
            }
            $result[$name] = is_scalar($item) ? (string)$item : '';
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = trim($value);
            if ($decoded !== '' && json_validate($decoded)) {
                $value = json_decode($decoded, true);
            } else {
                $value = array_filter(array_map('trim', explode(',', $value)));
            }
        }

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

    /**
     * @param array<string, mixed> $config
     */
    private static function normalizeTimeout(array $config): ?int
    {
        $raw = $config['timeout'] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }

        $timeout = (int)$raw;
        return $timeout > 0 ? $timeout : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveServerUrl(array $config): string
    {
        $serverUrl = trim((string)($config['server_url'] ?? ''));
        if ($serverUrl === '') {
            return '';
        }
        return $serverUrl;
    }

    /**
     * @template T
     * @param array<string, mixed> $config
     * @param callable(array<string, mixed>): T $runner
     * @return T
     */
    private static function withTransportFallback(array $config, callable $runner): mixed
    {
        try {
            return $runner($config);
        } catch (Throwable $e) {
            if (!self::shouldRetryWithAlternateTransport($config, $e)) {
                throw $e;
            }
            $retry = $config;
            $current = strtolower(trim((string)($config['transport'] ?? 'streamable_http')));
            $retry['transport'] = $current === 'sse' ? 'streamable_http' : 'sse';
            return $runner($retry);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function shouldRetryWithAlternateTransport(array $config, Throwable $e): bool
    {
        if (array_key_exists('async', $config)) {
            return false;
        }
        $message = strtolower(trim($e->getMessage()));
        if ($message === '') {
            return false;
        }
        return str_contains($message, 'no json data found in sse response')
            || str_contains($message, 'timeout waiting for endpoint event')
            || str_contains($message, 'invalid json response');
    }
}
