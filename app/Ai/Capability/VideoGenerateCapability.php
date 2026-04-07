<?php

declare(strict_types=1);

namespace App\Ai\Capability;

use App\Ai\Interface\AgentCapabilityContextInterface;
use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AI;
use App\Ai\Service\AiConfig;
use App\Ai\Service\Neuron\Provider\Video\VideoTaskProviderInterface;
use App\Ai\Service\Neuron\Video\VideoProvider;
use App\Ai\Service\Scheduler\AiSchedulerService;
use Carbon\Carbon;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\Chat\Messages\Message;
use function is_array;
use function json_encode;
use function ltrim;
use function md5;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trim;

final class VideoGenerateCapability
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function __invoke(array $input, CapabilityContextInterface $context): array
    {
        $modelId = (int)($input['model_id'] ?? 0);
        if ($modelId <= 0) {
            $modelId = (int)AiConfig::getValue('default_video_model_id', 0);
        }
        if ($modelId <= 0) {
            throw new ExceptionBusiness('视频工具缺少 model_id 配置，请先在 AI 系统设置中设置默认视频模型');
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->with('provider')->find($modelId);
        if (!$model) {
            throw new ExceptionBusiness(sprintf('视频模型 [%d] 不存在', $modelId));
        }
        if ((string)($model->type ?? '') !== AiModel::TYPE_VIDEO) {
            throw new ExceptionBusiness(sprintf('模型 [%s] 不是 Video 类型', (string)($model->name ?? $modelId)));
        }
        if (!(bool)$model->active) {
            throw new ExceptionBusiness(sprintf('模型 [%s] 已禁用', (string)($model->name ?? $modelId)));
        }
        if (!$model->provider instanceof AiProvider) {
            throw new ExceptionBusiness('视频模型未绑定服务商');
        }
        if (!(bool)$model->provider->active) {
            throw new ExceptionBusiness(sprintf('服务商 [%s] 已禁用', (string)($model->provider->name ?? '')));
        }

        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '') {
            throw new ExceptionBusiness('请输入视频生成提示词（prompt）');
        }

        $pollInterval = max(1, (int)($input['poll_interval_seconds'] ?? ((int)($input['poll_interval_minutes'] ?? 1) * 60)));
        $firstPollDelay = max(0, (int)($input['delay_seconds'] ?? ((int)($input['delay_minutes'] ?? 0) * 60)));
        $timeoutMinutes = max(1, (int)($input['timeout_minutes'] ?? 30));

        $payload = [];
        foreach (['image_url', 'resolution', 'ratio'] as $field) {
            $value = trim((string)($input[$field] ?? ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }
        foreach (['duration', 'frames', 'seed', 'execution_expires_after'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = (int)$input[$field];
            if ($value !== 0 || (string)$input[$field] === '0') {
                $payload[$field] = $value;
            }
        }
        foreach (['camera_fixed', 'watermark', 'return_last_frame', 'generate_audio', 'draft'] as $field) {
            if (array_key_exists($field, $input)) {
                $payload[$field] = (bool)$input[$field];
            }
        }

        $provider = AI::forVideoModel($model, $payload);
        if (!$provider instanceof VideoTaskProviderInterface) {
            throw new ExceptionBusiness('视频驱动不支持 createTask/queryTask');
        }

        $message = VideoProvider::make($provider)->createTask($prompt);
        $taskMeta = $this->extractTaskMeta($message);

        $taskId = trim((string)($taskMeta['task_id'] ?? ''));
        if ($taskId === '') {
            throw new ExceptionBusiness('视频任务提交成功但未返回 task_id');
        }

        $statusUrl = trim((string)($taskMeta['status_url'] ?? ''));
        $submittedAt = Carbon::now();

        if ($context->scope() === 'flow') {
            return $this->buildFlowSuspendResult($model->provider, $taskMeta, $taskId, $statusUrl, $pollInterval, $timeoutMinutes, $submittedAt);
        }

        return $this->buildAgentScheduleResult($context, $model, $taskId, $pollInterval, $firstPollDelay, $timeoutMinutes, $submittedAt, $taskMeta);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTaskMeta(Message $message): array
    {
        $meta = $message->getMetadata('video_task');
        return is_array($meta) ? $meta : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFlowSuspendResult(
        AiProvider $provider,
        array $taskMeta,
        string $taskId,
        string $statusUrl,
        int $pollInterval,
        int $timeoutMinutes,
        Carbon $submittedAt,
    ): array {
        $statusMethod = strtoupper(trim((string)($taskMeta['status_method'] ?? 'GET')));
        if ($statusMethod !== 'POST') {
            $statusMethod = 'GET';
        }

        $client = $provider->clientConfig();
        $headers = [
            'Authorization' => 'Bearer ' . (string)($client['api_key'] ?? ''),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        foreach ((array)($client['headers'] ?? []) as $name => $value) {
            $key = trim((string)$name);
            if ($key !== '') {
                $headers[$key] = (string)$value;
            }
        }

        $completedValues = is_array($taskMeta['completed_values'] ?? null)
            ? ($taskMeta['completed_values'] ?? [])
            : ['succeeded', 'completed', 'success'];
        $failedValues = is_array($taskMeta['failed_values'] ?? null)
            ? ($taskMeta['failed_values'] ?? [])
            : ['failed', 'error', 'canceled', 'cancelled', 'timeout'];
        $statusPath = trim((string)($taskMeta['status_path'] ?? 'data.status'));
        $statusUrl = $this->normalizeStatusUrl($statusUrl, $provider);

        return [
            'status' => 2,
            'message' => '视频任务已提交，流程已挂起等待完成',
            'data' => [
                'summary' => sprintf('视频任务已提交（%s）', $taskId),
                'task_id' => $taskId,
                'provider' => (string)($provider->code ?? ''),
                'submitted_at' => $submittedAt->toDateTimeString(),
                'status_url' => $statusUrl,
            ],
            'meta' => [
                'suspend' => [
                    'capability' => 'video_generate',
                    'task_id' => $taskId,
                    'provider' => (string)($provider->code ?? ''),
                    'status_url' => $statusUrl,
                    'status_base_url' => trim((string)($client['base_url'] ?? '')),
                    'status_method' => $statusMethod,
                    'status_headers' => $headers,
                    'status_query' => [],
                    'status_body' => ['task_id' => $taskId],
                    'response_path' => $statusPath !== '' ? $statusPath : 'data.status',
                    'completed_values' => $completedValues,
                    'failed_values' => $failedValues,
                    'poll_interval_seconds' => $pollInterval,
                    'timeout_minutes' => $timeoutMinutes,
                    'suspended_at' => $submittedAt->toDateTimeString(),
                ],
            ],
        ];
    }

    private function normalizeStatusUrl(string $statusUrl, AiProvider $provider): string
    {
        $statusUrl = trim($statusUrl);
        if ($statusUrl === '') {
            return '';
        }
        if (str_starts_with($statusUrl, 'http://') || str_starts_with($statusUrl, 'https://')) {
            return $statusUrl;
        }

        $client = $provider->clientConfig();
        $baseUrl = trim((string)($client['base_url'] ?? ''));
        if ($baseUrl === '') {
            return $statusUrl;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($statusUrl, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAgentScheduleResult(
        CapabilityContextInterface $context,
        AiModel $model,
        string $taskId,
        int $pollInterval,
        int $firstPollDelay,
        int $timeoutMinutes,
        Carbon $submittedAt,
        array $taskMeta,
    ): array {
        if (!$context instanceof AgentCapabilityContextInterface) {
            throw new ExceptionBusiness('当前会话上下文无效，无法创建视频轮询任务');
        }
        $sessionId = (int)$context->sessionId();
        $agentId = (int)$context->agentId();
        if ($sessionId <= 0 || $agentId <= 0) {
            throw new ExceptionBusiness('当前会话上下文无效，无法创建视频轮询任务');
        }

        $executeAt = Carbon::now()->addSeconds($firstPollDelay > 0 ? $firstPollDelay : $pollInterval);
        $dedupeKey = sprintf(
            'video:poll:%d:%s:%s',
            $sessionId,
            $taskId,
            md5((string)json_encode([$model->id, $executeAt->toDateTimeString()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        );

        $job = AiSchedulerService::createJob([
            'callback_type' => 'video',
            'callback_code' => 'poll_task',
            'callback_name' => '视频任务轮询',
            'callback_action' => 'poll',
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
            'execute_at' => $executeAt,
            'max_attempts' => 3,
            'callback_params' => [
                'model_id' => (int)$model->id,
                'task_id' => $taskId,
                'poll_interval_seconds' => $pollInterval,
                'delay_seconds' => $firstPollDelay,
                'timeout_minutes' => $timeoutMinutes,
                'submitted_at' => $submittedAt->toDateTimeString(),
                'agent_id' => $agentId,
                'session_id' => $sessionId,
                'status_path' => (string)($taskMeta['status_path'] ?? 'status'),
                'completed_values' => is_array($taskMeta['completed_values'] ?? null) ? ($taskMeta['completed_values'] ?? []) : ['succeeded', 'completed', 'success'],
                'failed_values' => is_array($taskMeta['failed_values'] ?? null) ? ($taskMeta['failed_values'] ?? []) : ['failed', 'error', 'canceled', 'cancelled', 'timeout'],
            ],
            'source_type' => 'agent',
            'source_id' => $sessionId,
        ]);

        return [
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'mode' => 'scheduled',
                'schedule_id' => (int)$job->id,
                'task_id' => $taskId,
                'provider' => (string)($model->provider->code ?? ''),
                'submitted_at' => $submittedAt->toDateTimeString(),
                'next_execute_at' => $executeAt->toDateTimeString(),
            ],
            'summary' => '视频任务已提交，稍后查询生成结果',
        ];
    }
}
