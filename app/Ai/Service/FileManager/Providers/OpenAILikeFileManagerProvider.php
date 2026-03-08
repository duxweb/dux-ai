<?php

declare(strict_types=1);

namespace App\Ai\Service\FileManager\Providers;

use App\Ai\Service\FileManager\Contracts\FileManagerProviderInterface;
use App\Ai\Service\FileManager\DTO\FileRef;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

class OpenAILikeFileManagerProvider extends BaseHttpFileManagerProvider implements FileManagerProviderInterface
{
    /**
     * @param array<string, mixed> $meta
     */
    public function upload(string $filePath, array $meta = []): FileRef
    {
        if (!is_file($filePath)) {
            throw new ExceptionBusiness('待上传文件不存在');
        }

        $purpose = trim((string)($meta['purpose'] ?? $this->options['purpose'] ?? 'assistants'));
        if ($purpose === '') {
            $purpose = 'assistants';
        }

        $filename = trim((string)($meta['filename'] ?? basename($filePath)));
        if ($filename === '') {
            $filename = basename($filePath);
        }

        $file = fopen($filePath, 'rb');
        if (!is_resource($file)) {
            throw new ExceptionBusiness('读取文件失败');
        }

        try {
            $response = $this->client->post('files', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                RequestOptions::MULTIPART => [
                    [
                        'name' => 'purpose',
                        'contents' => $purpose,
                    ],
                    [
                        'name' => 'file',
                        'contents' => $file,
                        'filename' => $filename,
                        'headers' => [
                            'Content-Type' => (string)($meta['mime_type'] ?? 'application/octet-stream'),
                        ],
                    ],
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->rethrow($e, '上传文件失败');
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }

        $payload = $this->decodeJson($response, '上传文件失败：返回内容无效');
        $this->assertHttpOk($response, $payload, '上传文件失败');

        $fileId = trim((string)($payload['id'] ?? ''));
        if ($fileId === '') {
            throw new ExceptionBusiness('上传文件失败：未返回文件 ID');
        }

        $bytes = null;
        $rawBytes = $payload['bytes'] ?? $payload['size'] ?? $meta['bytes'] ?? null;
        if (is_numeric($rawBytes)) {
            $bytes = (int)$rawBytes;
        }

        $mimeType = isset($payload['mime_type'])
            ? (string)$payload['mime_type']
            : (isset($meta['mime_type']) ? (string)$meta['mime_type'] : null);

        return new FileRef(
            fileId: $fileId,
            provider: 'openai_like',
            filename: isset($payload['filename']) ? (string)$payload['filename'] : $filename,
            mimeType: $mimeType,
            bytes: $bytes,
            raw: $payload,
        );
    }

    public function delete(string $fileId): void
    {
        $fileId = trim($fileId);
        if ($fileId === '') {
            return;
        }

        try {
            $response = $this->client->delete('files/' . rawurlencode($fileId), [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->rethrow($e, '删除文件失败');
        }

        $payload = $this->decodeJson($response, '删除文件失败：返回内容无效');
        $this->assertHttpOk($response, $payload, '删除文件失败');
    }
}
