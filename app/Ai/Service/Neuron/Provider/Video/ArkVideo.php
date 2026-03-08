<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Provider\Video;

use App\Ai\Support\AiRuntime;
use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

use function array_filter;
use function array_values;
use function end;
use function http_build_query;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function max;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;
use Throwable;

final class ArkVideo implements VideoTaskProviderInterface
{
    use HasHttpClient;

    protected ?string $system = null;

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $queryParams
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected string $baseUri = 'https://ark.cn-beijing.volces.com/api/v3',
        protected array $parameters = [],
        protected string $submitEndpoint = 'contents/generations/tasks',
        protected string $queryEndpoint = 'contents/generations/tasks/{id}',
        protected string $cancelEndpoint = 'contents/generations/tasks/{id}',
        protected string $queryMethod = 'GET',
        protected string $statusPath = 'status',
        protected array $completedValues = ['succeeded', 'completed', 'success'],
        protected array $failedValues = ['failed', 'error', 'canceled', 'cancelled', 'timeout'],
        protected int $timeout = 30,
        protected array $headers = [],
        protected array $queryParams = [],
        protected bool $debug = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        $defaultHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->key,
        ];
        foreach ($this->headers as $name => $value) {
            $headerName = trim((string)$name);
            if ($headerName === '') {
                continue;
            }
            $defaultHeaders[$headerName] = (string)$value;
        }

        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/') . '/')
            ->withTimeout((float)max(1, $this->timeout))
            ->withHeaders($defaultHeaders);

        $this->queryMethod = strtoupper(trim($this->queryMethod)) === 'POST' ? 'POST' : 'GET';
        $this->submitEndpoint = trim($this->submitEndpoint, '/');
        $this->queryEndpoint = trim($this->queryEndpoint, '/');
        $this->cancelEndpoint = trim($this->cancelEndpoint, '/');
    }

    public function systemPrompt(?string $prompt): VideoTaskProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    /**
     * @param array<int, mixed> $tools
     */
    public function setTools(array $tools): VideoTaskProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new ProviderException('Video provider does not support message mapper.');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new ProviderException('Video provider does not support tools.');
    }

    public function chat(Message ...$messages): Message
    {
        return $this->createTask(...$messages);
    }

    public function createTask(Message ...$messages): Message
    {
        $message = end($messages);
        if (!$message instanceof Message) {
            throw new ProviderException('Video provider requires at least one message.');
        }

        $body = $this->buildCreateBody($message);
        $uri = $this->buildSubmitUri();
        $this->debugLog('ai.video.ark.request', [
            'action' => 'create',
            'method' => 'POST',
            'uri' => $uri,
            'body' => $this->sanitizeContext($body),
        ]);

        try {
            $response = $this->httpClient->request(
                HttpRequest::post($uri, $body)
            );
        } catch (Throwable $throwable) {
            $this->debugLog('ai.video.ark.request_failed', [
                'action' => 'create',
                'method' => 'POST',
                'uri' => $uri,
                'error' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }

        $json = $response->json();
        $this->debugLog('ai.video.ark.response', [
            'action' => 'create',
            'method' => 'POST',
            'uri' => $uri,
            'success' => $response->isSuccessful(),
            'result' => $this->sanitizeContext($json),
        ]);
        if (!$response->isSuccessful()) {
            $errorMessage = trim((string)($json['error']['message'] ?? $json['message'] ?? '视频任务创建失败'));
            throw new ProviderException($errorMessage);
        }

        $taskId = $this->extractTaskId($json);
        if ($taskId === '') {
            throw new ProviderException('视频任务创建成功但未返回 id');
        }

        $status = $this->resolveStatus($json) ?: 'queued';
        $statusUrl = $this->buildTaskUri($this->queryEndpoint, $taskId, false);
        $cancelUrl = $this->buildTaskUri($this->cancelEndpoint, $taskId, false);

        $result = new AssistantMessage(new TextContent(sprintf('视频任务已提交（%s）', $taskId)));
        $result->addMetadata('video_task', [
            'task_id' => $taskId,
            'status' => $status,
            'status_url' => $statusUrl,
            'status_method' => $this->queryMethod,
            'status_path' => $this->statusPath,
            'completed_values' => $this->completedValues,
            'failed_values' => $this->failedValues,
            'cancel_url' => $cancelUrl,
            'raw' => $json,
        ]);

        return $result;
    }

    public function queryTask(string $taskId): Message
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new ProviderException('任务 ID 不能为空');
        }

        $uri = $this->buildTaskUri($this->queryEndpoint, $taskId);
        $request = $this->queryMethod === 'POST'
            ? HttpRequest::post($uri, ['id' => $taskId])
            : HttpRequest::get($uri);
        $this->debugLog('ai.video.ark.request', [
            'action' => 'query',
            'method' => $this->queryMethod,
            'uri' => $uri,
            'task_id' => $taskId,
        ]);
        try {
            $response = $this->httpClient->request($request);
        } catch (Throwable $throwable) {
            $this->debugLog('ai.video.ark.request_failed', [
                'action' => 'query',
                'method' => $this->queryMethod,
                'uri' => $uri,
                'task_id' => $taskId,
                'error' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }

        $json = $response->json();
        $this->debugLog('ai.video.ark.response', [
            'action' => 'query',
            'method' => $this->queryMethod,
            'uri' => $uri,
            'task_id' => $taskId,
            'success' => $response->isSuccessful(),
            'result' => $this->sanitizeContext($json),
        ]);
        if (!$response->isSuccessful()) {
            $errorMessage = trim((string)($json['error']['message'] ?? $json['message'] ?? '视频任务查询失败'));
            throw new ProviderException($errorMessage);
        }

        $status = $this->resolveStatus($json);
        $videos = $this->extractVideoUrls($json);
        $lastFrameUrl = $this->extractLastFrameUrl($json);
        $errorMessage = trim((string)($json['error']['message'] ?? ''));
        $normalized = strtolower($status);
        $completed = $normalized !== '' && in_array($normalized, $this->normalizedValues($this->completedValues), true);
        if (!$completed && $videos !== []) {
            $completed = true;
        }
        $failed = $normalized !== '' && in_array($normalized, $this->normalizedValues($this->failedValues), true);
        if (!$failed && !$completed && $errorMessage !== '') {
            $failed = true;
        }

        $parts = [];
        $text = $completed
            ? sprintf('视频任务已完成（%s）', $taskId)
            : sprintf('视频任务处理中（%s）', $taskId);
        if ($failed) {
            $text = $errorMessage !== ''
                ? sprintf('视频任务失败（%s）：%s', $taskId, $errorMessage)
                : sprintf('视频任务失败（%s）', $taskId);
        }
        $parts[] = new TextContent($text);
        foreach ($videos as $url) {
            $parts[] = new VideoContent($url, SourceType::URL, 'video/mp4');
        }

        $result = new AssistantMessage($parts);
        $result->addMetadata('video_task', [
            'task_id' => $taskId,
            'status' => $status,
            'completed' => $completed,
            'failed' => $failed,
            'videos' => $videos,
            'last_frame_url' => $lastFrameUrl,
            'raw' => $json,
        ]);

        return $result;
    }

    public function cancelTask(string $taskId): Message
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new ProviderException('任务 ID 不能为空');
        }

        $uri = $this->buildTaskUri($this->cancelEndpoint, $taskId);
        $this->debugLog('ai.video.ark.request', [
            'action' => 'cancel',
            'method' => 'DELETE',
            'uri' => $uri,
            'task_id' => $taskId,
        ]);
        try {
            $response = $this->httpClient->request(HttpRequest::delete($uri));
        } catch (Throwable $throwable) {
            $this->debugLog('ai.video.ark.request_failed', [
                'action' => 'cancel',
                'method' => 'DELETE',
                'uri' => $uri,
                'task_id' => $taskId,
                'error' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }
        $json = $response->json();
        $this->debugLog('ai.video.ark.response', [
            'action' => 'cancel',
            'method' => 'DELETE',
            'uri' => $uri,
            'task_id' => $taskId,
            'success' => $response->isSuccessful(),
            'result' => $this->sanitizeContext($json),
        ]);
        if (!$response->isSuccessful()) {
            $errorMessage = trim((string)($json['error']['message'] ?? $json['message'] ?? '视频任务取消失败'));
            throw new ProviderException($errorMessage);
        }

        $result = new AssistantMessage(new TextContent(sprintf('视频任务取消请求已提交（%s）', $taskId)));
        $result->addMetadata('video_task', [
            'task_id' => $taskId,
            'status' => 'cancelled',
            'raw' => $json,
        ]);
        return $result;
    }

    public function stream(Message ...$messages): Generator
    {
        throw new ProviderException('Video provider does not support stream.');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        throw new ProviderException('Video provider does not support structured output.');
    }

    private function buildSubmitUri(): string
    {
        return $this->queryParams === []
            ? $this->submitEndpoint
            : $this->submitEndpoint . '?' . http_build_query($this->queryParams);
    }

    private function buildTaskUri(string $endpoint, string $taskId, bool $appendTaskIdForQuery = true): string
    {
        $uri = str_replace('{id}', $taskId, $endpoint);
        $uri = str_replace('{task_id}', $taskId, $uri);
        if (!str_contains($uri, $taskId) && $appendTaskIdForQuery) {
            $query = ['id' => $taskId, ...$this->queryParams];
            return $uri . '?' . http_build_query($query);
        }
        if ($this->queryParams !== []) {
            $uri .= '?' . http_build_query($this->queryParams);
        }

        return $uri;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractTaskId(array $response): string
    {
        foreach (['task_id', 'id', 'request_id'] as $key) {
            $value = trim((string)($response[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $data = is_array($response['data'] ?? null) ? ($response['data'] ?? []) : [];
        foreach (['task_id', 'id', 'request_id'] as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolveStatus(array $response): string
    {
        $status = $this->readByPath($response, $this->statusPath);
        if (is_string($status) && trim($status) !== '') {
            return trim($status);
        }

        foreach (['task_status', 'status', 'state'] as $key) {
            $value = trim((string)($response[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        $data = is_array($response['data'] ?? null) ? ($response['data'] ?? []) : [];
        foreach (['task_status', 'status', 'state'] as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, string>
     */
    private function extractVideoUrls(array $response): array
    {
        $urls = [];

        $push = static function (mixed $value) use (&$urls): void {
            $url = trim((string)$value);
            if ($url !== '') {
                $urls[] = $url;
            }
        };

        $content = is_array($response['content'] ?? null) ? ($response['content'] ?? []) : [];
        $push($content['video_url'] ?? '');

        $data = is_array($response['data'] ?? null) ? ($response['data'] ?? []) : [];
        $push($data['video_url'] ?? '');

        foreach ([$response['video_result'] ?? null, $data['video_result'] ?? null] as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_string($item)) {
                    $push($item);
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $push($item['url'] ?? '');
                $push($item['video_url'] ?? '');
            }
        }

        foreach ([$response['videos'] ?? null, $data['videos'] ?? null] as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_string($item)) {
                    $push($item);
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $push($item['url'] ?? '');
                $push($item['video_url'] ?? '');
            }
        }

        return array_values(array_unique($urls));
    }

    private function extractLastFrameUrl(array $response): string
    {
        $content = is_array($response['content'] ?? null) ? ($response['content'] ?? []) : [];
        return trim((string)($content['last_frame_url'] ?? ''));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readByPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * @param array<int, string> $items
     * @return array<int, string>
     */
    private function normalizedValues(array $items): array
    {
        return array_values(array_filter(array_map(static fn (string $item): string => strtolower(trim($item)), $items)));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreateBody(Message $message): array
    {
        $prompt = trim((string)($message->getContent() ?? ''));
        if ($this->system !== null && $this->system !== '') {
            $prompt = trim($this->system . "\n" . $prompt);
        }

        $body = [
            'model' => $this->model,
        ];

        $content = [];
        if ($prompt !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $prompt,
            ];
        }

        $imageUrl = trim((string)($this->parameters['image_url'] ?? ''));
        if ($imageUrl !== '') {
            $content[] = [
                'type' => 'image_url',
                'role' => 'first_frame',
                'image_url' => ['url' => $imageUrl],
            ];
        }

        $explicitContent = $this->parameters['content'] ?? null;
        if (is_array($explicitContent) && $explicitContent !== []) {
            $content = $explicitContent;
        }
        if ($content === []) {
            throw new ProviderException('视频生成请求缺少 content');
        }

        $body['content'] = $content;

        foreach ($this->parameters as $key => $value) {
            if (in_array($key, ['content', 'image_url'], true)) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if (is_array($value) && $value === []) {
                continue;
            }
            if (is_bool($value)) {
                $body[$key] = $value;
                continue;
            }
            $body[$key] = $value;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function debugLog(string $event, array $context): void
    {
        if (!$this->debug) {
            return;
        }
        AiRuntime::instance()->log('ai.video')->info($event, $context);
    }

    private function sanitizeContext(mixed $value, ?string $key = null): mixed
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
