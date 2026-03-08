<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Service\Agent\CardParser;
use App\Ai\Service\Agent\Logger as AgentLogger;
use App\Ai\Service\Agent\MessageStore as AgentMessageStore;
use App\Ai\Service\Agent\Sse as AgentSse;
use App\Ai\Service\Neuron\MessageAdapter;
use App\Ai\Service\Usage\UsageResolver;
use App\Ai\Support\AiRuntime;
use Generator;
use GuzzleHttp\Exception\RequestException;
use NeuronAI\Agent\Agent as NeuronAgent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tools\ToolInterface;
use Throwable;

final class ChatOrchestrator
{
    /**
     * @param array<int, array<string, mixed>> $openaiMessages
     * @param array<string, array<string, mixed>> $toolMap
     * @param array<int, \NeuronAI\Tools\ToolInterface> $tools
     * @return Generator<int, string>
     */
    public static function run(
        AIProviderInterface $provider,
        AiAgent $agent,
        string $agentCode,
        int $sessionId,
        string $modelForDisplay,
        string $instructions,
        array $openaiMessages,
        array $toolMap,
        array $tools,
        int $promptTokens,
        ?ChatHistoryInterface $chatHistory = null,
        array $attachmentSettings = [],
    ): Generator {
        $assistantRecord = AgentMessageStore::appendMessage($agent->id, $sessionId, 'assistant', '');
        $assistantMessageId = (int)$assistantRecord->id;
        $replyBuffer = '';
        $lastToolCallAssistantMessageId = $assistantMessageId;
        $debugEnabled = (bool)($agent->settings['debug_enabled'] ?? false);
        $pendingToolCalls = [];
        $pendingToolResultParts = [];
        $pendingToolResultSummary = '';
        $finalAssistantParts = [];
        $assistantUsage = null;

        try {
            $neuron = NeuronAgent::make()
                ->setAiProvider($provider)
                ->setInstructions($instructions);
            if ($debugEnabled) {
                $neuron->observe(new LogObserver(AiRuntime::instance()->log('ai.neuron.agent')));
            }
            if ($chatHistory) {
                $neuron->setChatHistory($chatHistory);
            }

            $summaryMaxTokens = isset($agent->settings['summary_max_tokens']) && is_numeric($agent->settings['summary_max_tokens'])
                ? (int)$agent->settings['summary_max_tokens']
                : 50000;
            $summaryKeep = isset($agent->settings['summary_messages_keep']) && is_numeric($agent->settings['summary_messages_keep'])
                ? (int)$agent->settings['summary_messages_keep']
                : 5;
            if ($summaryMaxTokens > 0 && $summaryKeep > 0) {
                $neuron->addGlobalMiddleware(new Summarization(
                    provider: $provider,
                    maxTokens: $summaryMaxTokens,
                    messagesToKeep: $summaryKeep,
                ));
            }

            if ($tools !== []) {
                $neuron->addTool($tools);
            }

            $supportImage = array_key_exists('support_image', $attachmentSettings)
                ? (bool)$attachmentSettings['support_image']
                : true;
            $supportFile = array_key_exists('support_file', $attachmentSettings)
                ? (bool)$attachmentSettings['support_file']
                : true;

            $imageMode = (string)($attachmentSettings['support_image_model'] ?? 'auto');
            if (!in_array($imageMode, ['auto', 'url', 'base64'], true)) {
                $imageMode = 'auto';
            }
            $fileMode = (string)($attachmentSettings['support_file_model'] ?? 'auto');
            if (!in_array($fileMode, ['auto', 'base64'], true)) {
                $fileMode = 'auto';
            }

            if ($imageMode === 'auto') {
                $imageMode = $provider instanceof Ollama ? 'base64' : 'url';
            }

            $neuronMessages = MessageAdapter::fromOpenAIMessages(
                $openaiMessages,
                $supportImage,
                $supportFile,
                [
                    'image_mode' => $imageMode,
                    'document_mode' => $fileMode,
                ]
            );

            $handler = $neuron->stream($neuronMessages);
            $events = $handler->events();
            $events->rewind();
            while ($events->valid()) {
                $chunk = $events->current();
                if ($chunk instanceof ToolCallChunk) {
                    $pendingToolCalls[] = self::toolCallPayload($chunk->tool);
                    $events->next();
                    continue;
                }

                if ($chunk instanceof ToolResultChunk) {
                    if ($assistantMessageId > 0) {
                        AgentMessageStore::persistAssistantMessage(
                            $assistantMessageId,
                            $replyBuffer,
                            $pendingToolCalls !== [] ? ['tool_calls' => $pendingToolCalls] : []
                        );
                        $lastToolCallAssistantMessageId = $assistantMessageId;
                        $assistantMessageId = 0;
                        $replyBuffer = '';
                    }

                    $toolState = self::persistToolResultAndCollect($agent, $sessionId, $toolMap, $chunk->tool, $lastToolCallAssistantMessageId);
                    $parts = is_array($toolState['parts'] ?? null) ? ($toolState['parts'] ?? []) : [];
                    if ($parts !== []) {
                        $pendingToolResultParts = $parts;
                        $pendingToolResultSummary = trim((string)($toolState['summary'] ?? ''));
                    }
                    yield AgentSse::format([
                        'session_id' => $sessionId,
                        'tool_result' => (string)($toolState['public_result'] ?? ''),
                        'tool_result_parts' => is_array($toolState['parts'] ?? null) ? ($toolState['parts'] ?? []) : [],
                        'tool_result_summary' => (string)($toolState['summary'] ?? ''),
                        'tool' => (string)($toolState['tool'] ?? ''),
                        'tool_label' => (string)($toolState['tool_label'] ?? ''),
                        'tool_call_id' => $toolState['tool_call_id'] ?? null,
                        'message_id' => $toolState['message_id'] ?? null,
                    ]);
                    $events->next();
                    continue;
                }

                $text = self::extractStreamText($chunk);
                if ($text === '') {
                    $events->next();
                    continue;
                }

                if ($assistantMessageId <= 0) {
                    $assistantRecord = AgentMessageStore::appendMessage($agent->id, $sessionId, 'assistant', '');
                    $assistantMessageId = (int)$assistantRecord->id;
                }

                $replyBuffer .= $text;
                yield AgentSse::openAIChunk($text, $sessionId, $assistantMessageId, $modelForDisplay);
                $events->next();
            }

            $workflowState = $events->getReturn();
            $assistantUsage = null;
            if (is_object($workflowState) && method_exists($workflowState, 'getMessage')) {
                $finalMessage = $workflowState->getMessage();
                if (is_object($finalMessage) && method_exists($finalMessage, 'getUsage')) {
                    $usage = $finalMessage->getUsage();
                    if (is_object($usage) && method_exists($usage, 'jsonSerialize')) {
                        $serialized = $usage->jsonSerialize();
                        $assistantUsage = is_array($serialized) ? $serialized : null;
                    }
                }
            }
        } catch (Throwable $e) {
            if ($e instanceof RequestException) {
                try {
                    $reqBody = (string)$e->getRequest()?->getBody();
                } catch (Throwable) {
                    $reqBody = '';
                }
                $respBody = '';
                if ($e->hasResponse()) {
                    try {
                        $respBody = (string)$e->getResponse()?->getBody();
                    } catch (Throwable) {
                        $respBody = '';
                    }
                }
                if (mb_strlen($reqBody, 'UTF-8') > 10000) {
                    $reqBody = mb_substr($reqBody, 0, 10000, 'UTF-8') . '…(truncated)';
                }
                if (mb_strlen($respBody, 'UTF-8') > 10000) {
                    $respBody = mb_substr($respBody, 0, 10000, 'UTF-8') . '…(truncated)';
                }
                AiRuntime::instance()->log('ai.agent')->error('agent.provider.request_error', [
                    'agent' => $agentCode,
                    'session_id' => $sessionId,
                    'message' => $e->getMessage(),
                    'request' => [
                        'method' => $e->getRequest()?->getMethod(),
                        'uri' => (string)($e->getRequest()?->getUri() ?? ''),
                        'body' => $reqBody,
                    ],
                    'response_body' => $respBody,
                ]);
            }
            $fallbackText = trim($replyBuffer);
            if ($fallbackText === '') {
                $fallbackText = $pendingToolResultSummary;
            }

            if ($fallbackText !== '') {
                if ($assistantMessageId <= 0) {
                    $assistantRecord = AgentMessageStore::appendMessage($agent->id, $sessionId, 'assistant', '');
                    $assistantMessageId = (int)$assistantRecord->id;
                }

                $parts = $pendingToolResultParts;
                if ($parts !== [] && !self::looksLikeStructuredPayload($fallbackText)) {
                    array_unshift($parts, [
                        'type' => 'text',
                        'text' => $fallbackText,
                    ]);
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $fallbackText,
                        ['parts' => $parts]
                    );
                } else {
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $fallbackText,
                        []
                    );
                }

                yield AgentSse::openAIChunk($fallbackText, $sessionId, $assistantMessageId, $modelForDisplay);
                $mediaParts = self::extractNonTextParts($parts);
                if ($mediaParts !== []) {
                    yield AgentSse::openAIChunk([
                        'content' => $mediaParts,
                    ], $sessionId, $assistantMessageId, $modelForDisplay);
                }

                yield AgentSse::format([
                    'session_id' => $sessionId,
                    'choices' => [
                        [
                            'index' => 0,
                            'finish_reason' => 'stop',
                            'delta' => new \stdClass(),
                        ],
                    ],
                    'id' => $assistantMessageId ? sprintf('msg_%d', $assistantMessageId) : null,
                    'model' => $modelForDisplay,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                ]);
                yield AgentSse::done();
                return;
            }

            if ($assistantMessageId) {
                $errorText = $replyBuffer !== '' ? $replyBuffer : $e->getMessage();
                AgentMessageStore::persistAssistantMessage(
                    $assistantMessageId,
                    $errorText,
                    ['error' => true]
                );
            }
            AgentLogger::debug($debugEnabled, 'agent.chat.error', [
                'agent' => $agentCode,
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);
            yield AgentSse::errorChunk($sessionId, $modelForDisplay, $assistantMessageId ?: null, $e->getMessage());
            yield AgentSse::done();
            return;
        }

        if ($assistantMessageId) {
            if ($pendingToolResultParts !== []) {
                $replyText = trim($replyBuffer);
                $parts = $pendingToolResultParts;
                if (!self::looksLikeStructuredPayload($replyText) && $replyText !== '') {
                    array_unshift($parts, [
                        'type' => 'text',
                        'text' => $replyText,
                    ]);
                }
                $summary = $replyText !== '' && !self::looksLikeStructuredPayload($replyText)
                    ? $replyText
                    : ($pendingToolResultSummary !== '' ? $pendingToolResultSummary : '已返回结果');

                AgentMessageStore::persistAssistantMessage(
                    $assistantMessageId,
                    $summary,
                    ['parts' => $parts]
                );
                $finalAssistantParts = $parts;
            } else {
                $structured = CardParser::extractStructuredResult($replyBuffer);
                if ($structured) {
                    $summary = trim((string)($structured['summary'] ?? ''));
                    if ($summary === '') {
                        $summary = '已返回结果';
                    }
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $summary,
                        ['parts' => is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : []]
                    );
                    $finalAssistantParts = is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : [];
                } else {
                    AgentMessageStore::persistAssistantMessage(
                        $assistantMessageId,
                        $replyBuffer,
                        []
                    );
                }
            }
        }

        $resolvedUsage = UsageResolver::fromUsageOrEstimate($assistantUsage ?? null, $replyBuffer);
        AgentMessageStore::recordUsage(
            $agent,
            $sessionId,
            $resolvedUsage['prompt_tokens'] > 0 ? $resolvedUsage['prompt_tokens'] : $promptTokens,
            $resolvedUsage['completion_tokens']
        );
        AiRuntime::instance()->log('ai.agent')->info('agent.usage.recorded', [
            'agent' => $agentCode,
            'session_id' => $sessionId,
            'usage_source' => $resolvedUsage['usage_source'],
            'usage_missing' => $resolvedUsage['usage_missing'],
            'prompt_tokens' => $resolvedUsage['prompt_tokens'],
            'completion_tokens' => $resolvedUsage['completion_tokens'],
            'total_tokens' => $resolvedUsage['total_tokens'],
        ]);

        $mediaParts = self::extractNonTextParts($finalAssistantParts);
        if ($assistantMessageId > 0 && $mediaParts !== []) {
            yield AgentSse::openAIChunk([
                'content' => $mediaParts,
            ], $sessionId, $assistantMessageId, $modelForDisplay);
        }

        yield AgentSse::format([
            'session_id' => $sessionId,
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => 'stop',
                    'delta' => new \stdClass(),
                ],
            ],
            'id' => $assistantMessageId ? sprintf('msg_%d', $assistantMessageId) : null,
            'model' => $modelForDisplay,
            'object' => 'chat.completion.chunk',
            'created' => time(),
        ]);
        yield AgentSse::done();
    }

    private static function extractStreamText(mixed $chunk): string
    {
        if (is_string($chunk)) {
            return $chunk;
        }

        if (
            $chunk instanceof TextChunk
            || $chunk instanceof ReasoningChunk
            || $chunk instanceof ImageChunk
            || $chunk instanceof AudioChunk
        ) {
            return (string)$chunk->content;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolCallPayload(ToolInterface $tool): array
    {
        return [
            'id' => (string)($tool->getCallId() ?? ''),
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'arguments' => json_encode($tool->getInputs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $toolMap
     * @return array{
     *     parts: array<int, array<string, mixed>>,
     *     summary: string,
     *     public_result: string,
     *     tool: string,
     *     tool_label: string,
     *     tool_call_id: string|null,
     *     message_id: string|null
     * }
     */
    private static function persistToolResultAndCollect(AiAgent $agent, int $sessionId, array $toolMap, ToolInterface $tool, int $assistantMessageId): array
    {
        $toolName = (string)$tool->getName();
        $toolCallId = (string)($tool->getCallId() ?? '');
        $toolLabel = (string)($toolMap[$toolName]['label'] ?? $toolName);

        $rawText = (string)$tool->getResult();
        $decoded = null;
        if ($rawText !== '' && json_validate($rawText)) {
            $tmp = json_decode($rawText, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $tmp;
            }
        }
        $toolResult = $decoded ?? $rawText;
        $normalized = self::normalizeToolResult($toolResult, $rawText);
        $summary = $normalized['summary'];
        $toolFailed = $normalized['failed'];

        $payload = [
            'tool_label' => $toolLabel,
        ];
        if (is_array($toolResult)) {
            $payload['raw'] = $toolResult;
        } elseif ($rawText !== '') {
            $payload['raw_text'] = $rawText;
        }
        if ($summary !== '') {
            $payload['tool_summary'] = $summary;
        }
        $toolResultParts = self::extractToolResultParts($toolResult);
        if ($toolFailed) {
            $payload['error'] = [
                'message' => (string)($normalized['debug_message'] ?? $summary),
                'type' => (string)($normalized['error_type'] ?? 'tool_error'),
                'retryable' => (bool)($normalized['retryable'] ?? false),
                'user_message' => $summary,
            ];
        }

        $toolContent = $summary !== ''
            ? $summary
            : (is_string($toolResult) && trim($toolResult) !== '' ? trim($toolResult) : $toolLabel);

        AgentMessageStore::appendMessage(
            $agent->id,
            $sessionId,
            'tool',
            $toolContent,
            $payload,
            $toolName,
            $toolCallId !== '' ? $toolCallId : null
        );

        $publicResult = $toolContent;

        return [
            'parts' => $toolResultParts,
            'summary' => $summary,
            'public_result' => $publicResult,
            'tool' => $toolName,
            'tool_label' => $toolLabel,
            'tool_call_id' => $toolCallId !== '' ? $toolCallId : null,
            'message_id' => $assistantMessageId > 0 ? sprintf('msg_%d', $assistantMessageId) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extractToolResultParts(mixed $toolResult): array
    {
        $payload = null;
        if (is_array($toolResult)) {
            $encoded = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== '') {
                $payload = $encoded;
            }
        } elseif (is_string($toolResult)) {
            $payload = $toolResult;
        }

        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $structured = CardParser::extractStructuredResult($payload);
        if (!is_array($structured)) {
            return [];
        }

        $parts = is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : [];
        return self::extractNonTextParts($parts);
    }

    private static function looksLikeStructuredPayload(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }
        if ((!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) || !json_validate($trimmed)) {
            return false;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return false;
        }

        if (array_is_list($decoded)) {
            return false;
        }

        if (isset($decoded['type']) || isset($decoded['card']) || isset($decoded['images']) || isset($decoded['videos'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array<string, mixed>>
     */
    private static function extractNonTextParts(array $parts): array
    {
        return array_values(array_filter($parts, static function (mixed $part): bool {
            if (!is_array($part)) {
                return false;
            }
            return (string)($part['type'] ?? '') !== 'text';
        }));
    }

    /**
     * @return array{
     *     summary: string,
     *     failed: bool,
     *     retryable: bool,
     *     error_type: string,
     *     debug_message: string
     * }
     */
    private static function normalizeToolResult(mixed $toolResult, string $rawText): array
    {
        $summary = '';
        $failed = false;
        $retryable = false;
        $errorType = 'tool_error';
        $debugMessage = '';

        if (is_array($toolResult)) {
            $isStructuredError = ($toolResult['__tool_error'] ?? false) === true
                || (isset($toolResult['status']) && (int)$toolResult['status'] === 0 && isset($toolResult['user_message']));

            if ($isStructuredError) {
                $failed = true;
                $summary = trim((string)($toolResult['user_message'] ?? $toolResult['message'] ?? ''));
                $retryable = (bool)($toolResult['retryable'] ?? false);
                $errorType = trim((string)($toolResult['error_type'] ?? 'tool_error'));
                $debugMessage = trim((string)($toolResult['debug_message'] ?? $rawText));
            } else {
                $summary = trim((string)($toolResult['summary'] ?? $toolResult['message'] ?? ''));
            }
        } elseif (is_string($toolResult)) {
            $summary = trim($toolResult);
        }

        if ($failed && $summary === '') {
            $summary = '工具调用失败，系统可能暂时异常，请稍后重试。';
        }
        if ($failed && $debugMessage === '') {
            $debugMessage = $rawText !== '' ? $rawText : $summary;
        }

        return [
            'summary' => $summary,
            'failed' => $failed,
            'retryable' => $retryable,
            'error_type' => $errorType,
            'debug_message' => $debugMessage,
        ];
    }
}
