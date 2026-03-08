<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Models\RagKnowledge;
use App\Ai\Service\FileDataLoader;
use App\Ai\Support\AiRuntime;
use App\System\Service\Storage as StorageService;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Rap2hpoutre\FastExcel\FastExcel;

final class AssetDocumentBuilder
{
    /**
     * @param array<string, mixed> $payload
     * @return Document[]
     */
    public static function build(
        RagKnowledge $knowledge,
        int $knowledgeId,
        int $dataId,
        array $payload,
        string $sourceType,
        string $sourceName
    ): array {
        $defaults = is_array($knowledge->settings ?? null) ? ($knowledge->settings ?? []) : [];
        $overrides = is_array($payload['options'] ?? null) ? ($payload['options'] ?? []) : [];
        $cfg = array_replace_recursive($defaults, $overrides);

        $type = strtolower(trim((string)($payload['content_type'] ?? 'document')));

        if ($type === 'sheet') {
            return self::buildSheetDocuments($cfg, $payload, $sourceType, $sourceName);
        }

        $storage = trim((string)($payload['file_storage'] ?? ''));
        $path = trim((string)($payload['file_storage_path'] ?? ''));
        if ($storage === '' || $path === '') {
            throw new ExceptionBusiness('缺少 storage/path，无法入库');
        }

        $contents = self::readStorageContents($storage, $path);
        if ($contents === '') {
            throw new ExceptionBusiness('导入文件内容为空');
        }

        $parsedDocuments = self::tryParseByDataLoader($cfg, $payload, $contents);
        if ($parsedDocuments === []) {
            throw new ExceptionBusiness('未能解析出文本内容（请配置解析配置）');
        }

        $docs = [];
        foreach ($parsedDocuments as $chunkText) {
            $chunkText = trim((string)$chunkText);
            if ($chunkText === '') {
                continue;
            }
            $idx = count($docs);
            $doc = new Document($chunkText);
            $doc->sourceType = $sourceType;
            $doc->sourceName = $sourceName;
            $doc->metadata = [
                'type' => $type === 'qa' ? 'qa' : 'document',
                'chunk_index' => $idx,
                'file_name' => (string)($payload['file_name'] ?? ''),
                'file_url' => (string)($payload['file_url'] ?? ''),
                'knowledge_id' => $knowledgeId,
                'data_id' => $dataId,
            ];
            $docs[] = $doc;
        }

        if ($docs === []) {
            throw new ExceptionBusiness('未能解析出文本内容（请配置解析配置）');
        }

        return $docs;
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $payload
     * @return Document[]
     */
    private static function buildSheetDocuments(array $cfg, array $payload, string $sourceType, string $sourceName): array
    {
        $storage = trim((string)($payload['file_storage'] ?? ''));
        $path = trim((string)($payload['file_storage_path'] ?? ''));
        if ($storage === '' || $path === '') {
            throw new ExceptionBusiness('缺少 storage/path，无法解析表格');
        }

        $contents = self::readStorageContents($storage, $path);
        $tmp = tempnam(sys_get_temp_dir(), 'rag_sheet_');
        if ($tmp === false) {
            throw new ExceptionBusiness('无法创建表格临时文件');
        }

        file_put_contents($tmp, $contents);
        try {
            $items = (new FastExcel())->import($tmp)->toArray();
        } finally {
            @unlink($tmp);
        }

        if (!is_array($items)) {
            $items = [];
        }
        if ($items === []) {
            throw new ExceptionBusiness('表格内容为空或无法解析');
        }

        $docs = [];
        foreach ($items as $idx => $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $content = self::formatSheetRowForText($row, $idx + 1);
            if ($content === '') {
                continue;
            }
            $doc = new Document($content);
            $doc->sourceType = $sourceType;
            $doc->sourceName = $sourceName;
            $doc->metadata = [
                'type' => 'sheet',
                'row_index' => $idx + 1,
                'row_json' => $row,
                'file_name' => (string)($payload['file_name'] ?? ''),
                'file_url' => (string)($payload['file_url'] ?? ''),
            ];
            $docs[] = $doc;
        }
        return $docs;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function formatSheetRowForText(array $row, int $index): string
    {
        $parts = [];
        foreach ($row as $key => $value) {
            $k = trim((string)$key);
            $v = trim((string)$value);
            if ($k === '' || $v === '') {
                continue;
            }
            $parts[] = $k . ':' . $v;
        }
        if ($parts === []) {
            return '';
        }
        return sprintf('Row %d | %s', $index, implode(' | ', $parts));
    }

    private static function readStorageContents(string $storage, string $path): string
    {
        $object = StorageService::getObject($storage);
        $resource = $object->readStream($path);
        if (!is_resource($resource)) {
            return (string)$object->read($path);
        }

        $target = fopen('php://temp', 'w+b');
        if ($target === false) {
            fclose($resource);
            throw new ExceptionBusiness('无法读取存储文件');
        }

        try {
            stream_copy_to_stream($resource, $target);
            rewind($target);
            $contents = stream_get_contents($target);
            return is_string($contents) ? $contents : '';
        } finally {
            fclose($target);
            fclose($resource);
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private static function tryParseByDataLoader(array $cfg, array $payload, string $contents): array
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'rag_asset_');
        if ($tmpBase === false) {
            return [];
        }

        $tmp = $tmpBase;
        $ext = self::resolveFileExtension($payload);
        if ($ext !== '') {
            $tmpWithExt = $tmpBase . '.' . $ext;
            if (@rename($tmpBase, $tmpWithExt)) {
                $tmp = $tmpWithExt;
            }
        }

        file_put_contents($tmp, $contents);
        try {
            $parseProvider = $cfg['parse_provider'] ?? null;
            $fileExt = strtolower((string)pathinfo($tmp, PATHINFO_EXTENSION));
            if (($parseProvider === null || $parseProvider === '') && in_array($fileExt, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif'], true)) {
                throw new ExceptionBusiness('当前文件类型需要先配置解析配置（parse_provider），当前值为空');
            }

            $context = array_filter([
                'parse_provider' => $parseProvider,
                'file_name' => $payload['file_name'] ?? null,
                'file_url' => $payload['file_url'] ?? null,
                'file_storage_path' => $payload['file_storage_path'] ?? null,
                'file_storage' => $payload['file_storage'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
            $loader = new FileDataLoader($tmp, $context);
            $loader->withSplitter(self::buildNeuronSplitter($cfg));
            $documents = $loader->getDocuments();
            $parts = [];
            foreach ($documents as $document) {
                $content = trim((string)$document->content);
                if ($content === '') {
                    continue;
                }
                $parts[] = $content;
            }
            return $parts;
        } catch (\Throwable $e) {
            AiRuntime::log('ai.docs')->error('rag.asset.parse.failed', [
                'file_name' => (string)($payload['file_name'] ?? ''),
                'file_type' => (string)($payload['file_type'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            throw new ExceptionBusiness('文件解析失败：' . $e->getMessage(), 0, $e);
        } finally {
            @unlink($tmp);
            if ($tmp !== $tmpBase) {
                @unlink($tmpBase);
            }
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function buildNeuronSplitter(array $cfg): SplitterInterface
    {
        $ingest = is_array($cfg['ingest'] ?? null) ? ($cfg['ingest'] ?? []) : [];

        $maxLength = (int)($ingest['chunk_size'] ?? 1500);
        $maxLength = max(200, $maxLength);

        $separator = (string)($ingest['separator'] ?? "\n");
        if ($separator === '') {
            $separator = "\n";
        }

        $wordOverlap = (int)($ingest['word_overlap'] ?? 0);
        $wordOverlap = max(0, $wordOverlap);

        return new DelimiterTextSplitter(
            maxLength: $maxLength,
            separator: $separator,
            wordOverlap: $wordOverlap
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function resolveFileExtension(array $payload): string
    {
        $fileType = strtolower(trim((string)($payload['file_type'] ?? '')));
        if ($fileType !== '') {
            return ltrim($fileType, '.');
        }

        $fileName = trim((string)($payload['file_name'] ?? ''));
        if ($fileName !== '') {
            return strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        }

        return '';
    }
}
