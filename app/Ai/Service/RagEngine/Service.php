<?php

declare(strict_types=1);

namespace App\Ai\Service\RagEngine;

use App\Ai\Models\AiModel;
use App\Ai\Models\AiVector;
use App\Ai\Models\RagKnowledge;
use App\Ai\Models\RagKnowledgeData;
use App\Ai\Models\RegProvider;
use App\Ai\Service\Rag\AssetDocumentBuilder;
use App\Ai\Service\Rag\KnowledgeId as RagKnowledgeId;
use App\Ai\Service\Rag\SourceId as RagSourceId;
use App\Ai\Service\Rag\SourceType as RagSourceType;
use App\Ai\Service\Rag\VectorIngestor;
use App\Ai\Service\AI;
use App\Ai\Service\VectorStore;
use App\Ai\Service\VectorStore\VectorStoreInterface;
use App\Ai\Support\AiRuntimeInterface;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use Throwable;

final class Service
{
    public function __construct(private readonly AiRuntimeInterface $runtime)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeConfig(array|null $config): array
    {
        if ($config === null || $config === []) {
            return [];
        }

        if (array_is_list($config)) {
            throw new ExceptionBusiness('RAG 配置格式错误：请使用对象（key/value）');
        }

        return $config;
    }

    public function ensureSynced(RagKnowledge $knowledge): void
    {
        if (!$knowledge->config) {
            throw new ExceptionBusiness('知识库未配置文档库配置（RegProvider）');
        }
        if (!$knowledge->base_id) {
            $knowledge->base_id = 'neuron:' . (int)$knowledge->id;
        }
        if (!$knowledge->is_async) {
            $knowledge->is_async = true;
        }
        $knowledge->save();
    }

    public function deleteKnowledge(RegProvider $config, string $remoteId): bool
    {
        $knowledgeId = self::parseKnowledgeId($remoteId);
        if ($knowledgeId <= 0) {
            return true;
        }

        $store = self::vectorStore($config, $knowledgeId);
        $store->deleteStore();

        return true;
    }

    public function addContent(RagKnowledge $knowledge, RagKnowledgeData $record, array $payload): string
    {
        $config = $knowledge->config;
        if (!$config) {
            throw new ExceptionBusiness('知识库未配置文档库配置（RegProvider）');
        }
        $this->ensureSynced($knowledge);
        if (!$knowledge->base_id) {
            throw new ExceptionBusiness('知识库未同步');
        }

        $knowledgeId = (int)$knowledge->id;
        $dataId = (int)$record->id;
        $settings = is_array($knowledge->settings ?? null) ? ($knowledge->settings ?? []) : [];
        $debugLog = self::toBool($settings['debug_log'] ?? false);

        $sourceType = self::assetSourceTypeFor($record->type ?: ($payload['content_type'] ?? null));
        $sourceName = self::assetSourceName($knowledgeId, $dataId);

        $documents = AssetDocumentBuilder::build($knowledge, $knowledgeId, $dataId, $payload, $sourceType, $sourceName);
        if ($documents === []) {
            throw new ExceptionBusiness('入库失败：未生成任何文档片段');
        }

        $store = $this->vectorStore($config, $knowledgeId);
        $embedder = $this->embeddingsProvider($config);
        VectorIngestor::upsert($store, $embedder, $sourceType, $sourceName, $documents, $debugLog);

        return self::formatSourceId($sourceType, $sourceName);
    }

    /**
     * @param array<int, array{question: string, answer: string}> $qas
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function addQa(RagKnowledge $knowledge, RagKnowledgeData $record, array $qas, array $options = []): array
    {
        $config = $knowledge->config;
        if (!$config) {
            throw new ExceptionBusiness('知识库未配置文档库配置（RegProvider）');
        }
        $this->ensureSynced($knowledge);
        if (!$knowledge->base_id) {
            throw new ExceptionBusiness('知识库未同步');
        }

        $knowledgeId = (int)$knowledge->id;
        $dataId = (int)$record->id;
        $settings = is_array($knowledge->settings ?? null) ? ($knowledge->settings ?? []) : [];
        $debugLog = self::toBool($settings['debug_log'] ?? false);

        $sourceType = self::assetSourceTypeFor($record->type ?: 'qa');
        $sourceName = self::assetSourceName($knowledgeId, $dataId);

        $documents = [];
        foreach ($qas as $pair) {
            $q = trim((string)($pair['question'] ?? ''));
            $a = trim((string)($pair['answer'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $doc = new Document("Q: {$q}\nA: {$a}");
            $doc->sourceType = $sourceType;
            $doc->sourceName = $sourceName;
            $doc->metadata = [
                'type' => 'qa',
                'question' => $q,
                'answer' => $a,
                'file_name' => (string)($options['file_name'] ?? ''),
                'file_url' => (string)($options['file_url'] ?? ''),
            ];
            $documents[] = $doc;
        }
        if ($documents === []) {
            return [];
        }

        $store = $this->vectorStore($config, $knowledgeId);
        $embedder = $this->embeddingsProvider($config);
        VectorIngestor::upsert($store, $embedder, $sourceType, $sourceName, $documents, $debugLog);

        return [self::formatSourceId($sourceType, $sourceName)];
    }

    /**
     * @param array<int, string> $sourceIds
     */
    public function deleteContent(RegProvider $config, string $remoteId, array $sourceIds): bool
    {
        $knowledgeId = self::parseKnowledgeId($remoteId);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('删除失败：缺少 knowledge_id');
        }

        $store = $this->vectorStore($config, $knowledgeId);
        foreach ($sourceIds as $id) {
            $pair = self::parseSourceId((string)$id);
            if (!$pair) {
                continue;
            }
            [$sourceType, $sourceName] = $pair;
            $store->deleteBySource($sourceType, $sourceName);
        }
        return true;
    }

    public function query(RegProvider $config, string $remoteId, string $query, int $limit = 5, array $options = []): array
    {
        $knowledgeId = self::parseKnowledgeId($remoteId);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('检索失败：缺少 knowledge_id');
        }

        $embedder = $this->embeddingsProvider($config);
        $store = $this->vectorStore($config, $knowledgeId);
        $debugLog = self::toBool($options['debug_log'] ?? false);
        $startedAt = microtime(true);

        $retrieval = new SimilarityRetrieval($store, $embedder);
        $docs = $retrieval->retrieve(new UserMessage($query));

        $contentLength = (int)($options['content_length'] ?? 0);
        if ($contentLength < 0) {
            $contentLength = 0;
        }

        $items = [];
        foreach ($docs as $doc) {
            if (!$doc instanceof Document) {
                continue;
            }
            $meta = is_array($doc->metadata ?? null) ? ($doc->metadata ?? []) : [];
            $type = (string)($meta['type'] ?? 'document');
            $content = (string)($doc->content ?? '');
            if ($contentLength > 0 && mb_strlen($content, 'UTF-8') > $contentLength) {
                $content = mb_substr($content, 0, $contentLength, 'UTF-8') . '…';
            }

            $meta['score'] = (float)($doc->score ?? 0);
            $items[] = [
                'title' => (string)($meta['file_name'] ?? ''),
                'content' => $content,
                'type' => $type,
                'meta' => $meta,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        if ($debugLog) {
            $firstDoc = $docs[0] ?? null;
            $firstVectorDim = ($firstDoc instanceof Document && is_array($firstDoc->embedding ?? null))
                ? count($firstDoc->embedding ?? [])
                : 0;
            $this->runtime->log('ai.rag')->info('rag.query.result', [
                'knowledge_id' => $knowledgeId,
                'query_length' => mb_strlen($query, 'UTF-8'),
                'retrieval' => SimilarityRetrieval::class,
                'first_vector_dimensions' => $firstVectorDim,
                'limit' => $limit,
                'result_count' => count($items),
                'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
            ]);
        }

        return $items;
    }

    private static function parseKnowledgeId(string $remoteId): int
    {
        return RagKnowledgeId::parse($remoteId);
    }

    private static function assetSourceTypeFor(mixed $type): string
    {
        return RagSourceType::forAssetType($type);
    }

    private static function assetSourceName(int $knowledgeId, int $dataId): string
    {
        return RagSourceId::assetName($knowledgeId, $dataId);
    }

    private static function formatSourceId(string $sourceType, string $sourceName): string
    {
        return RagSourceId::format($sourceType, $sourceName);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseSourceId(string $id): ?array
    {
        return RagSourceId::parse($id);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return false;
    }

    private function embeddingsProvider(RegProvider $config): EmbeddingsProviderInterface
    {
        $modelId = (int)($config->embedding_model_id ?? 0);
        if ($modelId <= 0) {
            throw new ExceptionBusiness('RAG 未配置 Embeddings 模型（embedding_model_id）');
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->with('provider')->find($modelId);
        if (!$model) {
            throw new ExceptionBusiness('Embeddings 模型不存在或已删除');
        }
        if (!$model->active) {
            throw new ExceptionBusiness('Embeddings 模型已禁用');
        }

        $this->runtime->log('ai.rag')->info('rag.embeddings.model.selected', [
            'reg_provider_id' => (int)$config->id,
            'embedding_model_id' => (int)$model->id,
            'embedding_model_code' => (string)($model->code ?? ''),
            'embedding_remote_model' => (string)($model->model ?? ''),
            'embedding_provider_id' => (int)($model->provider_id ?? 0),
            'embedding_provider_code' => (string)($model->provider?->code ?? ''),
        ]);

        return AI::forEmbeddingsModel($model);
    }

    private function embeddingDimensions(RegProvider $config): ?int
    {
        $modelId = (int)($config->embedding_model_id ?? 0);
        if ($modelId <= 0) {
            return null;
        }
        $model = AiModel::query()->find($modelId);
        $dim = $model?->dimensions ? (int)$model->dimensions : null;
        return ($dim !== null && $dim > 0) ? $dim : null;
    }

    private function vectorStore(RegProvider $config, int $knowledgeId): VectorStoreInterface
    {
        $vectorId = (int)($config->vector_id ?? 0);
        if ($vectorId <= 0) {
            throw new ExceptionBusiness('RAG 未配置向量库（vector_id）');
        }

        /** @var AiVector|null $vector */
        $vector = AiVector::query()->find($vectorId);
        if (!$vector) {
            throw new ExceptionBusiness('向量库不存在或已删除');
        }
        if (!$vector->active) {
            throw new ExceptionBusiness('向量库已禁用');
        }

        return VectorStore::make($vector, $knowledgeId, $this->embeddingDimensions($config));
    }

}
