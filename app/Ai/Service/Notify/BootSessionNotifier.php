<?php

declare(strict_types=1);

namespace App\Ai\Service\Notify;

use App\Boot\Service\BotService;
use App\Boot\Service\Message;
use Core\Handlers\ExceptionBusiness;

final class BootSessionNotifier implements NotifierInterface
{
    public function __construct(
        private readonly ?BotService $botService = null,
    ) {
    }

    public function send(array $payload): array
    {
        $botCode = trim((string)($payload['bot_code'] ?? ''));
        if ($botCode === '') {
            throw new ExceptionBusiness('boot_session 通知缺少 bot_code');
        }

        $title = trim((string)($payload['title'] ?? '提醒通知'));
        $content = trim((string)($payload['content'] ?? ''));
        $images = $this->normalizeImages($payload);
        $videos = $this->normalizeVideos($payload);
        $imageUrl = (string)($images[0] ?? '');
        $videoUrl = (string)($videos[0] ?? '');
        if ($content === '' && $imageUrl === '' && $videoUrl === '') {
            throw new ExceptionBusiness('通知内容、图片、视频不能同时为空');
        }

        $message = $videoUrl !== ''
            ? Message::video($videoUrl, $content !== '' ? $content : $title)
            : ($imageUrl !== ''
                ? Message::image($imageUrl, $content !== '' ? $content : $title)
                : Message::markdown($title, $content));
        $meta = is_array($payload['data'] ?? null) ? ($payload['data'] ?? []) : [];
        if ($meta !== []) {
            $message->meta($meta);
        }

        $service = $this->botService ?: new BotService();
        $result = $service->sendMessageByCode($botCode, $message);

        return [
            'channel' => 'boot_session',
            'bot_code' => $botCode,
            'title' => $title,
            'image_url' => $imageUrl,
            'video_url' => $videoUrl,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function normalizeImages(array $payload): array
    {
        $images = [];
        $single = trim((string)($payload['image_url'] ?? ''));
        if ($single !== '') {
            $images[] = $single;
        }
        if (is_array($payload['images'] ?? null)) {
            foreach (($payload['images'] ?? []) as $item) {
                $url = trim((string)$item);
                if ($url !== '') {
                    $images[] = $url;
                }
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function normalizeVideos(array $payload): array
    {
        $videos = [];
        $single = trim((string)($payload['video_url'] ?? ''));
        if ($single !== '') {
            $videos[] = $single;
        }
        if (is_array($payload['videos'] ?? null)) {
            foreach (($payload['videos'] ?? []) as $item) {
                $url = trim((string)$item);
                if ($url !== '') {
                    $videos[] = $url;
                }
            }
        }

        return array_values(array_unique($videos));
    }
}
