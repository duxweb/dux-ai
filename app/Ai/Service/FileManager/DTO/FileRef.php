<?php

declare(strict_types=1);

namespace App\Ai\Service\FileManager\DTO;

final class FileRef
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $fileId,
        public readonly string $provider,
        public readonly ?string $filename = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $bytes = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'provider' => $this->provider,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
            'bytes' => $this->bytes,
            'raw' => $this->raw,
        ];
    }
}
