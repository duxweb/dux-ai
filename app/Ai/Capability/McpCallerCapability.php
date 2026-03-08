<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Service\Neuron\Mcp\McpToolkitFactory;
use Core\Handlers\ExceptionBusiness;

class McpCallerCapability
{
    /**
     * Agent/Flow unified input:
     * - url: string
     * - transport: streamable_http|sse (http only)
     * - tool: string
     * - headers/token/timeout
     * - arguments: object|string
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $toolName = trim((string)($input['tool'] ?? ''));
        if ($toolName === '') {
            throw new ExceptionBusiness('请配置 MCP tool');
        }

        if ((!isset($input['timeout']) || !is_numeric($input['timeout'])) && is_numeric($input['timeout_ms'] ?? null)) {
            $timeoutMs = (int)$input['timeout_ms'];
            if ($timeoutMs > 0) {
                $input['timeout'] = (int)ceil($timeoutMs / 1000);
            }
        }

        $rawArguments = $input['arguments'] ?? [];
        $arguments = $this->normalizeArguments($rawArguments);
        if (!is_array($arguments)) {
            $arguments = [];
        }

        try {
            $result = McpToolkitFactory::call($input, $toolName, $arguments);
        } catch (\Throwable $e) {
            $text = trim($e->getMessage());
            if (str_contains(strtolower($text), 'no json data found in sse response')) {
                throw new ExceptionBusiness('MCP 返回协议解析失败，请切换传输类型（Streamable HTTP / SSE）后重试，或检查服务 URL 与 token 是否正确');
            }
            throw $e;
        }
        $payload = is_array($result) ? $result : ['result' => $result];
        $payload['transport'] = strtolower(trim((string)($input['transport'] ?? 'streamable_http')));
        $payload['service_url'] = trim((string)($input['server_url'] ?? ($input['url'] ?? '')));

        return $payload;
    }

    private function normalizeArguments(mixed $arguments): mixed
    {
        if (is_array($arguments)) {
            foreach ($arguments as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $decodedValue = $this->decodeJsonString($value);
                if ($decodedValue !== null) {
                    $arguments[$key] = $decodedValue;
                }
            }
            return $arguments;
        }

        if (is_string($arguments)) {
            $decoded = $this->decodeJsonString($arguments);
            return $decoded ?? [];
        }

        return $arguments;
    }

    private function decodeJsonString(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }
}
