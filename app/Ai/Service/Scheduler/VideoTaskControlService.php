<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler;

use App\Ai\Models\AiModel;
use App\Ai\Models\AiScheduler;
use App\Ai\Service\AI;
use App\Ai\Service\Neuron\Provider\Video\VideoTaskProviderInterface;
use App\Ai\Service\Neuron\Video\VideoProvider;
use Core\App;

final class VideoTaskControlService
{
    public static function cancelRemote(AiScheduler $job): void
    {
        if ((string)$job->callback_type !== 'video' || (string)$job->callback_code !== 'poll_task') {
            return;
        }

        $params = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];
        $modelId = (int)($params['model_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? ''));
        if ($modelId <= 0 || $taskId === '') {
            return;
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->with('provider')->find($modelId);
        if (!$model || !$model->provider) {
            return;
        }

        $provider = AI::forVideoModel($model);
        if (!$provider instanceof VideoTaskProviderInterface) {
            return;
        }

        try {
            VideoProvider::make($provider)->cancelTask($taskId);
            App::log('ai_scheduler')->info('ai.scheduler.video.cancel_remote', [
                'id' => (int)$job->id,
                'task_id' => $taskId,
            ]);
        } catch (\Throwable $throwable) {
            App::log('ai_scheduler')->warning('ai.scheduler.video.cancel_remote_failed', [
                'id' => (int)$job->id,
                'task_id' => $taskId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
