<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;
use ZipArchive;

/** Validates an exact, plugin-owned inventory of host app and config files. */
final class HostPayloadManifest
{
    /**
     * @return list<array{path:string,sha256:string}>
     */
    public static function inspectArchive(ZipArchive $archive, string $app): array
    {
        $actual = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (!is_string($name) || str_ends_with($name, '/')) {
                continue;
            }
            if (str_starts_with($name, 'app/') || str_starts_with($name, 'config/')) {
                $actual[$name] = true;
            }
        }

        return self::inspect(
            $archive->getFromName('host-payload.json'),
            $app,
            $actual,
            static fn (string $path): string|false => $archive->getFromName($path),
        );
    }

    /**
     * @return list<array{path:string,sha256:string}>
     */
    public static function inspectDirectory(string $root, string $app): array
    {
        HostPayloadPlan::assertSafePath($root);
        if (!is_dir($root)) {
            throw new RuntimeException('插件候选目录不存在');
        }
        $actual = [];
        foreach (['app', 'config'] as $name) {
            $directory = HostPayloadPlan::directoryPrefix($root) . $name;
            HostPayloadPlan::assertSafePath($directory);
            if (!file_exists($directory)) {
                continue;
            }
            if (!is_dir($directory)) {
                throw new RuntimeException('应用服务或运行配置目录类型无效');
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isLink() || !$entry->isFile()) {
                    throw new RuntimeException('宿主载荷包含非普通文件');
                }
                $path = HostPayloadPlan::relativePath($entry->getPathname(), $root);
                HostPayloadPlan::assertSafePath($entry->getPathname());
                $actual[$path] = true;
            }
        }
        $manifest = HostPayloadPlan::directoryPrefix($root) . 'host-payload.json';
        HostPayloadPlan::assertSafePath($manifest);
        return self::inspect(
            is_file($manifest) ? file_get_contents($manifest) : false,
            $app,
            $actual,
            static function (string $path) use ($root): string|false {
                $source = HostPayloadPlan::directoryPrefix($root) . $path;
                HostPayloadPlan::assertSafePath($source);
                return is_file($source) ? file_get_contents($source) : false;
            },
        );
    }

    /**
     * @param array<string,bool> $actual
     * @param callable(string):string|false $read
     * @return list<array{path:string,sha256:string}>
     */
    private static function inspect(string|false $raw, string $app, array $actual, callable $read): array
    {
        if ($raw === false) {
            if ($actual !== []) {
                throw new RuntimeException('应用服务或运行配置缺少 host-payload.json 精确清单');
            }
            return [];
        }
        if (strlen($raw) > 1048576) {
            throw new RuntimeException('host-payload.json 不可读取或过大');
        }
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || count($manifest) !== 3
            || ($manifest['schema'] ?? null) !== 1 || ($manifest['app'] ?? null) !== $app
            || !is_array($manifest['files']) || !array_is_list($manifest['files'])) {
            throw new RuntimeException('host-payload.json 格式或插件标识无效');
        }

        $declared = [];
        $files = [];
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry) || count($entry) !== 2
                || !array_key_exists('path', $entry) || !array_key_exists('sha256', $entry)) {
                throw new RuntimeException('host-payload.json 文件条目无效');
            }
            $path = $entry['path'];
            $sha256 = $entry['sha256'];
            if (!is_string($path) || !self::allowedPath($path, $app)
                || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
                || isset($declared[$path])) {
                throw new RuntimeException('host-payload.json 包含不安全或重复文件');
            }
            $contents = $read($path);
            if (!is_string($contents) || !hash_equals($sha256, hash('sha256', $contents))) {
                throw new RuntimeException('host-payload.json 文件摘要不匹配：' . $path);
            }
            $declared[$path] = true;
            $files[] = ['path' => $path, 'sha256' => $sha256];
        }
        $listed = array_keys($declared);
        $present = array_keys($actual);
        sort($listed, SORT_STRING);
        sort($present, SORT_STRING);
        if ($listed !== $present) {
            throw new RuntimeException('host-payload.json 与应用服务/运行配置实际文件不一致');
        }
        return $files;
    }

    public static function allowedPath(string $path, string $app): bool
    {
        if (str_contains($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1f]/', $path)) {
            return false;
        }
        $parts = explode('/', $path);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            return false;
        }
        if (str_starts_with($path, 'app/')) {
            return count($parts) >= 3
                && preg_match('~^app/(?:[A-Za-z][A-Za-z0-9_]*/)+[A-Za-z][A-Za-z0-9_]*\.php$~D', $path) === 1;
        }
        $prefix = str_replace('-', '_', $app);
        return preg_match('~^config/' . preg_quote($prefix, '~') . '_[a-z][a-z0-9_]*\.php$~D', $path) === 1;
    }
}
