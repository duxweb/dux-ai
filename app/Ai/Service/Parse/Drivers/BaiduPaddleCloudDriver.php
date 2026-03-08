<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Drivers;

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Contracts\DriverInterface;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Throwable;

class BaiduPaddleCloudDriver implements DriverInterface
{
    /**
     * @var array<string, string>
     */
    private const MIME_MAP = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'gif' => 'image/gif',
    ];

    public static function meta(): array
    {
        return [
            'label' => '百度 Paddle 云解析',
            'description' => '仅需配置 API_URL 与 TOKEN，内部自动按 Base64 方式组装请求。',
            'register_url' => 'https://aistudio.baidu.com/paddleocr',
            'form_schema' => [
                ['tag' => 'dux-form-item', 'attrs' => ['label' => 'API_URL', 'required' => true], 'children' => [[
                    'tag' => 'n-input',
                    'attrs' => [
                        'v-model:value' => 'config.api_url',
                        'placeholder' => '请输入完整 API 地址',
                    ],
                ]]],
                ['tag' => 'dux-form-item', 'attrs' => ['label' => 'TOKEN', 'required' => true], 'children' => [[
                    'tag' => 'n-input',
                    'attrs' => [
                        'v-model:value' => 'config.token',
                        'type' => 'password',
                        'show-password-on' => 'mousedown',
                        'placeholder' => '请输入鉴权 Token',
                    ],
                ]]],
            ],
        ];
    }

    public function parseFile(ParseProvider $provider, string $filePath, string $fileType, array $options = []): string
    {
        if (!is_file($filePath)) {
            throw new ExceptionBusiness('待解析文件不存在');
        }

        $type = strtolower(trim($fileType));
        $mime = self::MIME_MAP[$type] ?? null;
        if ($mime === null) {
            throw new ExceptionBusiness('百度 Paddle 云解析仅支持 PDF/图片');
        }

        $config = is_array($provider->config ?? null) ? $provider->config : [];
        $logEnabled = (bool)($config['log_enabled'] ?? false);
        $logger = AiRuntime::log('ai.docs');

        $apiUrl = trim((string)($config['api_url'] ?? ''));
        if ($apiUrl === '') {
            throw new ExceptionBusiness('百度 Paddle 驱动缺少 API_URL 配置');
        }

        $token = trim((string)($config['token'] ?? ''));
        if ($token === '') {
            throw new ExceptionBusiness('百度 Paddle 驱动缺少 TOKEN 配置');
        }

        $timeout = is_numeric($config['timeout'] ?? null)
            ? max(3, (int)$config['timeout'])
            : 60;
        $headers = [
            'Authorization' => $this->buildAuthorizationValue($token),
        ];
        $payload = [
            'file' => $this->buildBase64($filePath),
            'fileType' => $type === 'pdf' ? 0 : 1,
            'useDocOrientationClassify' => false,
            'useDocUnwarping' => false,
            'useChartRecognition' => false,
        ];

        if ($logEnabled) {
            $logger->info('parse.baidu.start', [
                'file_name' => basename($filePath),
                'file_type' => $type,
                'api_url' => $apiUrl,
                'timeout' => $timeout,
                'request_mode' => 'base64',
                'extract_mode' => 'auto',
            ]);
        }

        $startedAt = microtime(true);
        try {
            [$status, $body] = $this->sendRequest($apiUrl, $timeout, $headers, $payload);
            if ($status >= 400) {
                throw new ExceptionBusiness(sprintf(
                    '百度 Paddle 请求失败: HTTP %d, %s',
                    $status,
                    $this->bodySnippet($body)
                ));
            }

            $decoded = $this->decodeJsonBody($body);
            $text = $this->extractText($decoded);
        } catch (Throwable $e) {
            $logger->error('parse.baidu.failed', [
                'file_name' => basename($filePath),
                'file_type' => $type,
                'api_url' => $apiUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $durationMs = (int)((microtime(true) - $startedAt) * 1000);
        if ($logEnabled) {
            $this->logParsedContent($filePath, $text, $durationMs);
            $logger->info('parse.baidu.success', [
                'file_name' => basename($filePath),
                'file_type' => $type,
                'api_url' => $apiUrl,
                'duration_ms' => $durationMs,
                'content_length' => strlen($text),
                'http_code' => 200,
            ]);
        }

        return $text;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: string}
     */
    protected function sendRequest(string $apiUrl, int $timeout, array $headers, array $payload): array
    {
        $client = new Client([
            'http_errors' => false,
            'timeout' => $timeout,
        ]);

        $requestHeaders = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        try {
            $response = $client->request('POST', $apiUrl, [
                RequestOptions::HEADERS => $requestHeaders,
                RequestOptions::JSON => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('百度 Paddle 请求异常: ' . $e->getMessage());
        }

        return [
            $response->getStatusCode(),
            (string)$response->getBody()->getContents(),
        ];
    }

    private function buildAuthorizationValue(string $token): string
    {
        if (str_starts_with(strtolower($token), 'token ')) {
            return $token;
        }
        return 'token ' . $token;
    }

    private function buildBase64(string $filePath): string
    {
        $content = @file_get_contents($filePath);
        if (!is_string($content) || $content === '') {
            throw new ExceptionBusiness('读取待解析文件失败');
        }

        return base64_encode($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ExceptionBusiness('百度 Paddle 返回非 JSON 内容: ' . $this->bodySnippet($body));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractText(array $decoded): string
    {
        $layoutResults = $this->getByPath($decoded, 'result.layoutParsingResults');
        if (is_array($layoutResults)) {
            $parts = [];
            foreach ($layoutResults as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $markdown = $item['markdown'] ?? null;
                if (is_string($markdown) && trim($markdown) !== '') {
                    $parts[] = trim($markdown);
                    continue;
                }
                if (is_array($markdown) && is_string($markdown['text'] ?? null) && trim((string)$markdown['text']) !== '') {
                    $parts[] = trim((string)$markdown['text']);
                }
            }
            $joined = trim(implode("\n\n", $parts));
            if ($joined !== '') {
                return $joined;
            }
        }

        foreach (['result.markdown', 'result.text', 'data.markdown', 'data.text', 'text'] as $path) {
            $value = $this->getByPath($decoded, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach (['result.pages', 'data.pages', 'pages'] as $path) {
            $pages = $this->getByPath($decoded, $path);
            if (!is_array($pages)) {
                continue;
            }

            $items = [];
            foreach ($pages as $page) {
                if (is_array($page) && is_string($page['text'] ?? null) && trim((string)$page['text']) !== '') {
                    $items[] = trim((string)$page['text']);
                }
            }

            $text = trim(implode("\n\n", $items));
            if ($text !== '') {
                return $text;
            }
        }

        throw new ExceptionBusiness('百度 Paddle 解析结果为空或无法识别响应结构');
    }

    private function getByPath(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    private function bodySnippet(string $body): string
    {
        $text = trim($body);
        if ($text === '') {
            return '[empty]';
        }
        return mb_substr($text, 0, 200, 'UTF-8');
    }

    private function logParsedContent(string $filePath, string $text, int $durationMs): void
    {
        $dumpPath = $this->writeDebugDump($text, 'baidu_paddle_text_');

        AiRuntime::log('ai.docs')->info('parse.baidu.parsed', [
            'file_name' => basename($filePath),
            'chars' => mb_strlen($text, 'UTF-8'),
            'bytes' => strlen($text),
            'line_count' => substr_count($text, "\n") + 1,
            'duration_ms' => $durationMs,
            'dump_path' => $dumpPath,
            'preview_head' => mb_substr($text, 0, 500, 'UTF-8'),
            'preview_tail' => mb_substr($text, max(0, mb_strlen($text, 'UTF-8') - 500), 500, 'UTF-8'),
        ]);
    }

    private function writeDebugDump(string $content, string $prefix): string
    {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'duxai';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $prefix . date('Ymd_His') . '_' . uniqid('', true) . '.txt';
        @file_put_contents($path, $content);

        return $path;
    }
}
