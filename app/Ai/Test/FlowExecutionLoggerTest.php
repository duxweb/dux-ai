<?php

use App\Ai\Service\Neuron\Flow\FlowExecutionLogger;

it('流程日志：compactValueForLog 不截断长字符串', function () {
    $long = str_repeat('a', 10000);
    $out = FlowExecutionLogger::compactValueForLog($long);
    expect($out)->toBe($long);
});

it('流程日志：compactValueForLog 可直接返回数组对象结构', function () {
    $value = [
        'a' => str_repeat('中', 3000),
        'b' => ['c' => 1],
    ];
    $out = FlowExecutionLogger::compactValueForLog($value);
    expect($out)->toBe($value);
});

