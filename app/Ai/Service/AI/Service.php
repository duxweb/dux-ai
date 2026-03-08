<?php

declare(strict_types=1);

namespace App\Ai\Service\AI;

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\Neuron\Provider\Embedding\ArkEmbeddingsProvider;
use App\Ai\Service\Neuron\Provider\Image\ArkImage;
use App\Ai\Service\Neuron\Provider\Image\OpenAICompatibleImage;
use App\Ai\Service\Neuron\Provider\LLM\Ark;
use App\Ai\Service\Neuron\Provider\Video\ArkVideo;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\OpenAILikeResponses;
use NeuronAI\Providers\XAI\Grok;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

use function array_map;
use function in_array;
use function is_array;
use function is_numeric;
use function sprintf;
use function trim;

final class Service
{
    /**
     * @param array<string, mixed> $overrides
     */
    public function forModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        $model->loadMissing('provider');
        if (!$model->provider instanceof AiProvider) {
            throw new ExceptionBusiness('AI 模型未绑定服务商');
        }

        $parameters = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        if ($overrides !== []) {
            $parameters = array_merge($parameters, $overrides);
        }
        $structuredStrict = array_key_exists('__structured_strict', $parameters)
            ? (bool)$parameters['__structured_strict']
            : true;
        unset($parameters['__structured_strict']);

        return $this->makeProvider(
            $model->provider,
            (string)$model->model,
            $parameters,
            $timeoutSeconds,
            $structuredStrict
        );
    }

    public function forEmbeddingsModel(AiModel $model): EmbeddingsProviderInterface
    {
        $model->loadMissing('provider');
        if (!$model->provider instanceof AiProvider) {
            throw new ExceptionBusiness('Embeddings 模型未绑定服务商');
        }

        $remoteModel = trim((string)($model->model ?? ''));
        if ($remoteModel === '') {
            throw new ExceptionBusiness('Embeddings 模型缺少远端模型 ID');
        }

        $dimensions = $model->dimensions ? (int)$model->dimensions : null;
        if ($dimensions !== null && $dimensions <= 0) {
            $dimensions = null;
        }

        $clientCfg = $model->provider->clientConfig();
        $protocol = (string)($clientCfg['protocol'] ?? AiProvider::PROTOCOL_OPENAI_LIKE);
        $modelOptions = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $batchSize = isset($modelOptions['batch_size']) && is_numeric($modelOptions['batch_size'])
            ? (int)$modelOptions['batch_size']
            : 0;

        AiRuntime::instance()->log('ai.rag')->info('rag.embeddings.resolve', [
            'embedding_model_id' => (int)$model->id,
            'embedding_model_code' => (string)($model->code ?? ''),
            'embedding_remote_model' => $remoteModel,
            'embedding_dimensions' => $dimensions,
            'provider_id' => (int)$model->provider->id,
            'provider_code' => (string)($model->provider->code ?? ''),
            'protocol' => $protocol,
            'base_url' => (string)($clientCfg['base_url'] ?? ''),
            'timeout' => (int)($clientCfg['timeout'] ?? 0),
            'has_api_key' => (string)($clientCfg['api_key'] ?? '') !== '',
        ]);

        if ($protocol === AiProvider::PROTOCOL_OLLAMA) {
            return new OllamaEmbeddingsProvider(
                model: $remoteModel,
                url: (string)($clientCfg['base_url'] ?? 'http://localhost:11434/api'),
                parameters: []
            );
        }

        if ($protocol === AiProvider::PROTOCOL_ARK) {
            $endpoint = trim((string)($modelOptions['endpoint'] ?? $modelOptions['embeddings_endpoint'] ?? ''));
            if ($endpoint === '') {
                $endpoint = $this->defaultArkEmbeddingsEndpoint($remoteModel);
            }
            return new ArkEmbeddingsProvider(
                key: (string)($clientCfg['api_key'] ?? ''),
                model: $remoteModel,
                baseUri: (string)($clientCfg['base_url'] ?? 'https://ark.cn-beijing.volces.com/api/v3'),
                dimensions: $dimensions,
                timeout: (int)($clientCfg['timeout'] ?? 30),
                headers: is_array($clientCfg['headers'] ?? null) ? ($clientCfg['headers'] ?? []) : [],
                queryParams: is_array($clientCfg['query_params'] ?? null) ? ($clientCfg['query_params'] ?? []) : [],
                batchSize: $batchSize,
                endpoint: $endpoint !== '' ? $endpoint : null,
            );
        }

        $baseUrl = trim((string)($clientCfg['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $provider = new OpenAILikeEmbeddings(
            baseUri: $baseUrl . '/embeddings',
            key: (string)($clientCfg['api_key'] ?? ''),
            model: $remoteModel,
            dimensions: $dimensions,
        );

        $httpClient = (new GuzzleHttpClient())
            ->withBaseUri($baseUrl . '/embeddings')
            ->withTimeout((float)max(1, (int)($clientCfg['timeout'] ?? 30)));

        $headers = is_array($clientCfg['headers'] ?? null) ? ($clientCfg['headers'] ?? []) : [];
        if ($headers !== []) {
            $httpClient = $httpClient->withHeaders($headers);
        }

        return $provider->setHttpClient($httpClient);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function forImageModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        $model->loadMissing('provider');
        if (!$model->provider instanceof AiProvider) {
            throw new ExceptionBusiness('图片模型未绑定服务商');
        }

        $provider = $model->provider;
        $parameters = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        if ($overrides !== []) {
            $parameters = array_merge($parameters, $overrides);
        }

        $clientCfg = $provider->clientConfig();
        $protocol = (string)($clientCfg['protocol'] ?? AiProvider::PROTOCOL_OPENAI_LIKE);
        $baseUrl = trim((string)($clientCfg['base_url'] ?? AiProvider::defaultBaseUrl($protocol)), '/');
        $apiKey = trim((string)($clientCfg['api_key'] ?? ''));

        if ($baseUrl === '') {
            throw new ExceptionBusiness('图片服务商缺少 base_url');
        }
        if ($apiKey === '') {
            throw new ExceptionBusiness('图片服务商缺少 API Key');
        }

        $headers = is_array($clientCfg['headers'] ?? null) ? ($clientCfg['headers'] ?? []) : [];
        $queryParams = is_array($clientCfg['query_params'] ?? null) ? ($clientCfg['query_params'] ?? []) : [];
        $timeout = $timeoutSeconds ?? (int)($clientCfg['timeout'] ?? 30);
        if ($timeout <= 0) {
            $timeout = 30;
        }

        $endpoint = trim((string)($parameters['image_endpoint'] ?? $parameters['endpoint'] ?? 'images/generations'));
        if ($endpoint === '') {
            $endpoint = 'images/generations';
        }
        unset($parameters['image_endpoint'], $parameters['endpoint']);

        if (!array_key_exists('response_format', $parameters) && isset($parameters['image_response_format'])) {
            $parameters['response_format'] = $parameters['image_response_format'];
        }
        unset($parameters['image_response_format']);

        $outputFormat = trim((string)($parameters['image_output_format'] ?? $parameters['output_format'] ?? 'png'));
        unset($parameters['image_output_format']);

        $debug = (bool)($parameters['debug_log'] ?? false);
        if (array_key_exists('__debug', $parameters)) {
            $debug = $debug || (bool)$parameters['__debug'];
        }
        unset($parameters['debug_log'], $parameters['__debug']);

        $httpClient = (new GuzzleHttpClient())
            ->withBaseUri($baseUrl)
            ->withTimeout((float)$timeout)
            ->withHeaders($headers);

        return match ($protocol) {
            AiProvider::PROTOCOL_OPENAI => new OpenAIImage(
                key: $apiKey,
                model: (string)$model->model,
                output_format: $outputFormat !== '' ? $outputFormat : 'png',
                parameters: $parameters,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_ARK => new ArkImage(
                key: $apiKey,
                model: (string)$model->model,
                baseUri: $baseUrl,
                parameters: $parameters,
                endpoint: $endpoint,
                timeout: $timeout,
                headers: $headers,
                queryParams: $queryParams,
                debug: $debug,
            ),
            AiProvider::PROTOCOL_BIGMODEL => new OpenAICompatibleImage(
                key: $apiKey,
                model: (string)$model->model,
                baseUri: $baseUrl,
                parameters: $parameters,
                endpoint: $endpoint,
                outputFormat: $outputFormat,
                timeout: $timeout,
                headers: $headers,
                queryParams: $queryParams,
                debug: $debug,
            ),
            default => new OpenAICompatibleImage(
                key: $apiKey,
                model: (string)$model->model,
                baseUri: $baseUrl,
                parameters: $parameters,
                endpoint: $endpoint,
                outputFormat: $outputFormat,
                timeout: $timeout,
                headers: $headers,
                queryParams: $queryParams,
                debug: $debug,
            ),
        };
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function forVideoModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        $model->loadMissing('provider');
        if (!$model->provider instanceof AiProvider) {
            throw new ExceptionBusiness('视频模型未绑定服务商');
        }
        if ((string)($model->type ?? '') !== AiModel::TYPE_VIDEO) {
            throw new ExceptionBusiness('所选模型不是视频模型');
        }

        $provider = $model->provider;
        $parameters = $overrides;

        $clientCfg = $provider->clientConfig();
        $protocol = (string)($clientCfg['protocol'] ?? AiProvider::PROTOCOL_OPENAI_LIKE);
        $baseUrl = trim((string)($clientCfg['base_url'] ?? AiProvider::defaultBaseUrl($protocol)), '/');
        $apiKey = trim((string)($clientCfg['api_key'] ?? ''));
        if ($baseUrl === '') {
            throw new ExceptionBusiness('视频服务商缺少 base_url');
        }
        if ($apiKey === '') {
            throw new ExceptionBusiness('视频服务商缺少 API Key');
        }

        $headers = is_array($clientCfg['headers'] ?? null) ? ($clientCfg['headers'] ?? []) : [];
        $queryParams = is_array($clientCfg['query_params'] ?? null) ? ($clientCfg['query_params'] ?? []) : [];
        $timeout = $timeoutSeconds ?? (int)($clientCfg['timeout'] ?? 30);
        if ($timeout <= 0) {
            $timeout = 30;
        }

        $defaultSubmitEndpoint = $protocol === AiProvider::PROTOCOL_BIGMODEL
            ? 'videos/generations'
            : 'contents/generations/tasks';
        $submitEndpoint = trim((string)($parameters['video_endpoint'] ?? $defaultSubmitEndpoint));
        if ($submitEndpoint === '') {
            $submitEndpoint = $defaultSubmitEndpoint;
        }
        unset($parameters['video_endpoint']);

        $defaultQueryEndpoint = $protocol === AiProvider::PROTOCOL_BIGMODEL
            ? 'async-result/{id}'
            : 'contents/generations/tasks/{id}';
        $queryEndpoint = trim((string)($parameters['video_status_endpoint'] ?? $parameters['video_poll_endpoint'] ?? $defaultQueryEndpoint));
        if ($queryEndpoint === '') {
            $queryEndpoint = $defaultQueryEndpoint;
        }
        unset($parameters['video_status_endpoint'], $parameters['video_poll_endpoint']);

        $defaultCancelEndpoint = $protocol === AiProvider::PROTOCOL_BIGMODEL
            ? 'videos/generations/{id}'
            : 'contents/generations/tasks/{id}';
        $cancelEndpoint = trim((string)($parameters['video_cancel_endpoint'] ?? $defaultCancelEndpoint));
        if ($cancelEndpoint === '') {
            $cancelEndpoint = $defaultCancelEndpoint;
        }
        unset($parameters['video_cancel_endpoint']);

        $queryMethod = trim((string)($parameters['video_status_method'] ?? 'GET'));
        unset($parameters['video_status_method']);

        $defaultStatusPath = $protocol === AiProvider::PROTOCOL_BIGMODEL ? 'task_status' : 'status';
        $statusPath = trim((string)($parameters['video_status_path'] ?? $defaultStatusPath));
        unset($parameters['video_status_path']);

        $defaultCompletedValues = $protocol === AiProvider::PROTOCOL_BIGMODEL
            ? ['success', 'succeeded', 'completed']
            : ['succeeded', 'completed', 'success'];
        $completedValues = is_array($parameters['video_completed_values'] ?? null)
            ? ($parameters['video_completed_values'] ?? [])
            : $defaultCompletedValues;
        $defaultFailedValues = $protocol === AiProvider::PROTOCOL_BIGMODEL
            ? ['failed', 'error', 'canceled', 'cancelled', 'timeout']
            : ['failed', 'error', 'canceled', 'cancelled', 'timeout'];
        $failedValues = is_array($parameters['video_failed_values'] ?? null)
            ? ($parameters['video_failed_values'] ?? [])
            : $defaultFailedValues;
        unset($parameters['video_completed_values'], $parameters['video_failed_values']);

        $debug = (bool)($parameters['debug_log'] ?? false);
        if (array_key_exists('__debug', $parameters)) {
            $debug = $debug || (bool)$parameters['__debug'];
        }
        unset($parameters['debug_log'], $parameters['__debug']);

        return match ($protocol) {
            AiProvider::PROTOCOL_BIGMODEL,
            AiProvider::PROTOCOL_ARK => new ArkVideo(
                key: $apiKey,
                model: (string)$model->model,
                baseUri: $baseUrl,
                parameters: $parameters,
                submitEndpoint: $submitEndpoint,
                queryEndpoint: $queryEndpoint,
                cancelEndpoint: $cancelEndpoint,
                queryMethod: $queryMethod,
                statusPath: $statusPath,
                completedValues: $completedValues,
                failedValues: $failedValues,
                timeout: $timeout,
                headers: $headers,
                queryParams: $queryParams,
                debug: $debug,
            ),
            default => throw new ExceptionBusiness(sprintf('当前协议 [%s] 暂不支持视频生成', $protocol)),
        };
    }

    private function defaultArkEmbeddingsEndpoint(string $model): string
    {
        // 当前 ARK 向量统一走 multimodal endpoint（文本同样可作为 text 模态输入）。
        return 'embeddings/multimodal';
    }

    /**
     * Create an LLM provider directly from `ai/provider` config.
     *
     * @param array<string, mixed> $parameters
     */
    public function forProvider(AiProvider $provider, string $model, array $parameters = [], ?int $timeoutSeconds = null): AIProviderInterface
    {
        $clientCfg = $provider->clientConfig();
        $protocol = (string)($clientCfg['protocol'] ?? AiProvider::PROTOCOL_OPENAI_LIKE);
        if (!in_array($protocol, array_map(static fn (array $item): string => (string)$item['value'], AiProvider::protocolRegistry()), true)) {
            throw new ExceptionBusiness('不支持的协议类型');
        }

        $structuredStrict = array_key_exists('__structured_strict', $parameters)
            ? (bool)$parameters['__structured_strict']
            : true;
        unset($parameters['__structured_strict']);
        return $this->makeProvider($provider, $model, $parameters, $timeoutSeconds, $structuredStrict);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function makeProvider(
        AiProvider $provider,
        string $model,
        array $parameters = [],
        ?int $timeoutSeconds = null,
        bool $structuredStrict = true,
    ): AIProviderInterface
    {
        $clientCfg = $provider->clientConfig();
        $protocol = (string)($clientCfg['protocol'] ?? AiProvider::PROTOCOL_OPENAI_LIKE);
        $baseUrl = (string)($clientCfg['base_url'] ?? AiProvider::defaultBaseUrl($protocol));
        $apiKey = (string)($clientCfg['api_key'] ?? '');
        $headers = is_array($clientCfg['headers'] ?? null) ? ($clientCfg['headers'] ?? []) : [];
        $queryParams = is_array($clientCfg['query_params'] ?? null) ? ($clientCfg['query_params'] ?? []) : [];
        $timeout = $timeoutSeconds ?? (int)($clientCfg['timeout'] ?? 30);
        $httpClient = $this->buildHttpClient($timeout, $headers);

        return match ($protocol) {
            AiProvider::PROTOCOL_OPENAI => new OpenAI(
                key: $apiKey,
                model: $model,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_OPENAI_RESPONSES => new OpenAILikeResponses(
                baseUri: $baseUrl,
                key: $apiKey,
                model: $model,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_OPENAI_LIKE,
            AiProvider::PROTOCOL_BIGMODEL,
            AiProvider::PROTOCOL_ARK => new Ark(
                key: $apiKey,
                model: $model,
                baseUri: $baseUrl,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_OLLAMA => new Ollama(
                url: $baseUrl,
                model: $model,
                parameters: $parameters,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_DEEPSEEK => new Deepseek(
                key: $apiKey,
                model: $model,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_ANTHROPIC => new Anthropic(
                key: $apiKey,
                model: $model,
                version: (string)($queryParams['anthropic_version'] ?? '2023-06-01'),
                max_tokens: isset($queryParams['anthropic_max_tokens']) ? (int)$queryParams['anthropic_max_tokens'] : 8192,
                parameters: $parameters,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_GEMINI => new Gemini(
                key: $apiKey,
                model: $model,
                parameters: $parameters,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_MISTRAL => new Mistral(
                key: $apiKey,
                model: $model,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_COHERE => new Cohere(
                key: $apiKey,
                model: $model,
                baseUri: $baseUrl,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_GROK => new Grok(
                key: $apiKey,
                model: $model,
                parameters: $parameters,
                strict_response: $structuredStrict,
                httpClient: $httpClient,
            ),
            AiProvider::PROTOCOL_AZURE_OPENAI => new AzureOpenAI(
                key: $apiKey,
                endpoint: $baseUrl,
                model: $model,
                version: (string)($queryParams['api-version'] ?? $queryParams['api_version'] ?? '2024-02-01'),
                strict_response: $structuredStrict,
                parameters: $parameters,
                httpClient: $httpClient,
            ),
            default => throw new ExceptionBusiness(sprintf('不支持的协议类型: %s', $protocol)),
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildHttpClient(int $timeout, array $headers): GuzzleHttpClient
    {
        $client = new GuzzleHttpClient();
        if ($timeout > 0) {
            $client = $client->withTimeout((float)$timeout);
        }
        if ($headers !== []) {
            $client = $client->withHeaders($headers);
        }

        return $client;
    }
}
