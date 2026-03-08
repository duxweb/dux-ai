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

final class VideoTaskQueryCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        if (!$context instanceof AgentCapabilityContextInterface) {
            throw new ExceptionBusiness('当前会话无效，无法查询视频任务');
        }
        $sessionId = (int)$context->sessionId();
        if ($sessionId <= 0) {
            throw new ExceptionBusiness('当前会话无效，无法查询视频任务');
        }

        $taskId = trim((string)($input['task_id'] ?? ''));
        $job = $this->resolveSchedulerJob($sessionId, $taskId);
        if (!$job) {
            return [
                'status' => 1,
                'message' => 'ok',
                'data' => [
                    'task_id' => null,
                    'provider_status' => 'not_found',
                ],
                'summary' => '当前会话暂无进行中的视频任务',
            ];
        }

        $params = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];
        $modelId = (int)($params['model_id'] ?? 0);
        $taskId = trim((string)($params['task_id'] ?? $taskId));
        if ($modelId <= 0 || $taskId === '') {
            throw new ExceptionBusiness('视频任务参数不完整');
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->with('provider')->find($modelId);
        if (!$model || !$model->provider) {
            throw new ExceptionBusiness('视频模型不存在或未绑定服务商');
        }

        $provider = AI::forVideoModel($model);
        if (!$provider instanceof VideoTaskProviderInterface) {
            throw new ExceptionBusiness('视频驱动不支持任务查询');
        }

        $message = VideoProvider::make($provider)->queryTask($taskId);
        $meta = is_array($message->getMetadata('video_task')) ? ($message->getMetadata('video_task') ?? []) : [];
        $status = trim((string)($meta['status'] ?? ''));
        $videoUrl = trim((string)(is_array($meta['videos'] ?? null) ? (($meta['videos'][0] ?? '') ?: '') : ''));
        $lastFrameUrl = trim((string)($meta['last_frame_url'] ?? ''));

        return [
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'task_id' => $taskId,
                'provider_status' => $status !== '' ? $status : null,
                'video_url' => $videoUrl !== '' ? $videoUrl : null,
                'last_frame_url' => $lastFrameUrl !== '' ? $lastFrameUrl : null,
                'schedule_id' => (int)$job->id,
            ],
            'summary' => $status !== ''
                ? sprintf('视频任务 %s 当前状态：%s', $taskId, $status)
                : sprintf('视频任务 %s 状态已更新', $taskId),
        ];
    }

    private function resolveSchedulerJob(int $sessionId, string $taskId): ?AiScheduler
    {
        $query = AiScheduler::query()
            ->where('source_type', 'agent')
            ->where('source_id', $sessionId)
            ->where('callback_type', 'video')
            ->where('callback_code', 'poll_task')
            ->whereIn('status', ['pending', 'running', 'retrying'])
            ->orderByDesc('id');

        /** @var AiScheduler|null $job */
        $job = $query->first();
        if ($taskId === '') {
            return $job;
        }

        /** @var \Illuminate\Support\Collection<int, AiScheduler> $jobs */
        $jobs = $query->get();
        foreach ($jobs as $item) {
            $params = is_array($item->callback_params ?? null) ? ($item->callback_params ?? []) : [];
            if (trim((string)($params['task_id'] ?? '')) === $taskId) {
                return $item;
            }
        }

        return null;
    }
}
