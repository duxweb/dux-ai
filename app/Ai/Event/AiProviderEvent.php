<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class AiProviderEvent extends Event
{
    /**
     * @var array<string, callable>
     */
    private array $providers = [];

    public function register(string $name, callable $factory): void
    {
        $this->providers[$name] = $factory;
    }

    /**
     * @return array<string, callable>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
