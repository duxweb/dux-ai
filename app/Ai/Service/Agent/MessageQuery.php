<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgentMessage;

final class MessageQuery
{
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

    private static function shouldHideMessage(AiAgentMessage $msg, bool $forUi = false): bool
    {
        $role = (string)($msg->role ?? '');
        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            if (!$forUi) {
                return false;
            }
            $content = trim((string)($msg->content ?? ''));
            $payload = is_array($msg->payload ?? null) ? ($msg->payload ?? []) : [];
            if ($role === 'tool' && $content === '') {
                return true;
            }
            return $content === '' && ($payload === [] || $payload === null);
        }

        $content = trim((string)($msg->content ?? ''));
        $payload = is_array($msg->payload ?? null) ? ($msg->payload ?? []) : [];

        // 解析/调用失败的错误占位消息：不返回给前端（避免污染上下文/误显示为正常回复）。
        if ($role === 'assistant' && self::isErrorMessagePayload($payload)) {
            return true;
        }

        // 历史构建允许 tool_calls 的 assistant 作为占位存在。
        if ($role === 'assistant' && !empty($payload['tool_calls'])) {
            if (!$forUi) {
                return false;
            }
            if (!self::hasAssistantCard($payload)) {
                return true;
            }
        }

        // 纯空白、无 payload 的异常消息：不返回给前端，避免再次参与历史构建导致 OpenAI-like 400。
        if ($content === '' && ($payload === [] || $payload === null)) {
            return true;
        }

        // 仅展示层：用户/系统若无文本且无可见附件，不展示。
        if ($forUi && in_array($role, ['user', 'system'], true) && $content === '' && !self::hasVisibleUserParts($payload)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listMessagesForHistory(int $sessionId, int $limit = 0): array
    {
        $query = AiAgentMessage::query()
            ->where('session_id', $sessionId)
            ->orderBy('id', 'asc');
        if ($limit) {
            $query->limit($limit);
        }
        return $query->get()
            ->reject(static fn (AiAgentMessage $msg) => self::shouldHideMessage($msg))
            ->map(static fn (AiAgentMessage $msg) => $msg->transform())
            ->values()
            ->all();
    }

    /**
     * UI output: OpenAI-like message objects.
     *
     * - `content` will be an array of parts when possible (text/image/file).
     * - `payload` is not returned.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listMessagesForUI(int $sessionId, int $limit = 0): array
    {
        $query = AiAgentMessage::query()
            ->where('session_id', $sessionId)
            ->orderBy('id', 'asc');
        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()
            ->reject(static fn (AiAgentMessage $msg) => self::shouldHideMessage($msg, true))
            ->map(static function (AiAgentMessage $msg) {
                return self::toOpenAIMessage($msg);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function toOpenAIMessage(AiAgentMessage $msg): array
    {
        $role = (string)($msg->role ?? '');
        $payload = is_array($msg->payload ?? null) ? ($msg->payload ?? []) : [];

        $content = (string)($msg->content ?? '');
        $contentParts = null;
        $meta = [];
        if ($role === 'assistant' || $role === 'user' || $role === 'system') {
            if (isset($payload['parts']) && is_array($payload['parts']) && array_is_list($payload['parts'])) {
                if ($role === 'assistant') {
                    $contentParts = self::sanitizeAssistantPartsForUI($payload['parts']);
                } else {
                    $contentParts = self::sanitizeUserPartsForUI($payload['parts']);
                }
            }
        }

        $out = [
            'id' => (int)$msg->id,
            'role' => $role,
            'content' => $contentParts ?? $content,
            'created_at' => $msg->created_at?->toDateTimeString(),
        ];

        if ($role === 'assistant' && isset($payload['tool_calls']) && is_array($payload['tool_calls']) && $payload['tool_calls'] !== []) {
            $out['tool_calls'] = $payload['tool_calls'];
            if (trim($content) === '') {
                $out['content'] = '';
            }
        }
        if ($role === 'assistant') {
            if (isset($payload['parts']) && is_array($payload['parts']) && array_is_list($payload['parts'])) {
                foreach ($payload['parts'] as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'card') {
                        $meta['card'] = $part['card'] ?? [];
                        break;
                    }
                }
            }
        }

        if ($role === 'tool') {
            $toolCallId = (string)($msg->tool_call_id ?? '');
            if ($toolCallId !== '') {
                $out['tool_call_id'] = $toolCallId;
            }
        }

        if ($meta !== []) {
            $out['meta'] = $meta;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function hasAssistantCard(array $payload): bool
    {
        if (!isset($payload['parts']) || !is_array($payload['parts']) || !array_is_list($payload['parts'])) {
            return false;
        }
        foreach ($payload['parts'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['type'] ?? '') === 'card' && !empty($part['card'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function hasVisibleUserParts(array $payload): bool
    {
        $parts = $payload['parts'] ?? null;
        if (!is_array($parts) || !array_is_list($parts)) {
            return false;
        }

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string)($part['type'] ?? '');
            if ($type === 'image_url' || $type === 'file_url' || $type === 'video_url') {
                return true;
            }
            if ($type === 'text') {
                $text = trim((string)($part['text'] ?? $part['content'] ?? ''));
                if ($text !== '' && !str_starts_with($text, '[本地解析附件]')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * UI 历史消息：仅保留用户原始输入 + 每种附件类型首个条目，过滤解析注入文本。
     *
     * @param array<int, mixed> $parts
     * @return array<int, array<string, mixed>>
     */
    private static function sanitizeUserPartsForUI(array $parts): array
    {
        $result = [];
        $hasText = false;
        $hasImage = false;
        $hasFile = false;

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $type = (string)($part['type'] ?? '');
            if ($type === 'text') {
                if ($hasText) {
                    continue;
                }
                $text = trim((string)($part['text'] ?? $part['content'] ?? ''));
                if ($text === '' || str_starts_with($text, '[本地解析附件]')) {
                    continue;
                }
                $result[] = [
                    'type' => 'text',
                    'text' => $text,
                ];
                $hasText = true;
                continue;
            }

            if ($type === 'image_url') {
                if ($hasImage) {
                    continue;
                }
                $result[] = $part;
                $hasImage = true;
                continue;
            }

            if ($type === 'file_url') {
                if ($hasFile) {
                    continue;
                }
                $result[] = $part;
                $hasFile = true;
            }
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $parts
     * @return array<int, array<string, mixed>>
     */
    private static function sanitizeAssistantPartsForUI(array $parts): array
    {
        $result = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $type = (string)($part['type'] ?? '');
            if ($type === 'text') {
                $text = trim((string)($part['text'] ?? $part['content'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $result[] = [
                    'type' => 'text',
                    'text' => $text,
                ];
                continue;
            }

            if ($type === 'image_url') {
                $result[] = $part;
                continue;
            }

            if ($type === 'video_url') {
                $video = $part['video_url'] ?? null;
                if (is_string($video)) {
                    $video = ['url' => trim($video)];
                }
                if (is_array($video) && trim((string)($video['url'] ?? '')) !== '') {
                    $result[] = [
                        'type' => 'video_url',
                        'video_url' => $video,
                    ];
                }
                continue;
            }

            if ($type === 'file_url') {
                $result[] = $part;
                continue;
            }

            if ($type === 'card' && is_array($part['card'] ?? null)) {
                $result[] = [
                    'type' => 'card',
                    'card' => $part['card'],
                ];
            }
        }

        return $result;
    }
}
