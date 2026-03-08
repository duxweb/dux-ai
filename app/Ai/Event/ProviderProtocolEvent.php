<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class ProviderProtocolEvent extends Event
{
    /** @var array<string, array<string, mixed>> */
    private array $protocols = [];

    /** @param array<string, mixed> $meta */
    public function register(array $meta): void
    {
        $value = strtolower(trim((string)($meta['value'] ?? '')));
        if ($value === '') {
            return;
        }
        $this->protocols[$value] = array_merge([
            'value' => $value,
            'label' => $value,
            'description' => '',
            'default_base_url' => '',
            'requires_api_key' => true,
        ], $meta, [
            'value' => $value,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    public function getProtocols(): array
    {
        return $this->protocols;
    }
}
