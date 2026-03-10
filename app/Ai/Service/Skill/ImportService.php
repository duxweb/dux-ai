<?php

declare(strict_types=1);

namespace App\Ai\Service\Skill;

use App\Ai\Models\AiSkill;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

final class ImportService
{
    /**
     * @return array<string, mixed>
     */
    public function import(string $source, bool $overwrite = true): array
    {
        $source = trim($source);
        if ($source === '') {
            throw new ExceptionBusiness('请输入导入来源');
        }

        $resolved = $this->resolveSource($source);
        $cleanup = is_string($resolved['cleanup'] ?? null) ? ($resolved['cleanup'] ?? '') : '';

        try {
            $directories = $this->discoverSkillDirectories((string)$resolved['root']);
            if ($directories === []) {
                throw new ExceptionBusiness('未发现可导入的 SKILL.md');
            }

            $imported = [];
            $updated = [];
            $skipped = [];

            foreach ($directories as $directory) {
                $state = $this->importDirectory($directory, $resolved, $overwrite);
                if ($state['action'] === 'created') {
                    $imported[] = $state['name'];
                    continue;
                }
                if ($state['action'] === 'updated') {
                    $updated[] = $state['name'];
                    continue;
                }
                $skipped[] = $state['name'];
            }

            return [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'count' => count($imported) + count($updated),
            ];
        } finally {
            if ($cleanup !== '') {
                $this->deleteDirectory($cleanup);
            }
        }
    }

    public static function deleteStoredDirectory(?AiSkill $skill): void
    {
        if (!$skill?->storage_path) {
            return;
        }
        $path = data_path(trim((string)$skill->storage_path, '/'));
        if (!is_dir($path)) {
            return;
        }
        (new self())->deleteDirectory($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSource(string $source): array
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $source)) {
            $source = 'https://github.com/' . $source;
        }

        if (file_exists($source)) {
            $path = realpath($source) ?: $source;
            if (is_file($path) && basename($path) === 'SKILL.md') {
                return [
                    'root' => dirname($path),
                    'source_type' => 'local',
                    'source_url' => null,
                    'source_path' => $path,
                    'cleanup' => null,
                ];
            }
            if (is_dir($path)) {
                return [
                    'root' => $path,
                    'source_type' => 'local',
                    'source_url' => null,
                    'source_path' => $path,
                    'cleanup' => null,
                ];
            }
        }

        if (!filter_var($source, FILTER_VALIDATE_URL)) {
            throw new ExceptionBusiness('暂仅支持本地路径、GitHub 地址、ZIP 地址或 SKILL.md 地址');
        }

        $github = $this->parseGithubSource($source);
        if ($github) {
            return $this->downloadGithubSource($github);
        }

        if (str_ends_with(strtolower(parse_url($source, PHP_URL_PATH) ?: ''), '.zip')) {
            return $this->downloadZipSource($source);
        }

        return $this->downloadMarkdownSource($source);
    }

    /**
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function importDirectory(string $directory, array $resolved, bool $overwrite): array
    {
        $skillFile = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'SKILL.md';
        $markdown = file_get_contents($skillFile);
        if (!is_string($markdown) || trim($markdown) === '') {
            throw new ExceptionBusiness('SKILL.md 读取失败');
        }

        $parsed = Parser::parse($markdown);
        $name = (string)$parsed['name'];
        $existing = AiSkill::query()->where('name', $name)->first();
        if ($existing && !$overwrite) {
            return ['action' => 'skipped', 'name' => $name];
        }

        $storageRelative = 'ai/skills/' . $this->sanitizeName($name);
        $storagePath = data_path($storageRelative);
        $sourcePath = realpath($directory) ?: $directory;
        if (!$this->sameDirectory($sourcePath, $storagePath)) {
            $this->copyDirectory($directory, $storagePath);
        }

        $payload = [
            'name' => $name,
            'title' => $parsed['title'] ?: null,
            'description' => $parsed['description'],
            'content' => $parsed['content'],
            'frontmatter' => $parsed['frontmatter'],
            'source_type' => $resolved['source_type'] ?? null,
            'source_url' => $resolved['source_url'] ?? null,
            'source_path' => $resolved['source_path'] ?? $sourcePath,
            'storage_path' => $storageRelative,
            'compatibility' => $this->resolveCompatibility($parsed),
            'enabled' => $existing?->enabled ?? true,
        ];

        if ($existing) {
            $existing->fill($payload);
            $existing->save();
            return ['action' => 'updated', 'name' => $name];
        }

        AiSkill::query()->create($payload);
        return ['action' => 'created', 'name' => $name];
    }

    /**
     * @return array<int, string>
     */
    private function discoverSkillDirectories(string $root): array
    {
        if (!is_dir($root)) {
            throw new ExceptionBusiness('技能目录不存在');
        }

        $directories = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                static function (SplFileInfo $current): bool {
                    if (!$current->isDir()) {
                        return true;
                    }
                    return !in_array($current->getFilename(), ['.git', 'vendor', 'node_modules'], true);
                }
            )
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            if ($item->getFilename() !== 'SKILL.md') {
                continue;
            }
            $directories[] = $item->getPath();
        }

        $directories = array_values(array_unique($directories));
        sort($directories);
        return $directories;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseGithubSource(string $source): ?array
    {
        if (!preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:/(tree|blob)/([^/]+)(?:/(.*))?)?/?$#', $source, $matches)) {
            return null;
        }

        $repo = preg_replace('/\.git$/', '', (string)$matches[2]) ?: '';
        return [
            'owner' => (string)$matches[1],
            'repo' => $repo,
            'mode' => (string)($matches[3] ?? ''),
            'ref' => (string)($matches[4] ?? ''),
            'path' => trim((string)($matches[5] ?? ''), '/'),
            'url' => $source,
        ];
    }

    /**
     * @param array<string, string> $github
     * @return array<string, mixed>
     */
    private function downloadGithubSource(array $github): array
    {
        if ($github['mode'] === 'blob' && str_ends_with($github['path'], 'SKILL.md')) {
            $rawUrl = sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s',
                $github['owner'],
                $github['repo'],
                $github['ref'] ?: 'main',
                $github['path']
            );
            return $this->downloadMarkdownSource($rawUrl, $github['url']);
        }

        $zipUrl = $github['ref'] !== ''
            ? sprintf('https://api.github.com/repos/%s/%s/zipball/%s', $github['owner'], $github['repo'], $github['ref'])
            : sprintf('https://api.github.com/repos/%s/%s/zipball', $github['owner'], $github['repo']);

        $resolved = $this->downloadZipSource($zipUrl, $github['url']);
        if ($github['path'] !== '') {
            $subPath = rtrim((string)$resolved['root'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $github['path']);
            if (is_dir($subPath)) {
                $resolved['root'] = $subPath;
            } elseif (is_file($subPath) && basename($subPath) === 'SKILL.md') {
                $resolved['root'] = dirname($subPath);
            } else {
                throw new ExceptionBusiness('GitHub 子目录不存在');
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadZipSource(string $url, ?string $sourceUrl = null): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ExceptionBusiness('当前环境未安装 ZipArchive，无法导入 ZIP');
        }

        $tmpDir = $this->makeTempDirectory('ai_skill_zip_');
        $zipFile = $tmpDir . DIRECTORY_SEPARATOR . 'source.zip';
        $this->downloadFile($url, $zipFile);

        $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'extract';
        mkdir($extractDir, 0777, true);

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new ExceptionBusiness('ZIP 解压失败');
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $root = $extractDir;
        $children = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $item): bool => !in_array($item, ['.', '..'], true)));
        if (count($children) === 1) {
            $child = $extractDir . DIRECTORY_SEPARATOR . $children[0];
            if (is_dir($child)) {
                $root = $child;
            }
        }

        return [
            'root' => $root,
            'source_type' => 'url',
            'source_url' => $sourceUrl ?: $url,
            'source_path' => null,
            'cleanup' => $tmpDir,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadMarkdownSource(string $url, ?string $sourceUrl = null): array
    {
        $tmpDir = $this->makeTempDirectory('ai_skill_md_');
        file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'SKILL.md', $this->requestText($url));

        return [
            'root' => $tmpDir,
            'source_type' => 'url',
            'source_url' => $sourceUrl ?: $url,
            'source_path' => null,
            'cleanup' => $tmpDir,
        ];
    }

    private function downloadFile(string $url, string $file): void
    {
        file_put_contents($file, $this->requestText($url));
    }

    private function requestText(string $url): string
    {
        $client = new Client([
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'duxai-skill-importer/1.0',
                'Accept' => 'application/octet-stream,text/plain,application/json',
            ],
        ]);

        try {
            $response = $client->request('GET', $url, [
                RequestOptions::ALLOW_REDIRECTS => true,
            ]);
        } catch (Throwable $e) {
            throw new ExceptionBusiness('技能下载失败');
        }

        if ($response->getStatusCode() >= 400) {
            throw new ExceptionBusiness('技能下载失败');
        }

        $body = (string)$response->getBody();
        if ($body === '') {
            throw new ExceptionBusiness('技能内容为空');
        }

        return $body;
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new ExceptionBusiness('技能目录不存在');
        }

        $this->deleteDirectory($target);
        mkdir($target, 0777, true);

        $items = scandir($source) ?: [];
        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }
            $from = $source . DIRECTORY_SEPARATOR . $item;
            $to = $target . DIRECTORY_SEPARATOR . $item;
            if (is_dir($from)) {
                $this->copyDirectory($from, $to);
                continue;
            }
            copy($from, $to);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }
            $file = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($file)) {
                $this->deleteDirectory($file);
                continue;
            }
            @unlink($file);
        }
        @rmdir($path);
    }

    private function makeTempDirectory(string $prefix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new ExceptionBusiness('临时目录创建失败');
        }
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        mkdir($tmp, 0777, true);
        return $tmp;
    }

    private function sanitizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_\-]/', '-', $name) ?: '';
        $name = preg_replace('/-+/', '-', $name) ?: '';
        $name = trim($name, '-');
        return $name !== '' ? $name : 'skill';
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function resolveCompatibility(array $parsed): string
    {
        $frontmatter = is_array($parsed['frontmatter'] ?? null) ? ($parsed['frontmatter'] ?? []) : [];
        $metadata = is_array($parsed['metadata'] ?? null) ? ($parsed['metadata'] ?? []) : [];
        $openclaw = is_array($metadata['openclaw'] ?? null) ? ($metadata['openclaw'] ?? []) : [];

        if (
            (string)($frontmatter['command-dispatch'] ?? '') !== ''
            || (string)($frontmatter['command-tool'] ?? '') !== ''
            || $openclaw !== []
            || !empty($openclaw['install'])
            || !empty($openclaw['requires'])
        ) {
            return 'partial';
        }

        if ((bool)($metadata['internal'] ?? false)) {
            return 'manual';
        }

        return 'full';
    }

    private function sameDirectory(string $source, string $target): bool
    {
        $sourceReal = realpath($source);
        $targetReal = realpath($target);
        return $sourceReal && $targetReal && $sourceReal === $targetReal;
    }
}
