<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\RagKnowledgeData as RagKnowledgeDataModel;
use App\Ai\Service\Rag;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

#[Resource(app: 'admin', route: '/ai/ragKnowledgeData', name: 'ai.ragKnowledgeData')]
class RagKnowledgeData extends Resources
{
    protected string $model = RagKnowledgeDataModel::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'knowledge_id' => null,
            'type' => null,
            'tab' => null,
            'keyword' => null,
        ];

        if ($params['knowledge_id']) {
            $query->where('knowledge_id', (int)$params['knowledge_id']);
        }

        if ($params['type']) {
            $query->where('type', (string)$params['type']);
        }

        $tab = $params['tab'];
        if ($tab && $tab !== 'all' && in_array((string)$tab, Rag::CONTENT_TYPES, true)) {
            $query->where('type', (string)$tab);
        }

        if ($params['keyword']) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('url', 'like', "%{$keyword}%")
                    ->orWhere('file_name', 'like', "%{$keyword}%");
            });
        }

        $query->with('knowledge')->orderByDesc('id');
    }

    public function validator(array $input, ServerRequestInterface $request, array $args): array
    {
        return [
            'file_name' => ['required', '请输入文件名称'],
        ];
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        return [
            'file_name' => trim((string)$data->file_name),
        ];
    }

    public function transform(object $item): array
    {
        /** @var RagKnowledgeDataModel $item */
        $item->loadMissing('knowledge');
        return $item->transform();
    }

    #[Action(methods: 'POST', route: '/import', name: 'import')]
    public function import(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $params = $request->getParsedBody() ?: [];
        $knowledgeId = (int)($params['knowledge_id'] ?? 0);
        if ($knowledgeId <= 0) {
            throw new ExceptionBusiness('请选择知识库');
        }
        $contentType = is_string($params['type'] ?? null) ? (string)$params['type'] : '';
        $options = $this->extractImportOptions(is_array($params) ? $params : []);
        $files = $this->extractUploadedFiles($request);
        if ($files === []) {
            throw new ExceptionBusiness('请上传要导入的文件');
        }

        $results = [];
        foreach ($files as $uploadedFile) {
            $record = Rag::importContent($knowledgeId, $uploadedFile, $contentType, $options);
            $results[] = [
                'id' => $record->id,
                'file_name' => $record->file_name,
            ];
        }

        return send($response, sprintf('成功导入 %d 条内容', count($results)), [
            'count' => count($results),
            'items' => $results,
        ]);
    }

    public function delBefore(mixed $info): void
    {
        if ($info instanceof RagKnowledgeDataModel) {
            Rag::deleteContent($info, false);
        }
    }

    private function extractUploadedFiles(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $file = $files['files'] ?? ($files['file'] ?? null);
        if ($file instanceof UploadedFileInterface) {
            return $file->getError() !== UPLOAD_ERR_NO_FILE ? [$file] : [];
        }

        if (is_array($file)) {
            $list = [];
            foreach ($file as $item) {
                if ($item instanceof UploadedFileInterface && $item->getError() !== UPLOAD_ERR_NO_FILE) {
                    $list[] = $item;
                }
            }
            return $list;
        }

        return [];
    }

    /**
     * Extract optional per-import overrides.
     *
     * Only sheet import needs per-file overrides; other parsing/splitting settings
     * are configured on RagKnowledge.settings.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractImportOptions(array $params): array
    {
        $options = [];

        $sheet = [];
        if (isset($params['sheet_header_rows']) && $params['sheet_header_rows'] !== '') {
            $sheet['header_rows'] = (int)$params['sheet_header_rows'];
        }
        if (isset($params['sheet_start_row']) && $params['sheet_start_row'] !== '') {
            $sheet['start_row'] = (int)$params['sheet_start_row'];
        }
        if ($sheet !== []) {
            $options['sheet'] = $sheet;
        }

        return $options;
    }
}
