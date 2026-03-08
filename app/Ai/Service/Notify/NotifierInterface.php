<?php

declare(strict_types=1);

namespace App\Ai\Service\Notify;

interface NotifierInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): array;
}
