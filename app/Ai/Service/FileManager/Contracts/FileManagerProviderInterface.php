<?php

declare(strict_types=1);

namespace App\Ai\Service\FileManager\Contracts;

use App\Ai\Service\FileManager\DTO\FileRef;

interface FileManagerProviderInterface
{
    /**
     * @param array<string, mixed> $meta
     */
    public function upload(string $filePath, array $meta = []): FileRef;

    public function delete(string $fileId): void;
}
