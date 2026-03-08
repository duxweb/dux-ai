<?php

declare(strict_types=1);

namespace App\Ai\Service\FileManager;

use App\Ai\Models\AiProvider;
use App\Ai\Service\FileManager\Contracts\FileManagerProviderInterface;
use App\Ai\Service\FileManager\Providers\ArkFileManagerProvider;
use App\Ai\Service\FileManager\Providers\OpenAILikeFileManagerProvider;

final class FileManagerProviderFactory
{
    public static function make(AiProvider $provider): ?FileManagerProviderInterface
    {
        $apiKey = trim((string)($provider->api_key ?? ''));
        if ($apiKey === '') {
            return null;
        }

        $baseUrl = trim((string)($provider->base_url ?? ''));
        if ($baseUrl === '') {
            return null;
        }

        $driver = self::guessDriver($provider);
        if ($driver === '') {
            return null;
        }

        $options = [];
        $options['timeout'] = (int)($provider->timeout ?? 30);
        if (self::isMoonshot($provider)) {
            $options['purpose'] = 'file-extract';
        }

        return match ($driver) {
            'ark' => new ArkFileManagerProvider($baseUrl, $apiKey, $options),
            default => new OpenAILikeFileManagerProvider($baseUrl, $apiKey, $options),
        };
    }

    private static function guessDriver(AiProvider $provider): string
    {
        $protocol = strtolower((string)($provider->protocol ?? ''));
        if ($protocol === AiProvider::PROTOCOL_ARK) {
            return 'ark';
        }
        if (in_array($protocol, [
            AiProvider::PROTOCOL_OPENAI,
            AiProvider::PROTOCOL_OPENAI_LIKE,
            AiProvider::PROTOCOL_OPENAI_RESPONSES,
            AiProvider::PROTOCOL_AZURE_OPENAI,
            AiProvider::PROTOCOL_BIGMODEL,
        ], true)) {
            return 'openai_like';
        }

        if (self::isMoonshot($provider)) {
            return 'openai_like';
        }

        return '';
    }

    private static function isMoonshot(AiProvider $provider): bool
    {
        $baseUrl = strtolower((string)($provider->base_url ?? ''));
        return str_contains($baseUrl, 'moonshot.cn');
    }
}
