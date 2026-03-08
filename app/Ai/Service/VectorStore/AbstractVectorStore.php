<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface as NeuronVectorStoreInterface;

abstract class AbstractVectorStore implements VectorStoreInterface
{
    protected NeuronVectorStoreInterface $inner;

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->inner->addDocument($document);
        return $this;
    }

    /**
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->inner->addDocuments($documents);
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->inner->deleteBySource($sourceType, $sourceName);
        return $this;
    }

    /**
     * @param float[] $embedding
     * @return iterable<Document>
     */
    public function similaritySearch(array $embedding): iterable
    {
        return $this->inner->similaritySearch($embedding);
    }
}
