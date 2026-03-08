<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

final class OpenAiHttp
{
    public static function decodeSseChunk(string $chunk): ?array
    {
        $chunk = trim($chunk);
        if (!str_starts_with($chunk, 'data:')) {
            return null;
        }
        $payload = trim(substr($chunk, 5));
        if ($payload === '' || $payload === '[DONE]') {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write($json !== false ? $json : '{}');
        return $response;
    }

    public static function errorJson(
        ResponseInterface $response,
        string $message,
        string $type = 'invalid_request_error',
        int $status = 400,
        mixed $code = null,
        mixed $param = null
    ): ResponseInterface {
        return self::json($response, [
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => $param,
                'code' => $code,
            ],
        ], $status);
    }

    public static function withSseHeaders(ResponseInterface $response, int $status = 200): ResponseInterface
    {
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');
    }

    public static function sseErrorResponse(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream !== false) {
            fwrite($stream, Sse::errorChunk(0, null, null, $message) . Sse::done());
            rewind($stream);
        }

        return self::withSseHeaders($response, $status)
            ->withBody(new Stream($stream ?: fopen('php://temp', 'r+') ?: fopen('php://memory', 'r+')));
    }

    /**
     * @param \Generator<string> $generator
     */
    public static function ssePumpStream(\Generator $generator, string $modelForDisplay, ?callable $logger = null): PumpStream
    {
        $started = false;
        $ended = false;
        $lastSessionId = 0;

        return new PumpStream(function () use ($generator, $modelForDisplay, $logger, &$started, &$ended, &$lastSessionId) {
            if ($ended) {
                return false;
            }

            try {
                if ($started) {
                    $generator->next();
                } else {
                    $started = true;
                }

                if (!$generator->valid()) {
                    $ended = true;
                    return false;
                }

                $chunk = (string)$generator->current();
                $decoded = self::decodeSseChunk($chunk);
                if (is_array($decoded) && isset($decoded['session_id']) && is_numeric($decoded['session_id'])) {
                    $lastSessionId = (int)$decoded['session_id'];
                }

                return $chunk;
            } catch (\Throwable $e) {
                $ended = true;
                if ($logger) {
                    $logger($e);
                }
                $sid = $lastSessionId > 0 ? $lastSessionId : 0;
                return Sse::errorChunk($sid, $modelForDisplay, null, $e->getMessage()) . Sse::done();
            }
        });
    }
}
