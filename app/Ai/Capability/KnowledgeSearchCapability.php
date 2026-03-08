<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Models\RagKnowledge;
use App\Ai\Service\Rag;
use Core\Handlers\ExceptionBusiness;

/**
 * Knowledge base search capability (vector search).
 */
final class KnowledgeSearchCapability
{
    private const DEFAULT_LIMIT = 5;
    private const DEFAULT_CONTENT_LENGTH = 0;

    /**
     * Input:
     * - knowledge_id: int
     * - query: string
     * - limit: int
     * - content_length: int
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $knowledgeId = (int)($input['knowledge_id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('请选择要检索的知识库');
        }

        $knowledge = RagKnowledge::query()->with('config')->find($knowledgeId);
        if (!$knowledge) {
            throw new ExceptionBusiness(sprintf('知识库 [%d] 不存在', $knowledgeId));
        }
        if (!$knowledge->status) {
            throw new ExceptionBusiness(sprintf('知识库 [%s] 已禁用', $knowledge->name));
        }

        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            throw new ExceptionBusiness('请输入检索内容');
        }

        $limit = (int)($input['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $contentLength = (int)($input['content_length'] ?? self::DEFAULT_CONTENT_LENGTH);
        if ($contentLength < 0) {
            $contentLength = self::DEFAULT_CONTENT_LENGTH;
        }

        $result = Rag::query($knowledge, $query, $limit, [
            'content_length' => $contentLength,
        ]);

        $rawItems = is_array($result['items'] ?? null) ? $result['items'] : [];
        $items = $this->compactItems($rawItems);
        $overall = $this->buildOverallSummary($items);

        return [
            'items' => $items,
            'keyword' => $query,
            'limit' => $limit,
            'content_length' => $contentLength,
            'hits' => count($items),
            'knowledge_id' => (int)$knowledge->id,
            'knowledge_name' => (string)$knowledge->name,
            'knowledge_base_id' => (string)($knowledge->base_id ?? ''),
            'knowledge_provider' => (string)($knowledge->config?->provider ?? ''),
            'summary' => $overall,
        ];
    }

    /**
     * @param array<int, mixed> $rawItems
     * @return array<int, array<string, mixed>>
     */
    private function compactItems(array $rawItems): array
    {
        $items = [];
        foreach ($rawItems as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $meta = is_array($item['meta'] ?? null) ? ($item['meta'] ?? []) : [];
            $content = (string)($item['content'] ?? '');

            $score = (float)($meta['score'] ?? 0);
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') {
                $title = sprintf('检索结果#%d', $index + 1);
            }

            $items[] = [
                'rank' => $index + 1,
                'title' => $title,
                'type' => (string)($item['type'] ?? 'document'),
                'score' => round($score, 6),
                // 保持兼容：历史提示词里常引用 item.summary / item.content
                'summary' => $content,
                'content' => $content,
                'meta' => self::compactMeta($meta),
                'content_length' => mb_strlen($content, 'UTF-8'),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function compactMeta(array $meta): array
    {
        $keys = ['file_name', 'source_id', 'source_type', 'chunk_index', 'page', 'score'];
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $meta)) {
                $result[$key] = $meta[$key];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildOverallSummary(array $items): string
    {
        if ($items === []) {
            return '知识库未命中相关内容';
        }

        $parts = [];
        foreach ($items as $item) {
            $rank = (int)($item['rank'] ?? 0);
            $title = (string)($item['title'] ?? '');
            $score = (float)($item['score'] ?? 0);
            $parts[] = sprintf(
                '[%d] %s（score=%.4f）',
                $rank,
                $title !== '' ? $title : '无标题',
                $score
            );
            if (count($parts) >= 3) {
                break;
            }
        }

        return sprintf('知识库命中 %d 条。%s', count($items), implode(' ', $parts));
    }
}
