<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiFlowLog;
use App\Ai\Models\AiScheduler;
use App\Ai\Service\FlowPersistence\FlowResumeService;
use Core\Handlers\ExceptionBusiness;
use Core\Resources\Action\Resources;
use Core\Resources\Attribute\Action;
use Core\Resources\Attribute\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Resource(app: 'admin', route: '/ai/flowRun', name: 'ai.flowRun', actions: ['list', 'show'])]
class FlowRun extends Resources
{
    protected string $model = AiFlowLog::class;
    /**
     * @var array<string, array{running:bool,suspended:bool,canceled:bool}>|null
     */
    private ?array $schedulerStatusMap = null;

    public function queryMany(Builder $query, ServerRequestInterface $request, array $args): void
    {
        $params = $request->getQueryParams() + [
            'status' => null,
            'flow_id' => null,
            'tab' => null,
        ];

        $query->whereNotNull('workflow_id')
            ->where('workflow_id', '<>', '');

        $status = trim((string)($params['status'] ?: ($params['tab'] ?: '')));
        if ($status !== '' && $status !== 'all') {
            $this->applyStatusFilter($query, $status);
        }

        $flowId = (int)($params['flow_id'] ?? 0);
        if ($flowId > 0) {
            $query->where('flow_id', $flowId);
        }

        $query->with(['flow']);
        $query->orderByDesc('id');
    }

    public function transform(object $item): array
    {
        /** @var AiFlowLog $item */
        return $this->buildRunFromLog($item);
    }

    #[Action(methods: 'GET', route: '/{id}/snapshot')]
    public function snapshotByRunId(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            throw new ExceptionBusiness('id 不能为空');
        }

        /** @var AiFlowLog|null $log */
        $log = AiFlowLog::query()->with('flow')->find($id);
        $workflowId = trim((string)($log?->workflow_id ?? ''));
        if (!$log || $workflowId === '') {
            return send($response, '工作不存在', null, [], 0);
        }

        return send($response, 'ok', [
            'id' => (int)$log->id,
            'workflow_id' => $workflowId,
            'status' => $this->resolveRunStatus($workflowId, $log),
            'log' => $log->transform(),
        ]);
    }

    #[Action(methods: 'GET', route: '/{id}/context')]
    public function context(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $context = $this->buildRunContextByRunId($id);
        if (!$context) {
            return send($response, '工作不存在', null, [], 0);
        }

        return send($response, 'ok', $context);
    }

    #[Action(methods: 'GET', route: '/context/resolve')]
    public function resolve(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams() + [
            'workflow_id' => '',
        ];
        $workflowId = trim((string)$params['workflow_id']);
        if ($workflowId === '') {
            throw new ExceptionBusiness('workflow_id 不能为空');
        }

        $context = $this->buildRunContextByWorkflowId($workflowId);
        if (!$context) {
            return send($response, 'ok', null, [], 0);
        }

        return send($response, 'ok', $context);
    }

    #[Action(methods: 'POST', route: '/{id}/interrupt')]
    public function interrupt(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $context = $this->buildRunContextByRunId($id);
        $workflowId = trim((string)($context['workflow_id'] ?? ''));
        if ($workflowId === '') {
            throw new ExceptionBusiness('工作不存在');
        }

        /** @var AiFlowLog|null $log */
        $log = AiFlowLog::query()->find((int)($context['log_id'] ?? 0));
        $currentStatus = $this->resolveRunStatus($workflowId, $log);
        if (in_array($currentStatus, ['success', 'failed', 'canceled'], true)) {
            throw new ExceptionBusiness('当前状态不可中断');
        }

        $jobs = $this->findFlowPollJobsByWorkflowId($workflowId);
        $canceled = 0;
        /** @var AiScheduler $job */
        foreach ($jobs as $job) {
            if (!in_array((string)$job->status, ['pending', 'retrying', 'running'], true)) {
                continue;
            }
            $job->status = 'canceled';
            $job->locked_at = null;
            $job->locked_by = null;
            $job->save();
            $canceled++;
        }

        return send($response, 'ok', [
            'workflow_id' => $workflowId,
            'status' => 'canceled',
            'canceled_jobs' => $canceled,
        ]);
    }

    #[Action(methods: 'POST', route: '/{id}/resume')]
    public function resume(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $context = $this->buildRunContextByRunId($id);
        $workflowId = trim((string)($context['workflow_id'] ?? ''));
        if ($workflowId === '') {
            throw new ExceptionBusiness('工作不存在');
        }

        /** @var AiFlowLog|null $log */
        $log = AiFlowLog::query()->find((int)($context['log_id'] ?? 0));
        $currentStatus = $this->resolveRunStatus($workflowId, $log);
        if ($currentStatus !== 'suspended') {
            throw new ExceptionBusiness('仅挂起状态可恢复');
        }

        $jobs = $this->findFlowPollJobsByWorkflowId($workflowId);
        $payload = null;
        /** @var AiScheduler $job */
        foreach ($jobs as $job) {
            $data = is_array($job->callback_params ?? null) ? ($job->callback_params ?? []) : [];
            if (!empty($data['flow_code'])) {
                $payload = $data + ['schedule_id' => (int)$job->id];
                break;
            }
        }
        if (!is_array($payload)) {
            throw new ExceptionBusiness('未找到可恢复的任务上下文');
        }

        /** @var AiScheduler $job */
        foreach ($jobs as $job) {
            if (!in_array((string)$job->status, ['pending', 'retrying', 'running'], true)) {
                continue;
            }
            $job->status = 'canceled';
            $job->locked_at = null;
            $job->locked_by = null;
            $job->save();
        }

        $result = FlowResumeService::resumeByWorkflowId($payload);

        return send($response, 'ok', $result);
    }

    /**
     * @return Collection<int, AiScheduler>
     */
    private function findFlowPollJobsByWorkflowId(string $workflowId): Collection
    {
        return AiScheduler::query()
            ->where('callback_type', 'flow')
            ->where('callback_code', 'resume_poll')
            ->where('workflow_id', $workflowId)
            ->orderByDesc('id')
            ->get();
    }

    private function resolveRunStatus(string $workflowId, ?AiFlowLog $log): string
    {
        $scheduler = $this->resolveSchedulerStatus($workflowId);
        if ($scheduler['running']) {
            return 'resuming';
        }

        if ($scheduler['suspended']) {
            return 'suspended';
        }

        if ($scheduler['canceled'] && (int)($log?->status ?? 2) === 2) {
            return 'canceled';
        }

        return match ((int)($log?->status ?? 0)) {
            1 => 'success',
            2 => 'suspended',
            default => 'failed',
        };
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $status = strtolower(trim($status));
        $schedulerMap = $this->schedulerStatusMap();
        $workflowIds = static function (callable $matcher) use ($schedulerMap): array {
            $ids = [];
            foreach ($schedulerMap as $workflowId => $flags) {
                if ($matcher($flags)) {
                    $ids[] = $workflowId;
                }
            }
            return $ids;
        };

        if ($status === 'success') {
            $ids = $workflowIds(static fn (array $flags): bool => $flags['running'] || $flags['suspended']);
            $query->where('status', 1);
            if ($ids !== []) {
                $query->whereNotIn('workflow_id', $ids);
            }
            return;
        }

        if ($status === 'failed') {
            $query->where('status', 0);
            return;
        }

        if ($status === 'resuming') {
            $ids = $workflowIds(static fn (array $flags): bool => $flags['running']);
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('workflow_id', $ids);
            }
            return;
        }

        if ($status === 'suspended') {
            $ids = $workflowIds(static fn (array $flags): bool => $flags['suspended'] && !$flags['running']);
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('workflow_id', $ids);
            }
            return;
        }

        if ($status === 'running') {
            $ids = $workflowIds(static fn (array $flags): bool => $flags['running'] || $flags['suspended']);
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('workflow_id', $ids);
            }
            return;
        }

        if ($status === 'canceled') {
            $ids = $workflowIds(static fn (array $flags): bool => $flags['canceled']);
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', 2)->whereIn('workflow_id', $ids);
            }
        }
    }

    private function buildRunContextByRunId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        /** @var AiFlowLog|null $log */
        $log = AiFlowLog::query()->with('flow')->find($id);
        return $log ? $this->buildRunContextFromLog($log) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRunContextByWorkflowId(string $workflowId): ?array
    {
        /** @var AiFlowLog|null $log */
        $log = AiFlowLog::query()
            ->with('flow')
            ->where('workflow_id', $workflowId)
            ->first();
        return $log ? $this->buildRunContextFromLog($log) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRunFromLog(AiFlowLog $log): array
    {
        $workflowId = trim((string)$log->workflow_id);
        return [
            'id' => (int)$log->id,
            'workflow_id' => $workflowId,
            'flow_id' => $log->flow_id ? (int)$log->flow_id : null,
            'flow_code' => $log->flow_code ?: null,
            'flow_name' => $log->flow?->name,
            'status' => $workflowId !== '' ? $this->resolveRunStatus($workflowId, $log) : 'failed',
            'created_at' => $log->created_at?->toDateTimeString(),
            'updated_at' => $log->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRunContextFromLog(AiFlowLog $log): ?array
    {
        $workflowId = trim((string)$log->workflow_id);
        if ($workflowId === '') {
            return null;
        }

        return [
            'id' => (int)$log->id,
            'workflow_id' => $workflowId,
            'status' => $this->resolveRunStatus($workflowId, $log),
            'log_id' => (int)$log->id,
            'flow_id' => $log->flow_id ? (int)$log->flow_id : null,
            'flow_code' => $log->flow_code ?: null,
        ];
    }

    /**
     * @return array{running:bool,suspended:bool,canceled:bool}
     */
    private function resolveSchedulerStatus(string $workflowId): array
    {
        $map = $this->schedulerStatusMap();
        return $map[$workflowId] ?? [
            'running' => false,
            'suspended' => false,
            'canceled' => false,
        ];
    }

    /**
     * @return array<string, array{running:bool,suspended:bool,canceled:bool}>
     */
    private function schedulerStatusMap(): array
    {
        if (is_array($this->schedulerStatusMap)) {
            return $this->schedulerStatusMap;
        }

        $rows = AiScheduler::query()
            ->where('callback_type', 'flow')
            ->where('callback_code', 'resume_poll')
            ->whereIn('status', ['running', 'pending', 'retrying', 'canceled'])
            ->whereNotNull('workflow_id')
            ->where('workflow_id', '<>', '')
            ->get(['workflow_id', 'status']);

        $map = [];
        /** @var AiScheduler $row */
        foreach ($rows as $row) {
            $workflowId = trim((string)$row->workflow_id);
            if ($workflowId === '') {
                continue;
            }
            $bucket = $map[$workflowId] ?? [
                'running' => false,
                'suspended' => false,
                'canceled' => false,
            ];
            $status = (string)$row->status;
            if ($status === 'running') {
                $bucket['running'] = true;
            } elseif (in_array($status, ['pending', 'retrying'], true)) {
                $bucket['suspended'] = true;
            } elseif ($status === 'canceled') {
                $bucket['canceled'] = true;
            }
            $map[$workflowId] = $bucket;
        }

        $this->schedulerStatusMap = $map;
        return $map;
    }
}
