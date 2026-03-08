<?php

declare(strict_types=1);

namespace App\Ai\Service\FileManager\Providers;

use App\Ai\Service\FileManager\DTO\FileRef;

final class ArkFileManagerProvider extends OpenAILikeFileManagerProvider
{
    /**
     * @param array<string, mixed> $meta
     */
    public function upload(string $filePath, array $meta = []): FileRef
    {
        if (!array_key_exists('purpose', $meta)) {
            $meta['purpose'] = 'user_data';
        }

        $ref = parent::upload($filePath, $meta);

        return new FileRef(
            fileId: $ref->fileId,
            provider: 'ark',
            filename: $ref->filename,
            mimeType: $ref->mimeType,
            bytes: $ref->bytes,
            raw: $ref->raw,
        );
    }
}
