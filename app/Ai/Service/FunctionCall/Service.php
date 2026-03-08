<?php

declare(strict_types=1);

namespace App\Ai\Service\FunctionCall;

use App\Ai\Event\AiFunctionEvent;
use App\Ai\Support\AiRuntimeInterface;
use Core\Handlers\ExceptionBusiness;

final class Service
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registry = [];

    private bool $booted = false;

    public function __construct(private readonly AiRuntimeInterface $runtime)
    {
    }

    public function reset(): void
    {
        $this->registry = [];
        $this->booted = false;
    }

    /**
     * @return array<int, array{label: string, value: string, description?: string}>
     */
    public function list(): array
    {
        $this->boot();

        return array_values(array_map(static function (array $item) {
            return [
                'label' => $item['label'] ?? $item['value'],
                'value' => $item['value'],
                'description' => $item['description'] ?? '',
            ];
        }, $this->registry));
    }

    /**
     * @return mixed
     */
    public function call(string $code, array $input = [], array $options = [])
    {
        $entry = $this->get($code);
        $handler = $entry['handler'] ?? null;

        if (!is_callable($handler)) {
            throw new ExceptionBusiness(sprintf('函数 [%s] 未配置可执行方法', $code));
        }

        return $handler($input, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $code): array
    {
        $this->boot();

        if (!isset($this->registry[$code])) {
            throw new ExceptionBusiness(sprintf('函数 [%s] 未注册', $code));
        }

        return $this->registry[$code];
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $event = new AiFunctionEvent();
        $this->runtime->event()->dispatch($event, 'ai.function');

        $this->registry = $event->getRegistry();
        $this->booted = true;
    }
}
