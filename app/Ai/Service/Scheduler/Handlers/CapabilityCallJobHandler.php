<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler\Handlers;

use App\Ai\Models\AiAgentSession;
use App\Ai\Models\AiScheduler;
use App\Ai\Service\Agent\MessageStore;
use App\Ai\Service\Capability;
use App\Ai\Service\Scheduler\SchedulerCapabilityContext;
use Core\Handlers\ExceptionBusiness;

final class CapabilityCallJobHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(AiScheduler $job): array
    {
        $capabilityCode = trim((string)$job->callback_code);
        if ($capabilityCode === '') {
            throw new ExceptionBusiness('capability 回调缺少 callback_code');
        }
        $capabilityInput = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];
        $callbackScope = in_array((string)$job->source_type, ['agent', 'flow'], true)
            ? (string)$job->source_type
            : 'agent';

        $meta = Capability::get($capabilityCode);
        if (!$meta) {
            throw new ExceptionBusiness(sprintf('Capability [%s] 未注册', $capabilityCode));
        }
        $types = is_array($meta['types'] ?? null) ? ($meta['types'] ?? []) : [];
        if (!in_array($callbackScope, $types, true)) {
            throw new ExceptionBusiness(sprintf('Capability [%s] 不支持 %s 调度', $capabilityCode, $callbackScope));
        }

        $result = Capability::execute($capabilityCode, $capabilityInput, new SchedulerCapabilityContext($callbackScope));
        if (is_array($result) && array_key_exists('status', $result)) {
            $status = (int)($result['status'] ?? 0);
            if ($status === 0) {
                throw new ExceptionBusiness((string)($result['message'] ?? $result['content'] ?? '调度任务执行失败'));
            }
        }
        $this->writebackBySource($job, $capabilityCode, $capabilityInput, $result);

        return [
            'callback_type' => 'capability',
            'capability' => $capabilityCode,
            'input' => $capabilityInput,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function writebackBySource(AiScheduler $job, string $capabilityCode, array $input, mixed $result): void
    {
        $sourceType = trim((string)$job->source_type);
        if ($sourceType === 'agent') {
            $this->writebackAgentSession($job, $capabilityCode, $input, $result);
            return;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function writebackAgentSession(AiScheduler $job, string $capabilityCode, array $input, mixed $result): void
    {
        $sourceId = (int)($job->source_id ?? 0);
        if ($sourceId <= 0) {
            return;
        }

        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->find($sourceId);
        if (!$session) {
            return;
        }

        $summary = '';
        if (is_array($result)) {
            $summary = trim((string)($result['summary'] ?? $result['message'] ?? ''));
        }
        if ($summary === '') {
            $summary = sprintf('异步任务 %s 执行完成', $capabilityCode);
        }

        MessageStore::appendMessage(
            (int)$session->agent_id,
            (int)$session->id,
            'assistant',
            $summary,
            [
                'async' => [
                    'schedule_id' => (int)$job->id,
                    'capability' => $capabilityCode,
                    'input' => $input,
                ],
                'result' => is_array($result) ? $result : ['value' => $result],
            ],
        );
    }
}
