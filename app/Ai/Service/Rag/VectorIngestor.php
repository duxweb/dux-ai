<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Service\Agent\Token as AgentToken;
use App\Ai\Support\AiRuntime;
use App\Ai\Service\VectorStore\VectorStoreInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Document;
use Throwable;

final class VectorIngestor
{
    /**
     * @param Document[] $documents
     */
    public static function upsert(
        VectorStoreInterface $store,
        EmbeddingsProviderInterface $embedder,
        string $sourceType,
        string $sourceName,
        array $documents,
        bool $debugLog = false
    ): void {
        $logger = AiRuntime::log('ai.rag');
        $startedAt = microtime(true);
        $documentCount = count($documents);
        $estimatedPromptTokens = self::estimatePromptTokens($documents);

        try {
            $store->deleteBySource($sourceType, $sourceName);
        } catch (Throwable) {
        }

        try {
            $embedded = $embedder->embedDocuments($documents);
            $chunkSize = 200;
            if (count($embedded) <= $chunkSize) {
                $store->addDocuments($embedded);
            } else {
                foreach (array_chunk($embedded, $chunkSize) as $chunk) {
                    $store->addDocuments($chunk);
                }
            }

            if ($debugLog) {
                $logger->info('rag.embedding.usage.estimated', [
                    'source_type' => $sourceType,
                    'source_name' => $sourceName,
                    'document_count' => $documentCount,
                    'estimated_prompt_tokens' => $estimatedPromptTokens,
                    'estimated_completion_tokens' => 0,
                    'estimated_total_tokens' => $estimatedPromptTokens,
                    'usage_source' => 'estimate',
                    'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
                ]);
            }
        } catch (Throwable $throwable) {
            try {
                $store->deleteBySource($sourceType, $sourceName);
            } catch (Throwable) {
            }
            $logger->error('rag.embedding.usage.estimated.failed', [
                'source_type' => $sourceType,
                'source_name' => $sourceName,
                'document_count' => $documentCount,
                'estimated_prompt_tokens' => $estimatedPromptTokens,
                'estimated_completion_tokens' => 0,
                'estimated_total_tokens' => $estimatedPromptTokens,
                'usage_source' => 'estimate',
                'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
                'error' => $throwable->getMessage(),
            ]);
            throw $throwable;
        }
    }

    /**
     * @param Document[] $documents
     */
    private static function estimatePromptTokens(array $documents): int
    {
        $sum = 0;
        foreach ($documents as $document) {
            $sum += AgentToken::estimateTokensForText((string)$document->getContent());
        }

        return $sum;
    }
}
