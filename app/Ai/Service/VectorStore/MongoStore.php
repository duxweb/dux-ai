<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use Core\Handlers\ExceptionBusiness;
use MongoDB\Client as MongoClient;
use MongoDB\Collection;
use NeuronAI\RAG\Document;

final class MongoStore implements VectorStoreInterface
{
    private MongoClient $mongo;
    private Collection $collection;
    private string $index;
    private string $embeddingPath;
    private int $topK;
    private int $numCandidates;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (!class_exists(MongoClient::class)) {
            throw new ExceptionBusiness('MongoDB 向量库不可用：请安装 mongodb/mongodb 依赖与 ext-mongodb 扩展');
        }

        $vectorCode = trim((string)($config['_vector_code'] ?? ''));
        if ($vectorCode === '') {
            throw new ExceptionBusiness('向量库缺少调用标识（MongoVectorStore）');
        }

        $knowledgeId = (int)($config['_knowledge_id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('向量库缺少 knowledge_id（MongoVectorStore）');
        }

        $uri = trim((string)($config['uri'] ?? 'mongodb://127.0.0.1:27017'));
        if ($uri === '') {
            $uri = 'mongodb://127.0.0.1:27017';
        }

        $database = trim((string)($config['database'] ?? 'ai'));
        if ($database === '') {
            throw new ExceptionBusiness('MongoDB 向量库未配置 database');
        }

        $templateVars = [
            'knowledge_id' => (string)$knowledgeId,
            'vector_code' => $vectorCode,
        ];
        $collectionTemplate = (string)($config['collection'] ?? 'rag_k{{knowledge_id}}');
        $collectionName = self::applyTemplate($collectionTemplate, $templateVars);
        if ($collectionName === '') {
            throw new ExceptionBusiness('MongoDB 向量库未配置 collection');
        }

        $this->index = trim((string)($config['index'] ?? 'vector_index'));
        if ($this->index === '') {
            throw new ExceptionBusiness('MongoDB 向量库未配置 index（Atlas Vector Search 索引名）');
        }

        $this->embeddingPath = trim((string)($config['path'] ?? 'embedding'));
        if ($this->embeddingPath === '') {
            $this->embeddingPath = 'embedding';
        }

        $this->topK = max(1, (int)($config['topK'] ?? 4));
        $this->numCandidates = max($this->topK, (int)($config['num_candidates'] ?? 100));

        try {
            $this->mongo = new MongoClient($uri);
            $this->collection = $this->mongo->selectCollection($database, $collectionName);
        } catch (\Throwable $e) {
            throw new ExceptionBusiness('连接 MongoDB 失败：' . $e->getMessage());
        }
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $rows = [];
        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }
            $embedding = $document->getEmbedding();
            if (!is_array($embedding) || $embedding === []) {
                throw new ExceptionBusiness('向量库文档缺少 embedding（MongoVectorStore）');
            }

            $rows[] = [
                '_id' => (string)$document->getId(),
                'content' => $document->getContent(),
                'embedding' => array_values(array_map('floatval', $embedding)),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                'metadata' => $document->metadata,
                'createdAt' => new \MongoDB\BSON\UTCDateTime(),
            ];
        }

        if ($rows === []) {
            return $this;
        }

        try {
            $this->collection->insertMany($rows, ['ordered' => false]);
        } catch (\Throwable $e) {
            // Duplicate _id means re-ingest; use replaceOne fallback for each.
            foreach ($rows as $row) {
                try {
                    $id = $row['_id'];
                    $this->collection->replaceOne(['_id' => $id], $row, ['upsert' => true]);
                } catch (\Throwable) {
                }
            }
        }

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $sourceType = trim($sourceType);
        $sourceName = trim($sourceName);
        if ($sourceType === '' || $sourceName === '') {
            return $this;
        }

        try {
            $this->collection->deleteMany([
                'sourceType' => $sourceType,
                'sourceName' => $sourceName,
            ]);
        } catch (\Throwable $e) {
            throw new ExceptionBusiness('MongoDB 删除失败：' . $e->getMessage());
        }

        return $this;
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $vector = array_values(array_map('floatval', $embedding));

        $pipeline = [
            [
                '$vectorSearch' => [
                    'index' => $this->index,
                    'path' => $this->embeddingPath,
                    'queryVector' => $vector,
                    'numCandidates' => $this->numCandidates,
                    'limit' => $this->topK,
                ],
            ],
            [
                '$project' => [
                    'content' => 1,
                    'sourceType' => 1,
                    'sourceName' => 1,
                    'metadata' => 1,
                    'score' => ['$meta' => 'vectorSearchScore'],
                ],
            ],
        ];

        try {
            $cursor = $this->collection->aggregate($pipeline);
        } catch (\Throwable $e) {
            throw new ExceptionBusiness('MongoDB 向量检索失败：请确认已启用 Atlas Vector Search / MongoDB 向量索引。' . ' ' . $e->getMessage());
        }

        $docs = [];
        foreach ($cursor as $row) {
            $content = isset($row['content']) ? (string)$row['content'] : '';
            $doc = new Document($content);
            if (isset($row['sourceType'])) {
                $doc->sourceType = (string)$row['sourceType'];
            }
            if (isset($row['sourceName'])) {
                $doc->sourceName = (string)$row['sourceName'];
            }
            if (isset($row['metadata']) && is_array($row['metadata'])) {
                $doc->metadata = $row['metadata'];
            }
            if (isset($row['score']) && is_numeric($row['score'])) {
                $doc->setScore((float)$row['score']);
            }
            $docs[] = $doc;
        }

        return $docs;
    }

    public function deleteStore(): void
    {
        try {
            $this->collection->drop();
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function applyTemplate(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{{' . $key . '}}', (string)$value, $out);
        }
        return $out;
    }
}

