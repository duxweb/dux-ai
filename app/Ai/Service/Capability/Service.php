<?php

declare(strict_types=1);

namespace App\Ai\Service\Capability;

use App\Ai\Event\AiCapabilityEvent;
use App\Ai\Interface\AgentCapabilityContextInterface;
use App\Ai\Interface\CapabilityContextInterface;
use App\Ai\Interface\FlowCapabilityContextInterface;
use App\Ai\Service\Scheduler\AiSchedulerService;
use App\Ai\Support\AiRuntimeInterface;
use Carbon\Carbon;
use Core\Handlers\ExceptionBusiness;

final class Service
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $registry = [];

    private bool $booted = false;

    public function __construct(private readonly AiRuntimeInterface $runtime)
    {
    }

    public function reset(): void
    {
        $this->registry = [];
        $this->booted = false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $scope = null): array
    {
        $this->boot();

        if ($scope === null) {
            return array_values($this->registry);
        }

        $scope = strtolower(trim($scope));
        return array_values(array_filter($this->registry, static function (array $item) use ($scope) {
            $types = $item['types'] ?? [];
            if (!is_array($types)) {
                $types = [];
            }
            return in_array($scope, $types, true);
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $code): ?array
    {
        $this->boot();
        return $this->registry[$code] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @return mixed
     */
    public function execute(string $code, array $input, CapabilityContextInterface $context)
    {
        $capability = $this->get($code);
        if (!$capability) {
            throw new ExceptionBusiness(sprintf('Capability [%s] 未注册', $code));
        }

        $handler = $capability['handler'] ?? null;
        if (!is_callable($handler)) {
            throw new ExceptionBusiness(sprintf('Capability [%s] 未配置 handler', $code));
        }

        if ($this->shouldScheduleAsync($capability, $input, $context)) {
            return $this->dispatchAsyncSchedule($code, $capability, $input, $context);
        }

        return $handler($this->sanitizeCapabilityInput($input), $context);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $event = new AiCapabilityEvent();
        $this->runtime->event()->dispatch($event, 'ai.capability');

        $registry = [];
        foreach ($event->getRegistry() as $code => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!isset($item['handler']) || !is_callable($item['handler'])) {
                continue;
            }
            $item = $this->enrichAsyncMeta($item);
            $registry[$code] = $item;
        }

        $this->registry = $registry;
        $this->booted = true;
    }

    /**
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $input
     */
    private function shouldScheduleAsync(array $capability, array $input, CapabilityContextInterface $context): bool
    {
        if (($input['__from_scheduler'] ?? false) === true) {
            return false;
        }
        if (!in_array($context->scope(), ['agent', 'flow'], true)) {
            return false;
        }

        $policy = $this->asyncPolicy($capability);
        if ($policy === 'off') {
            if ($this->hasAsyncRequest($input)) {
                throw new ExceptionBusiness('当前能力未开启异步调度');
            }
            return false;
        }
        if ($policy === 'force_on') {
            return true;
        }

        $enabled = (bool)($input['async_enabled'] ?? ($capability['defaults']['async_enabled'] ?? false));
        if (!$enabled && $this->hasAsyncRequest($input)) {
            throw new ExceptionBusiness('当前能力未启用异步调度，请先在工具配置中开启');
        }

        return $enabled && $this->hasAsyncRequest($input);
    }

    /**
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function dispatchAsyncSchedule(string $code, array $capability, array $input, CapabilityContextInterface $context): array
    {
        $cleanInput = $this->sanitizeCapabilityInput($input);
        $capabilityLabel = trim((string)($capability['label'] ?? $capability['name'] ?? $code));

        $executeAtInput = trim((string)($input['execute_at'] ?? ''));
        $delayMinutes = max(0, (int)($input['delay_minutes'] ?? 0));
        $defaultDelay = max(0, (int)($capability['async']['delay_minutes'] ?? 0));
        $executeAt = $executeAtInput !== ''
            ? Carbon::parse($executeAtInput)
            : Carbon::now()->addMinutes($delayMinutes > 0 ? $delayMinutes : $defaultDelay);

        $maxAttempts = max(1, (int)($capability['async']['max_attempts'] ?? 3));
        $sourceScope = $context->scope();
        $sourceId = $this->resolveSourceId($context);
        $sourceKey = $sourceId ? sprintf('%s:%d', $sourceScope, $sourceId) : $sourceScope;
        $dedupeKey = sprintf(
            'capability:%s:%s',
            $code,
            md5(json_encode($cleanInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . $executeAt->toDateTimeString() . '|' . $sourceKey),
        );

        $job = AiSchedulerService::createJob([
            'callback_type' => 'capability',
            'callback_code' => $code,
            'callback_action' => 'invoke',
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
            'execute_at' => $executeAt,
            'max_attempts' => $maxAttempts,
            'callback_params' => [
                ...$cleanInput,
                '__from_scheduler' => true,
            ],
            'source_type' => $sourceScope,
            'source_id' => $sourceId,
        ]);

        return [
            'status' => 1,
            'message' => 'ok',
            'data' => [
                'mode' => 'scheduled',
                'schedule_id' => (int)$job->id,
                'capability' => $code,
                'capability_label' => $capabilityLabel,
                'execute_at' => $job->execute_at?->toDateTimeString(),
            ],
            'summary' => sprintf('已加入异步调度，将在 %s 执行 %s', $job->execute_at?->toDateTimeString(), $capabilityLabel),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function enrichAsyncMeta(array $item): array
    {
        $async = is_array($item['async'] ?? null) ? ($item['async'] ?? []) : [];
        $policy = strtolower(trim((string)($async['policy'] ?? 'off')));
        if (!in_array($policy, ['off', 'optional', 'force_on'], true)) {
            $policy = 'off';
        }
        $async['policy'] = $policy;
        $async['enabled'] = (bool)($async['enabled'] ?? false);
        $async['delay_minutes'] = max(0, (int)($async['delay_minutes'] ?? 0));
        $async['max_attempts'] = max(1, (int)($async['max_attempts'] ?? 3));
        $item['async'] = $async;

        if ($policy === 'off') {
            return $item;
        }

        $defaults = is_array($item['defaults'] ?? null) ? ($item['defaults'] ?? []) : [];
        if (!array_key_exists('async_enabled', $defaults)) {
            $defaults['async_enabled'] = (bool)$async['enabled'];
        }
        $item['defaults'] = $defaults;

        $settings = is_array($item['settings'] ?? null) ? ($item['settings'] ?? []) : [];
        $hasAsyncEnabled = false;
        foreach ($settings as $field) {
            if (is_array($field) && (string)($field['name'] ?? '') === 'async_enabled') {
                $hasAsyncEnabled = true;
                break;
            }
        }
        if (!$hasAsyncEnabled) {
            $settings[] = [
                'name' => 'async_enabled',
                'label' => '启用异步调度',
                'component' => 'switch',
                'defaultValue' => (bool)$async['enabled'],
                'description' => '开启后可根据 delay_minutes/execute_at 自动加入调度队列',
            ];
        }
        $item['settings'] = $settings;

        $schema = is_array($item['schema'] ?? null) ? ($item['schema'] ?? []) : [];
        if ((string)($schema['type'] ?? '') !== 'object') {
            $schema['type'] = 'object';
        }
        $properties = is_array($schema['properties'] ?? null) ? ($schema['properties'] ?? []) : [];
        if (!isset($properties['delay_minutes'])) {
            $properties['delay_minutes'] = ['type' => 'integer', 'description' => '延迟分钟，>0 时按延迟执行'];
        }
        if (!isset($properties['execute_at'])) {
            $properties['execute_at'] = ['type' => 'string', 'description' => '绝对执行时间，格式 YYYY-MM-DD HH:mm:ss'];
        }
        $schema['properties'] = $properties;
        $item['schema'] = $schema;

        return $item;
    }

    /**
     * @param array<string, mixed> $capability
     */
    private function asyncPolicy(array $capability): string
    {
        $async = is_array($capability['async'] ?? null) ? ($capability['async'] ?? []) : [];
        $policy = strtolower(trim((string)($async['policy'] ?? 'off')));
        return in_array($policy, ['off', 'optional', 'force_on'], true) ? $policy : 'off';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hasAsyncRequest(array $input): bool
    {
        $executeAt = trim((string)($input['execute_at'] ?? ''));
        $delay = (int)($input['delay_minutes'] ?? 0);
        return $executeAt !== '' || $delay > 0;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function sanitizeCapabilityInput(array $input): array
    {
        unset($input['async_enabled'], $input['delay_minutes'], $input['execute_at'], $input['__from_scheduler']);
        return $input;
    }

    private function resolveSourceId(CapabilityContextInterface $context): ?int
    {
        if ($context instanceof AgentCapabilityContextInterface) {
            $id = (int)$context->sessionId();
            if ($id > 0) {
                return $id;
            }
        }
        if ($context instanceof FlowCapabilityContextInterface) {
            $id = (int)$context->flowId();
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }
}
