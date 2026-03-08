<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI;
use App\Ai\Service\Neuron\Image\ImageProvider;
use App\Ai\Support\AiRuntime;
use App\System\Service\Storage as StorageService;
use App\System\Service\Upload as UploadService;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

use function array_unique;
use function array_values;
use function base64_decode;
use function count;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;
use function uniqid;

final class ImageGenerateCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $modelId = (int)($input['model_id'] ?? 0);
        if ($modelId <= 0) {
            throw new ExceptionBusiness('图片工具缺少 model_id 配置');
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->with('provider')->find($modelId);
        if (!$model) {
            throw new ExceptionBusiness(sprintf('图片模型 [%d] 不存在', $modelId));
        }
        if ((string)($model->type ?? '') !== AiModel::TYPE_IMAGE) {
            throw new ExceptionBusiness(sprintf('模型 [%s] 不是 Image 类型', (string)($model->name ?? $modelId)));
        }
        if (!(bool)$model->active) {
            throw new ExceptionBusiness(sprintf('模型 [%s] 已禁用', (string)($model->name ?? $modelId)));
        }
        if (!$model->provider instanceof AiProvider) {
            throw new ExceptionBusiness('图片模型未绑定服务商');
        }
        if (!(bool)$model->provider->active) {
            throw new ExceptionBusiness(sprintf('服务商 [%s] 已禁用', (string)($model->provider->name ?? '')));
        }
        $modelOptions = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $storageName = trim((string)($modelOptions['media_storage_name'] ?? ''));
        $debug = (bool)($modelOptions['debug_log'] ?? false);

        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '') {
            throw new ExceptionBusiness('请输入图片生成提示词（prompt）');
        }

        $n = (int)($input['n'] ?? 1);
        if ($n <= 0) {
            $n = 1;
        }
        if ($n > 4) {
            $n = 4;
        }

        $size = trim((string)($input['size'] ?? ''));
        $negativePrompt = trim((string)($input['negative_prompt'] ?? ''));
        $imageInput = $input['image'] ?? null;
        $maskUrl = trim((string)($input['mask_url'] ?? ''));

        $payload = [
            'n' => $n,
        ];
        if ($size !== '') {
            $this->validateSize($size);
            $payload['size'] = $size;
        }
        if ($negativePrompt !== '') {
            $payload['negative_prompt'] = $negativePrompt;
        }

        $validImages = [];
        if (is_string($imageInput)) {
            $candidate = trim($imageInput);
            if ($candidate !== '') {
                $validImages[] = $candidate;
            }
        } elseif (is_array($imageInput)) {
            foreach ($imageInput as $url) {
                $candidate = trim((string)$url);
                if ($candidate !== '') {
                    $validImages[] = $candidate;
                }
            }
        }
        $validImages = array_values(array_unique($validImages));
        if ($validImages !== []) {
            $payload['image'] = $validImages[0];
            $payload['images'] = $validImages;
        }
        if ($maskUrl !== '') {
            $payload['mask'] = $maskUrl;
        }

        try {
            if ($debug) {
                AiRuntime::instance()->log('ai.image')->info('ai.image.capability.request', [
                    'model_id' => (int)$model->id,
                    'model_code' => (string)($model->code ?? ''),
                    'provider' => (string)($model->provider->code ?? ''),
                    'payload' => $this->sanitizeDebugPayload([
                        'prompt' => $prompt,
                        'options' => $payload,
                    ]),
                ]);
            }
            $provider = AI::forImageModel($model, $payload);
            $response = ImageProvider::make($provider)
                ->chat(new UserMessage($prompt))
                ->getMessage();
            if ($debug) {
                $metaImages = $response->getMetadata('images');
                AiRuntime::instance()->log('ai.image')->info('ai.image.capability.response', [
                    'model_id' => (int)$model->id,
                    'model_code' => (string)($model->code ?? ''),
                    'provider' => (string)($model->provider->code ?? ''),
                    'meta_images_count' => is_array($metaImages) ? count($metaImages) : 0,
                ]);
            }
        } catch (Throwable $e) {
            if ($debug) {
                AiRuntime::instance()->log('ai.image')->error('ai.image.capability.failed', [
                    'model_id' => (int)$model->id,
                    'model_code' => (string)($model->code ?? ''),
                    'provider' => (string)($model->provider->code ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
            throw new ExceptionBusiness($e->getMessage());
        }

        $images = $this->extractImagesFromMessage($response, $storageName !== '' ? $storageName : null);
        if ($images === []) {
            throw new ExceptionBusiness('图片接口未返回可用图片');
        }

        $count = count($images);

        return [
            'summary' => sprintf('已生成图片 %d 张', $count),
            'count' => $count,
            'image' => (string)($images[0] ?? ''),
            'images' => $images,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractImagesFromMessage(Message $message, ?string $storageName = null): array
    {
        $images = [];

        $metaImages = $message->getMetadata('images');
        if (is_array($metaImages)) {
            foreach ($metaImages as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $url = trim((string)($item['content'] ?? ''));
                $sourceType = trim((string)($item['source_type'] ?? 'url'));
                if ($url === '') {
                    continue;
                }
                if ($sourceType === 'url') {
                    $images[] = $url;
                    continue;
                }
                $uploaded = $this->uploadBase64Image($url, $storageName);
                if ($uploaded !== '') {
                    $images[] = $uploaded;
                }
            }
        }

        if ($images !== []) {
            return array_values(array_unique($images));
        }

        foreach ($message->getContentBlocks() as $block) {
            if (!$block instanceof ImageContent) {
                continue;
            }
            $content = trim($block->content);
            if ($content === '') {
                continue;
            }

            if ($block->sourceType === SourceType::URL) {
                $images[] = $content;
                continue;
            }

            if ($block->sourceType === SourceType::BASE64) {
                $uploaded = $this->uploadBase64Image($content, $storageName);
                if ($uploaded !== '') {
                    $images[] = $uploaded;
                }
            }
        }

        return array_values(array_unique($images));
    }

    private function uploadBase64Image(string $content, ?string $storageName = null): string
    {
        $raw = $content;
        if (str_starts_with($content, 'data:') && str_contains($content, ',')) {
            $parts = explode(',', $content, 2);
            $raw = $parts[1] ?? '';
        }

        $binary = base64_decode($raw, true);
        if (!is_string($binary) || $binary === '') {
            return '';
        }

        return $this->uploadImageBinary($binary, $storageName);
    }

    private function uploadImageBinary(string $binary, ?string $storageName = null): string
    {
        $mime = $this->detectImageMime($binary);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $name = sprintf('image_%s.%s', str_replace('.', '', uniqid('', true)), $ext);
        $pathInfo = UploadService::generatePath($name, $mime, 'ai/generated');
        $object = StorageService::getObject($storageName ?: null);
        $object->write($pathInfo['path'], $binary);
        return (string)$object->publicUrl($pathInfo['path']);
    }

    private function detectImageMime(string $binary): string
    {
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, "RIFF") && str_contains(substr($binary, 0, 16), 'WEBP')) {
            return 'image/webp';
        }
        return 'image/png';
    }

    private function validateSize(string $size): void
    {
        if (!preg_match('/^(\d+)x(\d+)$/i', $size, $matches)) {
            throw new ExceptionBusiness('图片尺寸格式错误，请使用 宽x高，例如 1920x1920');
        }
        $width = (int)($matches[1] ?? 0);
        $height = (int)($matches[2] ?? 0);
        if ($width <= 0 || $height <= 0) {
            throw new ExceptionBusiness('图片尺寸必须为正整数');
        }
        if (($width * $height) < 3686400) {
            throw new ExceptionBusiness('图片尺寸像素过小，至少需要 3686400 像素（例如 1920x1920）');
        }
    }

    private function sanitizeDebugPayload(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->sanitizeDebugPayload($item);
            }
            return $result;
        }
        if (is_string($value) && mb_strlen($value, 'UTF-8') > 2000) {
            return mb_substr($value, 0, 2000, 'UTF-8') . '...(truncated)';
        }
        return $value;
    }

}
