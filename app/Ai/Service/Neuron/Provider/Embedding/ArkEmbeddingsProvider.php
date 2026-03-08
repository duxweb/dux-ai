<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Provider\Embedding;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;

use function array_chunk;
use function array_filter;
use function array_is_list;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function http_build_query;
use function is_array;
use function is_scalar;
use function rtrim;
use function str_ends_with;
use function trim;

final class ArkEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    private string $endpoint;
    private int $effectiveBatchSize;

    /**
     * @param array<string, string> $headers
     * @param array<string, scalar|null> $queryParams
     */
    public function __construct(
        private readonly string $key,
        private readonly string $model,
        private readonly string $baseUri = 'https://ark.cn-beijing.volces.com/api/v3',
        private readonly ?int $dimensions = null,
        private readonly int $timeout = 30,
        private readonly array $headers = [],
        private readonly array $queryParams = [],
        private readonly int $batchSize = 50,
        ?string $endpoint = null,
    ) {
        $this->endpoint = $this->normalizeEndpoint($endpoint);
        $this->effectiveBatchSize = $this->batchSize > 0 ? min($this->batchSize, 50) : 50;

        $this->httpClient = (new GuzzleHttpClient())
            ->withBaseUri(rtrim($this->baseUri, '/') . '/')
            ->withTimeout((float)max(1, $this->timeout))
            ->withHeaders(array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ], $this->headers));
    }

    public function embedDocuments(array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $chunks = array_chunk($documents, $this->effectiveBatchSize);
        foreach ($chunks as $chunkIndex => $chunk) {
            $texts = array_map(
                static fn (Document $document): string => (string)$document->getContent(),
                $chunk
            );

            $vectors = $this->embedInputs($texts);
            foreach ($chunk as $idx => $document) {
                $document->embedding = is_array($vectors[$idx] ?? null) ? $vectors[$idx] : [];
                $chunks[$chunkIndex][$idx] = $document;
            }
        }

        return array_merge(...$chunks);
    }

    public function embedText(string $text): array
    {
        $vectors = $this->embedInputs([$text]);
        return is_array($vectors[0] ?? null) ? $vectors[0] : [];
    }

    /**
     * @param array<int, string> $inputs
     * @return array<int, array>
     */
    private function embedInputs(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        $uri = $this->appendQuery($this->endpoint);
        $payload = $this->buildPayload(array_values($inputs));

        $response = $this->httpClient->request(HttpRequest::post($uri, $payload))->json();
        $rows = $this->normalizeDataRows($response['data'] ?? null);

        $result = [];
        foreach ($rows as $row) {
            $embedding = is_array($row) ? ($row['embedding'] ?? null) : null;
            $result[] = is_array($embedding) ? $embedding : [];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDataRows(mixed $data): array
    {
        if (!is_array($data) || $data === []) {
            return [];
        }

        // 某些实现单条输入会返回对象而不是数组列表。
        if (isset($data['embedding']) && is_array($data['embedding'])) {
            return [$data];
        }

        if (array_is_list($data)) {
            return $data;
        }

        $rows = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['embedding']) && is_array($row['embedding'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, string> $inputs
     * @return array<string, mixed>
     */
    private function buildPayload(array $inputs): array
    {
        if ($this->endpoint === 'embeddings/multimodal') {
            $items = [];
            foreach ($inputs as $text) {
                $items[] = [
                    'type' => 'text',
                    'text' => $text,
                ];
            }

            return [
                'model' => $this->model,
                'input' => $items,
            ];
        }

        $payload = [
            'model' => $this->model,
            'input' => count($inputs) === 1 ? $inputs[0] : $inputs,
            'encoding_format' => 'float',
        ];

        if ($this->dimensions !== null && $this->dimensions > 0) {
            $payload['dimensions'] = $this->dimensions;
        }

        return $payload;
    }

    private function normalizeEndpoint(?string $endpoint): string
    {
        $value = trim((string)$endpoint, '/');
        if ($value === '') {
            return 'embeddings';
        }

        if ($value === 'embeddings' || $value === 'embeddings/multimodal') {
            return $value;
        }

        if (str_ends_with($value, '/embeddings')) {
            return 'embeddings';
        }

        if (str_ends_with($value, '/embeddings/multimodal')) {
            return 'embeddings/multimodal';
        }

        return 'embeddings';
    }

    private function appendQuery(string $uri): string
    {
        if ($this->queryParams === []) {
            return $uri;
        }

        $query = http_build_query(array_filter(
            $this->queryParams,
            static fn (mixed $value): bool => $value !== null && is_scalar($value)
        ));

        if ($query === '') {
            return $uri;
        }

        return $uri . '?' . $query;
    }
}
