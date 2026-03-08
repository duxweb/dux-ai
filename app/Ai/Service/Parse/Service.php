<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse;

use App\Ai\Models\ParseProvider;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;
use Throwable;

final class Service
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(): array
    {
        return ParseFactory::registry();
    }

    /**
     * @return array<string, mixed>
     */
    public function providerMeta(string $provider): array
    {
        return ParseFactory::providerMeta($provider);
    }

    public function resolveProvider(ParseProvider|string|int $identifier): ParseProvider
    {
        if ($identifier instanceof ParseProvider) {
            return $identifier;
        }

        $query = ParseProvider::query()->with('storage');
        $model = is_int($identifier) || ctype_digit((string)$identifier)
            ? $query->find((int)$identifier)
            : $query->where('code', (string)$identifier)->first();

        if (!$model) {
            throw new ExceptionBusiness('解析配置不存在');
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function parseFile(ParseProvider|string|int $identifier, string $filePath, string $fileType, array $options = []): string
    {
        $provider = $this->resolveProvider($identifier);
        $config = is_array($provider->config ?? null) ? $provider->config : [];
        $logEnabled = (bool)($config['log_enabled'] ?? false);
        $logger = AiRuntime::instance()->log('ai.docs');

        if ($logEnabled) {
            $logger->info('Parse start', [
                'provider_id' => $provider->id,
                'provider' => $provider->provider,
                'file_type' => $fileType,
                'file_name' => basename($filePath),
            ]);
        }

        $driver = ParseFactory::driver($provider);
        try {
            $content = trim($driver->parseFile($provider, $filePath, $fileType, $options));
            if ($logEnabled) {
                $logger->info('Parse success', [
                    'provider_id' => $provider->id,
                    'provider' => $provider->provider,
                    'file_type' => $fileType,
                    'content_length' => strlen($content),
                ]);
            }
            return $content;
        } catch (Throwable $e) {
            $logger->error('Parse failed', [
                'provider_id' => $provider->id,
                'provider' => $provider->provider,
                'file_type' => $fileType,
                'file_name' => basename($filePath),
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
