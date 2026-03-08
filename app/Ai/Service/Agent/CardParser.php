<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

final class CardParser
{
    /**
     * @return array{parts: array<int, array<string, mixed>>, summary: string}|null
     */
    public static function extractStructuredResult(string $content): ?array
    {
        $decoded = self::decodeStructuredPayload($content);
        if (!is_array($decoded)) {
            return null;
        }

        $type = self::resolveType($decoded);
        if ($type === 'card') {
            $card = $decoded['card'] ?? null;
            if (!is_array($card) || !array_is_list($card)) {
                return null;
            }
            $normalized = self::normalizeCard($card);
            if ($normalized === []) {
                return null;
            }
            $summary = trim((string)($decoded['summary'] ?? $decoded['message'] ?? ''));
            if ($summary === '') {
                $summary = count($normalized) > 0 ? sprintf('已返回结果（%d条）', count($normalized)) : '已返回结果';
            }
            return [
                'parts' => [['type' => 'card', 'card' => $normalized]],
                'summary' => $summary,
            ];
        }

        if ($type === 'image') {
            return self::buildMediaStructuredResult($decoded, 'image');
        }
        if ($type === 'video') {
            return self::buildMediaStructuredResult($decoded, 'video');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeStructuredPayload(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        if (json_validate($content)) {
            $decoded = json_decode($content, true);
            return is_array($decoded) ? $decoded : null;
        }

        $json = self::extractFirstJsonObject($content);
        if ($json === '' || !json_validate($json)) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function resolveType(array $decoded): string
    {
        $type = strtolower(trim((string)($decoded['type'] ?? '')));
        if (in_array($type, ['card', 'image', 'video'], true)) {
            return $type;
        }

        $hasCard = is_array($decoded['card'] ?? null) && array_is_list($decoded['card']);
        if ($hasCard) {
            return 'card';
        }

        if (self::collectMediaUrls($decoded, 'image') !== []) {
            return 'image';
        }
        if (self::collectMediaUrls($decoded, 'video') !== []) {
            return 'video';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{parts: array<int, array<string, mixed>>, summary: string}|null
     */
    private static function buildMediaStructuredResult(array $decoded, string $mediaType): ?array
    {
        $urls = self::collectMediaUrls($decoded, $mediaType);
        if ($urls === []) {
            return null;
        }

        $parts = [];
        $text = trim((string)($decoded['text'] ?? $decoded['summary'] ?? $decoded['message'] ?? ''));
        if ($text !== '') {
            $parts[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        foreach ($urls as $url) {
            if ($mediaType === 'video') {
                $parts[] = [
                    'type' => 'video_url',
                    'video_url' => [
                        'url' => $url,
                    ],
                ];
            } else {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $url,
                    ],
                ];
            }
        }

        $summary = $text;
        if ($summary === '') {
            $summary = $mediaType === 'video'
                ? sprintf('已生成视频 %d 个', count($urls))
                : sprintf('已生成图片 %d 张', count($urls));
        }

        return [
            'parts' => $parts,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, string>
     */
    private static function collectMediaUrls(array $decoded, string $mediaType): array
    {
        $keys = $mediaType === 'video'
            ? ['video', 'video_url', 'videos', 'video_urls', 'output_url', 'download_url', 'url']
            : ['image', 'image_url', 'images', 'image_urls', 'url'];

        $urls = [];
        foreach ($keys as $key) {
            $urls = [...$urls, ...self::normalizeUrlCandidates($decoded[$key] ?? null)];
        }

        $data = is_array($decoded['data'] ?? null) ? ($decoded['data'] ?? []) : [];
        if ($data !== []) {
            foreach ($keys as $key) {
                $urls = [...$urls, ...self::normalizeUrlCandidates($data[$key] ?? null)];
            }
            if (is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach ($keys as $key) {
                        $urls = [...$urls, ...self::normalizeUrlCandidates($item[$key] ?? null)];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeUrlCandidates(mixed $value): array
    {
        if (is_string($value)) {
            $url = trim($value);
            return $url === '' ? [] : [$url];
        }
        if (!is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $item) {
                $result = [...$result, ...self::normalizeUrlCandidates($item)];
            }
            return $result;
        }

        $result = [];
        foreach (['url', 'image_url', 'image', 'video_url', 'video', 'output_url', 'download_url'] as $key) {
            if (array_key_exists($key, $value)) {
                $result = [...$result, ...self::normalizeUrlCandidates($value[$key])];
            }
        }
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $card
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeCard(array $card): array
    {
        foreach ($card as &$item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['buttons']) && is_array($item['buttons']) && array_is_list($item['buttons'])) {
                $item['buttons'] = self::normalizeButtons($item['buttons']);
            }
        }
        unset($item);

        return $card;
    }

    /**
     * @param array<int, mixed> $buttons
     * @return array<int, array{type:string,action?:string,path?:string,url?:string}>
     */
    private static function normalizeButtons(array $buttons): array
    {
        $result = [];
        foreach ($buttons as $button) {
            if (!is_array($button)) {
                continue;
            }

            $type = strtolower(trim((string)($button['type'] ?? '')));
            $action = trim((string)($button['action'] ?? ''));
            $path = trim((string)($button['path'] ?? ''));
            $url = trim((string)($button['url'] ?? ''));

            if (($type === 'path' || ($type === '' && $action === '')) && $path !== '') {
                $normalized = $button;
                $normalized['type'] = 'path';
                $normalized['path'] = $path;
                unset($normalized['action']);
                $result[] = $normalized;
                continue;
            }

            if (($type === 'action' || $type === '') && $action !== '') {
                $normalized = $button;
                $normalized['type'] = 'action';
                $normalized['action'] = $action;
                unset($normalized['path']);
                $result[] = $normalized;
                continue;
            }

            if ($type === 'url' && $url !== '') {
                $normalized = $button;
                $normalized['type'] = 'url';
                $normalized['url'] = $url;
                unset($normalized['path'], $normalized['action']);
                $result[] = $normalized;
            }
        }

        return $result;
    }

    private static function extractFirstJsonObject(string $content): string
    {
        $length = strlen($content);
        $depth = 0;
        $inString = false;
        $escape = false;
        $start = null;

        for ($i = 0; $i < $length; $i++) {
            $ch = $content[$i];
            if ($start === null) {
                if ($ch === '{') {
                    $start = $i;
                    $depth = 1;
                }
                continue;
            }

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }
}
