<?php

declare(strict_types=1);

namespace App\Ai\Service\VectorStore;

use App\Ai\Event\VectorStoreEvent;
use App\Ai\Models\AiVector;
use App\Ai\Support\AiRuntimeInterface;
use Core\Handlers\ExceptionBusiness;

final class Service
{
    /** @var array<string, callable> */
    private array $drivers = [];

    /** @var array<string, array<string, mixed>> */
    private array $meta = [];

    private bool $booted = false;

    public function __construct(private readonly AiRuntimeInterface $runtime)
    {
    }

    public function reset(): void
    {
        $this->drivers = [];
        $this->meta = [];
        $this->booted = false;
    }

    public function registry(): array
    {
        $this->bootDrivers();
        return array_values($this->meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function driverMeta(string $driver): array
    {
        $this->bootDrivers();
        return $this->meta[$driver] ?? [
            'label' => $driver,
            'value' => $driver,
        ];
    }

    public function make(AiVector $vector, int $knowledgeId, ?int $dimensions = null): VectorStoreInterface
    {
        $this->bootDrivers();

        $driver = trim((string)($vector->driver ?? 'file'));
        if ($driver === '') {
            $driver = 'file';
        }
        $cfg = is_array($vector->options) ? $vector->options : [];
        $cfg['_driver'] = $driver;
        $cfg['_vector_code'] = (string)($vector->code ?? '');
        $cfg['_knowledge_id'] = $knowledgeId;
        $cfg['_dimensions'] = $dimensions;

        $factory = $this->drivers[$driver] ?? null;
        if (!$factory) {
            throw new ExceptionBusiness(sprintf('向量库驱动 [%s] 未注册', $driver));
        }

        $store = $factory($cfg);
        if (!$store instanceof VectorStoreInterface) {
            throw new ExceptionBusiness(sprintf('向量库驱动 [%s] 工厂返回类型错误', $driver));
        }
        return $store;
    }

    private function bootDrivers(): void
    {
        if ($this->booted) {
            return;
        }

        $event = new VectorStoreEvent();
        $this->runtime->event()->dispatch($event, 'ai.vectorStore');

        $this->drivers = $event->getDrivers();
        $this->meta = $event->getMeta();
        $this->booted = true;
    }
}
