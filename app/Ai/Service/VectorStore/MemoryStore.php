<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use NeuronAI\RAG\VectorStore\MemoryVectorStore as NeuronMemoryVectorStore;

final class MemoryStore extends AbstractVectorStore
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $topK = (int)($config['topK'] ?? 4);
        $this->inner = new NeuronMemoryVectorStore(max(1, $topK));
    }

    public function deleteStore(): void
    {
        // Process-local store; deleting just means dropping the instance.
    }
}
