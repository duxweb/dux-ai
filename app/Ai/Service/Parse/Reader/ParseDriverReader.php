<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Reader;

use App\Ai\Service\Parse;
use Core\Handlers\ExceptionBusiness;
use NeuronAI\RAG\DataLoader\ReaderInterface;

final class ParseDriverReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        $context = ParseReaderContext::get();
        $provider = $context['parse_provider'] ?? null;
        if ($provider === null || $provider === '') {
            throw new ExceptionBusiness('请先选择解析配置');
        }

        $fileType = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
        if ($fileType === '') {
            throw new ExceptionBusiness('无法识别文件类型');
        }

        return Parse::parseFile($provider, $filePath, $fileType, $context);
    }
}
