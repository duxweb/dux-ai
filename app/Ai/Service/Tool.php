<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\Tool\Service as ToolService;
use App\Ai\Support\AiRuntime;
use Core\App;

final class Tool
{
    public const DI_KEY = 'ai.tool.service';

    private static ?ToolService $service = null;

    public static function setService(?ToolService $service): void
    {
        self::$service = $service;
        if ($service) {
            App::di()->set(self::DI_KEY, $service);
        }
    }

    public static function reset(): void
    {
        self::$service?->reset();
        self::$service = null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list(): array
    {
        return self::service()->list();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $code): ?array
    {
        return self::service()->get($code);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function execute(string $code, array $config = [], array $params = [])
    {
        return self::service()->execute($code, $config, $params);
    }

    /**
     * Merge tool config (minus reserved keys) with runtime params.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function mergeToolParams(array $config, array $params): array
    {
        $config = self::sanitizeParams($config);
        $merged = array_merge($config, $params);
        foreach ($config as $key => $value) {
            if (is_string($key) && str_starts_with($key, '__')) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * Agent 工具配置表单：只保留“工具固定配置”字段，剔除 Flow 专用的 field-config/note 等组件。
     *
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeAgentSettings(array $settings): array
    {
        $result = [];
        foreach ($settings as $field) {
            if (!is_array($field)) {
                continue;
            }
            $component = (string)($field['component'] ?? '');
            if ($component === 'field-config' || $component === 'note') {
                continue;
            }

            $result[] = $field;
        }

        return $result;
    }

    /**
     * 将 payload/arguments 中的 {{input.xxx}} 占位符替换为调用入参中的值
     *
     * @param mixed $payload
     * @param array<string, mixed> $context
     * @return mixed
     */
    public static function preparePayload(mixed $payload, array $context): mixed
    {
        if (is_string($payload)) {
            $replaced = self::replacePlaceholders($payload, $context);
            $decoded = json_decode($replaced, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $replaced;
        }

        if (is_array($payload)) {
            $result = [];
            foreach ($payload as $key => $value) {
                $result[$key] = self::preparePayload($value, $context);
            }
            return $result;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function replacePlaceholders(string $text, array $context): string
    {
        return (string)preg_replace_callback('/\{\{\s*input\.([^\}]+)\s*\}\}/', static function (array $matches) use ($context) {
            $path = trim($matches[1]);
            $value = self::getValueByPath($context, $path);
            if (is_scalar($value) || $value === null) {
                return (string)($value ?? '');
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }, $text);
    }

    public static function getValueByPath(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }
        return $current;
    }

    private static function service(): ToolService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof ToolService) {
                return self::$service = $resolved;
            }
        }

        // Ensure Capability service exists in DI
        $capabilityService = $di->has(Capability::DI_KEY) ? $di->get(Capability::DI_KEY) : null;
        if (!$capabilityService instanceof CapabilityService) {
            $capabilityService = new CapabilityService(AiRuntime::instance());
            $di->set(Capability::DI_KEY, $capabilityService);
        }

        $instance = new ToolService($capabilityService);
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }

    /**
     * 去除工具元信息，仅保留业务参数（配置）
     *
     * @param array<string, mixed> $params
     */
    private static function sanitizeParams(array $params): array
    {
        foreach (self::reservedKeys() as $key) {
            unset($params[$key]);
        }

        return $params;
    }

    /**
     * @return array<int, string>
     */
    private static function reservedKeys(): array
    {
        return [
            'code',
            'name',
            'label',
            'type',
            'function',
            'types',
            'tool',
            'schema',
            'description',
            'settings',
            'defaults',
            'handler',
            'editor',
        ];
    }
}
