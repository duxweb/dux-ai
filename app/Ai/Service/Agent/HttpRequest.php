<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class HttpRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function jsonBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $raw = (string)$request->getBody();
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function extractUploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        if (isset($files['file']) && $files['file'] instanceof UploadedFileInterface) {
            return $files['file'];
        }

        foreach ($files as $file) {
            if ($file instanceof UploadedFileInterface) {
                return $file;
            }
            if (is_array($file)) {
                foreach ($file as $inner) {
                    if ($inner instanceof UploadedFileInterface) {
                        return $inner;
                    }
                }
            }
        }

        return null;
    }
}

