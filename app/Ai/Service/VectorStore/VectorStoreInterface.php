<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use NeuronAI\RAG\VectorStore\VectorStoreInterface as NeuronVectorStoreInterface;

/**
 * Extended VectorStore interface used by this project.
 * It keeps NeuronAI's minimal API and adds store-level deletion.
 */
interface VectorStoreInterface extends NeuronVectorStoreInterface
{
    /**
     * Delete the whole knowledge base store (collection/index/file), if supported.
     */
    public function deleteStore(): void;
}
