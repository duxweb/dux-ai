<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

final class HistoryBuilder
{
    private static function normalizeMessageComparableContent(mixed $content): string
    {
        if (is_array($content)) {
            return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return trim((string)($content ?? ''));
    }

    /**
     * @param array<string, mixed>|null $message
     */
    private static function isAssistantToolCallMessage(?array $message): bool
    {
        if (!is_array($message)) {
            return false;
        }

        return ($message['role'] ?? '') === 'assistant'
            && isset($message['tool_calls'])
            && is_array($message['tool_calls'])
            && $message['tool_calls'] !== [];
    }

    /**
     * @param array<string, mixed>|null $last
     * @param array<string, mixed> $message
     */
    private static function isDuplicateConsecutiveUserMessage(?array $last, array $message): bool
    {
        if (!is_array($last)) {
            return false;
        }

        if (($last['role'] ?? '') !== 'user' || ($message['role'] ?? '') !== 'user') {
            return false;
        }

        return self::normalizeMessageComparableContent($last['content'] ?? null)
            === self::normalizeMessageComparableContent($message['content'] ?? null);
    }

    /**
     * 清洗历史中的非法序列，兼容旧数据里的连续 user / 孤立 tool。
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private static function sanitizeSequence(array $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? '');
            $lastIndex = array_key_last($result);
            $last = $lastIndex !== null ? $result[$lastIndex] : null;

            if (self::isDuplicateConsecutiveUserMessage($last, $message)) {
                $result[$lastIndex] = $message;
                continue;
            }

            if ($role === 'tool' && !self::isAssistantToolCallMessage($last)) {
                continue;
            }

            $result[] = $message;
        }

        return $result;
    }

    private static function isErrorMessagePayload(array $payload): bool
    {
        if (!array_key_exists('error', $payload)) {
            return false;
        }

        $val = $payload['error'];
        if (is_bool($val)) {
            return $val;
        }
        if (is_array($val)) {
            return $val !== [];
        }
        if (is_string($val)) {
            return trim($val) !== '';
        }
        return $val !== null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function decodeContentParts(mixed $content): ?array
    {
        if (is_array($content) && array_is_list($content)) {
            return $content;
        }
        if (!is_string($content) || trim($content) === '') {
            return null;
        }
        $trimmed = ltrim($content);
        if (!str_starts_with($trimmed, '[') || !json_validate($content)) {
            return null;
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }
        return null;
    }

    private static function stringifyToolContent(mixed $content): string
    {
        if (is_array($content)) {
            return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return (string)($content ?? '');
    }

    public static function stringifyMessageContent(mixed $content): string
    {
        if (is_string($content)) {
            $parts = self::decodeContentParts($content);
            if ($parts === null) {
                return $content;
            }
            $content = $parts;
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }
                if (is_array($part)) {
                    if (isset($part['text'])) {
                        $parts[] = (string)$part['text'];
                    } elseif (isset($part['content'])) {
                        $parts[] = (string)$part['content'];
                    }
                }
            }
            $parts = array_values(array_filter(array_map(static fn ($t) => trim((string)$t), $parts), static fn ($t) => $t !== ''));
            return implode("\n", $parts);
        }
        return (string)$content;
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array<string, mixed>>
     */
    private static function filterAttachmentParts(array $parts, bool $supportImage, bool $supportFile): array
    {
        if ($supportImage && $supportFile) {
            return $parts;
        }

        return array_values(array_filter($parts, static function ($part) use ($supportImage, $supportFile) {
            if (!is_array($part)) {
                return true;
            }

            $type = (string)($part['type'] ?? '');
            if ($type === 'image_url') {
                return $supportImage;
            }
            if ($type === 'file_url' || $type === 'file') {
                return $supportFile;
            }
            return true;
        }));
    }

    /**
     * 构建 OpenAI 兼容 messages（不做内容补齐/不做兼容修复，按落库顺序原样转换）。
     *
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, mixed>>
     */
    public static function buildOpenAIMessagesFromHistory(array $history, bool $supportImage, bool $supportFile): array
    {
        $messages = [];

        foreach ($history as $item) {
            $role = $item['role'] ?? '';
            if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) {
                continue;
            }

            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $content = $item['content'] ?? '';

            // 错误占位消息不参与模型历史，避免影响后续对话。
            if ($role === 'assistant' && self::isErrorMessagePayload($payload)) {
                continue;
            }
            if ($role === 'assistant' && isset($payload['parts']) && is_array($payload['parts'])) {
                foreach ($payload['parts'] as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'card') {
                        continue 2;
                    }
                }
            }

            // 清理异常空消息：空白 content 且没有任何有效 payload，不参与历史构建（避免部分 OpenAI-like 返回 text content is empty）。
            if ($role !== 'tool') {
                $contentText = trim(self::stringifyMessageContent($content));
                $hasToolCalls = $role === 'assistant' && isset($payload['tool_calls']) && is_array($payload['tool_calls']) && $payload['tool_calls'] !== [];
                if (!$hasToolCalls && $contentText === '' && ($payload === [] || $payload === null)) {
                    continue;
                }
            }

            $message = [
                'role' => $role,
                'content' => self::stringifyMessageContent($content),
            ];

            if ($role === 'user' && isset($payload['parts'])) {
                $parts = self::decodeContentParts($payload['parts']);
                if ($parts !== null) {
                    $message['content'] = self::filterAttachmentParts($parts, $supportImage, $supportFile);
                }
            }

            if ($role === 'assistant' && isset($payload['tool_calls']) && is_array($payload['tool_calls']) && $payload['tool_calls'] !== []) {
                $message['tool_calls'] = $payload['tool_calls'];
                $message['content'] = '';
            }

            if ($role === 'tool') {
                $toolCallId = (string)($item['tool_call_id'] ?? '');
                if ($toolCallId === '') {
                    continue;
                }
                $message['tool_call_id'] = $toolCallId;
                $toolContent = $payload['raw_text'] ?? ($payload['raw'] ?? $content);
                $message['content'] = self::stringifyToolContent($toolContent);
            }

            $messages[] = $message;
        }

        return self::sanitizeSequence($messages);
    }
}
