<?php

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Drivers\BaiduPaddleCloudDriver;
use Core\Handlers\ExceptionBusiness;

it('BaiduPaddleCloudDriver：API_URL 为空时报错', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'baidu_parse_');
    expect($tmp)->not->toBeFalse();
    @file_put_contents((string)$tmp, 'test');

    $provider = new ParseProvider();
    $provider->provider = 'baidu_paddle_cloud';
    $provider->config = [];

    try {
        expect(fn () => (new BaiduPaddleCloudDriver())->parseFile($provider, (string)$tmp, 'jpg'))
            ->toThrow(ExceptionBusiness::class, '缺少 API_URL');
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});

it('BaiduPaddleCloudDriver：TOKEN 为空时报错', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'baidu_parse_');
    expect($tmp)->not->toBeFalse();
    @file_put_contents((string)$tmp, 'test');

    $provider = new ParseProvider();
    $provider->provider = 'baidu_paddle_cloud';
    $provider->config = [
        'api_url' => 'https://example.com/ocr',
    ];

    try {
        expect(fn () => (new BaiduPaddleCloudDriver())->parseFile($provider, (string)$tmp, 'jpg'))
            ->toThrow(ExceptionBusiness::class, '缺少 TOKEN');
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});

it('BaiduPaddleCloudDriver：200 + result.markdown 可提取', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'baidu_parse_');
    expect($tmp)->not->toBeFalse();
    @file_put_contents((string)$tmp, 'test');

    $provider = new ParseProvider();
    $provider->provider = 'baidu_paddle_cloud';
    $provider->config = [
        'api_url' => 'https://example.com/ocr',
        'token' => 'abc',
    ];

    $driver = new class extends BaiduPaddleCloudDriver
    {
        protected function sendRequest(string $endpoint, int $timeout, array $headers, array $payload): array
        {
            return [200, json_encode(['result' => ['markdown' => 'hello markdown']], JSON_UNESCAPED_UNICODE)];
        }
    };

    try {
        expect($driver->parseFile($provider, (string)$tmp, 'jpg'))->toBe('hello markdown');
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});

it('BaiduPaddleCloudDriver：200 + pages.text 可拼接提取', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'baidu_parse_');
    expect($tmp)->not->toBeFalse();
    @file_put_contents((string)$tmp, 'test');

    $provider = new ParseProvider();
    $provider->provider = 'baidu_paddle_cloud';
    $provider->config = [
        'api_url' => 'https://example.com/ocr',
        'token' => 'abc',
    ];

    $driver = new class extends BaiduPaddleCloudDriver
    {
        protected function sendRequest(string $endpoint, int $timeout, array $headers, array $payload): array
        {
            return [200, json_encode([
                'pages' => [
                    ['text' => '第一页'],
                    ['text' => '第二页'],
                ],
            ], JSON_UNESCAPED_UNICODE)];
        }
    };

    try {
        expect($driver->parseFile($provider, (string)$tmp, 'jpg'))->toBe("第一页\n\n第二页");
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});

it('BaiduPaddleCloudDriver：HTTP 错误会抛出带状态码异常', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'baidu_parse_');
    expect($tmp)->not->toBeFalse();
    @file_put_contents((string)$tmp, 'test');

    $provider = new ParseProvider();
    $provider->provider = 'baidu_paddle_cloud';
    $provider->config = [
        'api_url' => 'https://example.com/ocr',
        'token' => 'abc',
    ];

    $driver = new class extends BaiduPaddleCloudDriver
    {
        protected function sendRequest(string $endpoint, int $timeout, array $headers, array $payload): array
        {
            return [429, '{"error":"rate limit"}'];
        }
    };

    try {
        expect(fn () => $driver->parseFile($provider, (string)$tmp, 'jpg'))
            ->toThrow(ExceptionBusiness::class, 'HTTP 429');
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});
