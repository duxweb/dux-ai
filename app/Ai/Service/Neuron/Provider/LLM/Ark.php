<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Provider\LLM;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\HandleChat;
use NeuronAI\Providers\OpenAI\HandleStream;
use NeuronAI\Providers\OpenAI\HandleStructured;
use NeuronAI\Providers\OpenAI\MessageMapper;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function json_decode;
use function trim;
use function uniqid;

final class Ark implements AIProviderInterface
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected string $model;
    protected array $parameters = [];
    protected bool $strict_response = false;
    protected string $baseUri = 'https://ark.cn-beijing.volces.com/api/v3';
    protected ?string $system = null;
    protected MessageMapperInterface $messageMapper;
    protected ToolMapperInterface $toolPayloadMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $key,
        string $model,
        string $baseUri = 'https://ark.cn-beijing.volces.com/api/v3',
        array $parameters = [],
        bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->model = $model;
        $this->parameters = $parameters;
        $this->strict_response = $strict_response;
        $this->baseUri = rtrim($baseUri, '/');
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/') . '/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ]);
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post(
            uri: 'chat/completions',
            body: $payload
        );
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ??= new ToolMapper();
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     * @param ContentBlockInterface|ContentBlockInterface[]|null $blocks
     *
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, array|ContentBlockInterface|null $blocks = null): ToolCallMessage
    {
        $tools = array_map(
            fn (array $item): ToolInterface => $this->findTool((string)$item['function']['name'])
                ->setInputs(
                    json_decode((string)$item['function']['arguments'], true)
                )
                ->setCallId((string)$item['id']),
            $toolCalls
        );

        $result = new ToolCallMessage($blocks, $tools);
        $result->addMetadata('tool_calls', $toolCalls);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $annotations
     * @return Citation[]
     */
    protected function extractCitations(array $annotations): array
    {
        $citations = [];

        foreach ($annotations as $annotation) {
            $type = $annotation['type'] ?? null;

            if ($type === 'file_citation') {
                $fileCitation = $annotation['file_citation'] ?? [];
                $citations[] = new Citation(
                    id: $fileCitation['file_id'] ?? uniqid('ark_file_'),
                    source: $fileCitation['file_id'] ?? '',
                    startIndex: $annotation['start_index'] ?? null,
                    endIndex: $annotation['end_index'] ?? null,
                    citedText: $annotation['text'] ?? null,
                    metadata: [
                        'type' => 'file_citation',
                        'quote' => $fileCitation['quote'] ?? null,
                        'provider' => 'ark',
                    ]
                );
            } elseif ($type === 'file_path') {
                $filePath = $annotation['file_path'] ?? [];
                $citations[] = new Citation(
                    id: $filePath['file_id'] ?? uniqid('ark_path_'),
                    source: $filePath['file_id'] ?? '',
                    startIndex: $annotation['start_index'] ?? null,
                    endIndex: $annotation['end_index'] ?? null,
                    citedText: $annotation['text'] ?? null,
                    metadata: [
                        'type' => 'file_path',
                        'provider' => 'ark',
                    ]
                );
            }
        }

        return $citations;
    }

    /**
     * Hook for enriching messages with provider-specific data.
     *
     * @param array<string, mixed>|null $response
     */
    protected function enrichMessage(AssistantMessage $message, ?array $response = null): AssistantMessage
    {
        if (isset($this->streamState)) {
            foreach ($this->streamState->getMetadata() as $key => $value) {
                if ($message->getMetadata($key) === null) {
                    $message->addMetadata($key, $value);
                }
            }
        }

        return $message;
    }
}
