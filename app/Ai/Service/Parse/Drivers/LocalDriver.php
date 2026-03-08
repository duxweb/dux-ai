<?php

declare(strict_types=1);

namespace App\Ai\Service\Parse\Drivers;

use App\Ai\Models\ParseProvider;
use App\Ai\Service\Parse\Contracts\DriverInterface;
use App\Ai\Support\AiRuntime;
use Core\Handlers\ExceptionBusiness;
use Symfony\Component\Process\Process;
use Throwable;

final class LocalDriver implements DriverInterface
{
    public static function meta(): array
    {
        return [
            'label' => '本地解析',
            'description' => '本地解析：PDF 走 RapidOCRPDF；图片走 RapidOCR。',
            'register_url' => 'https://github.com/RapidAI/RapidOCRPDF',
            'form_schema' => [
                ['tag' => 'dux-form-item', 'attrs' => ['label' => 'PDF 超时（秒）'], 'children' => [[
                    'tag' => 'n-input-number',
                    'attrs' => [
                        'v-model:value.number' => 'config.pdf_timeout',
                        'min' => 10,
                        'max' => 600,
                    ],
                ]]],
            ],
        ];
    }

    public function parseFile(ParseProvider $provider, string $filePath, string $fileType, array $options = []): string
    {
        if (!is_file($filePath)) {
            throw new ExceptionBusiness('待解析文件不存在');
        }

        $type = strtolower(trim($fileType));
        $config = is_array($provider->config ?? null) ? ($provider->config ?? []) : [];

        return match ($type) {
            'pdf' => $this->parsePdf($filePath, $config, $options),
            'png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif' => $this->parseImage($filePath, $config),
            default => throw new ExceptionBusiness('本地解析仅支持 PDF/图片'),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    private function parsePdf(string $filePath, array $config, array $options): string
    {
        $logEnabled = $this->toBool($config['log_enabled'] ?? false);
        $forceOcr = true;
        $pageNumList = $this->normalizePageNumList($options['page_num_list'] ?? null);
        $timeout = isset($config['pdf_timeout']) && is_numeric($config['pdf_timeout'])
            ? max(10, (int)$config['pdf_timeout'])
            : 90;
        $runMode = 'local';

        $logger = AiRuntime::log('ai.docs');
        if ($logEnabled) {
            $logger->info('parse.local.start', [
                'file_name' => basename($filePath),
                'strategy' => $runMode,
                'pdf_parser' => 'rapidocr_pdf',
                'force_ocr' => $forceOcr,
                'page_num_list' => $pageNumList,
            ]);
        }

        try {
            $python = $this->resolveRapidOcrPdfPythonBinary();
            $decoded = $this->runLocalPdf($python, $filePath, $forceOcr, $pageNumList, $timeout);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $logger->error('parse.local.failed', [
                'file_name' => basename($filePath),
                'strategy' => $runMode,
                'pdf_parser' => 'rapidocr_pdf',
                'error' => $message,
            ]);
            throw new ExceptionBusiness('本地 PDF 解析失败：' . $message);
        }

        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            $error = is_array($decoded) ? (string)($decoded['error'] ?? '解析返回数据格式错误') : '解析返回非 JSON 内容';
            $logger->error('parse.local.failed', [
                'file_name' => basename($filePath),
                'strategy' => $runMode,
                'pdf_parser' => 'rapidocr_pdf',
                'error' => $error,
            ]);
            throw new ExceptionBusiness('本地 PDF 解析失败：' . $error);
        }

        $text = trim((string)($decoded['text'] ?? ''));
        if ($text === '') {
            throw new ExceptionBusiness('本地 PDF 解析结果为空');
        }

        if ($logEnabled) {
            $this->logParsedContent($filePath, $text, $decoded);
        }

        if ($logEnabled) {
            $logger->info('parse.local.success', [
                'file_name' => basename($filePath),
                'strategy' => $runMode,
                'pdf_parser' => 'rapidocr_pdf',
                'page_count' => (int)($decoded['page_count'] ?? 0),
                'chars' => mb_strlen($text, 'UTF-8'),
                'duration_ms' => (int)($decoded['duration_ms'] ?? 0),
                'dpi' => (int)($decoded['dpi'] ?? 0),
                'fallback_used' => (bool)($decoded['fallback_used'] ?? false),
            ]);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function parseImage(string $filePath, array $config): string
    {
        $timeout = isset($config['ocr_timeout']) && is_numeric($config['ocr_timeout'])
            ? max(3, (int)$config['ocr_timeout'])
            : 10;
        $python = $this->resolveRapidOcrPythonBinary();
        $script = __DIR__ . '/bin/rapidocr.py';
        if (!is_file($script)) {
            throw new ExceptionBusiness('RapidOCR 脚本不存在: ' . $script);
        }

        $process = new Process([$python, $script, $filePath]);
        $process->setTimeout($timeout);

        try {
            $process->mustRun();
        } catch (Throwable $e) {
            $stderr = trim((string)$process->getErrorOutput());
            $message = $stderr !== '' ? $stderr : $e->getMessage();
            if (str_contains(strtolower($message), 'timed out')) {
                throw new ExceptionBusiness('RapidOCR 执行超时');
            }
            throw new ExceptionBusiness($message !== '' ? $message : 'RapidOCR 执行失败');
        }

        $text = trim((string)$process->getOutput());
        if ($text === '') {
            throw new ExceptionBusiness('本地图片 OCR 结果为空');
        }
        return $text;
    }

    /**
     * @param array<int, int> $pageNumList
     * @return array<string, mixed>|null
     */
    private function runLocalPdf(string $python, string $filePath, bool $forceOcr, array $pageNumList, int $timeout): ?array
    {
        $script = __DIR__ . '/bin/rapidocr_pdf.py';
        if (!is_file($script)) {
            throw new ExceptionBusiness('RapidOCRPDF 脚本不存在: ' . $script);
        }

        $command = [
            $python,
            $script,
            $filePath,
            '--force-ocr=' . ($forceOcr ? '1' : '0'),
        ];
        if ($pageNumList !== []) {
            $command[] = '--page-num-list=' . implode(',', $pageNumList);
        }

        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->mustRun();
        } catch (Throwable $e) {
            $stderr = trim((string)$process->getErrorOutput());
            $message = $stderr !== '' ? $stderr : $e->getMessage();
            throw new ExceptionBusiness($message);
        }

        return $this->decodeJsonOutput((string)$process->getOutput());
    }

    private function resolveRapidOcrPdfPythonBinary(): string
    {
        return $this->resolvePythonBinary(
            'rapidocr_pdf',
            'RapidOCRPDF 未安装到当前运行的 Python，请执行：%s -m pip install rapidocr-pdf'
        );
    }

    private function resolveRapidOcrPythonBinary(): string
    {
        return $this->resolvePythonBinary(
            'rapidocr_onnxruntime',
            'RapidOCR 未安装到当前运行的 Python，请执行：%s -m pip install rapidocr-onnxruntime pillow'
        );
    }

    private function resolvePythonBinary(string $module, string $installHint): string
    {
        $candidates = [
            trim((string)getenv('RAPIDOCR_PYTHON')),
            '/opt/homebrew/bin/python3',
            '/usr/local/bin/python3',
            '/usr/bin/python3',
            'python3',
        ];
        $candidates = array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));

        foreach ($candidates as $python) {
            if ($this->hasPythonModule($python, $module)) {
                return $python;
            }
        }

        $hint = $candidates[0] ?? 'python3';
        throw new ExceptionBusiness(sprintf($installHint, $hint));
    }

    private function hasPythonModule(string $python, string $module): bool
    {
        $process = new Process([$python, '-c', 'import ' . $module]);
        $process->setTimeout(5);
        try {
            $process->mustRun();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, int>
     */
    private function normalizePageNumList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\\s*,\\s*/', trim($value)) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $pages = [];
        foreach ($value as $item) {
            if (!is_numeric($item)) {
                continue;
            }
            $page = (int)$item;
            if ($page > 0) {
                $pages[] = $page;
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);
        return $pages;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        $raw = strtolower(trim((string)$value));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function logParsedContent(string $filePath, string $text, array $decoded): void
    {
        $dumpPath = $this->writeDebugDump($text, 'local_pdf_text_');

        AiRuntime::log('ai.docs')->info('parse.local.parsed', [
            'file_name' => basename($filePath),
            'page_count' => (int)($decoded['page_count'] ?? 0),
            'chars' => mb_strlen($text, 'UTF-8'),
            'bytes' => strlen($text),
            'line_count' => substr_count($text, "\n") + 1,
            'duration_ms' => (int)($decoded['duration_ms'] ?? 0),
            'dpi' => (int)($decoded['dpi'] ?? 0),
            'fallback_used' => (bool)($decoded['fallback_used'] ?? false),
            'dump_path' => $dumpPath,
            'preview_head' => mb_substr($text, 0, 500, 'UTF-8'),
            'preview_tail' => mb_substr($text, max(0, mb_strlen($text, 'UTF-8') - 500), 500, 'UTF-8'),
            'pages' => is_array($decoded['pages'] ?? null) ? $decoded['pages'] : [],
        ]);
    }

    private function writeDebugDump(string $content, string $prefix): string
    {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'duxai';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $prefix . date('Ymd_His') . '_' . uniqid('', true) . '.txt';
        @file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonOutput(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{"ok"');
        if ($start === false) {
            $start = strpos($trimmed, '{');
        }
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($trimmed, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
