<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Provider\Image;

use App\Ai\Support\AiRuntime;
use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

use function array_values;
use function end;
use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function strtolower;
use function trim;
use Throwable;

class OpenAICompatibleImage implements AIProviderInterface
{
    use HasHttpClient;

    protected string $endpoint;

    protected bool $includeOutputFormat;

    protected ?string $system = null;

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $queryParams
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected string $baseUri,
        protected array $parameters = [],
        string $endpoint = 'images/generations',
        protected string $outputFormat = 'png',
        protected int $timeout = 30,
        protected array $headers = [],
        protected array $queryParams = [],
        protected bool $debug = false,
        bool $includeOutputFormat = true,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->endpoint = trim($endpoint) !== '' ? trim($endpoint, '/') : 'images/generations';
        $this->includeOutputFormat = $includeOutputFormat;

        $defaultHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->key,
        ];
        foreach ($this->headers as $name => $value) {
            $key = trim((string)$name);
            if ($key === '') {
                continue;
            }
            $defaultHeaders[$key] = (string)$value;
        }

        $client = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/') . '/')
            ->withTimeout((float) max(1, $this->timeout))
            ->withHeaders($defaultHeaders);

        $this->httpClient = $client;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    /**
     * @param array<int, mixed> $tools
     */
    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new ProviderException('Image provider does not support message mapper.');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new ProviderException('Image provider does not support tools.');
    }

    public function chat(Message ...$messages): Message
    {
        $message = end($messages);
        if (!$message instanceof Message) {
            throw new ProviderException('Image provider requires at least one message.');
        }

        if ($this->system !== null && $this->system !== '') {
            $message->addContent(new TextContent($this->system));
        }

        $body = [
            'model' => $this->model,
            'prompt' => (string) ($message->getContent() ?? ''),
            ...$this->parameters,
        ];

        if ($this->includeOutputFormat && trim($this->outputFormat) !== '' && !isset($body['output_format'])) {
            $body['output_format'] = $this->outputFormat;
        }

        $uri = $this->buildUri();
        $this->debugLog('ai.image.request', [
            'provider' => static::class,
            'method' => 'POST',
            'uri' => $uri,
            'body' => $this->sanitizeContext($body),
        ]);

        try {
            $response = $this->httpClient->request(
                HttpRequest::post(
                    uri: $uri,
                    body: $body,
                )
            );
        } catch (Throwable $throwable) {
            $this->debugLog('ai.image.request_failed', [
                'provider' => static::class,
                'method' => 'POST',
                'uri' => $uri,
                'error' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }

        $json = $response->json();
        $this->debugLog('ai.image.response', [
            'provider' => static::class,
            'method' => 'POST',
            'uri' => $uri,
            'success' => $response->isSuccessful(),
            'result' => $this->sanitizeContext($json),
        ]);
        if (!$response->isSuccessful()) {
            $errorMessage = trim((string) ($json['error']['message'] ?? $json['message'] ?? '图片生成请求失败'));
            throw new ProviderException($errorMessage);
        }

        $images = $this->extractImages($json);
        if ($images === []) {
            $errorMessage = trim((string) ($json['error']['message'] ?? $json['message'] ?? '图片生成接口未返回可用图片'));
            throw new ProviderException($errorMessage);
        }

        $first = $images[0];
        $result = new AssistantMessage(
            new ImageContent(
                (string) ($first['content'] ?? ''),
                ($first['source_type'] ?? 'url') === 'base64' ? SourceType::BASE64 : SourceType::URL,
                is_string($first['media_type'] ?? null) ? $first['media_type'] : null,
            )
        );

        $result->addMetadata('images', $images);

        if (is_array($json['usage'] ?? null)) {
            $result->setUsage(new Usage(
                (int) ($json['usage']['input_tokens'] ?? $json['usage']['prompt_tokens'] ?? 0),
                (int) ($json['usage']['output_tokens'] ?? $json['usage']['completion_tokens'] ?? 0),
            ));
        }

        return $result;
    }

    public function stream(Message ...$messages): Generator
    {
        throw new ProviderException('Image provider does not support streaming in this integration.');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        throw new ProviderException('Image provider does not support structured output.');
    }

    protected function buildUri(): string
    {
        $uri = $this->endpoint;
        if ($this->queryParams === []) {
            return $uri;
        }

        $query = http_build_query($this->queryParams);
        if ($query === '') {
            return $uri;
        }

        return $uri . '?' . $query;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array{source_type: string, content: string, media_type?: string}>
     */
    protected function extractImages(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? ($response['data'] ?? []) : [];
        $images = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                $images[] = ['source_type' => 'url', 'content' => $url];
                continue;
            }

            $base64 = trim((string) ($item['b64_json'] ?? $item['base64'] ?? ''));
            if ($base64 !== '') {
                $image = ['source_type' => 'base64', 'content' => $base64];
                $mediaType = trim((string) ($item['mime_type'] ?? ''));
                if ($mediaType !== '') {
                    $image['media_type'] = $mediaType;
                }
                $images[] = $image;
            }
        }

        return array_values($images);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function debugLog(string $event, array $context): void
    {
        if (!$this->debug) {
            return;
        }
        AiRuntime::instance()->log('ai.image')->info($event, $context);
    }

    protected function sanitizeContext(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $item) {
                $result[$k] = $this->sanitizeContext($item, is_string($k) ? $k : null);
            }
            return $result;
        }
        if (is_string($value)) {
            $lowerKey = strtolower((string)$key);
            if (in_array($lowerKey, ['authorization', 'api_key', 'key', 'token'], true)) {
                return '***';
            }
            if (mb_strlen($value, 'UTF-8') > 2000) {
                return mb_substr($value, 0, 2000, 'UTF-8') . '...(truncated)';
            }
        }
        return $value;
    }
}
