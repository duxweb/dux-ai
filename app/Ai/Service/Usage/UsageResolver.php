<?php

declare(strict_types=1);

namespace App\Ai\Service\Usage;

use App\Ai\Service\Agent\Token;

final class UsageResolver
{
    /**
     * @param array<string, mixed>|null $usage
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int,usage_source:string,usage_missing:bool}
     */
    public static function fromUsageOrEstimate(?array $usage, string $fallbackText): array
    {
        if (is_array($usage) && $usage !== []) {
            $normalized = self::normalizeUsage($usage);
            if ($normalized !== null) {
                return [
                    ...$normalized,
                    'usage_source' => 'provider',
                    'usage_missing' => false,
                ];
            }
        }

        $completion = Token::estimateTokensForText($fallbackText);

        return [
            'prompt_tokens' => 0,
            'completion_tokens' => $completion,
            'total_tokens' => $completion,
            'usage_source' => 'estimate',
            'usage_missing' => true,
        ];
    }

    /**
     * @param array<string, mixed> $usage
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}|null
     */
    public static function normalizeUsage(array $usage): ?array
    {
        if (isset($usage['input_tokens']) || isset($usage['output_tokens'])) {
            $prompt = (int)($usage['input_tokens'] ?? 0);
            $completion = (int)($usage['output_tokens'] ?? 0);

            return [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
            ];
        }

        if (isset($usage['prompt_tokens']) || isset($usage['completion_tokens']) || isset($usage['total_tokens'])) {
            $prompt = (int)($usage['prompt_tokens'] ?? 0);
            $completion = (int)($usage['completion_tokens'] ?? 0);
            $total = (int)($usage['total_tokens'] ?? ($prompt + $completion));

            return [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $total,
            ];
        }

        return null;
    }
}
