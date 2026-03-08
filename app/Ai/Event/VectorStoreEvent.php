<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class VectorStoreEvent extends Event
{
    /** @var array<string, callable> */
    private array $drivers = [];

    /** @var array<string, array<string, mixed>> */
    private array $meta = [];

    public function register(string $name, callable $factory, array $meta = []): void
    {
        $this->drivers[$name] = $factory;
        $this->meta[$name] = array_merge([
            'label' => $name,
            'value' => $name,
        ], $meta);
    }

    /**
     * @return array<string, callable>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}

