<?php

declare(strict_types=1);

namespace App\Ai\Service\FileManager\Providers;

use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

abstract class BaseHttpFileManagerProvider
{
    protected Client $client;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly string $apiKey,
        protected readonly array $options = [],
    ) {
        $timeout = isset($options['timeout']) && is_numeric($options['timeout'])
            ? (float)$options['timeout']
            : 30.0;

        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout' => max(1, $timeout),
            'http_errors' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(ResponseInterface $response, string $errorMessage): array
    {
        $contents = (string)$response->getBody()->getContents();
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new ExceptionBusiness($errorMessage);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function assertHttpOk(ResponseInterface $response, array $payload, string $fallback): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = (string)($payload['error']['message'] ?? $payload['message'] ?? $fallback);
        throw new ExceptionBusiness(trim($message) !== '' ? $message : $fallback);
    }

    /**
     * @throws ExceptionBusiness
     */
    protected function rethrow(GuzzleException $e, string $fallback): never
    {
        throw new ExceptionBusiness($fallback . '：' . $e->getMessage());
    }
}
