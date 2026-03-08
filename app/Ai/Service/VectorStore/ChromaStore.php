<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\VectorStore\ChromaVectorStore as NeuronChromaVectorStore;

final class ChromaStore extends AbstractVectorStore
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $knowledgeId = (int)($config['_knowledge_id'] ?? 0);
        $collection = trim((string)($config['collection'] ?? ''));
        if ($collection === '') {
            throw new ExceptionBusiness('向量库未配置 collection（ChromaVectorStore）');
        }
        if ($knowledgeId > 0) {
            $collection = str_replace('{{knowledge_id}}', (string)$knowledgeId, $collection);
        }

        $host = trim((string)($config['host'] ?? 'http://localhost:8000')) ?: 'http://localhost:8000';
        $tenant = trim((string)($config['tenant'] ?? 'default_tenant')) ?: 'default_tenant';
        $database = trim((string)($config['database'] ?? 'default_database')) ?: 'default_database';
        $key = (string)($config['key'] ?? '');
        $key = $key !== '' ? $key : null;
        $topK = (int)($config['topK'] ?? 5);

        $this->inner = new NeuronChromaVectorStore(
            collection: $collection,
            host: $host,
            tenant: $tenant,
            database: $database,
            key: $key,
            topK: max(1, $topK),
        );
    }

    public function deleteStore(): void
    {
        if ($this->inner instanceof NeuronChromaVectorStore) {
            $this->inner->destroy();
        }
    }
}
