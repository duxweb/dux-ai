<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\AgentCapabilityContextInterface;
use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiScheduler;
use App\Ai\Service\AI;
use App\Ai\Service\Neuron\Provider\Video\VideoTaskProviderInterface;
use App\Ai\Service\Neuron\Video\VideoProvider;
use Core\Handlers\ExceptionBusiness;

use function is_array;
use function trim;

final class VideoTaskCancelCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        if (!$context instanceof AgentCapabilityContextInterface) {
            throw new ExceptionBusiness('当前会话无效，无法取消视频任务');
        }
        $sessionId = (int)$context->sessionId();
        if ($sessionId <= 0) {
            throw new ExceptionBusiness('当前会话无效，无法取消视频任务');
        }

        $taskId = trim((string)($input['task_id'] ?? ''));
        $jobs = $this->resolveSchedulerJobs($sessionId, $taskId);
        if ($jobs === []) {
            return [
                'status' => 1,
                'message' => 'ok',
                'data' => [
                    'task_id' => $taskId !== '' ? $taskId : null,
                    'canceled' => false,
                    'canceled_count' => 0,
                ],
                'summary' => $taskId !== ''
                    ? sprintf('未找到任务 %s 的可取消调度记录', $taskId)
                    : '当前会话暂无可取消的视频任务',
            ];
        }

        $first = $jobs[0];
        $params = is_array($first->callback_params ?? null) ? ($first->callback_params ?? []) : [];
        $taskId = trim((string)($params['task_id'] ?? $taskId));
        $modelId = (int)($params['model_id'] ?? 0);

        $remoteCanceled = false;
        $remoteError = '';
        if ($taskId !== '' && $modelId > 0) {
            try {
                /** @var AiModel|null $model */
                $model = AiModel::query()->with('provider')->find($modelId);
                if ($model && $model->provider) {
                    $provider = AI::forVideoModel($model);
                    if ($provider instanceof VideoTaskProviderInterface) {
                        VideoProvider::make($provider)->cancelTask($taskId);
                        $remoteCanceled = true;
                    }
                }
            } catch (\Throwable $throwable) {
                $remoteError = $throwable->getMessage();
            }
        }

        $count = 0;
        foreach ($jobs as $job) {
            $job->status = 'canceled';
            $job->locked_at = null;
            $job->locked_by = null;
            $job->save();
            $count++;
        }

        return [
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'task_id' => $taskId !== '' ? $taskId : null,
                'canceled' => $count > 0,
                'canceled_count' => $count,
                'remote_canceled' => $remoteCanceled,
                'remote_error' => $remoteError !== '' ? $remoteError : null,
            ],
            'summary' => $taskId !== ''
                ? sprintf('已取消视频任务 %s（本地调度 %d 条）', $taskId, $count)
                : sprintf('已取消当前会话视频任务（本地调度 %d 条）', $count),
        ];
    }

    /**
     * @return array<int, AiScheduler>
     */
    private function resolveSchedulerJobs(int $sessionId, string $taskId): array
    {
        /** @var \Illuminate\Support\Collection<int, AiScheduler> $rows */
        $rows = AiScheduler::query()
            ->where('source_type', 'agent')
            ->where('source_id', $sessionId)
            ->where('callback_type', 'video')
            ->where('callback_code', 'poll_task')
            ->whereIn('status', ['pending', 'running', 'retrying'])
            ->orderByDesc('id')
            ->get();

        $items = $rows->all();
        if ($taskId === '') {
            return $items === [] ? [] : [$items[0]];
        }

        $filtered = [];
        foreach ($items as $item) {
            $params = is_array($item->callback_params ?? null) ? ($item->callback_params ?? []) : [];
            if (trim((string)($params['task_id'] ?? '')) === $taskId) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }
}
