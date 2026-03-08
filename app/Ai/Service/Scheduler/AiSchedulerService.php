<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler;

use App\Ai\Models\AiScheduler;
use Carbon\Carbon;
use Core\App;
use Core\Handlers\ExceptionBusiness;

final class AiSchedulerService
{
    private const RUNNING_STALE_SECONDS = 180;

    /**
     * @param array<string, mixed> $input
     */
    public static function createJob(array $input): AiScheduler
    {
        $callbackType = trim((string)($input['callback_type'] ?? ''));
        if ($callbackType === '') {
            throw new ExceptionBusiness('计划任务缺少 callback_type');
        }
        $callbackCode = trim((string)($input['callback_code'] ?? ''));
        if ($callbackCode === '') {
            throw new ExceptionBusiness('计划任务缺少 callback_code');
        }
        $dedupeKey = trim((string)($input['dedupe_key'] ?? ''));
        if ($dedupeKey === '') {
            throw new ExceptionBusiness('计划任务缺少 dedupe_key');
        }

        $executeAt = $input['execute_at'] ?? Carbon::now();
        if (!$executeAt instanceof Carbon) {
            $executeAt = Carbon::parse((string)$executeAt);
        }

        /** @var AiScheduler $record */
        $record = AiScheduler::query()->updateOrCreate([
            'dedupe_key' => $dedupeKey,
        ], [
            'callback_type' => $callbackType,
            'callback_code' => $callbackCode,
            'callback_action' => trim((string)($input['callback_action'] ?? '')) ?: null,
            'workflow_id' => trim((string)($input['workflow_id'] ?? '')) ?: null,
            'status' => (string)($input['status'] ?? 'pending'),
            'execute_at' => $executeAt,
            'attempts' => (int)($input['attempts'] ?? 0),
            'max_attempts' => max(1, (int)($input['max_attempts'] ?? 3)),
            'callback_params' => is_array($input['callback_params'] ?? null) ? ($input['callback_params'] ?? []) : [],
            'result' => is_array($input['result'] ?? null) ? ($input['result'] ?? []) : null,
            'last_error' => $input['last_error'] ?? null,
            'source_type' => (string)($input['source_type'] ?? 'api'),
            'source_id' => isset($input['source_id']) ? (int)$input['source_id'] : null,
            'locked_at' => null,
            'locked_by' => null,
        ]);

        return $record;
    }

    public static function dispatchDueJobs(int $limit = 100): int
    {
        $now = Carbon::now();
        $staleAt = $now->copy()->subSeconds(self::RUNNING_STALE_SECONDS);

        // 兜底恢复：避免任务因进程中断或投递异常长期卡在 running。
        AiScheduler::query()
            ->where('status', 'running')
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $staleAt)
            ->update([
                'status' => 'retrying',
                'locked_at' => null,
                'locked_by' => null,
                'updated_at' => $now,
            ]);

        $jobs = AiScheduler::query()
            ->whereIn('status', ['pending', 'retrying'])
            ->whereNotNull('execute_at')
            ->where('execute_at', '<=', $now)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $count = 0;
        $lockBy = self::lockIdentity();

        /** @var AiScheduler $job */
        foreach ($jobs as $job) {
            $claimed = AiScheduler::query()
                ->where('id', $job->id)
                ->whereIn('status', ['pending', 'retrying'])
                ->update([
                    'status' => 'running',
                    'locked_at' => $now,
                    'locked_by' => $lockBy,
                    'updated_at' => $now,
                ]);

            if ($claimed !== 1) {
                continue;
            }

            App::log('ai_scheduler')->info('ai.scheduler.claimed', [
                'id' => (int)$job->id,
                'time' => $now->toDateTimeString(),
            ]);

            try {
                App::log('ai_scheduler')->info('ai.scheduler.execute_start', [
                    'id' => (int)$job->id,
                    'time' => $now->toDateTimeString(),
                ]);
                $result = (new AiSchedulerExecutor())->executeById((int)$job->id);
                App::log('ai_scheduler')->info('ai.scheduler.execute_done', [
                    'id' => (int)$job->id,
                    'result' => $result,
                    'time' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $exception) {
                AiScheduler::query()
                    ->where('id', (int)$job->id)
                    ->where('status', 'running')
                    ->update([
                        'status' => 'retrying',
                        'last_error' => $exception->getMessage(),
                        'locked_at' => null,
                        'locked_by' => null,
                        'updated_at' => $now,
                    ]);
                App::log('ai_scheduler')->error('ai.scheduler.execute_failed', [
                    'id' => (int)$job->id,
                    'error' => $exception->getMessage(),
                    'time' => $now->toDateTimeString(),
                ]);
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array{status:string,attempt:int,next_execute_at:?string}
     */
    public static function failWithRetry(AiScheduler $job, string $error): array
    {
        $attempt = (int)$job->attempts + 1;
        $max = max(1, (int)$job->max_attempts);

        if ($attempt >= $max) {
            $job->status = 'failed';
            $job->attempts = $attempt;
            $job->last_error = $error;
            $job->locked_at = null;
            $job->locked_by = null;
            $job->save();

            return [
                'status' => 'failed',
                'attempt' => $attempt,
                'next_execute_at' => null,
            ];
        }

        $next = BackoffPolicy::nextExecuteAt($attempt);
        $job->status = 'retrying';
        $job->attempts = $attempt;
        $job->execute_at = $next;
        $job->last_error = $error;
        $job->locked_at = null;
        $job->locked_by = null;
        $job->save();

        return [
            'status' => 'retrying',
            'attempt' => $attempt,
            'next_execute_at' => $next->toDateTimeString(),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function markSuccess(AiScheduler $job, array $result = []): void
    {
        $job->status = 'success';
        $job->result = $result;
        $job->last_error = null;
        $job->locked_at = null;
        $job->locked_by = null;
        $job->save();
    }

    public static function cancel(int $id): bool
    {
        $row = AiScheduler::query()->where('id', $id)->whereIn('status', ['pending', 'retrying', 'running'])->first();
        if (!$row instanceof AiScheduler) {
            return false;
        }

        VideoTaskControlService::cancelRemote($row);

        $row->status = 'canceled';
        $row->locked_at = null;
        $row->locked_by = null;
        $row->save();

        return true;
    }

    public static function retryNow(int $id): bool
    {
        $row = AiScheduler::query()->where('id', $id)->first();
        if (!$row instanceof AiScheduler) {
            return false;
        }

        $row->status = 'pending';
        $row->execute_at = Carbon::now();
        $row->locked_at = null;
        $row->locked_by = null;
        $row->save();

        return true;
    }

    private static function lockIdentity(): string
    {
        $host = gethostname() ?: 'host';
        return sprintf('%s:%d', $host, getmypid());
    }
}
