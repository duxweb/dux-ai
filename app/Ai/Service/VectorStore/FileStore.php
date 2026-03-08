<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\VectorStore\FileVectorStore as NeuronFileVectorStore;

final class FileStore extends AbstractVectorStore
{
    private string $directory;
    private string $name;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $vectorCode = trim((string)($config['_vector_code'] ?? ''));
        if ($vectorCode === '') {
            throw new ExceptionBusiness('向量库缺少调用标识（FileVectorStore）');
        }

        $knowledgeId = (int)($config['_knowledge_id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('向量库缺少 knowledge_id（FileVectorStore）');
        }

        $directory = data_path($vectorCode);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ExceptionBusiness('无法创建向量库目录（FileVectorStore）');
        }

        $this->directory = $directory;
        $this->name = sprintf('rag_k%d', $knowledgeId);

        $topK = (int)($config['topK'] ?? 4);
        $this->inner = new NeuronFileVectorStore(
            directory: $this->directory,
            topK: max(1, $topK),
            name: $this->name,
        );
    }

    public function deleteStore(): void
    {
        $storeFile = $this->directory . DIRECTORY_SEPARATOR . $this->name . '.store';
        if (is_file($storeFile)) {
            @unlink($storeFile);
        }
    }
}
