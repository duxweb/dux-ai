<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Models\AiModel;
use App\Ai\Service\Neuron\Flow\StateTemplate;
use App\Ai\Service\Neuron\Flow\WorkflowToolContext;
use App\Ai\Service\Neuron\Structured\StructuredOutputService;
use Core\Handlers\ExceptionBusiness;

final class InstructionCapability
{
    public const DEFAULT_PROMPT = <<<PROMPT
你是一个流程指令分析器，需要根据用户的最新输入判断是否需要调用以下工具：
{{TOOL_LIST}}

只输出 JSON：
{
  "decisions": [
    {"tool": "工具名称", "reason": "调用原因", "arguments": {"key": "value"}}
  ]
}

当不需要任何工具时返回 {"decisions": []}。
PROMPT;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $modelCode = trim((string)($input['model'] ?? ''));
        if ($modelCode === '') {
            throw new ExceptionBusiness('指令分析节点必须选择模型');
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->where('code', $modelCode)->first();
        if (!$model) {
            throw new ExceptionBusiness(sprintf('模型 [%s] 不存在', $modelCode));
        }

        $runtime = [];
        if ($context instanceof WorkflowToolContext) {
            $runtime = $context->state();
        }

        $tools = $this->normalizeTools($input['tools'] ?? []);
        if ($tools === []) {
            throw new ExceptionBusiness('请配置至少一个可用工具（tools.items）');
        }

        $analysisPrompt = trim((string)($input['analysis_prompt'] ?? ''));
        if ($analysisPrompt === '') {
            $analysisPrompt = self::DEFAULT_PROMPT;
        }
        $analysisPrompt = str_replace(['{{TOOL_LIST}}', '{{tool_list}}'], $this->formatToolList($tools), $analysisPrompt);
        $analysisPrompt = StateTemplate::render($analysisPrompt, $runtime);

        $content = $input['content'] ?? ($runtime['last'] ?? '');
        $content = StateTemplate::resolve($content, $runtime);
        if (is_array($content) || is_object($content)) {
            $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $content = $encoded === false ? '' : $encoded;
        }
        $content = is_string($content) ? $content : (string)($content ?? '');

        $temperature = null;
        if (array_key_exists('temperature', $input) && $input['temperature'] !== null && $input['temperature'] !== '') {
            $temperature = (float)$input['temperature'];
        }
        $timeoutMs = (int)($input['timeout_ms'] ?? 0);
        $timeoutSeconds = $timeoutMs > 0 ? (int)ceil($timeoutMs / 1000) : null;

        $overrides = [];
        if ($temperature !== null) {
            $overrides['temperature'] = $temperature;
        }
        $structuredSchema = self::structuredSchemaTree();
        $outputMode = (string)($input['output_mode'] ?? 'auto');

        $run = StructuredOutputService::run(
            model: $model,
            prompt: $content,
            systemPrompt: $analysisPrompt,
            outputMode: $outputMode,
            structuredSchema: $structuredSchema,
            providerOverrides: $overrides,
            timeoutSeconds: $timeoutSeconds,
        );

        $parsed = is_array($run['data'] ?? null) ? ($run['data'] ?? []) : $this->decodeJson((string)($run['content'] ?? ''));
        $decisions = $this->normalizeDecisions($parsed['decisions'] ?? null);
        $usage = is_array($run['usage'] ?? null) ? ($run['usage'] ?? []) : [];
        $promptTokens = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $totalTokens = (int)($usage['total_tokens'] ?? ($promptTokens + $completionTokens));
        $strictMode = true;

        return [
            'status' => 1,
            'content' => '',
            'data' => [
                'decisions' => $decisions,
                'decision_count' => count($decisions),
                'strict_mode' => $strictMode,
                'mode_used' => (string)($run['mode_used'] ?? 'text'),
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ],
            'input' => [
                'model' => $model->code,
                'analysis_prompt' => $analysisPrompt,
                'tools' => $tools,
                'content' => $content,
                'temperature' => $temperature,
                'output_mode' => $outputMode,
            ],
            'output' => [
                'decisions' => $decisions,
                'decision_count' => count($decisions),
                'strict_mode' => $strictMode,
            ],
            'meta' => [
                'mode_used' => (string)($run['mode_used'] ?? 'text'),
                'structured_errors' => is_array($run['errors'] ?? null) ? ($run['errors'] ?? []) : [],
            ],
        ];
    }

    /**
     * 指令分析结构为固定协议，不开放节点自定义。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function structuredSchemaTree(): array
    {
        return [
            [
                'name' => 'decisions',
                'type' => 'array',
                'params' => ['required' => true],
                'children' => [
                    [
                        'name' => 'tool',
                        'type' => 'string',
                        'params' => ['required' => true],
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'params' => ['required' => false],
                    ],
                    [
                        'name' => 'arguments',
                        'type' => 'object',
                        'params' => ['required' => false],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{tool:string,label:string,description:string}>
     */
    private function normalizeTools(mixed $tools): array
    {
        $items = [];
        if (is_array($tools) && isset($tools['items']) && is_array($tools['items'])) {
            $items = $tools['items'];
        } elseif (is_array($tools) && array_is_list($tools)) {
            $items = $tools;
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tool = trim((string)($item['tool'] ?? $item['name'] ?? ''));
            if ($tool === '') {
                continue;
            }
            $label = trim((string)($item['label'] ?? $tool));
            $description = trim((string)($item['description'] ?? ''));
            $result[] = [
                'tool' => $tool,
                'label' => $label !== '' ? $label : $tool,
                'description' => $description,
            ];
        }
        return $result;
    }

    private function formatToolList(array $tools): string
    {
        $lines = [];
        foreach ($tools as $tool) {
            $desc = (string)($tool['description'] ?? '');
            if ($desc !== '') {
                $lines[] = sprintf('- %s（%s）：%s', $tool['label'], $tool['tool'], $desc);
            } else {
                $lines[] = sprintf('- %s（%s）', $tool['label'], $tool['tool']);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to extract JSON object from fenced code blocks.
        if (preg_match('/```(?:json)?\\s*(\\{.*\\})\\s*```/s', $trimmed, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array<int, array{tool:string,reason:string,arguments:array<string,mixed>}>
     */
    private function normalizeDecisions(mixed $decisions): array
    {
        if (!is_array($decisions)) {
            return [];
        }
        $result = [];
        foreach ($decisions as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tool = trim((string)($item['tool'] ?? ''));
            if ($tool === '') {
                continue;
            }
            $reason = trim((string)($item['reason'] ?? ''));
            $arguments = $item['arguments'] ?? [];
            $arguments = is_array($arguments) ? $arguments : [];
            $result[] = [
                'tool' => $tool,
                'reason' => $reason,
                'arguments' => $arguments,
            ];
        }
        return $result;
    }
}
