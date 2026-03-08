<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\CapabilityContextInterface;
use Core\Handlers\ExceptionBusiness;

final class DelayedMessageCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $content = trim((string)($input['content'] ?? ''));
        if ($content === '') {
            throw new ExceptionBusiness('消息内容不能为空');
        }

        return [
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'content' => $content,
            ],
            'summary' => $content,
        ];
    }
}
