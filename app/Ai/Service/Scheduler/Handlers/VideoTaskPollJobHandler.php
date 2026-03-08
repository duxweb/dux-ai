<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler\Handlers;

use App\Ai\Models\AiAgentSession;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiScheduler;
use App\Ai\Service\AI;
use App\Ai\Service\Agent\MessageStore;
use App\Ai\Service\Neuron\Provider\Video\VideoTaskProviderInterface;
use App\Ai\Service\Neuron\Video\VideoProvider;
use App\System\Service\Storage as StorageService;
use App\System\Service\Upload as UploadService;
use Carbon\Carbon;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;
use function str_starts_with;
use function trim;
use function uniqid;

final class VideoTaskPollJobHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(AiScheduler $job): array
    {
        $payload = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];

        $modelId = (int)($payload['model_id'] ?? 0);
        $taskId = trim((string)($payload['task_id'] ?? ''));
        if ($modelId <= 0 || $taskId === '') {
            throw new ExceptionBusiness('video.poll_task 回调参数不完整');
        }

        /** @var AiModel|null $model */
        $model = AiModel::query()->with('provider')->find($modelId);
        if (!$model || !$model->provider) {
            throw new ExceptionBusiness('视频轮询模型不存在或未绑定服务商');
        }

        $timeoutMinutes = max(1, (int)($payload['timeout_minutes'] ?? 30));
        $submittedAt = Carbon::parse((string)($payload['submitted_at'] ?? Carbon::now()->toDateTimeString()));
        if ($submittedAt->copy()->addMinutes($timeoutMinutes)->lessThanOrEqualTo(Carbon::now())) {
            throw new ExceptionBusiness('视频任务等待超时');
        }

        $provider = AI::forVideoModel($model);
        if (!$provider instanceof VideoTaskProviderInterface) {
            throw new ExceptionBusiness('视频驱动不支持轮询查询');
        }
        $message = VideoProvider::make($provider)->queryTask($taskId);
        $taskMeta = is_array($message->getMetadata('video_task')) ? ($message->getMetadata('video_task') ?? []) : [];

        $status = strtolower(trim((string)($taskMeta['status'] ?? '')));
        $completedValues = array_values(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string)$item)),
            is_array($payload['completed_values'] ?? null) ? ($payload['completed_values'] ?? []) : ['succeeded', 'completed', 'success'],
        )));
        $failedValues = array_values(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string)$item)),
            is_array($payload['failed_values'] ?? null) ? ($payload['failed_values'] ?? []) : ['failed', 'error', 'canceled', 'cancelled', 'timeout'],
        )));

        $isCompleted = (bool)($taskMeta['completed'] ?? false) || ($status !== '' && in_array($status, $completedValues, true));
        $isFailed = (bool)($taskMeta['failed'] ?? false) || ($status !== '' && in_array($status, $failedValues, true));

        if (in_array($status, ['canceled', 'cancelled'], true)) {
            $job->status = 'canceled';
            $job->locked_at = null;
            $job->locked_by = null;
            $job->last_error = null;
            $job->result = [
                'status' => 'canceled',
                'provider_status' => $status,
            ];
            $job->save();

            return [
                'status' => 'canceled',
                'provider_status' => $status,
            ];
        }

        if ($isFailed) {
            throw new ExceptionBusiness(sprintf('视频任务执行失败：%s', $status !== '' ? $status : 'failed'));
        }

        if (!$isCompleted) {
            $next = Carbon::now()->addMinutes(max(1, (int)($payload['poll_interval_minutes'] ?? 1)));
            $job->status = 'retrying';
            $job->execute_at = $next;
            $job->attempts = max(0, (int)$job->attempts);
            $job->locked_at = null;
            $job->locked_by = null;
            $job->last_error = null;
            $job->result = [
                'status' => 'pending',
                'provider_status' => $status,
                'next_execute_at' => $next->toDateTimeString(),
            ];
            $job->save();

            return [
                'status' => 'pending',
                'provider_status' => $status,
                'next_execute_at' => $next->toDateTimeString(),
            ];
        }

        $videoUrls = $this->extractVideoUrls($message, $taskMeta);
        if ($videoUrls === []) {
            throw new ExceptionBusiness('视频任务已完成但未返回可用视频');
        }

        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $storageName = trim((string)($options['media_storage_name'] ?? ''));
        $storedVideos = [];
        foreach ($videoUrls as $url) {
            $stored = $this->downloadAndStoreVideo($url, $storageName !== '' ? $storageName : null);
            if (trim((string)($stored['storage_url'] ?? '')) !== '') {
                $storedVideos[] = $stored;
            }
        }
        $storedVideos = array_values($this->uniqueStoredVideos($storedVideos));
        $storedUrls = array_values(array_filter(array_map(static fn (array $item): string => trim((string)($item['storage_url'] ?? '')), $storedVideos)));
        if ($storedUrls === []) {
            throw new ExceptionBusiness('视频下载转存失败，请稍后重试');
        }

        $this->writebackAgent(
            agentId: (int)($payload['agent_id'] ?? 0),
            sessionId: (int)($payload['session_id'] ?? ($job->source_id ?? 0)),
            modelId: (int)$model->id,
            videoCompress: is_array($options['video_compress'] ?? null) ? ($options['video_compress'] ?? []) : [],
            storedVideos: $storedVideos,
            taskId: $taskId,
            videoUrls: $storedUrls,
            scheduleId: (int)$job->id,
        );
        $this->cleanupLocalVideos($storedVideos);

        return [
            'status' => 'completed',
            'task_id' => $taskId,
            'provider_status' => $status,
            'videos' => $storedUrls,
        ];
    }

    /**
     * @param array<string, mixed> $taskMeta
     * @return array<int, string>
     */
    private function extractVideoUrls(Message $message, array $taskMeta): array
    {
        $urls = [];
        $metaVideos = is_array($taskMeta['videos'] ?? null) ? ($taskMeta['videos'] ?? []) : [];
        foreach ($metaVideos as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $urls[] = $value;
            }
        }

        foreach ($message->getContentBlocks() as $block) {
            if (!$block instanceof VideoContent) {
                continue;
            }
            if ($block->sourceType !== SourceType::URL) {
                continue;
            }
            $url = trim((string)$block->content);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, mixed> $videoCompress
     */
    private function writebackAgent(int $agentId, int $sessionId, int $modelId, array $videoCompress, array $storedVideos, string $taskId, array $videoUrls, int $scheduleId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        if ($agentId <= 0) {
            /** @var AiAgentSession|null $session */
            $session = AiAgentSession::query()->find($sessionId);
            if ($session) {
                $agentId = (int)$session->agent_id;
            }
        }
        if ($agentId <= 0) {
            return;
        }

        $summary = sprintf('视频任务已完成（%s）', $taskId);
        $content = $summary . "\n" . (string)($videoUrls[0] ?? '');

        $parts = [
            ['type' => 'text', 'text' => $summary],
        ];
        foreach ($videoUrls as $url) {
            $parts[] = [
                'type' => 'video_url',
                'video_url' => ['url' => $url],
            ];
        }

        MessageStore::appendMessage(
            $agentId,
            $sessionId,
            'assistant',
            $content,
            [
                'parts' => $parts,
                'result' => [
                    'task_id' => $taskId,
                    'videos' => $videoUrls,
                    'stored_videos' => $storedVideos,
                ],
                'async' => [
                    'schedule_id' => $scheduleId,
                    'task_id' => $taskId,
                    'capability' => 'video_generate',
                    'model_id' => $modelId,
                    'video_compress' => $videoCompress,
                    'stored_videos' => $storedVideos,
                ],
            ],
        );
    }

    /**
     * @return array{storage_url:string,remote_url:string,local_path:?string}
     */
    private function downloadAndStoreVideo(string $url, ?string $storageName = null): array
    {
        $client = new Client([
            'timeout' => 120,
            'http_errors' => false,
        ]);

        try {
            $response = $client->request('GET', $url, [
                RequestOptions::HEADERS => [
                    'Accept' => 'video/*,*/*',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new ExceptionBusiness('视频下载失败：' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ExceptionBusiness(sprintf('视频下载失败（HTTP %d）', $status));
        }

        $binary = (string)$response->getBody();
        if ($binary === '') {
            throw new ExceptionBusiness('视频下载内容为空');
        }

        $mime = trim((string)$response->getHeaderLine('Content-Type'));
        $mime = strtolower($mime);
        if ($mime === '') {
            $mime = 'video/mp4';
        }

        $ext = match (true) {
            str_starts_with($mime, 'video/webm') => 'webm',
            str_starts_with($mime, 'video/quicktime') => 'mov',
            str_starts_with($mime, 'video/x-matroska') => 'mkv',
            default => 'mp4',
        };
        $name = sprintf('video_%s.%s', str_replace('.', '', uniqid('', true)), $ext);
        $pathInfo = UploadService::generatePath($name, $mime, 'ai/generated');
        $object = StorageService::getObject($storageName ?: null);
        $object->write($pathInfo['path'], $binary);
        $localPath = $this->persistVideoToDataPath($name, $binary);

        return [
            'storage_url' => (string)$object->publicUrl($pathInfo['path']),
            'remote_url' => $url,
            'local_path' => $localPath !== '' ? $localPath : null,
        ];
    }

    private function persistVideoToDataPath(string $filename, string $binary): string
    {
        $dir = data_path('ai/video');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ExceptionBusiness('本地视频目录创建失败：' . $dir);
        }

        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($path, $binary) === false) {
            throw new ExceptionBusiness('本地视频文件写入失败：' . $path);
        }

        return $path;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function uniqueStoredVideos(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $url = trim((string)($item['storage_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = [
                'storage_url' => $url,
                'remote_url' => trim((string)($item['remote_url'] ?? '')),
                'local_path' => trim((string)($item['local_path'] ?? '')) ?: null,
            ];
        }
        return array_values($map);
    }

    /**
     * @param array<int, array<string, mixed>> $videos
     */
    private function cleanupLocalVideos(array $videos): void
    {
        foreach ($videos as $item) {
            $path = trim((string)($item['local_path'] ?? ''));
            if ($path === '' || !is_file($path)) {
                continue;
            }
            try {
                @unlink($path);
            } catch (\Throwable) {
                // ignore cleanup failure
            }
        }
    }
}
