<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Drivers;

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Contracts\DriverInterface;
use App\Ai\Support\AiRuntime;
use App\System\Service\Config as ConfigService;
use App\System\Service\Storage as StorageService;
use App\System\Service\Upload as UploadService;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Throwable;

final class VolcengineDriver implements DriverInterface
{
    private const BASE_URL = 'https://open.volcengineapi.com/';
    private const ACTION = 'OCRPdf';
    private const VERSION = '2021-08-23';
    private const REGION = 'cn-north-1';
    private const SERVICE = 'cv';
    private const CONTENT_TYPE = 'application/x-www-form-urlencoded; charset=utf-8';
    private const REQUEST_TIMEOUT = 60;

    public static function meta(): array
    {
        return [
            'label' => '火山引擎解析',
            'description' => '使用火山引擎 OCRPdf 接口解析 PDF/图片文档，返回 markdown 或结构化文本。',
            'register_url' => 'https://console.volcengine.com/iam/keymanage/',
            'open_url' => 'https://www.volcengine.com/product/OCR',
            'open_label' => '开通',
            'form_schema' => [
                ['tag' => 'dux-form-item', 'attrs' => ['label' => 'AccessKeyId', 'required' => true], 'children' => [['tag' => 'n-input', 'attrs' => ['v-model:value' => 'config.access_key_id']]]],
                ['tag' => 'dux-form-item', 'attrs' => ['label' => 'SecretAccessKey', 'required' => true], 'children' => [['tag' => 'n-input', 'attrs' => ['v-model:value' => 'config.secret_access_key']]]],
                ['tag' => 'dux-form-item', 'attrs' => ['label' => '安全令牌(STS 可选)'], 'children' => [['tag' => 'n-input', 'attrs' => ['v-model:value' => 'config.security_token']]]],
                ['tag' => 'dux-form-item', 'attrs' => ['label' => '存储驱动', 'tooltip' => '当仅有 filePath 时用于自动转存生成可访问 URL，留空时回退系统默认存储'], 'children' => [[
                    'tag' => 'dux-select',
                    'attrs' => [
                        'v-model:value.number' => 'config.__storage_id',
                        'path' => 'system/storage',
                        'label-field' => 'title',
                        'value-field' => 'id',
                        'placeholder' => '请选择存储驱动',
                    ],
                ]]],
            ],
        ];
    }

    public function parseFile(ParseProvider $provider, string $filePath, string $fileType, array $options = []): string
    {
        $fileUrl = trim((string)($options['file_url'] ?? ''));
        $uploadedPath = '';
        $uploadedStorageId = 0;

        $config = is_array($provider->config ?? null) ? $provider->config : [];
        $accessKeyId = trim((string)($config['access_key_id'] ?? ''));
        $secretAccessKey = trim((string)($config['secret_access_key'] ?? ''));
        if ($accessKeyId === '' || $secretAccessKey === '') {
            throw new ExceptionBusiness('火山文档解析未配置 AccessKeyId 或 SecretAccessKey');
        }

        $securityToken = trim((string)($config['security_token'] ?? ''));
        $logEnabled = (bool)($config['log_enabled'] ?? false);
        $logger = AiRuntime::log('ai.docs');
        $fileName = trim((string)($options['file_name'] ?? ''));
        if ($fileName === '') {
            $fileName = basename($filePath);
        }

        $storageId = 0;
        try {
            if ($fileUrl === '') {
                $storageId = $this->resolveStorageId($provider);
                if ($storageId <= 0) {
                    throw new ExceptionBusiness('火山解析驱动缺少存储驱动配置，请在解析配置中选择存储驱动，或先配置系统默认存储');
                }
                if (!is_file($filePath)) {
                    throw new ExceptionBusiness('火山解析驱动缺少可用的 filePath');
                }
                [$fileUrl, $uploadedPath] = $this->uploadLocalFile($filePath, $fileName, $storageId);
                $uploadedStorageId = $storageId;
            }

            $requestBody = $this->buildBody($fileUrl, $fileType, $options);
            if ($logEnabled) {
                $logger->info('parse.volc.start', [
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'request_file_type' => $requestBody['file_type'] ?? '',
                    'storage_id' => $storageId ?: null,
                    'has_file_url' => $fileUrl !== '',
                    'file_url_host' => (string)(parse_url($fileUrl, PHP_URL_HOST) ?? ''),
                    'timeout' => self::REQUEST_TIMEOUT,
                ]);
            }

            $response = $this->request(
                $this->newClient(),
                $accessKeyId,
                $secretAccessKey,
                $securityToken,
                $requestBody
            );

            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody()->getContents();
            $decoded = $this->decodeJson($responseBody, $statusCode);
            $this->assertSuccess($decoded, $statusCode, $responseBody);

            if ($logEnabled) {
                $logger->info('parse.volc.success', [
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'http_code' => $statusCode,
                    'response_code' => (string)($decoded['code'] ?? ''),
                    'request_id' => $this->extractRequestId($decoded),
                ]);
            }

            return $this->extractContent($decoded);
        } catch (Throwable $e) {
            $logger->error('parse.volc.failed', [
                'file_name' => $fileName,
                'file_type' => $fileType,
                'storage_id' => $storageId ?: null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($uploadedPath !== '' && $uploadedStorageId > 0) {
                $this->cleanupUploadedObject($uploadedStorageId, $uploadedPath);
            }
        }
    }

    private function resolveStorageId(ParseProvider $provider): int
    {
        $storageId = (int)($provider->storage_id ?? 0);
        if ($storageId > 0) {
            return $storageId;
        }

        return (int)ConfigService::getValue('system.storage');
    }

    private function newClient(): Client
    {
        return new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildBody(string $fileUrl, string $fileType, array $options): array
    {
        $startPage = isset($options['start_page']) && is_numeric($options['start_page']) ? max(1, (int)$options['start_page']) : null;
        $endPage = isset($options['end_page']) && is_numeric($options['end_page']) ? max(1, (int)$options['end_page']) : null;

        $body = [
            'image_url' => $fileUrl,
            'version' => 'v3',
            'file_type' => $this->guessFileType($fileUrl, $fileType),
        ];

        if ($startPage !== null) {
            $body['page_start'] = $startPage - 1;
            if ($endPage !== null && $endPage >= $startPage) {
                $body['page_num'] = max(1, $endPage - $startPage + 1);
            }
        }

        return $body;
    }

    private function guessFileType(string $fileUrl, string $fileType): string
    {
        $ext = strtolower((string)pathinfo((string)(parse_url($fileUrl, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'pdf' || strtolower($fileType) === 'pdf') {
            return 'pdf';
        }
        return 'image';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(Client $client, string $accessKeyId, string $secretAccessKey, string $securityToken, array $body): \Psr\Http\Message\ResponseInterface
    {
        $query = [
            'Action' => self::ACTION,
            'Version' => self::VERSION,
        ];
        ksort($query);

        $bodyRaw = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        $timestamp = gmdate('Ymd\\THis\\Z');
        $shortDate = substr($timestamp, 0, 8);

        $urlParts = parse_url(self::BASE_URL);
        $host = (string)($urlParts['host'] ?? '');
        $path = (string)($urlParts['path'] ?? '/');
        if ($host === '') {
            throw new ExceptionBusiness('火山文档解析请求地址无效');
        }

        $payloadHash = hash('sha256', $bodyRaw);
        $signedHeaders = 'content-type;host;x-content-sha256;x-date';
        $canonicalHeaders = implode("\n", [
            'content-type:' . self::CONTENT_TYPE,
            'host:' . $host,
            'x-content-sha256:' . $payloadHash,
            'x-date:' . $timestamp,
        ]);
        $canonicalRequest = implode("\n", [
            'POST',
            $path,
            http_build_query($query),
            $canonicalHeaders,
            '',
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = implode('/', [$shortDate, self::REGION, self::SERVICE, 'request']);
        $stringToSign = implode("\n", [
            'HMAC-SHA256',
            $timestamp,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = $this->buildSignature($shortDate, $secretAccessKey, $stringToSign);

        $headers = [
            'Content-Type' => self::CONTENT_TYPE,
            'Host' => $host,
            'X-Date' => $timestamp,
            'X-Content-Sha256' => $payloadHash,
            'Authorization' => sprintf(
                'HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $accessKeyId,
                $credentialScope,
                $signedHeaders,
                $signature
            ),
        ];
        if ($securityToken !== '') {
            $headers['X-Security-Token'] = $securityToken;
        }

        try {
            return $client->request('POST', self::BASE_URL, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::QUERY => $query,
                RequestOptions::BODY => $bodyRaw,
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('火山文档解析请求失败：' . $e->getMessage());
        }
    }

    private function buildSignature(string $shortDate, string $secretAccessKey, string $stringToSign): string
    {
        $dateKey = hash_hmac('sha256', $shortDate, $secretAccessKey, true);
        $regionKey = hash_hmac('sha256', self::REGION, $dateKey, true);
        $serviceKey = hash_hmac('sha256', self::SERVICE, $regionKey, true);
        $signingKey = hash_hmac('sha256', 'request', $serviceKey, true);
        return hash_hmac('sha256', $stringToSign, $signingKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body, int $statusCode): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ExceptionBusiness(sprintf(
                '火山文档解析返回异常: HTTP %d, %s',
                $statusCode,
                $this->bodySnippet($body)
            ));
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function assertSuccess(array $decoded, int $statusCode, string $body): void
    {
        if ((string)($decoded['code'] ?? '') !== '10000') {
            $message = $this->extractErrorMessage($decoded);
            $parts = ['HTTP ' . $statusCode];

            $code = $this->extractErrorCode($decoded);
            if ($code !== '') {
                $parts[] = 'code ' . $code;
            }

            $requestId = $this->extractRequestId($decoded);
            if ($requestId !== '') {
                $parts[] = 'request_id ' . $requestId;
            }

            $snippet = $this->bodySnippet($body);
            if ($snippet !== '') {
                $parts[] = $snippet;
            }

            $prefix = $message !== '' ? $message : '火山文档解析失败';
            throw new ExceptionBusiness($prefix . '：' . implode(', ', $parts));
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractContent(array $decoded): string
    {
        $markdown = $decoded['data']['markdown'] ?? null;
        if (is_string($markdown) && trim($markdown) !== '') {
            return trim($markdown);
        }

        $content = json_encode($decoded['data'] ?? $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new ExceptionBusiness('火山文档解析结果为空');
        }

        return $content;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uploadLocalFile(string $filePath, string $fileName, int $storageId): array
    {
        if ($fileName === '') {
            $fileName = basename($filePath);
        }
        if ($fileName === '') {
            $fileName = 'document.pdf';
        }

        $pathInfo = UploadService::generatePath($fileName, null, 'docs');
        UploadService::validateFile((string)($pathInfo['ext'] ?? ''), (int)filesize($filePath));

        $object = StorageService::getObject($storageId);
        $resource = fopen($filePath, 'rb');
        if ($resource === false) {
            throw new ExceptionBusiness('火山解析转存失败：无法读取本地文件');
        }

        try {
            $object->writeStream($pathInfo['path'], $resource);
        } catch (\Throwable $e) {
            throw new ExceptionBusiness('火山解析转存失败：' . $e->getMessage());
        } finally {
            fclose($resource);
        }

        $url = (string)$object->publicUrl($pathInfo['path']);
        if ($url === '') {
            throw new ExceptionBusiness('火山解析转存失败：无法生成公网 URL');
        }

        return [$url, (string)$pathInfo['path']];
    }

    private function cleanupUploadedObject(int $storageId, string $path): void
    {
        try {
            StorageService::getObject($storageId)->delete($path);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractRequestId(array $decoded): string
    {
        $requestId = trim((string)($decoded['request_id'] ?? $decoded['requestId'] ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $metadata = $decoded['ResponseMetadata'] ?? null;
        if (is_array($metadata)) {
            return trim((string)($metadata['RequestId'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorMessage(array $decoded): string
    {
        $message = trim((string)($decoded['message'] ?? $decoded['msg'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $metadata = $decoded['ResponseMetadata'] ?? null;
        if (is_array($metadata)) {
            $error = $metadata['Error'] ?? null;
            if (is_array($error)) {
                return trim((string)($error['Message'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractErrorCode(array $decoded): string
    {
        $code = trim((string)($decoded['code'] ?? ''));
        if ($code !== '') {
            return $code;
        }

        $metadata = $decoded['ResponseMetadata'] ?? null;
        if (is_array($metadata)) {
            $error = $metadata['Error'] ?? null;
            if (is_array($error)) {
                return trim((string)($error['Code'] ?? ''));
            }
        }

        return '';
    }

    private function bodySnippet(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return 'empty body';
        }

        if (mb_strlen($body, 'UTF-8') > 400) {
            return mb_substr($body, 0, 400, 'UTF-8') . '...';
        }

        return $body;
    }
}
