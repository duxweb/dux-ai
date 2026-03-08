<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

final class UploadFileType
{
    private const ALLOWED_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'md',
        'txt',
        'png',
        'jpg',
        'jpeg',
        'csv',
    ];

    private const MIME_EXTENSION_MAP = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/markdown' => 'md',
        'text/plain' => 'txt',
        'image/png' => 'png',
        'image/jpg' => 'jpg',
        'image/jpeg' => 'jpeg',
        'image/pjpeg' => 'jpeg',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
    ];

    public static function label(): string
    {
        return implode('/', self::ALLOWED_EXTENSIONS);
    }

    public static function isAllowed(?string $extension): bool
    {
        if (!$extension) {
            return false;
        }
        return in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true);
    }

    public static function detectExtension(?string $name, ?string $url, ?string $mime): ?string
    {
        $candidates = [];
        if ($name) {
            $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '') {
                $candidates[] = $ext;
            }
        }

        if ($url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $ext = strtolower((string)pathinfo((string)$path, PATHINFO_EXTENSION));
            if ($ext !== '') {
                $candidates[] = $ext;
            }
        }

        if ($mime) {
            $mime = strtolower(trim($mime));
            if (isset(self::MIME_EXTENSION_MAP[$mime])) {
                $candidates[] = self::MIME_EXTENSION_MAP[$mime];
            } elseif (str_contains($mime, '/')) {
                $parts = explode('/', $mime);
                $candidate = end($parts) ?: null;
                if ($candidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
