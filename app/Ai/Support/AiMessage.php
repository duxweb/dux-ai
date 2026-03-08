<?php

declare(strict_types=1);

namespace App\Ai\Support;

final class AiMessage
{
    /**
     * @return array<string, mixed>
     */
    public static function text(string $text, string $role = 'assistant'): array
    {
        return [
            'role' => $role,
            'content' => $text,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<string, mixed>
     */
    public static function card(array $cards, string $role = 'assistant'): array
    {
        return [
            'role' => $role,
            'content' => [
                'type' => 'card',
                'card' => $cards,
            ],
        ];
    }
}
