<?php

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Drivers\LocalDriver;
use Core\Handlers\ExceptionBusiness;

it('LocalDriver：不支持的文件类型会抛出业务异常', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'local_driver_');
    expect($tmp)->not->toBeFalse();

    $provider = new ParseProvider();
    $provider->provider = 'local';
    $provider->config = [];

    try {
        expect(fn () => (new LocalDriver())->parseFile($provider, (string)$tmp, 'txt'))
            ->toThrow(ExceptionBusiness::class, '仅支持 PDF/图片');
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});

