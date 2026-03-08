<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Models\RagKnowledge;
use App\Ai\Models\RagKnowledgeData;
use App\Ai\Models\RegProvider;
use App\Ai\Service\RagEngine;
use Core\Handlers\ExceptionBusiness;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final class Service
{
    public const CONTENT_TYPES = ['document', 'qa', 'sheet'];
    public const DEFAULT_CONTENT_TYPE = 'document';

    public function syncKnowledge(string|int|RagKnowledge $knowledge): RagKnowledge
    {
        $model = KnowledgeResolver::resolve($knowledge, true);
        $config = $model->config;
        if (!$config) {
            throw new ExceptionBusiness('知识库未配置服务商');
        }

        try {
            RagEngine::ensureSynced($model);
        } catch (Throwable $throwable) {
            throw new ExceptionBusiness('知识库同步失败：' . $throwable->getMessage(), 0, $throwable);
        }

        return $model;
    }

    public function deleteKnowledge(string|int|RagKnowledge $knowledge, bool $deleteRecord = true): bool
    {
        $model = KnowledgeResolver::resolve($knowledge, false);

        $config = $model->config ?? $model->config()->first();
        $entries = RagKnowledgeData::query()->where('knowledge_id', $model->id)->get();
        foreach ($entries as $entry) {
            $this->removeRemoteContentIfNeeded($entry);
            FileCleaner::purgeLocalFile($entry);
        }

        if ($config && $model->base_id && $model->is_async) {
            try {
                RagEngine::deleteKnowledge($config, $model->base_id);
            } catch (Throwable $throwable) {
                throw new ExceptionBusiness('删除知识库失败：' . $throwable->getMessage(), 0, $throwable);
            }
        }
        RagKnowledgeData::query()->where('knowledge_id', $model->id)->delete();

        return $deleteRecord ? (bool)$model->delete() : true;
    }

    public function clearKnowledge(string|int|RagKnowledge $knowledge): bool
    {
        $model = KnowledgeResolver::resolve($knowledge, false);

        $config = $model->config ?? $model->config()->first();
        $entries = RagKnowledgeData::query()->where('knowledge_id', $model->id)->get();

        if ($config && $model->base_id && $model->is_async) {
            try {
                RagEngine::deleteKnowledge($config, (string)$model->base_id);
            } catch (Throwable $throwable) {
                throw new ExceptionBusiness('清空知识库失败：' . $throwable->getMessage(), 0, $throwable);
            }
        }

        foreach ($entries as $entry) {
            FileCleaner::purgeLocalFile($entry);
        }

        RagKnowledgeData::query()->where('knowledge_id', $model->id)->delete();

        return true;
    }

    public function importContent(string|int|RagKnowledge $knowledge, UploadedFileInterface $file, string $type = 'document', array $options = []): RagKnowledgeData
    {
        $model = KnowledgeResolver::resolve($knowledge);
        $contentType = self::normalizeContentType($type);

        return $contentType === 'qa'
            ? $this->importQaContent($model, $file)
            : $this->importDocumentContent($model, $file, $contentType, $options);
    }

    public function query(string|int|RagKnowledge $knowledge, string $query, int $limit = 5, array $options = []): array
    {
        $model = KnowledgeResolver::resolve($knowledge, true);
        if (!$model->status) {
            throw new ExceptionBusiness('知识库未启用');
        }

        $query = trim($query);
        if ($query === '') {
            throw new ExceptionBusiness('请输入查询内容');
        }

        $limit = max(1, min(50, $limit));

        $config = $model->config;
        if (!$config) {
            throw new ExceptionBusiness('知识库未配置服务商');
        }

        if (!$model->base_id || !$model->is_async) {
            $this->syncKnowledge($model);
        }
        if (!$model->base_id) {
            throw new ExceptionBusiness('知识库未同步');
        }

        $settings = is_array($model->settings ?? null) ? ($model->settings ?? []) : [];
        if (!array_key_exists('debug_log', $options)) {
            $options['debug_log'] = self::toBool($settings['debug_log'] ?? false);
        }

        $items = RagEngine::query($config, (string)$model->base_id, $query, $limit, $options);

        return [
            'knowledge' => [
                'id' => $model->id,
                'name' => $model->name,
                'base_id' => $model->base_id,
            ],
            'query' => $query,
            'limit' => $limit,
            'items' => $items,
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return false;
    }

    public function deleteContent(RagKnowledgeData|int $record, bool $deleteRecord = true): bool
    {
        $content = $record instanceof RagKnowledgeData
            ? $record
            : RagKnowledgeData::query()->find((int)$record);

        if (!$content) {
            throw new ExceptionBusiness('知识库内容不存在或已删除');
        }

        $this->removeRemoteContentIfNeeded($content);
        FileCleaner::purgeLocalFile($content);

        if ($deleteRecord) {
            return (bool)$content->delete();
        }

        return true;
    }

    private function importDocumentContent(RagKnowledge $knowledge, UploadedFileInterface $uploadedFile, string $type, array $options = []): RagKnowledgeData
    {
        $uploadError = $uploadedFile->getError();
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new ExceptionBusiness('导入文件上传失败：' . $this->uploadErrorMessage($uploadError));
        }
        $stream = $uploadedFile->getStream();
        $contents = $stream->getContents();
        if ($contents === '') {
            throw new ExceptionBusiness('导入文件内容为空');
        }

        $fileMeta = self::storeUploadedFile(
            $knowledge,
            $uploadedFile->getClientFilename(),
            $contents,
            $uploadedFile->getClientMediaType()
        );

        $record = new RagKnowledgeData();
        $record->knowledge_id = $knowledge->id;
        $record->type = $type;
        $record->url = $fileMeta['url'];
        $record->file_path = $fileMeta['path'];
        $record->storage_name = $fileMeta['storage'];
        $record->file_name = $fileMeta['name'];
        $record->file_size = $fileMeta['size'];
        $record->file_type = $fileMeta['type'];
        $record->meta = $options !== [] ? ['options' => $options] : [];
        $record->is_async = false;
        $record->save();
        $record->setRelation('knowledge', $knowledge);

        try {
            $this->syncOrIndexContent($knowledge, $record, $contents, $uploadedFile->getClientMediaType());
        } catch (Throwable $throwable) {
            FileCleaner::purgeLocalFile($record);
            $record->delete();
            $fileName = $record->file_name ?? '导入文件';
            if ($throwable instanceof ExceptionBusiness) {
                throw new ExceptionBusiness(sprintf('%s 入库失败：%s', $fileName, $throwable->getMessage()), 0, $throwable);
            }
            throw new ExceptionBusiness('知识库文档入库失败：' . $throwable->getMessage(), 0, $throwable);
        }

        return $record;
    }

    private function importQaContent(RagKnowledge $knowledge, UploadedFileInterface $uploadedFile): RagKnowledgeData
    {
        $uploadError = $uploadedFile->getError();
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new ExceptionBusiness('导入文件上传失败：' . $this->uploadErrorMessage($uploadError));
        }

        $originalName = $uploadedFile->getClientFilename() ?: 'qa.csv';
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== '' && $extension !== 'csv') {
            throw new ExceptionBusiness('问答导入仅支持 CSV 文件');
        }

        $stream = $uploadedFile->getStream();
        $contents = $stream->getContents();
        if ($contents === '') {
            throw new ExceptionBusiness('导入文件内容为空');
        }

        $csv = QaCsvParser::normalizeCsv($contents);
        $qaPairs = QaCsvParser::parse($csv);
        if ($qaPairs === []) {
            throw new ExceptionBusiness('问答 CSV 内容为空或格式不正确');
        }

        $fileMeta = self::storeUploadedFile($knowledge, $originalName, $contents, $uploadedFile->getClientMediaType());

        $record = new RagKnowledgeData();
        $record->knowledge_id = $knowledge->id;
        $record->type = 'qa';
        $record->url = $fileMeta['url'];
        $record->file_path = $fileMeta['path'];
        $record->storage_name = $fileMeta['storage'];
        $record->file_name = $fileMeta['name'];
        $record->file_size = $fileMeta['size'];
        $record->file_type = $fileMeta['type'];
        $record->meta = [];
        $record->is_async = false;
        $record->save();
        $record->setRelation('knowledge', $knowledge);

        try {
            $this->syncOrIndexQaContent($knowledge, $record, $qaPairs);
        } catch (Throwable $throwable) {
            FileCleaner::purgeLocalFile($record);
            $record->delete();
            throw new ExceptionBusiness('问答内容入库失败：' . $throwable->getMessage(), 0, $throwable);
        }

        return $record;
    }

    private function syncOrIndexContent(RagKnowledge $knowledge, RagKnowledgeData $record, string $contents, ?string $mime): void
    {
        if (!$knowledge->config) {
            throw new ExceptionBusiness('知识库未配置服务商');
        }

        $this->syncKnowledge($knowledge);
        if (!$knowledge->base_id) {
            throw new ExceptionBusiness('知识库未同步');
        }

        $payload = PayloadBuilder::fromRecord($record);
        $payload['mime'] = $mime ?: null;
        $payload['raw_size'] = strlen($contents);

        $remoteId = RagEngine::addContent($knowledge, $record, $payload);
        $pair = $remoteId ? SourceId::parse($remoteId) : null;
        if ($pair) {
            [$sourceType, $sourceName] = $pair;
            $record->source_type = $sourceType;
            $record->source_name = $sourceName;
        }
        $record->is_async = true;
        $record->save();
    }

    /**
     * @param array<int, array{question: string, answer: string}> $qaPairs
     */
    private function syncOrIndexQaContent(RagKnowledge $knowledge, RagKnowledgeData $record, array $qaPairs): void
    {
        if (!$knowledge->config) {
            throw new ExceptionBusiness('知识库未配置服务商');
        }

        $this->syncKnowledge($knowledge);
        if (!$knowledge->base_id) {
            throw new ExceptionBusiness('知识库未同步');
        }

        $remoteIds = RagEngine::addQa($knowledge, $record, $qaPairs, [
            'data_id' => (int)$record->id,
            'knowledge_id' => (int)$knowledge->id,
            'file_name' => $record->file_name,
            'file_url' => $record->url,
        ]);
        $first = is_array($remoteIds) ? (string)($remoteIds[0] ?? '') : '';
        $pair = $first !== '' ? SourceId::parse($first) : null;
        if ($pair) {
            [$sourceType, $sourceName] = $pair;
            $record->source_type = $sourceType;
            $record->source_name = $sourceName;
        }
        $record->is_async = true;
        $record->save();
    }

    private function removeRemoteContentIfNeeded(RagKnowledgeData $content): void
    {
        if (!$content->source_type || !$content->source_name) {
            return;
        }

        $knowledge = $content->knowledge ?? $content->knowledge()->with('config')->first();
        if (!$knowledge) {
            return;
        }

        if (!$knowledge->base_id || !$knowledge->is_async) {
            return;
        }

        $config = $knowledge->config ?? $knowledge->config()->first();
        if (!$config) {
            return;
        }

        RagEngine::deleteContent($config, (string)$knowledge->base_id, [
            SourceId::format((string)$content->source_type, (string)$content->source_name),
        ]);
    }

    private static function normalizeContentType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::CONTENT_TYPES, true)) {
            $type = self::DEFAULT_CONTENT_TYPE;
        }
        return $type;
    }

    /**
     * @return array{url:string,path:string,storage:string,name:string,size:int,type:string}
     */
    private static function storeUploadedFile(RagKnowledge $knowledge, ?string $originalName, string $contents, ?string $mime): array
    {
        return UploadedFileStorage::store($knowledge, $originalName, $contents, $mime);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => '文件大小超过服务器 upload_max_filesize 限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单 MAX_FILE_SIZE 限制',
            UPLOAD_ERR_PARTIAL => '文件仅部分上传，请重试',
            UPLOAD_ERR_NO_FILE => '未接收到上传文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件',
            UPLOAD_ERR_EXTENSION => '文件上传被 PHP 扩展中止',
            default => '未知错误（code=' . $error . ')',
        };
    }
}
