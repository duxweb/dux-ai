<?php

declare(strict_types=1);

namespace App\Ai\Service;

use Core\Handlers\ExceptionBusiness;

final class CodeGenerator
{
    private const CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public static function random(int $length = 10): string
    {
        $length = max(1, $length);
        $max = strlen(self::CHARS) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::CHARS[random_int(0, $max)];
        }
        return $code;
    }

    public static function unique(callable $exists, int $length = 10, int $maxAttempts = 32): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = self::random($length);
            if (!$exists($code)) {
                return $code;
            }
        }
        throw new ExceptionBusiness('自动生成标识失败，请稍后重试');
    }

    public static function resolve(string $inputCode, string $currentCode, callable $exists, int $length = 10): string
    {
        $inputCode = trim($inputCode);
        if ($inputCode !== '') {
            return $inputCode;
        }
        $currentCode = trim($currentCode);
        if ($currentCode !== '') {
            return $currentCode;
        }
        return self::unique($exists, $length);
    }
}

