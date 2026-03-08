<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Service\Agent\ToolConfigBuilder as AgentToolConfigBuilder;
use App\Ai\Service\Neuron\Mcp\McpToolkitFactory;
use App\Ai\Service\Neuron\Toolkit\SystemToolkit;
use App\Ai\Service\Tool as ToolService;
use Core\Handlers\ExceptionBusiness;
use Throwable;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

final class ToolFactory
{
    /**
     * @return array{tools: array<int, ToolInterface|ToolkitInterface>, map: array<string, array<string, mixed>>}
     */
    public static function buildForAgent(AiAgent $agent, int $sessionId = 0): array
    {
        $toolsConfig = AgentToolConfigBuilder::build($agent);
        $toolMap = $toolsConfig['map'] ?? [];
        $tools = [];

        foreach ($toolMap as $toolName => $meta) {
            if (!is_string($toolName) || trim($toolName) === '' || !is_array($meta)) {
                continue;
            }

            $schema = is_array($meta['schema'] ?? null) ? ($meta['schema'] ?? []) : [];
            $schema = self::stripFixedConfigPropertiesFromSchema($schema, $meta);
            $properties = self::schemaToProperties($schema);
            $description = (string)($meta['description'] ?? '');
            $agentId = (int)$agent->id;

            $tool = Tool::make($toolName, $description, $properties)
                ->setCallable(static function (...$args) use ($meta, $sessionId, $agentId) {

                    $toolCode = (string)($meta['code'] ?? '');
                    if ($toolCode === '') {
                        throw new ExceptionBusiness(sprintf('工具 [%s] 配置缺失 code', (string)($meta['label'] ?? 'unknown')));
                    }

                    try {
                        return ToolService::execute($toolCode, [
                            ...$meta,
                            '__session_id' => $sessionId,
                            '__agent_id' => $agentId,
                        ], $args);
                    } catch (Throwable $e) {
                        return self::encodeToolError($e);
                    }
                });

            $tools[] = $tool;
        }

        foreach (self::resolveToolkitItems($agent) as $item) {
            $toolkit = self::createToolkit($item);
            if ($toolkit === null) {
                continue;
            }

            if (is_array($toolkit)) {
                foreach ($toolkit as $tool) {
                    if ($tool instanceof ToolInterface) {
                        $tools[] = $tool;
                    }
                }
                continue;
            }

            $tools[] = $toolkit;
        }

        return [
            'tools' => $tools,
            'map' => $toolMap,
        ];
    }

    /**
     * 若工具配置中已填写固定值，则从可调用 schema 中移除，避免被运行时参数覆盖。
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function stripFixedConfigPropertiesFromSchema(array $schema, array $meta): array
    {
        if (($schema['type'] ?? '') !== 'object') {
            return $schema;
        }

        $properties = is_array($schema['properties'] ?? null) ? ($schema['properties'] ?? []) : [];
        if ($properties === []) {
            return $schema;
        }

        $required = is_array($schema['required'] ?? null) ? ($schema['required'] ?? []) : [];
        $reserved = [
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

        foreach (array_keys($properties) as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            if (in_array($field, $reserved, true)) {
                continue;
            }
            if (!array_key_exists($field, $meta)) {
                continue;
            }
            $value = $meta[$field];
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if (is_array($value) && $value === []) {
                continue;
            }
            if ($value === null) {
                continue;
            }

            unset($properties[$field]);
            $required = array_values(array_filter($required, static fn (mixed $name): bool => (string)$name !== $field));
        }

        $schema['properties'] = $properties === [] ? new \stdClass() : $properties;
        $schema['required'] = $required;

        return $schema;
    }

    private static function encodeToolError(Throwable $throwable): string
    {
        $debugMessage = trim($throwable->getMessage());
        if ($debugMessage === '') {
            $debugMessage = sprintf('工具执行异常（%s）', $throwable::class);
        }

        [$userMessage, $errorType, $retryable] = self::mapErrorMessage($debugMessage);
        $payload = [
            '__tool_error' => true,
            'status' => 0,
            'message' => $userMessage,
            'user_message' => $userMessage,
            'retryable' => $retryable,
            'error_type' => $errorType,
            'debug_message' => $debugMessage,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            return $json;
        }

        return '{"__tool_error":true,"status":0,"message":"工具调用失败，系统可能暂时异常，请稍后重试。"}';
    }

    /**
     * @return array{0:string,1:string,2:bool}
     */
    private static function mapErrorMessage(string $message): array
    {
        $lower = strtolower($message);

        if (
            str_contains($message, 'RequestBurstTooFast')
            || str_contains($message, 'TooManyRequests')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'http 429')
            || str_contains($lower, 'http/1.1 429')
        ) {
            return ['当前请求过于频繁，服务繁忙，请稍后重试。', 'rate_limit', true];
        }

        if (
            str_contains($message, 'cURL error 28')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
        ) {
            return ['工具调用超时，请稍后重试。', 'timeout', true];
        }

        if (
            str_contains($lower, 'network error')
            || str_contains($lower, 'connection')
            || str_contains($lower, 'unable to resolve')
        ) {
            return ['工具调用网络异常，请稍后重试。', 'network', true];
        }

        if (self::shouldMaskMessage($message)) {
            return ['工具调用失败，系统可能暂时异常，请稍后重试。', 'runtime', false];
        }

        return [$message, 'business', false];
    }

    private static function shouldMaskMessage(string $message): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return true;
        }

        if (mb_strlen($trimmed, 'UTF-8') > 160) {
            return true;
        }

        $lower = strtolower($trimmed);
        foreach ([
            'http://',
            'https://',
            'post ',
            'get ',
            'request id',
            'trace',
            '{"error"',
            '"type":"',
        ] as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }


    /**
     * @param array<string, mixed> $schema
     * @return ToolPropertyInterface[]
     */
    private static function schemaToProperties(array $schema): array
    {
        if (($schema['type'] ?? null) !== 'object') {
            return [];
        }

        $props = is_array($schema['properties'] ?? null) ? ($schema['properties'] ?? []) : [];
        $required = is_array($schema['required'] ?? null) ? ($schema['required'] ?? []) : [];

        $result = [];
        foreach ($props as $name => $propSchema) {
            if (!is_string($name) || $name === '' || !is_array($propSchema)) {
                continue;
            }
            $isRequired = in_array($name, $required, true);
            $property = self::schemaToProperty($name, $propSchema, $isRequired);
            if ($property) {
                $result[] = $property;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaToProperty(string $name, array $schema, bool $required): ?ToolPropertyInterface
    {
        $type = (string)($schema['type'] ?? 'string');
        $description = isset($schema['description']) ? (string)$schema['description'] : null;
        $enum = is_array($schema['enum'] ?? null) ? ($schema['enum'] ?? []) : [];

        return match ($type) {
            'string' => ToolProperty::make($name, PropertyType::STRING, $description ?? '', $required, $enum),
            'integer' => ToolProperty::make($name, PropertyType::INTEGER, $description ?? '', $required, $enum),
            'number' => ToolProperty::make($name, PropertyType::NUMBER, $description ?? '', $required, $enum),
            'boolean' => ToolProperty::make($name, PropertyType::BOOLEAN, $description ?? '', $required, $enum),
            'array' => self::schemaToArrayProperty($name, $schema, $required, $description),
            'object' => self::schemaToObjectProperty($name, $schema, $required, $description),
            default => ToolProperty::make($name, PropertyType::STRING, $description ?? '', $required, $enum),
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaToArrayProperty(string $name, array $schema, bool $required, ?string $description): ToolPropertyInterface
    {
        $items = is_array($schema['items'] ?? null) ? ($schema['items'] ?? []) : [];
        $itemType = is_array($items) ? (string)($items['type'] ?? 'string') : 'string';

        $itemProp = match ($itemType) {
            'object' => self::schemaToObjectProperty($name . '_item', $items, false, isset($items['description']) ? (string)$items['description'] : null),
            'array' => self::schemaToArrayProperty($name . '_item', $items, false, isset($items['description']) ? (string)$items['description'] : null),
            'integer' => ToolProperty::make($name . '_item', PropertyType::INTEGER, isset($items['description']) ? (string)$items['description'] : '', false),
            'number' => ToolProperty::make($name . '_item', PropertyType::NUMBER, isset($items['description']) ? (string)$items['description'] : '', false),
            'boolean' => ToolProperty::make($name . '_item', PropertyType::BOOLEAN, isset($items['description']) ? (string)$items['description'] : '', false),
            default => ToolProperty::make($name . '_item', PropertyType::STRING, isset($items['description']) ? (string)$items['description'] : '', false),
        };

        $minItems = isset($schema['minItems']) ? (int)$schema['minItems'] : null;
        $maxItems = isset($schema['maxItems']) ? (int)$schema['maxItems'] : null;

        return new ArrayProperty(
            name: $name,
            description: $description,
            required: $required,
            items: $itemProp,
            minItems: $minItems,
            maxItems: $maxItems,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function schemaToObjectProperty(string $name, array $schema, bool $required, ?string $description): ToolPropertyInterface
    {
        $props = is_array($schema['properties'] ?? null) ? ($schema['properties'] ?? []) : [];
        $req = is_array($schema['required'] ?? null) ? ($schema['required'] ?? []) : [];

        $children = [];
        foreach ($props as $childName => $childSchema) {
            if (!is_string($childName) || $childName === '' || !is_array($childSchema)) {
                continue;
            }
            $childRequired = in_array($childName, $req, true);
            $child = self::schemaToProperty($childName, $childSchema, $childRequired);
            if ($child) {
                $children[] = $child;
            }
        }

        return new ObjectProperty(
            name: $name,
            description: $description,
            required: $required,
            class: null,
            properties: $children,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolveToolkitItems(AiAgent $agent): array
    {
        $items = [];

        $settings = is_array($agent->settings ?? null) ? ($agent->settings ?? []) : [];
        $toolkits = is_array($settings['toolkits'] ?? null) ? ($settings['toolkits'] ?? []) : [];
        foreach ($toolkits as $toolkit) {
            if (!is_array($toolkit)) {
                continue;
            }
            $items[] = $toolkit;
        }

        $tools = is_array($agent->tools ?? null) ? ($agent->tools ?? []) : [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if ((string)($tool['type'] ?? '') !== 'toolkit') {
                continue;
            }
            $items[] = $tool;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return ToolkitInterface|array<int, ToolInterface>|null
     */
    private static function createToolkit(array $item): ToolkitInterface|array|null
    {
        $type = strtolower(trim((string)($item['toolkit'] ?? $item['code'] ?? $item['name'] ?? '')));
        if ($type === '') {
            return null;
        }

        return match ($type) {
            'system', 'toolkit.system' => SystemToolkit::make(),
            'calculator', 'toolkit.calculator' => CalculatorToolkit::make(),
            'mcp', 'toolkit.mcp' => McpToolkitFactory::tools($item),
            default => null,
        };
    }
}
