<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

final class MessageAdapter
{
    /**
     * @param array<int, array<string, mixed>> $messages OpenAI-compatible messages
     * @param bool $supportImage When false, ignore image attachments
     * @param bool $supportFile When false, ignore file/document attachments
     * @param array{image_mode?: 'auto'|'url'|'base64', document_mode?: 'auto'|'base64'} $options
     * @return array<int, Message>
     */
    public static function fromOpenAIMessages(array $messages, bool $supportImage = true, bool $supportFile = true, array $options = []): array
    {
        $imageMode = ($options['image_mode'] ?? 'url');
        $documentMode = ($options['document_mode'] ?? 'base64');

        $http = null;
        $result = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = (string)($msg['role'] ?? '');
            if ($role === '') {
                continue;
            }

            $normalized = self::normalizeContent($msg['content'] ?? null, [
                'image_mode' => $imageMode,
                'document_mode' => $documentMode,
                'support_image' => $supportImage,
                'support_file' => $supportFile,
            ], $http);

            $toolCalls = isset($msg['tool_calls']) && is_array($msg['tool_calls']) ? $msg['tool_calls'] : null;
            $toolCallId = isset($msg['tool_call_id']) ? trim((string)$msg['tool_call_id']) : '';

            if ($role === 'assistant' && $toolCalls) {
                $tools = [];
                foreach ($toolCalls as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $fn = is_array($call['function'] ?? null) ? ($call['function'] ?? []) : [];
                    $name = (string)($fn['name'] ?? '');
                    $arguments = (string)($fn['arguments'] ?? '');
                    $inputs = null;
                    if ($arguments !== '' && json_validate($arguments)) {
                        $decoded = json_decode($arguments, true);
                        if (is_array($decoded)) {
                            $inputs = $decoded;
                        }
                    }
                    $tools[] = Tool::make($name !== '' ? $name : 'tool')
                        ->setInputs($inputs)
                        ->setCallId(isset($call['id']) ? (string)$call['id'] : null);
                }

                $message = new ToolCallMessage(
                    is_string($normalized) && trim($normalized) === '' ? null : $normalized,
                    $tools
                );
                $message->addMetadata('tool_calls', $toolCalls);
            } elseif ($role === 'tool' && $toolCallId !== '') {
                $tool = Tool::make('tool_result')
                    ->setCallId($toolCallId)
                    ->setResult(self::normalizeScalarContent($normalized));
                $message = new ToolResultMessage([$tool]);
            } else {
                $message = match ($role) {
                    'user' => new UserMessage($normalized),
                    'assistant' => new AssistantMessage($normalized),
                    'system' => new Message(MessageRole::SYSTEM, $normalized),
                    'tool' => new Message(MessageRole::TOOL, self::normalizeScalarContent($normalized)),
                    default => new Message(MessageRole::from($role), $normalized),
                };
            }

            if (!$message instanceof ToolCallMessage && $role === 'assistant' && isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $message->addMetadata('tool_calls', $msg['tool_calls']);
            }
            if (!$message instanceof ToolResultMessage && $role === 'tool' && isset($msg['tool_call_id'])) {
                $message->addMetadata('tool_call_id', (string)$msg['tool_call_id']);
            }

            $result[] = $message;
        }

        return $result;
    }

    /**
     * @param array{image_mode:'auto'|'url'|'base64', document_mode:'auto'|'base64', support_image:bool, support_file:bool} $options
     * @return string|array<int, ContentBlockInterface>
     */
    private static function normalizeContent(mixed $content, array $options, ?Client &$httpClient): string|array
    {
        if (is_string($content) || is_int($content) || is_float($content) || $content === null) {
            return (string)($content ?? '');
        }

        if (!is_array($content)) {
            return (string)$content;
        }

        $blocks = [];
        $textParts = [];
        $supportImage = (bool)($options['support_image'] ?? true);
        $supportFile = (bool)($options['support_file'] ?? true);
        foreach ($content as $part) {
            if (is_string($part)) {
                if ($part !== '') {
                    $textParts[] = $part;
                }
                continue;
            }
            if (!is_array($part)) {
                continue;
            }

            $type = (string)($part['type'] ?? 'text');

            if ($type === 'image_url') {
                if (!$supportImage) {
                    continue;
                }
                $url = self::extractUrlFromImagePart($part);
                if ($url === '') {
                    continue;
                }

                $imageMode = (string)($options['image_mode'] ?? 'url');
                $imageModeHint = trim((string)(
                    (is_array($part['image_url'] ?? null) ? ($part['image_url']['mode_hint'] ?? null) : null)
                    ?? ($part['mode_hint'] ?? '')
                ));
                if (in_array($imageModeHint, ['auto', 'url', 'base64'], true)) {
                    $imageMode = $imageModeHint;
                }
                if ($imageMode === 'auto') {
                    $imageMode = 'url';
                }

                if ($imageMode === 'base64') {
                    $asset = self::fetchUrlAsBase64($url, $httpClient);
                    $blocks[] = new ImageContent(
                        $asset['base64'],
                        SourceType::BASE64,
                        $asset['mime'] !== '' ? $asset['mime'] : null
                    );
                } else {
                    $blocks[] = new ImageContent($url, SourceType::URL);
                }
                continue;
            }

            if ($type === 'file_url' || $type === 'file') {
                if (!$supportFile) {
                    continue;
                }

                $fileMeta = self::extractFilePartMeta($part);
                $url = trim((string)($fileMeta['url'] ?? ''));
                $fileName = trim((string)($fileMeta['filename'] ?? $fileMeta['name'] ?? ''));
                $mime = trim((string)($fileMeta['mime'] ?? $fileMeta['media_type'] ?? $fileMeta['mediaType'] ?? ''));

                $documentMode = (string)($options['document_mode'] ?? 'base64');
                $modeHint = trim((string)($fileMeta['mode_hint'] ?? ''));
                if (in_array($modeHint, ['auto', 'base64'], true)) {
                    $documentMode = $modeHint;
                }
                if ($documentMode === 'auto') {
                    $documentMode = 'base64';
                }

                if ($url === '') {
                    continue;
                }

                $asset = self::fetchUrlAsBase64($url, $httpClient);
                $blocks[] = new FileContent(
                    $asset['base64'],
                    SourceType::BASE64,
                    $mime !== '' ? $mime : ($asset['mime'] !== '' ? $asset['mime'] : 'application/octet-stream'),
                    $fileName !== '' ? $fileName : ($asset['filename'] !== '' ? $asset['filename'] : null)
                );
                continue;
            }

            $text = (string)($part['text'] ?? $part['content'] ?? '');
            if ($text !== '') {
                $textParts[] = $text;
            }
        }

        if ($textParts !== []) {
            $merged = implode("\n\n", array_values(array_filter(array_map(
                static fn (string $item): string => trim($item),
                $textParts
            ), static fn (string $item): bool => $item !== '')));
            if ($merged !== '') {
                array_unshift($blocks, new TextContent($merged));
            }
        }

        if ($blocks === []) {
            return '';
        }

        if (count($blocks) === 1 && $blocks[0] instanceof TextContent) {
            return (string)$blocks[0]->content;
        }

        return $blocks;
    }

    private static function normalizeScalarContent(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $parts = [];
        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = trim((string)$block->content);
            }
        }

        return implode("\n", array_values(array_filter($parts, static fn ($t) => $t !== '')));
    }

    private static function extractUrlFromImagePart(array $part): string
    {
        $imageUrl = $part['image_url'] ?? null;
        if (is_array($imageUrl)) {
            $url = trim((string)($imageUrl['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        if (is_string($imageUrl) && trim($imageUrl) !== '') {
            return trim($imageUrl);
        }
        return trim((string)($part['url'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractFilePartMeta(array $part): array
    {
        $file = $part['file_url'] ?? ($part['file'] ?? null);
        if (is_array($file)) {
            $url = $file['url'] ?? ($file['file_url'] ?? null);
            $filename = $file['filename'] ?? ($file['name'] ?? null);
            return [
                'url' => is_string($url) ? $url : '',
                'filename' => is_string($filename) ? $filename : '',
                'mime' => is_string($file['mime'] ?? null) ? (string)$file['mime'] : '',
                'media_type' => is_string($file['media_type'] ?? null) ? (string)$file['media_type'] : '',
                'mediaType' => is_string($file['mediaType'] ?? null) ? (string)$file['mediaType'] : '',
                'mode_hint' => is_string($file['mode_hint'] ?? null) ? (string)$file['mode_hint'] : '',
            ];
        }

        if (is_string($file) && trim($file) !== '') {
            return [
                'url' => trim($file),
            ];
        }

        $url = trim((string)($part['url'] ?? ''));
        return $url !== '' ? [
            'url' => $url,
            'mode_hint' => is_string($part['mode_hint'] ?? null) ? (string)$part['mode_hint'] : '',
        ] : [];
    }

    /**
     * @return array{base64: string, mime: string, filename: string}
     */
    private static function fetchUrlAsBase64(string $url, ?Client &$client): array
    {
        $client ??= new Client([
            'timeout' => 30,
            'http_errors' => false,
        ]);

        $response = $client->get($url, [
            RequestOptions::STREAM => true,
        ]);

        $body = $response->getBody()->getContents();
        $mime = '';
        if ($response->hasHeader('Content-Type')) {
            $mime = trim(explode(';', (string)$response->getHeaderLine('Content-Type'))[0] ?? '');
        }

        $filename = '';
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $basename = basename($path);
            if ($basename !== '' && $basename !== '/') {
                $filename = $basename;
            }
        }

        return [
            'base64' => base64_encode($body),
            'mime' => $mime,
            'filename' => $filename,
        ];
    }
}
