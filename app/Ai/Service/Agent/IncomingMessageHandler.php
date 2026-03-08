<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;

final class IncomingMessageHandler
{
    /**
     * @return array<string, mixed>
     */
    private static function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload)) {
            $trimmed = trim($payload);
            if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_validate($payload)) {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }

    /**
     * Extract the first text segment for storage/display.
     */
    private static function extractFirstText(mixed $contentValue, array $payload): string
    {
        $parts = null;
        if (isset($payload['parts'])) {
            $candidate = $payload['parts'];
            if (is_array($candidate) && array_is_list($candidate)) {
                $parts = $candidate;
            } elseif (is_string($candidate) && trim($candidate) !== '' && str_starts_with(ltrim($candidate), '[') && json_validate($candidate)) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded) && array_is_list($decoded)) {
                    $parts = $decoded;
                }
            }
        }
        if ($parts === null && is_array($contentValue) && array_is_list($contentValue)) {
            $parts = $contentValue;
        }
        if ($parts !== null) {
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $text = trim((string)$part['text']);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        // Fallback: stringify but keep only the first non-empty line.
        $text = HistoryBuilder::stringifyMessageContent($contentValue);
        $text = str_replace("\r\n", "\n", (string)$text);
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return trim($text);
    }

    /**
     * 从请求 messages 中提取最新一条 user 消息并入库。
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{user_text: string, stored_content: mixed}
     */
    public static function appendLatestUserMessage(AiAgent $agent, int $sessionId, array $messages): array
    {
        $userText = '';
        $storedContent = null;

        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') !== 'user' || empty($msg['content'])) {
                continue;
            }
            $contentValue = $msg['content'];
            $payload = self::normalizePayload($msg['payload'] ?? null);

            // Preserve original OpenAI content parts for UI replay / redispatch.
            if (!array_key_exists('parts', $payload)) {
                if (is_array($contentValue) && array_is_list($contentValue)) {
                    $payload['parts'] = $contentValue;
                } elseif (is_string($contentValue) && trim($contentValue) !== '' && str_starts_with(ltrim($contentValue), '[') && json_validate($contentValue)) {
                    $decoded = json_decode($contentValue, true);
                    if (is_array($decoded) && array_is_list($decoded)) {
                        $payload['parts'] = $decoded;
                    }
                }
            }

            $userText = self::extractFirstText($contentValue, $payload);
            $storedContent = $userText;

            MessageStore::appendMessage($agent->id, $sessionId, 'user', $storedContent, $payload);
            break;
        }

        return [
            'user_text' => $userText,
            'stored_content' => $storedContent,
        ];
    }
}
