<?php

use App\Ai\Service\FunctionCall;
use App\Ai\Service\FunctionCall\Service as FunctionCallService;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;

function setFunctionRegistry(array $registry): void
{
    $service = new FunctionCallService(AiRuntime::instance());

    $booted = new ReflectionProperty($service, 'booted');
    $reg = new ReflectionProperty($service, 'registry');
    $booted->setValue($service, true);
    $reg->setValue($service, $registry);

    FunctionCall::setService($service);
}

it('函数调用：list 返回 label/value/description', function () {
    setFunctionRegistry([
        'foo' => ['value' => 'foo', 'label' => 'Foo', 'description' => 'desc', 'handler' => static fn () => 1],
    ]);

    expect(FunctionCall::list())->toBe([[
        'label' => 'Foo',
        'value' => 'foo',
        'description' => 'desc',
    ]]);
});

it('函数调用：call 执行 handler', function () {
    setFunctionRegistry([
        'sum' => [
            'value' => 'sum',
            'label' => 'Sum',
            'handler' => static fn (array $input) => (int)($input['a'] ?? 0) + (int)($input['b'] ?? 0),
        ],
    ]);

    expect(FunctionCall::call('sum', ['a' => 1, 'b' => 2]))->toBe(3);
});

it('函数调用：未注册会抛业务异常', function () {
    setFunctionRegistry([]);

    expect(fn () => FunctionCall::get('missing'))
        ->toThrow(ExceptionBusiness::class, '未注册');
});

afterAll(function () {
    FunctionCall::reset();
});
