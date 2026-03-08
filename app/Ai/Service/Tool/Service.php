<?php

declare(strict_types=1);

namespace App\Ai\Service\Tool;

use App\Ai\Service\Tool;
use App\Ai\Service\Capability\Service as CapabilityService;
use Core\Handlers\ExceptionBusiness;

final class Service
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registry = [];

    private bool $booted = false;

    public function __construct(
        private readonly CapabilityService $capabilities,
    ) {
    }

    public function reset(): void
    {
        $this->registry = [];
        $this->booted = false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $this->boot();
        return array_values($this->registry);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $code): ?array
    {
        $this->boot();
        return $this->registry[$code] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $config
     * @return mixed
     */
    public function execute(string $code, array $config = [], array $params = [])
    {
        $tool = $this->get($code);
        if (!$tool) {
            throw new ExceptionBusiness(sprintf('工具 [%s] 未注册', $code));
        }

        $mergedParams = Tool::mergeToolParams($config, $params);
        $sessionId = isset($mergedParams['__session_id']) ? (int)$mergedParams['__session_id'] : 0;
        $agentId = isset($mergedParams['__agent_id']) ? (int)$mergedParams['__agent_id'] : 0;
        return $this->capabilities->execute($code, $mergedParams, new AgentToolContext($sessionId, $agentId));
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $registry = [];
        foreach ($this->capabilities->list('agent') as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $toolMeta = is_array($item['tool'] ?? null) ? $item['tool'] : [];
            $settings = is_array($item['settings'] ?? null) ? $item['settings'] : [];
            $registry[$code] = [
                ...$item,
                'type' => (string)($toolMeta['type'] ?? 'function'),
                'function' => $toolMeta['function'] ?? null,
                'settings' => Tool::normalizeAgentSettings($settings),
            ];
        }

        $this->registry = $registry;
        $this->booted = true;
    }
}
