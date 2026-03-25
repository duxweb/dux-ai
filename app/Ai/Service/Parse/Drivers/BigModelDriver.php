<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Drivers;

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Contracts\DriverInterface;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

final class BigModelDriver implements DriverInterface
{
    private const DEFAULT_BASE_URL = 'https://open.bigmodel.cn/api/paas/v4';
    private const TIMEOUT_SECONDS = 60;

    public static function meta(): array
    {
        return [
            'label' => 'BigModel 解析',
            'description' => '使用 BigModel 文件解析同步接口，支持 PDF 与图片等文件提取文本。',
            'register_url' => 'https://open.bigmodel.cn/',
            'form_schema' => [
                ['tag' => 'dux-form-item', 'attrs' => ['label' => 'API Key', 'required' => true], 'children' => [['tag' => 'n-input', 'attrs' => ['v-model:value' => 'config.api_key']]]],
            ],
        ];
    }

    public function parseFile(ParseProvider $provider, string $filePath, string $fileType, array $options = []): string
    {
        if (!is_file($filePath)) {
            throw new ExceptionBusiness('待解析文件不存在');
        }

        $config = is_array($provider->config ?? null) ? $provider->config : [];
        $apiKey = trim((string)($config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new ExceptionBusiness('BigModel 文档解析未配置 API Key');
        }
        $baseUrl = trim((string)($config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        $fileName = $this->resolveFileName($filePath, $fileType, $options);
        $normalizedFileType = $this->normalizeFileType($fileType, $fileName);

        $client = new Client([
            'timeout' => (int)($config['timeout'] ?? self::TIMEOUT_SECONDS),
            'http_errors' => false,
        ]);

        $file = fopen($filePath, 'rb');
        if (!is_resource($file)) {
            throw new ExceptionBusiness('读取待上传文件失败');
        }

        try {
            $response = $client->post($baseUrl . '/files/parser/sync', [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
                RequestOptions::MULTIPART => [
                    ['name' => 'tool_type', 'contents' => 'prime-sync'],
                    ['name' => 'file_type', 'contents' => $normalizedFileType],
                    ['name' => 'file', 'contents' => $file, 'filename' => $fileName],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('BigModel 文件解析请求失败：' . $e->getMessage());
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }

        $payload = $this->decodeJson((string)$response->getBody()->getContents(), 'BigModel 文件解析返回异常');
        $statusCode = (int)$response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = trim((string)($payload['error']['message'] ?? $payload['msg'] ?? $payload['message'] ?? '文件解析失败'));
            throw new ExceptionBusiness('BigModel 文件解析失败：' . $message);
        }

        $status = trim((string)($payload['status'] ?? ''));
        $message = trim((string)($payload['message'] ?? $payload['msg'] ?? ''));
        if ($status !== '' && $status !== 'succeeded') {
            throw new ExceptionBusiness('BigModel 文件解析失败：' . ($message ?: $status));
        }

        $text = $this->extractText($payload);
        if ($text === '') {
            $downloadUrl = trim((string)($payload['parsing_result_url'] ?? ''));
            if ($downloadUrl !== '') {
                throw new ExceptionBusiness('BigModel 文件解析未返回文本内容，结果下载地址：' . $downloadUrl);
            }
            throw new ExceptionBusiness('BigModel 文件解析结果为空：' . ($message ?: '未返回 content'));
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveFileName(string $filePath, string $fileType, array $options): string
    {
        $name = trim((string)($options['file_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = basename($filePath);
        if ($name !== '') {
            return $name;
        }

        return 'document.' . strtolower($fileType);
    }

    private function normalizeFileType(string $fileType, string $fileName): string
    {
        $type = strtolower(trim($fileType));
        if ($type !== '') {
            return strtoupper($type);
        }
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $ext = strtolower(trim((string)$ext));
        return $ext !== '' ? strtoupper($ext) : 'PDF';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $contents, string $errorMessage): array
    {
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new ExceptionBusiness($errorMessage);
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractText(array $payload): string
    {
        $paths = [
            ['data', 'content'],
            ['data', 'text'],
            ['data', 'markdown'],
            ['content'],
            ['text'],
            ['markdown'],
        ];
        foreach ($paths as $path) {
            $value = $this->readByPath($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    /**
     * @param array<int, string> $path
     */
    private function readByPath(array $payload, array $path): mixed
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}
