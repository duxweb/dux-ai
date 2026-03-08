<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler;

use App\Ai\Models\AiScheduler;
use App\Ai\Service\Scheduler\Handlers\CapabilityCallJobHandler;
use App\Ai\Service\Scheduler\Handlers\FlowResumePollJobHandler;
use App\Ai\Service\Scheduler\Handlers\VideoTaskPollJobHandler;
use Core\Handlers\ExceptionBusiness;

final class AiSchedulerExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function executeById(int $id): array
    {
        /** @var AiScheduler|null $job */
        $job = AiScheduler::query()->where('id', $id)->first();
        if (!$job) {
            throw new ExceptionBusiness(sprintf('计划任务 [%d] 不存在', $id));
        }
        if ((string)$job->status !== 'running') {
            return [
                'status' => 'ignored',
                'message' => '任务非 running 状态，忽略执行',
            ];
        }

        try {
            $result = $this->dispatch($job);
            if ((string)($result['status'] ?? '') === 'pending') {
                return [
                    'status' => 'pending',
                    'result' => $result,
                ];
            }
            if ((string)($result['status'] ?? '') === 'canceled') {
                return [
                    'status' => 'canceled',
                    'result' => $result,
                ];
            }
            AiSchedulerService::markSuccess($job, $result);

            return [
                'status' => 'success',
                'result' => $result,
            ];
        } catch (\Throwable $throwable) {
            $retry = AiSchedulerService::failWithRetry($job, $throwable->getMessage());

            return [
                'status' => $retry['status'],
                'attempt' => $retry['attempt'],
                'next_execute_at' => $retry['next_execute_at'],
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(AiScheduler $job): array
    {
        $callbackType = trim((string)$job->callback_type);
        $callbackCode = trim((string)$job->callback_code);

        if ($callbackType === '' || $callbackCode === '') {
            throw new ExceptionBusiness('调度任务缺少 callback_type 或 callback_code');
        }

        return match ($callbackType) {
            'capability' => (new CapabilityCallJobHandler())->handle($job),
            'video' => $this->dispatchVideoCallback($job, $callbackCode),
            'flow' => $this->dispatchFlowCallback($job, $callbackCode),
            default => throw new ExceptionBusiness(sprintf('不支持的回调类型 [%s]', $callbackType)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchFlowCallback(AiScheduler $job, string $callbackCode): array
    {
        return match ($callbackCode) {
            'resume_poll' => (new FlowResumePollJobHandler())->handle($job),
            default => throw new ExceptionBusiness(sprintf('不支持的流程回调 [%s]', $callbackCode)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchVideoCallback(AiScheduler $job, string $callbackCode): array
    {
        return match ($callbackCode) {
            'poll_task' => (new VideoTaskPollJobHandler())->handle($job),
            default => throw new ExceptionBusiness(sprintf('不支持的视频回调 [%s]', $callbackCode)),
        };
    }
}
