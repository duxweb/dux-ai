<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Service\Tool as ToolConfig;
use App\Ai\Service\Tool as ToolService;
use Core\Handlers\ExceptionBusiness;
use Throwable;

final class AgentToolExecutor
{
    /**
     * @param array<string, mixed> $meta
     */
    private readonly string $toolCode;

    /**
     * 仅保留工具固定配置，避免把 capability handler 等闭包带进工具实例导致审批中断无法序列化。
     *
     * @var array<string, mixed>
     */
    private readonly array $meta;

    private readonly int $sessionId;

    private readonly int $agentId;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(string $toolCode, array $meta, int $sessionId, int $agentId)
    {
        $this->toolCode = $toolCode;
        $this->meta = ToolConfig::mergeToolParams($meta, []);
        $this->sessionId = $sessionId;
        $this->agentId = $agentId;
    }

    public function __invoke(mixed ...$args): mixed
    {
        if ($this->toolCode === '') {
            throw new ExceptionBusiness(sprintf('工具 [%s] 配置缺失 code', (string)($this->meta['label'] ?? 'unknown')));
        }

        try {
            return ToolService::execute($this->toolCode, [
                ...$this->meta,
                '__session_id' => $this->sessionId,
                '__agent_id' => $this->agentId,
            ], $args);
        } catch (Throwable $e) {
            return ToolFactory::encodeToolError($e);
        }
    }
}
