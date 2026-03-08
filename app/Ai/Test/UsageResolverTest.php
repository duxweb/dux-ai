<?php

use App\Ai\Service\Usage\UsageResolver;

it('UsageResolver：优先返回 provider usage', function () {
    $usage = UsageResolver::fromUsageOrEstimate([
        'input_tokens' => 11,
        'output_tokens' => 7,
    ], 'fallback');

    expect($usage)->toBe([
        'prompt_tokens' => 11,
        'completion_tokens' => 7,
        'total_tokens' => 18,
        'usage_source' => 'provider',
        'usage_missing' => false,
    ]);
});

it('UsageResolver：缺失 usage 时回退估算', function () {
    $usage = UsageResolver::fromUsageOrEstimate([], 'hello world');

    expect($usage['usage_source'])->toBe('estimate')
        ->and($usage['usage_missing'])->toBeTrue()
        ->and($usage['completion_tokens'])->toBeGreaterThan(0)
        ->and($usage['total_tokens'])->toBe($usage['completion_tokens']);
});
