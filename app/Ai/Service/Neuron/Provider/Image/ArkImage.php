<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Provider\Image;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use Throwable;

use function array_values;
use function base64_encode;
use function is_array;
use function max;
use function strtolower;
use function trim;

final class ArkImage extends OpenAICompatibleImage
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $queryParams
     */
    public function __construct(
        string $key,
        string $model,
        string $baseUri = 'https://ark.cn-beijing.volces.com/api/v3',
        array $parameters = [],
        string $endpoint = 'images/generations',
        int $timeout = 30,
        array $headers = [],
        array $queryParams = [],
        bool $debug = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        if (!isset($parameters['response_format'])) {
            $parameters['response_format'] = 'url';
        }

        parent::__construct(
            key: $key,
            model: $model,
            baseUri: $baseUri,
            parameters: $parameters,
            endpoint: $endpoint,
            outputFormat: '',
            timeout: $timeout,
            headers: $headers,
            queryParams: $queryParams,
            debug: $debug,
            includeOutputFormat: false,
            httpClient: $httpClient,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array{source_type: string, content: string, media_type?: string}>
     */
    protected function extractImages(array $response): array
    {
        $images = parent::extractImages($response);
        $normalized = [];

        foreach ($images as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sourceType = trim((string)($item['source_type'] ?? 'url'));
            $content = trim((string)($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($sourceType === 'url') {
                $converted = $this->downloadUrlAsBase64($content);
                if ($converted !== null) {
                    $normalized[] = $converted;
                    continue;
                }
            }

            $normalized[] = $item;
        }

        return array_values($normalized);
    }

    /**
     * @return array{source_type: string, content: string, media_type?: string}|null
     */
    private function downloadUrlAsBase64(string $url): ?array
    {
        try {
            $client = (new GuzzleHttpClient())
                ->withTimeout((float)max(1, $this->timeout));

            $response = $client->request(HttpRequest::get($url));
            if (!$response->isSuccessful()) {
                return null;
            }

            $binary = $response->body;
            if ($binary === '') {
                return null;
            }

            $result = [
                'source_type' => 'base64',
                'content' => base64_encode($binary),
            ];

            $headers = $response->headers;
            if (isset($headers['Content-Type'])) {
                $value = $headers['Content-Type'];
                $contentType = is_array($value) ? (string)($value[0] ?? '') : (string)$value;
                $contentType = trim(strtolower($contentType));
                if ($contentType !== '') {
                    $result['media_type'] = $contentType;
                }
            }

            return $result;
        } catch (Throwable $throwable) {
            $this->debugLog('ai.image.ark.download_failed', [
                'url' => $url,
                'error' => $throwable->getMessage(),
            ]);
            return null;
        }
    }
}
