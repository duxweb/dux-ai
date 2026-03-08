<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiModel;
use Throwable;

final class AttachmentConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function normalizeFromModel(?AiModel $model): array
    {
        $options = is_array($model?->options ?? null) ? ($model?->options ?? []) : [];
        return self::normalize($options['attachments'] ?? []);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function normalize(mixed $value): array
    {
        $attachments = is_array($value) ? $value : [];
        $enabled = is_array($attachments['enabled'] ?? null) ? ($attachments['enabled'] ?? []) : [];
        $mode = is_array($attachments['mode'] ?? null) ? ($attachments['mode'] ?? []) : [];
        $localParse = is_array($attachments['local_parse'] ?? null) ? ($attachments['local_parse'] ?? []) : [];
        $parse = is_array($attachments['parse'] ?? null) ? ($attachments['parse'] ?? []) : [];

        $localStorageName = trim((string)($attachments['local_storage_name'] ?? ''));

        return [
            'enabled' => [
                'image' => self::toBool($enabled['image'] ?? false),
                'file' => self::toBool($enabled['file'] ?? false),
                'audio' => self::toBool($enabled['audio'] ?? false),
                'video' => self::toBool($enabled['video'] ?? false),
            ],
            'mode' => [
                'image' => self::normalizeImageMode((string)($mode['image'] ?? 'auto')),
                'file' => self::normalizeDocumentMode((string)($mode['file'] ?? 'auto')),
                'audio' => self::normalizeDocumentMode((string)($mode['audio'] ?? 'auto')),
                'video' => self::normalizeDocumentMode((string)($mode['video'] ?? 'auto')),
            ],
            'local_parse' => [
                'image' => self::toBool($localParse['image'] ?? true),
                'file' => self::toBool($localParse['file'] ?? true),
                'audio' => self::toBool($localParse['audio'] ?? false),
                'video' => self::toBool($localParse['video'] ?? false),
            ],
            'local_storage_name' => $localStorageName,
            'parse' => [
                'parse_provider_id' => self::toNullableInt($parse['parse_provider_id'] ?? null),
            ],
        ];
    }

    public static function isEnabled(array $attachments, string $kind): bool
    {
        $enabled = is_array($attachments['enabled'] ?? null) ? ($attachments['enabled'] ?? []) : [];
        return self::toBool($enabled[$kind] ?? false);
    }

    /**
     * @return array{parse_provider_id:?int}
     */
    public static function parseConfig(array $attachments): array
    {
        $parse = is_array($attachments['parse'] ?? null) ? ($attachments['parse'] ?? []) : [];
        return [
            'parse_provider_id' => self::toNullableInt($parse['parse_provider_id'] ?? null),
        ];
    }

    public static function parseProviderId(array $attachments): ?int
    {
        return self::parseConfig($attachments)['parse_provider_id'];
    }

    public static function localStorageName(array $attachments): ?string
    {
        $value = trim((string)($attachments['local_storage_name'] ?? ''));
        return $value === '' ? null : $value;
    }

    public static function modeFor(array $attachments, string $kind): string
    {
        $mode = is_array($attachments['mode'] ?? null) ? ($attachments['mode'] ?? []) : [];
        if ($kind === 'image') {
            return self::normalizeImageMode((string)($mode['image'] ?? 'auto'));
        }

        return self::normalizeDocumentMode((string)($mode[$kind] ?? 'auto'));
    }

    public static function supportsImage(array $attachments): bool
    {
        return self::isEnabled($attachments, 'image');
    }

    public static function supportsFile(array $attachments): bool
    {
        return self::isEnabled($attachments, 'file')
            || self::isEnabled($attachments, 'audio')
            || self::isEnabled($attachments, 'video');
    }

    public static function localParseEnabled(array $attachments, string $kind): bool
    {
        $localParse = is_array($attachments['local_parse'] ?? null) ? ($attachments['local_parse'] ?? []) : [];
        return self::toBool($localParse[$kind] ?? false);
    }

    public static function mediaKind(string $mimeType, string $fileName = ''): string
    {
        $mimeType = strtolower(trim($mimeType));
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif'], true)) {
            return 'image';
        }
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], true)) {
            return 'audio';
        }
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true)) {
            return 'video';
        }

        return 'file';
    }

    /**
     * 一次性迁移历史 chat 模型附件配置，补齐 attachments 默认结构。
     */
    public static function migrateModelAttachments(): int
    {
        $updated = 0;
        try {
            AiModel::query()
                ->where('type', AiModel::TYPE_CHAT)
                ->orderBy('id')
                ->chunkById(100, function ($items) use (&$updated) {
                    foreach ($items as $model) {
                        if (!$model instanceof AiModel) {
                            continue;
                        }
                        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
                        $normalized = self::normalize($options['attachments'] ?? []);
                        if (is_array($options['attachments'] ?? null) && $options['attachments'] === $normalized) {
                            continue;
                        }
                        $options['attachments'] = $normalized;
                        $model->options = $options;
                        $model->save();
                        $updated++;
                    }
                }, 'id');
        } catch (Throwable) {
            return $updated;
        }

        return $updated;
    }

    private static function normalizeImageMode(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['auto', 'url', 'base64'], true) ? $value : 'auto';
    }

    private static function normalizeDocumentMode(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['auto', 'base64'], true) ? $value : 'auto';
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return false;
    }
}
