<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Service\Agent as AgentService;

final class OpenAiMessage
{
    /**
     * @return array<int, array{role:string,content:mixed}>
     */
    public static function normalize(mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string)$message['role'] : null;
            if (!$role) {
                continue;
            }

            $content = $message['content'] ?? '';
            if (!is_array($content)) {
                $content = self::stringifyContent($content);
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    public static function promptTokens(array $messages): int
    {
        $sum = 0;
        foreach ($messages as $message) {
            $content = is_array($message) ? ($message['content'] ?? '') : '';
            $sum += AgentService::estimateTokensForText((string)$content);
        }
        return $sum;
    }

    public static function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
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
            return implode('', $parts);
        }
        return (string)$content;
    }
}
