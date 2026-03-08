<?php

declare(strict_types=1);

namespace App\Ai\Event;

use App\Ai\Service\Parse\Contracts\DriverInterface;
use Symfony\Contracts\EventDispatcher\Event;

class ParseDriverEvent extends Event
{
    /** @var array<string, class-string<DriverInterface>> */
    private array $drivers = [];

    /** @var array<string, array<string, mixed>> */
    private array $meta = [];

    /**
     * @param class-string<DriverInterface> $driver
     * @param array<string, mixed> $meta
     */
    public function register(string $name, string $driver, array $meta = []): void
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return;
        }
        $this->drivers[$key] = $driver;
        $this->meta[$key] = array_merge([
            'label' => $key,
            'value' => $key,
        ], $meta, [
            'value' => $key,
        ]);
    }

    /** @return array<string, class-string<DriverInterface>> */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /** @return array<string, array<string, mixed>> */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
