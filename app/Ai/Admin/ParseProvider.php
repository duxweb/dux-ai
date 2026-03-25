<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\ParseProvider as ParseProviderModel;
use App\Ai\Service\CodeGenerator;
use App\Ai\Service\Parse;
use App\System\Service\Config as SystemConfigService;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Core\Validator\Data;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Core\Handlers\ExceptionBusiness;

#[Resource(app: 'admin', route: '/ai/parseProvider', name: 'ai.parseProvider')]
class ParseProvider extends Resources
{
    protected string $model = ParseProviderModel::class;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams();
        if (!empty($params['keyword'])) {
            $keyword = (string)$params['keyword'];
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        if (!empty($params['provider'])) {
            $query->where('provider', (string)$params['provider']);
        }

        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var ParseProviderModel $item */
        return $item->transform();
    }

    public function validator(array $data, ServerRequestInterface $request, array $args): array
    {
        $storageId = $data['storage_id'] ?? ($data['config']['__storage_id'] ?? null);
        $rules = [
            'name' => ['required', '请输入配置名称'],
            'provider' => ['required', '请选择服务商'],
        ];

        if (($data['provider'] ?? '') === 'volcengine_doc' && !$storageId && !SystemConfigService::getValue('system.storage')) {
            $rules['storage_id'] = ['required', '请选择存储驱动，或先在系统设置中配置默认存储'];
        }

        return $rules;
    }

    public function format(Data $data, ServerRequestInterface $request, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $inputCode = trim((string)$data->code);
        $config = is_array($data->config) ? $data->config : [];
        $storageId = $data->storage_id ?: ($config['__storage_id'] ?? null);
        unset($config['__storage_id']);
        $code = $inputCode !== ''
            ? $inputCode
            : CodeGenerator::unique(
                static function (string $value) use ($id): bool {
                    $query = ParseProviderModel::query()->where('code', $value);
                    if ($id > 0) {
                        $query->where('id', '<>', $id);
                    }
                    return $query->exists();
                },
            );

        return [
            'name' => (string)$data->name,
            'code' => $code,
            'provider' => (string)$data->provider,
            'storage_id' => $storageId ? (int)$storageId : null,
            'description' => $data->description ?: null,
            'config' => $config,
        ];
    }

    #[Action(methods: 'GET', route: '/providers')]
    public function providers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return send($response, 'ok', Parse::registry());
    }

    #[Action(methods: 'POST', route: '/{id}/run')]
    public function run(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ExceptionBusiness('解析配置不存在');
        }

        $provider = Parse::resolveProvider($id);
        $file = $this->extractUploadedFile($request);
        if (!$file) {
            throw new ExceptionBusiness('请上传要解析的文件');
        }

        $originalName = (string)($file->getClientFilename() ?? 'file');
        $fileType = $this->resolveFileType($originalName);

        $tmpPath = $this->writeTempFile($file, $fileType);
        try {
            $content = Parse::parseFile($provider, $tmpPath, $fileType, [
                'file_name' => $originalName,
            ]);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return send($response, 'ok', [
            'summary' => '解析运行成功',
            'provider' => $provider->provider,
            'file_name' => $originalName,
            'file_type' => $fileType,
            'content' => $content,
            'content_length' => mb_strlen($content, 'UTF-8'),
        ]);
    }

    private function extractUploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? ($files['files'] ?? null);
        if ($file instanceof UploadedFileInterface) {
            return $file->getError() === UPLOAD_ERR_NO_FILE ? null : $file;
        }
        if (is_array($file)) {
            foreach ($file as $item) {
                if ($item instanceof UploadedFileInterface && $item->getError() !== UPLOAD_ERR_NO_FILE) {
                    return $item;
                }
            }
        }
        return null;
    }

    private function resolveFileType(string $fileName): string
    {
        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        $allow = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif'];
        if (!in_array($ext, $allow, true)) {
            throw new ExceptionBusiness('仅支持 PDF / 图片 文件');
        }
        return $ext;
    }

    private function writeTempFile(UploadedFileInterface $file, string $fileType): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'parse_run_');
        if ($tmp === false) {
            throw new ExceptionBusiness('创建临时文件失败');
        }
        $target = $tmp . '.' . $fileType;
        @rename($tmp, $target);
        $file->moveTo($target);
        return $target;
    }
}
