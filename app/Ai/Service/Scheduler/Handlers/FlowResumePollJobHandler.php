<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler\Handlers;

use App\Ai\Models\AiScheduler;
use App\Ai\Service\FlowPersistence\FlowResumeService;
use Carbon\Carbon;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

final class FlowResumePollJobHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(AiScheduler $job): array
    {
        $payload = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];

        $workflowId = trim((string)($payload['workflow_id'] ?? ''));
        $statusUrl = trim((string)($payload['status_url'] ?? ''));
        $taskId = trim((string)($payload['task_id'] ?? ''));

        if ($workflowId === '' || $statusUrl === '' || $taskId === '') {
            throw new ExceptionBusiness('flow.resume_poll 回调参数不完整');
        }

        $timeoutMinutes = max(1, (int)($payload['timeout_minutes'] ?? 30));
        $suspendedAt = Carbon::parse((string)($payload['suspended_at'] ?? Carbon::now()->toDateTimeString()));
        if ($suspendedAt->addMinutes($timeoutMinutes)->lessThanOrEqualTo(Carbon::now())) {
            throw new ExceptionBusiness('异步任务等待超时');
        }

        $statusPayload = $this->queryTaskStatus($payload);
        $statusValue = strtolower(trim((string)$this->readByPath($statusPayload, (string)($payload['response_path'] ?? 'data.status'))));

        $completedValues = array_map('strtolower', array_map('strval', is_array($payload['completed_values'] ?? null) ? ($payload['completed_values'] ?? []) : ['succeeded', 'completed', 'success']));
        $failedValues = array_map('strtolower', array_map('strval', is_array($payload['failed_values'] ?? null) ? ($payload['failed_values'] ?? []) : ['failed', 'error', 'canceled']));

        if (in_array($statusValue, $failedValues, true)) {
            throw new ExceptionBusiness(sprintf('异步任务执行失败：%s', $statusValue));
        }

        if (!in_array($statusValue, $completedValues, true)) {
            // 未完成，按节点设置固定轮询间隔继续调度
            $next = Carbon::now()->addMinutes(max(1, (int)($payload['poll_interval_minutes'] ?? 1)));
            $job->status = 'retrying';
            $job->execute_at = $next;
            $job->attempts = max(0, (int)$job->attempts);
            $job->locked_at = null;
            $job->locked_by = null;
            $job->last_error = null;
            $job->result = [
                'status' => 'pending',
                'provider_status' => $statusValue,
                'next_execute_at' => $next->toDateTimeString(),
                'raw_output' => $statusPayload,
            ];
            $job->save();

            return [
                'status' => 'pending',
                'provider_status' => $statusValue,
                'next_execute_at' => $next->toDateTimeString(),
            ];
        }

        $resumeNodeOutput = $this->buildResumeNodeOutput($payload, $statusPayload, $statusValue);
        $resumeResult = FlowResumeService::resumeByWorkflowId([
            ...$payload,
            'schedule_id' => (int)$job->id,
            'resume_status' => 'completed',
            'resume_node_output' => $resumeNodeOutput,
            'resume_node_message' => (string)($resumeNodeOutput['summary'] ?? '异步任务已完成'),
        ]);

        return [
            'callback_type' => 'flow',
            'callback_code' => 'resume_poll',
            'status' => 'resumed',
            'provider_status' => $statusValue,
            'resume_result' => $resumeResult,
            'raw_output' => $statusPayload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $statusPayload
     * @return array<string, mixed>
     */
    private function buildResumeNodeOutput(array $payload, array $statusPayload, string $statusValue): array
    {
        $taskId = trim((string)($payload['task_id'] ?? ''));
        $capability = trim((string)($payload['capability'] ?? ''));
        if ($capability !== 'video_generate') {
            return [
                'task_id' => $taskId !== '' ? $taskId : null,
                'provider_status' => $statusValue !== '' ? $statusValue : null,
            ];
        }

        $videos = $this->extractVideos($statusPayload);
        return [
            'summary' => $taskId !== '' ? sprintf('视频任务已完成（%s）', $taskId) : '视频任务已完成',
            'task_id' => $taskId !== '' ? $taskId : null,
            'provider' => trim((string)($payload['provider'] ?? '')),
            'provider_status' => $statusValue !== '' ? $statusValue : null,
            'video_url' => (string)($videos[0] ?? ''),
            'videos' => $videos,
        ];
    }

    /**
     * @param array<string, mixed> $statusPayload
     * @return array<int, string>
     */
    private function extractVideos(array $statusPayload): array
    {
        $urls = [];

        $content = is_array($statusPayload['content'] ?? null) ? ($statusPayload['content'] ?? []) : [];
        $single = trim((string)($content['video_url'] ?? ''));
        if ($single !== '') {
            $urls[] = $single;
        }
        if (is_array($content['videos'] ?? null)) {
            foreach (($content['videos'] ?? []) as $item) {
                $url = trim((string)$item);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $data = is_array($statusPayload['data'] ?? null) ? ($statusPayload['data'] ?? []) : [];
        $dataContent = is_array($data['content'] ?? null) ? ($data['content'] ?? []) : [];
        $single = trim((string)($dataContent['video_url'] ?? ''));
        if ($single !== '') {
            $urls[] = $single;
        }
        if (is_array($dataContent['videos'] ?? null)) {
            foreach (($dataContent['videos'] ?? []) as $item) {
                $url = trim((string)$item);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function queryTaskStatus(array $payload): array
    {
        $statusUrl = trim((string)($payload['status_url'] ?? ''));
        $statusBaseUrl = trim((string)($payload['status_base_url'] ?? ''));
        if ($statusUrl !== '' && !str_starts_with($statusUrl, 'http://') && !str_starts_with($statusUrl, 'https://') && $statusBaseUrl !== '') {
            $statusUrl = rtrim($statusBaseUrl, '/') . '/' . ltrim($statusUrl, '/');
        }
        $timeoutSeconds = max(5, (int)($payload['timeout_seconds'] ?? 30));
        $method = strtoupper(trim((string)($payload['status_method'] ?? 'GET')));
        $headers = is_array($payload['status_headers'] ?? null) ? ($payload['status_headers'] ?? []) : [];

        if ($method !== 'POST') {
            $method = 'GET';
        }

        $query = is_array($payload['status_query'] ?? null) ? ($payload['status_query'] ?? []) : [];
        if (!isset($query['task_id'])) {
            $query['task_id'] = (string)($payload['task_id'] ?? '');
        }

        $client = new Client([
            'timeout' => $timeoutSeconds,
            'http_errors' => false,
        ]);

        try {
            $response = $client->request($method, $statusUrl, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::QUERY => $method === 'GET' ? $query : [],
                RequestOptions::JSON => $method === 'POST'
                    ? (is_array($payload['status_body'] ?? null) ? ($payload['status_body'] ?? []) : ['task_id' => (string)($payload['task_id'] ?? '')])
                    : null,
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('查询异步任务状态失败：' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        $json = is_array($decoded) ? $decoded : ['raw' => $body];
        $json['_http_status'] = $status;
        if ($status < 200 || $status >= 300) {
            $msg = (string)($json['error']['message'] ?? $json['message'] ?? '');
            throw new ExceptionBusiness($msg !== '' ? $msg : sprintf('查询状态失败（HTTP %d）', $status));
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readByPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }

        return $current;
    }
}
