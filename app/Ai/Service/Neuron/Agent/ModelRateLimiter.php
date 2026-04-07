<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\AiConfig;
use App\Ai\Support\AiRuntime;
use Core\App;
use Psr\SimpleCache\CacheInterface;
use Ramsey\Uuid\Uuid;

final class ModelRateLimiter
{
    private const CACHE_PREFIX = 'ai.agent.model_budget.';
    private const TOKEN_WINDOW_SECONDS = 60;
    private const ACTIVE_RESERVATION_TTL_SECONDS = 900;
    private const CACHE_TTL = 1200;

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     concurrency_limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     active_reservations: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    public static function acquireForAgent(AiAgent $agent, int $requestedTokens): array
    {
        $agent->loadMissing('model.provider');
        $model = $agent->model;
        if (!$model instanceof AiModel) {
            return self::disabledReservation();
        }

        return self::acquireForResolvedModel($model, $requestedTokens, true);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     concurrency_limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     active_reservations: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    public static function acquireForModel(AiModel $model, int $requestedTokens): array
    {
        $model->loadMissing('provider');
        return self::acquireForResolvedModel($model, $requestedTokens, false);
    }

    public static function finalize(array $reservation, int $actualTokens): void
    {
        if (!($reservation['enabled'] ?? false)) {
            return;
        }

        $modelKey = trim((string)($reservation['model_key'] ?? ''));
        $reservationId = trim((string)($reservation['reservation_id'] ?? ''));
        if ($modelKey === '' || $reservationId === '') {
            return;
        }

        $requestedTokens = max(0, (int)($reservation['requested_tokens'] ?? 0));
        $actualTokens = max(0, $actualTokens);
        $tokens = $actualTokens > 0 ? $actualTokens : $requestedTokens;

        $state = self::readState($modelKey);
        foreach ($state as $index => $item) {
            if ((string)($item['id'] ?? '') !== $reservationId) {
                continue;
            }
            $state[$index]['tokens'] = $tokens;
            $state[$index]['updated_at'] = microtime(true);
            $state[$index]['released_at'] = microtime(true);
            self::writeState($modelKey, $state);
            return;
        }
    }

    public static function clear(string $modelKey): void
    {
        self::cache()->delete(self::cacheKey($modelKey));
    }

    /**
     * @return array{used_tokens:int,reservations:int}
     */
    public static function snapshot(string $modelKey): array
    {
        $state = self::readState($modelKey);
        return [
            'used_tokens' => self::sumTokens($state),
            'reservations' => count($state),
        ];
    }

    /**
     * @return array{
     *     model_key:string,
     *     model_limit:int,
     *     global_limit:int,
     *     effective_limit:int,
     *     model_concurrency:int,
     *     global_concurrency:int,
     *     effective_concurrency:int,
     *     max_wait_ms:int
     * }
     */
    public static function inspectForModel(AiModel $model): array
    {
        $model->loadMissing('provider');

        return [
            'model_key' => self::modelKey($model),
            'model_limit' => self::resolveModelTpmLimit($model),
            'global_limit' => self::normalizeLimitCandidate(AiConfig::getValue('rate_limit.tpm')),
            'effective_limit' => self::resolveTpmLimit($model),
            'model_concurrency' => self::resolveModelConcurrencyLimit($model),
            'global_concurrency' => self::normalizeLimitCandidate(AiConfig::getValue('rate_limit.concurrency')),
            'effective_concurrency' => self::resolveConcurrencyLimit($model),
            'max_wait_ms' => self::resolveMaxWaitMs($model),
        ];
    }

    private static function appendReservation(string $modelKey, array $state, int $tokens): string
    {
        $reservationId = (string)Uuid::uuid7();
        $state[] = [
            'id' => $reservationId,
            'tokens' => max(0, $tokens),
            'created_at' => microtime(true),
            'updated_at' => microtime(true),
            'released_at' => null,
        ];
        self::writeState($modelKey, $state);
        return $reservationId;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     concurrency_limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     active_reservations: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    private static function acquireForResolvedModel(AiModel $model, int $requestedTokens, bool $forceOnTimeout): array
    {
        $limit = self::resolveTpmLimit($model);
        $concurrencyLimit = self::resolveConcurrencyLimit($model);
        if ($limit <= 0 && $concurrencyLimit <= 0) {
            return self::disabledReservation(self::modelKey($model));
        }

        $modelKey = self::modelKey($model);
        $maxWaitMs = self::resolveMaxWaitMs($model);
        $deadline = microtime(true) + ($maxWaitMs / 1000);
        $waitedMs = 0;

        while (true) {
            $state = self::readState($modelKey);
            $used = self::sumTokens($state);
            $activeReservations = self::countActiveReservations($state);
            $tokenAllowed = $limit <= 0 || $used + max(0, $requestedTokens) <= $limit;
            $concurrencyAllowed = $concurrencyLimit <= 0 || $activeReservations < $concurrencyLimit;

            if ($tokenAllowed && $concurrencyAllowed) {
                $reservationId = self::appendReservation($modelKey, $state, $requestedTokens);
                return [
                    'enabled' => true,
                    'model_key' => $modelKey,
                    'reservation_id' => $reservationId,
                    'limit' => $limit,
                    'concurrency_limit' => $concurrencyLimit,
                    'requested_tokens' => $requestedTokens,
                    'used_tokens' => $used,
                    'active_reservations' => $activeReservations,
                    'waited_ms' => $waitedMs,
                    'forced' => false,
                ];
            }

            $waitMs = self::nextWaitMs($state, !$tokenAllowed, !$concurrencyAllowed);
            if ($waitMs <= 0) {
                $waitMs = 200;
            }

            $remainingMs = max(0, (int)ceil(($deadline - microtime(true)) * 1000));
            if ($remainingMs <= 0 || $waitMs > $remainingMs) {
                if (!$forceOnTimeout) {
                    throw new \Core\Handlers\ExceptionBusiness('当前模型请求较多，请稍后重试');
                }
                $reservationId = self::appendReservation($modelKey, $state, $requestedTokens);
                return [
                    'enabled' => true,
                    'model_key' => $modelKey,
                    'reservation_id' => $reservationId,
                    'limit' => $limit,
                    'concurrency_limit' => $concurrencyLimit,
                    'requested_tokens' => $requestedTokens,
                    'used_tokens' => $used,
                    'active_reservations' => $activeReservations,
                    'waited_ms' => $waitedMs,
                    'forced' => true,
                ];
            }

            usleep($waitMs * 1000);
            $waitedMs += $waitMs;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function sumTokens(array $state): int
    {
        $cutoff = microtime(true) - self::TOKEN_WINDOW_SECONDS;
        $sum = 0;
        foreach ($state as $item) {
            $createdAt = (float)($item['created_at'] ?? 0);
            if ($createdAt <= 0 || $createdAt < $cutoff) {
                continue;
            }
            $sum += max(0, (int)($item['tokens'] ?? 0));
        }
        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function countActiveReservations(array $state): int
    {
        $count = 0;
        foreach ($state as $item) {
            $releasedAt = (float)($item['released_at'] ?? 0);
            if ($releasedAt <= 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function nextWaitMs(array $state, bool $tokenBlocked = true, bool $concurrencyBlocked = false): int
    {
        $waits = [];

        if ($concurrencyBlocked) {
            $waits[] = 200;
        }

        if (!$tokenBlocked) {
            return min($waits ?: [0]);
        }

        $oldest = null;
        foreach ($state as $item) {
            $createdAt = (float)($item['created_at'] ?? 0);
            if ($createdAt <= 0) {
                continue;
            }
            if ($oldest === null || $createdAt < $oldest) {
                $oldest = $createdAt;
            }
        }

        if ($oldest === null) {
            return min($waits ?: [0]);
        }

        $waits[] = max(100, (int)ceil((($oldest + self::TOKEN_WINDOW_SECONDS) - microtime(true)) * 1000) + 50);

        return min($waits);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function readState(string $modelKey): array
    {
        $items = self::cache()->get(self::cacheKey($modelKey), []);
        if (!is_array($items)) {
            return [];
        }

        $tokenCutoff = microtime(true) - self::TOKEN_WINDOW_SECONDS;
        $activeCutoff = microtime(true) - self::ACTIVE_RESERVATION_TTL_SECONDS;
        $resolved = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $createdAt = (float)($item['created_at'] ?? 0);
            if ($createdAt <= 0) {
                continue;
            }
            $releasedAt = (float)($item['released_at'] ?? 0);
            $active = $releasedAt <= 0;
            if ($active && $createdAt < $activeCutoff) {
                continue;
            }
            if (!$active && $createdAt < $tokenCutoff) {
                continue;
            }
            $resolved[] = $item;
        }
        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function writeState(string $modelKey, array $state): void
    {
        self::cache()->set(self::cacheKey($modelKey), array_values($state), self::CACHE_TTL);
    }

    private static function resolveTpmLimit(AiModel $model): int
    {
        $modelLimit = self::resolveModelTpmLimit($model);
        $globalLimit = self::normalizeLimitCandidate(AiConfig::getValue('rate_limit.tpm'));

        if ($modelLimit > 0 && $globalLimit > 0) {
            return min($modelLimit, $globalLimit);
        }

        return max($modelLimit, $globalLimit);
    }

    private static function resolveConcurrencyLimit(AiModel $model): int
    {
        $modelLimit = self::resolveModelConcurrencyLimit($model);
        $globalLimit = self::normalizeLimitCandidate(AiConfig::getValue('rate_limit.concurrency'));

        if ($modelLimit > 0 && $globalLimit > 0) {
            return min($modelLimit, $globalLimit);
        }

        return max($modelLimit, $globalLimit);
    }

    private static function resolveModelTpmLimit(AiModel $model): int
    {
        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $rateLimit = is_array($options['rate_limit'] ?? null) ? ($options['rate_limit'] ?? []) : [];

        return self::normalizeLimitCandidate(
            $rateLimit['tpm']
            ?? $rateLimit['tokens_per_minute']
            ?? $options['tpm']
            ?? $options['tokens_per_minute']
            ?? null
        );
    }

    private static function resolveModelConcurrencyLimit(AiModel $model): int
    {
        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $rateLimit = is_array($options['rate_limit'] ?? null) ? ($options['rate_limit'] ?? []) : [];

        return self::normalizeLimitCandidate(
            $rateLimit['concurrency']
            ?? $rateLimit['parallel']
            ?? $options['concurrency']
            ?? $options['parallel']
            ?? null
        );
    }

    private static function resolveMaxWaitMs(AiModel $model): int
    {
        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $rateLimit = is_array($options['rate_limit'] ?? null) ? ($options['rate_limit'] ?? []) : [];
        $candidate = $rateLimit['max_wait_ms']
            ?? $options['rate_limit_max_wait_ms']
            ?? AiConfig::getValue('rate_limit.max_wait_ms', 8000);
        if (!is_numeric($candidate)) {
            return 8000;
        }
        return max(0, min(60000, (int)$candidate));
    }

    private static function normalizeLimitCandidate(mixed $candidate): int
    {
        if (!is_numeric($candidate)) {
            return 0;
        }

        return max(0, (int)$candidate);
    }

    private static function modelKey(AiModel $model): string
    {
        $providerCode = '';
        if ($model->provider instanceof AiProvider) {
            $providerCode = trim((string)($model->provider->code ?? ''));
        }
        $modelCode = trim((string)($model->code ?? ''));
        $remoteModel = trim((string)($model->model ?? ''));

        return implode(':', array_filter([
            $providerCode !== '' ? $providerCode : ('provider' . (int)$model->provider_id),
            $modelCode !== '' ? $modelCode : ('model' . (int)$model->id),
            $remoteModel,
        ]));
    }

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     concurrency_limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     active_reservations: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    private static function disabledReservation(string $modelKey = ''): array
    {
        return [
            'enabled' => false,
            'model_key' => $modelKey,
            'reservation_id' => '',
            'limit' => 0,
            'concurrency_limit' => 0,
            'requested_tokens' => 0,
            'used_tokens' => 0,
            'active_reservations' => 0,
            'waited_ms' => 0,
            'forced' => false,
        ];
    }

    private static function cacheKey(string $modelKey): string
    {
        return self::CACHE_PREFIX . md5($modelKey);
    }

    private static function cache(): CacheInterface
    {
        return App::cache();
    }
}
