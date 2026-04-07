<?php

use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Service\Neuron\Agent\ModelRateLimiter;
use App\Ai\Service\Neuron\Agent\TokenEstimator;
use App\System\Service\Config;
use Core\App;
use Core\Handlers\ExceptionBusiness;

it('TokenEstimator：估算聊天预算并包含工具与安全余量', function () {
    $budget = TokenEstimator::estimateChatBudget(
        '你是一个工具调用助手',
        [
            ['role' => 'user', 'content' => '读取当前系统信息'],
            ['role' => 'assistant', 'content' => '正在处理'],
        ],
        [
            'tool_action' => [
                'label' => '工具动作',
                'description' => '调用工具动作',
                'schema' => ['type' => 'object'],
            ],
        ],
        [],
        []
    );

    expect($budget['input_tokens'])->toBeGreaterThan(0)
        ->and($budget['output_tokens'])->toBe(600)
        ->and($budget['tool_overhead'])->toBeGreaterThanOrEqual(300)
        ->and($budget['safety_margin'])->toBeGreaterThanOrEqual(200)
        ->and($budget['total'])->toBeGreaterThan($budget['input_tokens']);
});

it('ModelRateLimiter：可预占并按实际 token 回填', function () {
    $modelKey = 'test-provider:test-model:remote';
    ModelRateLimiter::clear($modelKey);

    $reservation = ModelRateLimiter::acquireForAgent(new class extends \App\Ai\Models\AiAgent {
        public function __construct()
        {
            $this->setRelation('model', new class extends \App\Ai\Models\AiModel {
                public function __construct()
                {
                    $this->options = ['rate_limit' => ['tpm' => 1000, 'max_wait_ms' => 0]];
                    $this->code = 'test-model';
                    $this->model = 'remote';
                    $this->provider_id = 1;
                    $this->setRelation('provider', new class extends \App\Ai\Models\AiProvider {
                        public function __construct()
                        {
                            $this->code = 'test-provider';
                        }
                    });
                }
            });
        }
    }, 300);

    expect($reservation['enabled'])->toBeTrue()
        ->and($reservation['requested_tokens'])->toBe(300);

    ModelRateLimiter::finalize($reservation, 180);
    $snapshot = ModelRateLimiter::snapshot($reservation['model_key']);

    expect($snapshot['used_tokens'])->toBe(180)
        ->and($snapshot['reservations'])->toBe(1);

    ModelRateLimiter::clear($reservation['model_key']);
});

it('ModelRateLimiter：普通模型调用超出等待时间时会直接报忙而不是强行放行', function () {
    $provider = new AiProvider();
    $provider->code = 'test-provider-generic';

    $model = new AiModel();
    $model->code = 'test-model-generic';
    $model->model = 'remote';
    $model->provider_id = 1;
    $model->options = ['rate_limit' => ['tpm' => 1000, 'max_wait_ms' => 0]];
    $model->setRelation('provider', $provider);

    $first = ModelRateLimiter::acquireForModel($model, 700);

    expect(fn () => ModelRateLimiter::acquireForModel($model, 400))
        ->toThrow(ExceptionBusiness::class, '当前模型请求较多，请稍后重试');

    ModelRateLimiter::clear($first['model_key']);
});

it('ModelRateLimiter：并发限制命中时会直接报忙', function () {
    $provider = new AiProvider();
    $provider->code = 'test-provider-concurrency';

    $model = new AiModel();
    $model->code = 'test-model-concurrency';
    $model->model = 'remote';
    $model->provider_id = 1;
    $model->options = ['rate_limit' => ['concurrency' => 1, 'max_wait_ms' => 0]];
    $model->setRelation('provider', $provider);

    $first = ModelRateLimiter::acquireForModel($model, 300);

    expect(fn () => ModelRateLimiter::acquireForModel($model, 200))
        ->toThrow(ExceptionBusiness::class, '当前模型请求较多，请稍后重试');

    ModelRateLimiter::finalize($first, 180);
    ModelRateLimiter::clear($first['model_key']);
});

it('ModelRateLimiter：长耗时请求超过 TPM 窗口后仍保持并发占位', function () {
    $provider = new AiProvider();
    $provider->code = 'test-provider-long-running';

    $model = new AiModel();
    $model->code = 'test-model-long-running';
    $model->model = 'remote';
    $model->provider_id = 1;
    $model->options = ['rate_limit' => ['concurrency' => 1, 'max_wait_ms' => 0]];
    $model->setRelation('provider', $provider);

    $first = ModelRateLimiter::acquireForModel($model, 300);
    $cacheKey = 'ai.agent.model_budget.' . md5($first['model_key']);
    $state = App::cache()->get($cacheKey, []);

    expect($state)->toBeArray()->and($state)->not->toBeEmpty();

    $state[0]['created_at'] = microtime(true) - 120;
    $state[0]['updated_at'] = $state[0]['created_at'];
    $state[0]['released_at'] = null;
    App::cache()->set($cacheKey, $state, 1200);

    expect(fn () => ModelRateLimiter::acquireForModel($model, 200))
        ->toThrow(ExceptionBusiness::class, '当前模型请求较多，请稍后重试');

    ModelRateLimiter::finalize($first, 180);

    $second = ModelRateLimiter::acquireForModel($model, 200);
    expect($second['enabled'])->toBeTrue();

    ModelRateLimiter::finalize($second, 120);
    ModelRateLimiter::clear($first['model_key']);
});

it('ModelRateLimiter：全局 TPM 与模型 TPM 同时存在时取更严格的限制', function () {
    $previous = Config::getJsonValue('ai', []);
    Config::setValue('ai', array_replace_recursive($previous, [
        'rate_limit' => [
            'tpm' => 3000,
        ],
    ]));

    try {
        $reservation = ModelRateLimiter::acquireForAgent(new class extends \App\Ai\Models\AiAgent {
            public function __construct()
            {
                $this->setRelation('model', new class extends \App\Ai\Models\AiModel {
                    public function __construct()
                    {
                        $this->options = ['rate_limit' => ['tpm' => 30000, 'max_wait_ms' => 0]];
                        $this->code = 'test-model-global-floor';
                        $this->model = 'remote';
                        $this->provider_id = 1;
                        $this->setRelation('provider', new class extends \App\Ai\Models\AiProvider {
                            public function __construct()
                            {
                                $this->code = 'test-provider-global-floor';
                            }
                        });
                    }
                });
            }
        }, 1200);

        expect($reservation['enabled'])->toBeTrue()
            ->and($reservation['limit'])->toBe(3000);

        ModelRateLimiter::clear($reservation['model_key']);
    } finally {
        Config::setValue('ai', $previous);
    }
});

it('ModelRateLimiter：全局并发与模型并发同时存在时取更严格的限制', function () {
    $previous = Config::getJsonValue('ai', []);
    Config::setValue('ai', array_replace_recursive($previous, [
        'rate_limit' => [
            'concurrency' => 2,
        ],
    ]));

    try {
        $reservation = ModelRateLimiter::acquireForAgent(new class extends \App\Ai\Models\AiAgent {
            public function __construct()
            {
                $this->setRelation('model', new class extends \App\Ai\Models\AiModel {
                    public function __construct()
                    {
                        $this->options = ['rate_limit' => ['concurrency' => 5, 'max_wait_ms' => 0]];
                        $this->code = 'test-model-global-concurrency';
                        $this->model = 'remote';
                        $this->provider_id = 1;
                        $this->setRelation('provider', new class extends \App\Ai\Models\AiProvider {
                            public function __construct()
                            {
                                $this->code = 'test-provider-global-concurrency';
                            }
                        });
                    }
                });
            }
        }, 300);

        expect($reservation['enabled'])->toBeTrue()
            ->and($reservation['concurrency_limit'])->toBe(2);

        ModelRateLimiter::finalize($reservation, 180);
        ModelRateLimiter::clear($reservation['model_key']);
    } finally {
        Config::setValue('ai', $previous);
    }
});
