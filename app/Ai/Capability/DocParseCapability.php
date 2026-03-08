<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Service\FileDataLoader;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class DocParseCapability
{
    /**
     * Agent/Flow unified input:
     * - provider: int|string (ParseProvider id/code)
     * - url/path: string
     * - file_name: string (optional)
     *
     * Output:
     * - content: string
     * - file_name: string
     * - file_url: string
     * - provider: string|int|null
     *
     * @param array<string, mixed> $input
     * @return array{content: string, file_name: string, file_url: string, provider: mixed}
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $provider = $input['provider'] ?? $input['provider_id'] ?? null;

        [$localPath, $fileUrl, $fileName] = $this->resolveDocumentInput($input);
        if ($localPath === '' && $fileUrl === '') {
            throw new ExceptionBusiness('请提供文档解析入参 url/path/file');
        }

        $tmpLocalPath = $localPath;
        if ($tmpLocalPath === '' && $fileUrl !== '') {
            $tmpLocalPath = $this->downloadRemoteFile($fileUrl);
        }

        try {
            $contextPayload = array_filter([
                'parse_provider' => ($provider === '' || $provider === null || $provider === 0) ? null : $provider,
                'file_name' => $fileName !== '' ? $fileName : basename($tmpLocalPath),
                'file_url' => $fileUrl !== '' ? $fileUrl : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            $content = trim(FileDataLoader::content($tmpLocalPath, $contextPayload));
            if ($content === '') {
                throw new ExceptionBusiness('文档解析结果为空');
            }
        } finally {
            if ($localPath === '' && $tmpLocalPath !== '' && is_file($tmpLocalPath)) {
                @unlink($tmpLocalPath);
            }
        }

        return [
            'content' => $content,
            'file_name' => $fileName !== '' ? $fileName : basename($tmpLocalPath),
            'file_url' => $fileUrl,
            'provider' => $provider,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveDocumentInput(array $payload): array
    {
        $preferredKeys = ['url', 'path', 'file', 'files'];
        foreach ($preferredKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $resolved = $this->resolveFromValue($payload[$key], $payload);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $fileName = trim((string)($payload['file_name'] ?? ''));
        return ['', '', $fileName];
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function resolveFromValue(mixed $value, array $payload): ?array
    {
        $fallbackName = trim((string)($payload['file_name'] ?? ''));

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if ($this->isRemotePath($value)) {
                return ['', $value, $fallbackName];
            }
            if (is_file($value)) {
                return [$value, '', $fallbackName !== '' ? $fallbackName : basename($value)];
            }
            return ['', $value, $fallbackName];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $first = $value[0] ?? null;
                if ($first !== null) {
                    return $this->resolveFromValue($first, $payload);
                }
                return null;
            }

            $url = '';
            $name = $fallbackName;

            foreach (['url', 'path'] as $key) {
                $candidate = $value[$key] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $url = trim($candidate);
                    break;
                }
            }

            $candidateName = $value['file_name'] ?? null;
            if (is_string($candidateName) && trim($candidateName) !== '') {
                $name = trim($candidateName);
            }

            if ($url === '') {
                return null;
            }
            if ($this->isRemotePath($url)) {
                return ['', $url, $name];
            }
            if (is_file($url)) {
                return [$url, '', $name !== '' ? $name : basename($url)];
            }
            return ['', $url, $name];
        }

        return null;
    }

    private function isRemotePath(string $path): bool
    {
        return (bool)preg_match('/^https?:\\/\\//i', $path);
    }

    private function downloadRemoteFile(string $url): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc_parse_');
        if ($tmp === false) {
            throw new ExceptionBusiness('无法创建解析临时文件');
        }

        $client = new Client(['timeout' => 10, 'http_errors' => false]);
        $response = $client->get($url, [
            RequestOptions::SINK => $tmp,
        ]);
        if ($response->getStatusCode() >= 400) {
            @unlink($tmp);
            throw new ExceptionBusiness('下载解析文件失败');
        }

        return $tmp;
    }
}
