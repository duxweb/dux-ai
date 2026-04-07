<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\System\Service\Config;

final class AiConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'default_chat_model_id' => null,
            'default_embedding_model_id' => null,
            'default_image_model_id' => null,
            'default_video_model_id' => null,
            'default_parse_provider_id' => null,
            'default_rag_provider_id' => null,
            'rate_limit' => [
                'tpm' => null,
                'concurrency' => null,
                'max_wait_ms' => 8000,
            ],
            'editor' => [
                'timeout' => 60,
                'system_prompt' => '你是 AIEditor 的写作助手。直接返回正文内容，不要解释，不要添加前后缀，不要输出 ``` 代码块。',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return array_replace_recursive(
            self::defaults(),
            Config::getJsonValue('ai', [])
        );
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return data_get(self::get(), $key, $default);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function set(array $data): void
    {
        $config = array_replace_recursive(
            Config::getJsonValue('ai', []),
            $data
        );

        Config::setValue('ai', $config);
    }
}
