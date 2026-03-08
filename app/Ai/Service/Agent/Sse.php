<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

final class Sse
{
    public static function prepareStreaming(): void
    {
        ignore_user_abort(true);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        ob_implicit_flush(true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function format(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '{"error":{"message":"编码响应失败","type":"internal_error"}}';
        }
        return sprintf("data: %s\n\n", $encoded);
    }

    public static function done(): string
    {
        return "data: [DONE]\n\n";
    }

    public static function comment(string $text = ''): string
    {
        return sprintf(": %s\n\n", $text);
    }

    /**
     * @param array<string, mixed>|string $delta
     */
    public static function openAIChunk(array|string $delta, int $sessionId, ?int $messageId = null, ?string $model = null): string
    {
        if (is_string($delta)) {
            $delta = ['content' => $delta];
        }
        return self::format([
            'session_id' => $sessionId,
            'id' => $messageId ? sprintf('msg_%d', $messageId) : uniqid('agent_chunk_', true),
            'object' => 'chat.completion.chunk',
            'model' => $model,
            'created' => time(),
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta === [] ? new \stdClass() : $delta,
                    'finish_reason' => null,
                ],
            ],
        ]);
    }

    public static function errorChunk(int $sessionId, ?string $model, ?int $messageId, string $message): string
    {
        return self::format([
            'session_id' => $sessionId,
            'id' => $messageId ? sprintf('msg_%d', $messageId) : uniqid('agent_chunk_', true),
            'object' => 'chat.completion.chunk',
            'model' => $model,
            'created' => time(),
            'choices' => [
                [
                    'index' => 0,
                    'delta' => new \stdClass(),
                    'finish_reason' => 'error',
                ],
            ],
            'error' => [
                'message' => $message,
                'type' => 'agent_error',
                'code' => null,
            ],
        ]);
    }
}
