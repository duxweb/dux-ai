<?php

declare(strict_types=1);

namespace App\Ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

class AiFunctionEvent extends Event
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $functions = [];

    /**
     * @param array{label?: string, name?: string, description?: string} $meta
     */
    public function register(string $code, callable $handler, array $meta = []): void
    {
        $this->functions[$code] = [
            'value' => $code,
            'label' => $meta['label'] ?? $meta['name'] ?? $code,
            'description' => $meta['description'] ?? '',
            'handler' => $handler,
        ];
    }

    /**
     * @param array{
     *     value?: string,
     *     code?: string,
     *     label?: string,
     *     name?: string,
     *     description?: string,
     *     handler?: callable
     * } $function
     */
    public function add(array $function): void
    {
        $code = $function['value'] ?? $function['code'] ?? null;
        if (!$code) {
            return;
        }

        $handler = $function['handler'] ?? ($this->functions[$code]['handler'] ?? null);

        $this->functions[$code] = array_merge([
            'value' => $code,
            'label' => $function['label'] ?? $function['name'] ?? ($this->functions[$code]['label'] ?? $code),
            'description' => $function['description'] ?? ($this->functions[$code]['description'] ?? ''),
        ], $this->functions[$code] ?? [], $function);

        if ($handler !== null) {
            $this->functions[$code]['handler'] = $handler;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFunctions(): array
    {
        return array_values(array_map(static function (array $item) {
            return [
                'label' => $item['label'] ?? $item['value'],
                'value' => $item['value'],
                'description' => $item['description'] ?? '',
            ];
        }, $this->functions));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRegistry(): array
    {
        return $this->functions;
    }

    public function get(string $code): ?array
    {
        return $this->functions[$code] ?? null;
    }
}
