<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Contracts;

use App\Ai\Models\ParseProvider;

interface DriverInterface
{
    /**
     * @return array<string, mixed>
     */
    public static function meta(): array;

    /**
     * @param array<string, mixed> $options
     */
    public function parseFile(ParseProvider $provider, string $filePath, string $fileType, array $options = []): string;
}
