<?php

use App\Ai\Service\FileDataLoader;

it('FileDataLoader：txt 文件可直接读取内容', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'ai_loader_txt_');
    expect($tmp)->not->toBeFalse();

    try {
        file_put_contents($tmp, "hello\nworld");
        $content = FileDataLoader::content($tmp);
        expect($content)->toContain('hello')->toContain('world');
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            @unlink($tmp);
        }
    }
});

it('FileDataLoader：图片在未配置 parse_provider 时返回空内容', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'ai_loader_img_');
    expect($tmp)->not->toBeFalse();
    $path = $tmp . '.png';
    @rename($tmp, $path);

    try {
        file_put_contents($path, 'not-really-an-image');

        expect(FileDataLoader::content($path))->toBe('');
    } finally {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});
