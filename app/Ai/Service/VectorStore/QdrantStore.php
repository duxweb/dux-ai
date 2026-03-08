<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\VectorStore\QdrantVectorStore as NeuronQdrantVectorStore;

final class QdrantStore extends AbstractVectorStore
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $knowledgeId = (int)($config['_knowledge_id'] ?? 0);
        $collectionUrl = trim((string)($config['collectionUrl'] ?? ''));
        if ($collectionUrl === '') {
            throw new ExceptionBusiness('向量库未配置 collectionUrl（QdrantVectorStore）');
        }
        if ($knowledgeId > 0) {
            $collectionUrl = str_replace('{{knowledge_id}}', (string)$knowledgeId, $collectionUrl);
        }

        $key = (string)($config['key'] ?? '');
        $topK = (int)($config['topK'] ?? 4);

        $dimension = $config['_dimensions'] ?? null;
        $dimension = $dimension === null || $dimension === '' ? null : (int)$dimension;
        if ($dimension === null || $dimension <= 0) {
            $raw = (string)($config['dimension'] ?? '');
            $dimension = $raw !== '' ? (int)$raw : 1536;
        }
        if ($dimension <= 0) {
            $dimension = 1536;
        }

        $this->inner = new NeuronQdrantVectorStore(
            collectionUrl: $collectionUrl,
            key: $key !== '' ? $key : null,
            topK: max(1, $topK),
            dimension: max(1, $dimension),
        );
    }

    public function deleteStore(): void
    {
        $this->inner->destroy();
    }
}
