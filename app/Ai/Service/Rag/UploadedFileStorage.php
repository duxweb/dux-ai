<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Models\RagKnowledge;
use App\System\Service\Storage as StorageService;
use App\System\Service\Upload as UploadService;
use Core\Handlers\ExceptionBusiness;

final class UploadedFileStorage
{
    /**
     * @return array{url:string,name:string,size:int,type:string,path:string,storage:string}
     */
    public static function store(RagKnowledge $knowledge, ?string $filename, string $contents, ?string $mime): array
    {
        $originalName = $filename && trim($filename) !== '' ? $filename : 'document';
        $size = strlen($contents);

        $extension = UploadFileType::detectExtension($originalName, null, $mime);
        if (!$extension) {
            throw new ExceptionBusiness(sprintf('无法识别文件类型，仅支持：%s', UploadFileType::label()));
        }
        $extension = strtolower($extension);
        if (!UploadFileType::isAllowed($extension)) {
            throw new ExceptionBusiness(sprintf('不支持的文件类型，仅支持：%s', UploadFileType::label()));
        }

        $uploadConfig = UploadService::getUploadConfig();
        $maxSize = (int)($uploadConfig['upload_size'] ?? 0) * 1024 * 1024;
        if ($maxSize > 0 && $size > $maxSize) {
            throw new ExceptionBusiness(sprintf('文件大小不得超过 %dMB', (int)$uploadConfig['upload_size']));
        }

        $storage = $knowledge->config?->storage;
        if (!$storage || !$storage->name) {
            throw new ExceptionBusiness('请先在知识库配置中设置存储驱动');
        }

        $pathInfo = UploadService::generatePath($originalName, $mime, 'ai/rag');
        $object = StorageService::getObject($storage->name);

        $resource = fopen('php://temp', 'wb+');
        fwrite($resource, $contents);
        rewind($resource);

        try {
            $object->writeStream($pathInfo['path'], $resource);
        } finally {
            fclose($resource);
        }

        return [
            'url' => $object->publicUrl($pathInfo['path']),
            'name' => $originalName,
            'size' => $size,
            'type' => strtoupper($pathInfo['ext'] ?: $extension),
            'path' => $pathInfo['path'],
            'storage' => $storage->name,
        ];
    }
}

