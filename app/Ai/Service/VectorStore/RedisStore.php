<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use Predis\Client as PredisClient;

final class RedisStore implements VectorStoreInterface
{
    private PredisClient $redis;
    private string $index;
    private string $prefix;
    private int $topK;
    private int $dimensions;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $vectorCode = trim((string)($config['_vector_code'] ?? ''));
        if ($vectorCode === '') {
            throw new ExceptionBusiness('向量库缺少调用标识（RedisVectorStore）');
        }

        $knowledgeId = (int)($config['_knowledge_id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('向量库缺少 knowledge_id（RedisVectorStore）');
        }

        $dimensions = (int)($config['_dimensions'] ?? 0);
        if ($dimensions <= 0) {
            $dimensions = (int)($config['dimension'] ?? 0);
        }
        if ($dimensions <= 0) {
            throw new ExceptionBusiness('向量库缺少 dimension（RedisVectorStore）');
        }
        $this->dimensions = $dimensions;

        $this->topK = max(1, (int)($config['topK'] ?? 4));

        $templateVars = [
            'knowledge_id' => (string)$knowledgeId,
            'vector_code' => $vectorCode,
        ];

        $indexTemplate = (string)($config['index'] ?? 'rag_k{{knowledge_id}}');
        $prefixTemplate = (string)($config['prefix'] ?? 'rag:{{knowledge_id}}:');
        $this->index = self::applyTemplate($indexTemplate, $templateVars);
        $this->prefix = self::applyTemplate($prefixTemplate, $templateVars);
        if ($this->prefix !== '' && !str_ends_with($this->prefix, ':')) {
            $this->prefix .= ':';
        }

        $dsn = trim((string)($config['dsn'] ?? ''));
        if ($dsn !== '') {
            $this->redis = new PredisClient($dsn);
        } else {
            $host = trim((string)($config['host'] ?? '127.0.0.1'));
            $port = (int)($config['port'] ?? 6379);
            $database = (int)($config['database'] ?? 0);
            $password = (string)($config['password'] ?? '');
            $parameters = [
                'scheme' => 'tcp',
                'host' => $host !== '' ? $host : '127.0.0.1',
                'port' => $port > 0 ? $port : 6379,
                'database' => $database >= 0 ? $database : 0,
            ];
            if ($password !== '') {
                $parameters['password'] = $password;
            }
            $this->redis = new PredisClient($parameters);
        }

        $this->ensureIndex();
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

        $this->ensureIndex();

        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }
            $embedding = $document->getEmbedding();
            if (!is_array($embedding) || $embedding === []) {
                throw new ExceptionBusiness('向量库文档缺少 embedding（RedisVectorStore）');
            }
            if (count($embedding) !== $this->dimensions) {
                throw new ExceptionBusiness(sprintf('向量维度不匹配：期望 %d，实际 %d（RedisVectorStore）', $this->dimensions, count($embedding)));
            }

            $id = (string)$document->getId();
            $key = $this->prefix . $id;
            $payload = [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                'metadata' => json_encode($document->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'embedding' => self::packFloat32($embedding),
            ];

            // HSET key field value ...
            $args = array_merge(['HSET', $key], self::flattenAssocArgs($payload));
            $this->redis->executeRaw($args);
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

        $this->ensureIndex();

        $query = sprintf('@sourceType:{%s} @sourceName:{%s}', self::escapeTagValue($sourceType), self::escapeTagValue($sourceName));
        $resp = $this->redis->executeRaw([
            'FT.SEARCH',
            $this->index,
            $query,
            'RETURN',
            '0',
            'DIALECT',
            '2',
        ]);

        $keys = self::parseFtSearchKeys($resp);
        foreach ($keys as $key) {
            try {
                $this->redis->executeRaw(['FT.DEL', $this->index, $key]);
                $this->redis->executeRaw(['DEL', $key]);
            } catch (\Throwable) {
            }
        }

        return $this;
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $this->ensureIndex();

        if (count($embedding) !== $this->dimensions) {
            throw new ExceptionBusiness(sprintf('向量维度不匹配：期望 %d，实际 %d（RedisVectorStore）', $this->dimensions, count($embedding)));
        }

        $binary = self::packFloat32($embedding);
        $query = sprintf('*=>[KNN %d @embedding $vec AS dist]', $this->topK);

        $resp = $this->redis->executeRaw([
            'FT.SEARCH',
            $this->index,
            $query,
            'PARAMS',
            '2',
            'vec',
            $binary,
            'SORTBY',
            'dist',
            'RETURN',
            '8',
            'content',
            'sourceType',
            'sourceName',
            'metadata',
            'dist',
            'DIALECT',
            '2',
        ]);

        return self::parseFtSearchDocuments($resp);
    }

    public function deleteStore(): void
    {
        try {
            $this->redis->executeRaw(['FT.DROPINDEX', $this->index, 'DD']);
        } catch (\Throwable) {
        }
    }

    private function ensureIndex(): void
    {
        try {
            $this->redis->executeRaw(['FT.INFO', $this->index]);
            return;
        } catch (\Throwable) {
        }

        $this->createIndex();
    }

    private function createIndex(): void
    {
        // RediSearch vector field requires Redis Stack (FT module).
        try {
            $this->redis->executeRaw([
                'FT.CREATE',
                $this->index,
                'ON',
                'HASH',
                'PREFIX',
                '1',
                $this->prefix,
                'SCHEMA',
                'content',
                'TEXT',
                'sourceType',
                'TAG',
                'sourceName',
                'TAG',
                'metadata',
                'TEXT',
                'embedding',
                'VECTOR',
                'HNSW',
                '6',
                'TYPE',
                'FLOAT32',
                'DIM',
                (string)$this->dimensions,
                'DISTANCE_METRIC',
                'COSINE',
            ]);
        } catch (\Throwable $e) {
            throw new ExceptionBusiness('创建 Redis 向量索引失败：请确保已安装 Redis Stack（RediSearch 模块）');
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

    /**
     * @param float[] $vector
     */
    private static function packFloat32(array $vector): string
    {
        $bin = '';
        foreach ($vector as $v) {
            $bin .= pack('g', (float)$v);
        }
        return $bin;
    }

    /**
     * @param array<string, mixed> $assoc
     * @return array<int, mixed>
     */
    private static function flattenAssocArgs(array $assoc): array
    {
        $args = [];
        foreach ($assoc as $k => $v) {
            $args[] = (string)$k;
            $args[] = $v;
        }
        return $args;
    }

    private static function escapeTagValue(string $value): string
    {
        // TAG field special chars: , . < > { } [ ] " ' : ; ! @ # $ % ^ & * ( ) - + = ~
        return str_replace(['\\', '{', '}', '|', ',', ' '], ['\\\\', '\\{', '\\}', '\\|', '\\,', '\\ '], $value);
    }

    /**
     * @param mixed $resp
     * @return array<int, string>
     */
    private static function parseFtSearchKeys(mixed $resp): array
    {
        if (!is_array($resp) || count($resp) < 2) {
            return [];
        }
        $keys = [];
        for ($i = 1; $i < count($resp); $i += 2) {
            if (isset($resp[$i]) && is_string($resp[$i])) {
                $keys[] = $resp[$i];
            }
        }
        return $keys;
    }

    /**
     * @param mixed $resp
     * @return Document[]
     */
    private static function parseFtSearchDocuments(mixed $resp): array
    {
        if (!is_array($resp) || count($resp) < 2) {
            return [];
        }

        $docs = [];
        for ($i = 1; $i < count($resp); $i += 2) {
            $fields = $resp[$i + 1] ?? null;
            if (!is_array($fields)) {
                continue;
            }
            $map = [];
            for ($j = 0; $j < count($fields); $j += 2) {
                $k = $fields[$j] ?? null;
                $v = $fields[$j + 1] ?? null;
                if (is_string($k)) {
                    $map[$k] = $v;
                }
            }

            $content = is_string($map['content'] ?? null) ? (string)$map['content'] : '';
            $doc = new Document($content);

            if (isset($map['sourceType']) && is_string($map['sourceType'])) {
                $doc->sourceType = (string)$map['sourceType'];
            }
            if (isset($map['sourceName']) && is_string($map['sourceName'])) {
                $doc->sourceName = (string)$map['sourceName'];
            }

            $metadata = $map['metadata'] ?? null;
            if (is_string($metadata) && $metadata !== '') {
                $decoded = json_decode($metadata, true);
                if (is_array($decoded)) {
                    $doc->metadata = $decoded;
                }
            }

            $dist = $map['dist'] ?? null;
            if (is_numeric($dist)) {
                $distance = (float)$dist;
                $score = (float)VectorSimilarity::similarityFromDistance($distance);
                $doc->setScore(max(0.0, min(1.0, $score)));
            }

            $docs[] = $doc;
        }

        return $docs;
    }
}

