<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Drivers;

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Contracts\DriverInterface;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

final class MoonshotDriver implements DriverInterface
{
    private const DEFAULT_BASE_URL = 'https://api.moonshot.cn/v1';
    private const TIMEOUT_SECONDS = 10;
    private const POLL_ATTEMPTS = 30;
    private const POLL_INTERVAL_USEC = 1_000_000;

    public static function meta(): array
    {
        return [
            'label' => 'Moonshot 解析',
            'description' => '使用 Moonshot 文件解析能力，适用于云端文档抽取与结构化处理。',
            'register_url' => 'https://platform.moonshot.cn/',
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
            throw new ExceptionBusiness('Moonshot 文档解析未配置 API Key');
        }
        $baseUrl = self::DEFAULT_BASE_URL;

        $fileName = $this->resolveFileName($filePath, $fileType, $options);
        $client = new Client(['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]);

        $fileId = '';
        try {
            $upload = $this->uploadFile($client, $baseUrl, $apiKey, $filePath, $fileName);
            $fileId = trim((string)($upload['id'] ?? ''));
            if ($fileId === '') {
                throw new ExceptionBusiness('Moonshot 上传文件失败：缺少文件 ID');
            }

            $status = $this->pollUntilReady($client, $baseUrl, $apiKey, $fileId);
            if (trim((string)($status['status'] ?? '')) !== 'ok') {
                $message = trim((string)($status['status_details'] ?? '文档解析失败'));
                throw new ExceptionBusiness('Moonshot 文档解析失败：' . ($message ?: '未知错误'));
            }

            return $this->extractText($this->fetchContent($client, $baseUrl, $apiKey, $fileId));
        } finally {
            if ($fileId !== '') {
                $this->deleteRemoteFile($client, $baseUrl, $apiKey, $fileId);
            }
        }
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

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $apiKey): array
    {
        return ['Authorization' => 'Bearer ' . $apiKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadFile(Client $client, string $baseUrl, string $apiKey, string $filePath, string $fileName): array
    {
        $file = fopen($filePath, 'rb');
        if (!is_resource($file)) {
            throw new ExceptionBusiness('读取待上传文件失败');
        }

        try {
            $response = $client->post($baseUrl . '/files', [
                RequestOptions::HEADERS => $this->authHeaders($apiKey),
                RequestOptions::MULTIPART => [
                    ['name' => 'purpose', 'contents' => 'file-extract'],
                    ['name' => 'file', 'contents' => $file, 'filename' => $fileName],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('Moonshot 文件上传请求失败：' . $e->getMessage());
        } finally {
            fclose($file);
        }

        return $this->decodeJson((string)$response->getBody()->getContents(), 'Moonshot 上传返回异常');
    }

    /**
     * @return array<string, mixed>
     */
    private function pollUntilReady(Client $client, string $baseUrl, string $apiKey, string $fileId): array
    {
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            try {
                $response = $client->get(sprintf('%s/files/%s', $baseUrl, $fileId), [
                    RequestOptions::HEADERS => $this->authHeaders($apiKey),
                ]);
            } catch (GuzzleException $e) {
                throw new ExceptionBusiness('Moonshot 请求失败：' . $e->getMessage());
            }

            $payload = $this->decodeJson((string)$response->getBody()->getContents(), 'Moonshot 请求返回异常');
            $status = trim((string)($payload['status'] ?? ''));
            if (in_array($status, ['ok', 'error'], true)) {
                return $payload;
            }

            usleep(self::POLL_INTERVAL_USEC);
        }

        throw new ExceptionBusiness('Moonshot 文档解析超时');
    }

    private function fetchContent(Client $client, string $baseUrl, string $apiKey, string $fileId): string
    {
        try {
            $response = $client->get(sprintf('%s/files/%s/content', $baseUrl, $fileId), [
                RequestOptions::HEADERS => $this->authHeaders($apiKey),
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('Moonshot 文件内容获取失败：' . $e->getMessage());
        }

        return (string)$response->getBody()->getContents();
    }

    private function deleteRemoteFile(Client $client, string $baseUrl, string $apiKey, string $fileId): void
    {
        try {
            $client->delete(sprintf('%s/files/%s', $baseUrl, $fileId), [
                RequestOptions::HEADERS => $this->authHeaders($apiKey),
            ]);
        } catch (GuzzleException) {
        }
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

    private function extractText(string $content): string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $text = trim($content);
            if ($text === '') {
                throw new ExceptionBusiness('Moonshot 文档解析结果为空');
            }
            return $text;
        }

        $items = isset($decoded['data']) && is_array($decoded['data']) ? array_values($decoded['data']) : (array_is_list($decoded) ? $decoded : [$decoded]);
        $parts = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $text = trim($item);
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            foreach (['content', 'text', 'value'] as $key) {
                if (isset($item[$key]) && is_string($item[$key])) {
                    $text = trim($item[$key]);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                    break;
                }
            }
        }

        $result = trim(implode("\n\n", $parts));
        if ($result === '') {
            throw new ExceptionBusiness('Moonshot 文档解析结果为空');
        }

        return $result;
    }
}
