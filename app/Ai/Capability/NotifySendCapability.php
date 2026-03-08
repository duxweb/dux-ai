<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Service\Notify\NotifierRouter;
use Core\Handlers\ExceptionBusiness;

final class NotifySendCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $channel = trim((string)($input['channel'] ?? 'boot_session'));
        $title = trim((string)($input['title'] ?? '通知'));
        $content = trim((string)($input['content'] ?? ''));
        $images = $this->normalizeImages($input);
        $imageUrl = (string)($images[0] ?? '');
        if ($content === '' && $imageUrl === '') {
            throw new ExceptionBusiness('通知内容和图片不能同时为空');
        }

        $payload = [
            'bot_code' => (string)($input['bot_code'] ?? ''),
            'title' => $title,
            'content' => $content,
            'image_url' => $imageUrl,
            'images' => $images,
        ];

        $result = NotifierRouter::send($channel, $payload);
        $summary = $imageUrl !== ''
            ? sprintf('图片通知已发送到 %s', $channel)
            : sprintf('通知已发送到 %s', $channel);

        return [
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'mode' => 'immediate',
                'schedule_id' => null,
                'channel' => $channel,
                'title' => $title,
                'content' => $content,
                'image_url' => $imageUrl,
                'images' => $images,
                'result' => $result,
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    private function normalizeImages(array $input): array
    {
        $images = [];
        $single = trim((string)($input['image_url'] ?? ''));
        if ($single !== '') {
            $images[] = $single;
        }
        if (is_array($input['images'] ?? null)) {
            foreach (($input['images'] ?? []) as $item) {
                $url = trim((string)$item);
                if ($url !== '') {
                    $images[] = $url;
                }
            }
        }

        return array_values(array_unique($images));
    }
}
