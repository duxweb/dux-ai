<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Service\FileDataLoader;
use App\System\Service\Storage as StorageService;
use App\System\Service\Upload as UploadService;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final class FileUploader
{
    /**
     * @return array{filename:string,size:int,mime:string,url:string,content:?string,media_kind:string,mode_hint:string,parse_mode:string,parsed_text:?string,parsed_parts_count:int,provider_file_id:?string,provider:?string,ingestion_mode:?string,upload_channel:?string}
     */
    public function upload(AiAgent $agent, UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ExceptionBusiness('文件上传失败');
        }

        $originalName = $file->getClientFilename() ?: 'file';
        $mime = $file->getClientMediaType() ?: 'application/octet-stream';
        $size = (int)($file->getSize() ?? 0);
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $mediaKind = AttachmentConfig::mediaKind($mime, $originalName);

        UploadService::validateFile($extension ?: 'bin', $size);

        $agent->loadMissing('model.provider');
        $model = $agent->model;
        if (!$model) {
            throw new ExceptionBusiness('智能体未绑定模型');
        }
        $attachments = AttachmentConfig::normalizeFromModel($model);
        $enabled = AttachmentConfig::isEnabled($attachments, $mediaKind);
        $shouldParse = $this->shouldParseAttachment($attachments, $mediaKind);
        if (!$enabled && !$shouldParse) {
            throw new ExceptionBusiness(sprintf('当前模型未开启 %s 附件上传', $this->mediaKindLabel($mediaKind)));
        }
        $modeHint = AttachmentConfig::modeFor($attachments, $mediaKind);

        $pathInfo = UploadService::generatePath($originalName, $mime, 'ai/upload');
        $storageName = AttachmentConfig::localStorageName($attachments);
        $object = StorageService::getObject($storageName);

        try {
            $stream = $file->getStream();
            $contents = (string)$stream;
        } catch (Throwable $e) {
            throw new ExceptionBusiness('读取上传文件失败：' . $e->getMessage());
        }

        $resource = fopen('php://temp', 'wb+');
        if (!is_resource($resource)) {
            throw new ExceptionBusiness('创建上传缓冲区失败');
        }
        fwrite($resource, $contents);
        rewind($resource);

        try {
            try {
                $object->writeStream($pathInfo['path'], $resource);
                $url = $object->publicUrl($pathInfo['path']);
            } catch (Throwable $e) {
                throw new ExceptionBusiness('写入存储失败：' . $e->getMessage(), 0, $e);
            }
        } finally {
            fclose($resource);
        }
        $parseMode = 'passthrough';
        $parsedText = null;
        if ($shouldParse) {
            $parsedText = $this->parseAttachmentText($attachments, $extension, $mime, $contents);
            $parseMode = 'parsed';
        }

        $parsedPartsCount = $parsedText !== null ? 1 : 0;
        $parsedContent = $parsedText ?? $this->tryParseTextContent($mime, $contents);

        return [
            'filename' => $originalName,
            'size' => $size,
            'mime' => $mime,
            'url' => $url,
            'content' => $parsedContent,
            'media_kind' => $mediaKind,
            'mode_hint' => $modeHint,
            'parse_mode' => $parseMode,
            'parsed_text' => $parsedText,
            'parsed_parts_count' => $parsedPartsCount,
            // 兼容字段：过渡一版后可移除
            'provider_file_id' => null,
            'provider' => null,
            'ingestion_mode' => null,
            'upload_channel' => null,
        ];
    }

    private function shouldParseAttachment(array $attachments, string $mediaKind): bool
    {
        $enabled = AttachmentConfig::isEnabled($attachments, $mediaKind);
        $localParseEnabled = AttachmentConfig::localParseEnabled($attachments, $mediaKind);

        if ($enabled) {
            return false;
        }

        if (!$localParseEnabled) {
            return false;
        }

        if ($mediaKind === 'audio' || $mediaKind === 'video') {
            throw new ExceptionBusiness(sprintf('当前模型的 %s 附件不支持本地解析，请改为模型支持或关闭', $this->mediaKindLabel($mediaKind)));
        }

        return true;
    }

    private function parseAttachmentText(array $attachments, string $extension, string $mime, string $contents): string
    {
        $text = $this->tryParseTextContent($mime, $contents);
        if ($text !== null && trim($text) !== '') {
            return trim($text);
        }

        $providerId = AttachmentConfig::parseProviderId($attachments);
        $tmpFile = $this->writeTempFile($contents, $extension);
        $context = [];
        if ($providerId) {
            $context['parse_provider'] = $providerId;
        }
        try {
            $text = trim(FileDataLoader::content($tmpFile, $context));
        } catch (Throwable $e) {
            throw new ExceptionBusiness('本地解析失败：' . $e->getMessage(), 0, $e);
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        if ($text === '') {
            throw new ExceptionBusiness('附件解析结果为空');
        }

        return $text;
    }

    private function writeTempFile(string $contents, string $extension): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'agent_parse_');
        if ($tmpPath === false) {
            throw new ExceptionBusiness('创建临时文件失败');
        }

        $suffix = strtolower(trim($extension));
        if ($suffix !== '') {
            $targetPath = $tmpPath . '.' . $suffix;
            @rename($tmpPath, $targetPath);
            $tmpPath = $targetPath;
        }

        file_put_contents($tmpPath, $contents);
        return $tmpPath;
    }

    private function tryParseTextContent(string $mime, string $contents): ?string
    {
        if (!$this->isTextLikeMime($mime)) {
            return null;
        }
        $mime = strtolower($mime);
        if ($mime === 'application/json') {
            return json_validate($contents) ? $contents : null;
        }
        return $contents;
    }

    private function isTextLikeMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        return in_array($mime, [
            'text/plain',
            'text/markdown',
            'text/csv',
            'text/html',
            'application/json',
            'application/xml',
            'application/x-yaml',
        ], true);
    }

    private function mediaKindLabel(string $kind): string
    {
        return match ($kind) {
            'image' => '图片',
            'audio' => '音频',
            'video' => '视频',
            default => '文件',
        };
    }
}
