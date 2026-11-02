<?php

declare(strict_types=1);

namespace SandAdmin\Package;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class Install
{
    public const WEBMAN_PLUGIN = true;

    private const PATH_RELATION = [
        'plugin/sandpackage' => 'plugin/sandpackage',
    ];

    /** 仅首次安装写入、后续更新不覆盖宿主定制的载荷子树。 */
    private const FIRST_WRITE_ONLY = [
        'config/',
    ];

    private const PAYLOAD_MANIFEST = '.sand-package-payload-manifest.json';

    public static function install(): void
    {
        self::run();
    }

    public static function update(): void
    {
        self::run();
    }

    public static function uninstall(): void
    {
        foreach (self::PATH_RELATION as $source => $destination) {
            $target = self::normalizePath(base_path() . '/' . $destination);
            if (!is_dir($target) || is_link($target)) {
                continue;
            }
            $registered = self::readPayloadManifest($target)
                ?? self::sourceManifest(self::normalizePath(__DIR__ . '/' . $source));
            $retained = [];
            $directories = [];
            foreach ($registered as $relative => $hash) {
                $path = $target . '/' . $relative;
                if (is_link($path)) {
                    $retained[] = $relative;
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                if (!hash_equals($hash, (string) hash_file('sha256', $path)) || !unlink($path)) {
                    $retained[] = $relative;
                    continue;
                }
                $directories[dirname($path)] = true;
            }
            $manifest = $target . '/' . self::PAYLOAD_MANIFEST;
            if (is_file($manifest)) {
                unlink($manifest);
            }
            foreach (array_keys($directories) as $directory) {
                self::pruneEmptyDirectories($target, $directory);
            }
            self::pruneEmptyDirectories(dirname($target), $target);
            if ($retained !== []) {
                self::warn(
                    'SandPackage 后端载荷中以下文件与安装记录不符，已保留未删除：' . PHP_EOL
                    . '  - ' . implode(PHP_EOL . '  - ', $retained)
                );
            }
            if (is_dir($target)) {
                self::warn("SandPackage 后端载荷目录存在未登记文件，已保留：{$target}；确认可丢弃后手动删除该目录");
            }
        }
    }

    public static function frontendTarget(): string
    {
        $basePath = self::normalizePath((string) (realpath(base_path()) ?: base_path()));
        $boundary = self::normalizePath(dirname($basePath));
        $configured = self::configuredFrontendPath($basePath);
        $candidate = $configured ?? (basename($basePath) === 'server'
            ? $boundary . '/sandadmin-artd'
            : $basePath . '/sandadmin-artd');

        return self::resolveWithinBoundary($candidate, $boundary);
    }

    private static function run(): void
    {
        self::installByRelation();
        try {
            self::reportFrontend(FrontendPublisher::publish(self::frontendTarget()));
        } catch (Throwable $exception) {
            self::warn('SandPackage 前端发布未完成：' . $exception->getMessage());
            self::warn(
                '后端载荷已安装，Composer 不因此中止；修正后执行 `' . FrontendPublisher::RECOVERY_COMMAND
                . '` 补齐前端（SAND_FRONTEND_PUBLISH=force 强制重发布，=skip 暂时跳过）'
            );
        }
    }

    private static function installByRelation(): void
    {
        foreach (self::PATH_RELATION as $source => $destination) {
            $sourceRoot = self::normalizePath(__DIR__ . '/' . $source);
            if (!is_dir($sourceRoot)) {
                throw new RuntimeException("SandPackage 后端载荷源目录不存在：{$sourceRoot}");
            }
            $target = self::normalizePath(base_path() . '/' . $destination);
            if (is_link($target)) {
                throw new RuntimeException("SandPackage 后端载荷目标是符号链接，拒绝写入：{$target}");
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建目录：{$parent}");
            }
            if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
                throw new RuntimeException("无法创建目录：{$target}");
            }
            $registered = self::readPayloadManifest($target) ?? [];
            $written = self::copyPayload($sourceRoot, $target, $registered);
            self::removeObsoletePayload($target, $registered, $written);
            self::writePayloadManifest($target, $written);
        }
    }

    /**
     * @param array<string,string> $registered
     * @return array<string,string>
     */
    private static function copyPayload(string $source, string $target, array $registered): array
    {
        $written = [];
        $overwritten = [];
        foreach (self::sourceManifest($source) as $relative => $hash) {
            self::assertSafeSegments($target, $relative);
            $destination = $target . '/' . $relative;
            if (is_link($destination)) {
                throw new RuntimeException("SandPackage 后端载荷目标是符号链接，拒绝写入：{$destination}");
            }
            if (is_file($destination)) {
                if (self::firstWriteOnly($relative)) {
                    if (isset($registered[$relative])) {
                        $written[$relative] = $registered[$relative];
                    }
                    continue;
                }
                $current = (string) hash_file('sha256', $destination);
                if (hash_equals($hash, $current)) {
                    $written[$relative] = $hash;
                    continue;
                }
                if (isset($registered[$relative]) && !hash_equals($registered[$relative], $current)) {
                    $overwritten[] = $relative;
                }
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建目录：{$parent}");
            }
            if (!copy($source . '/' . $relative, $destination)) {
                throw new RuntimeException("无法安装 SandPackage 后端载荷文件：{$relative}");
            }
            $written[$relative] = $hash;
        }
        if ($overwritten !== []) {
            self::warn(
                'SandPackage 后端载荷中以下文件曾被本地修改，已按新版本覆盖（后端载荷需整体一致）：' . PHP_EOL
                . '  - ' . implode(PHP_EOL . '  - ', $overwritten)
            );
        }

        return $written;
    }

    /**
     * @param array<string,string> $registered
     * @param array<string,string> $written
     */
    private static function removeObsoletePayload(string $target, array $registered, array $written): void
    {
        $retained = [];
        $directories = [];
        foreach (array_diff_key($registered, $written) as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (is_link($path)) {
                $retained[] = $relative;
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            if (!hash_equals($hash, (string) hash_file('sha256', $path)) || !unlink($path)) {
                $retained[] = $relative;
                continue;
            }
            $directories[dirname($path)] = true;
        }
        foreach (array_keys($directories) as $directory) {
            self::pruneEmptyDirectories($target, $directory);
        }
        if ($retained !== []) {
            self::warn(
                '新版本不再提供以下 SandPackage 后端载荷文件，但其内容与安装记录不符，已保留：' . PHP_EOL
                . '  - ' . implode(PHP_EOL . '  - ', $retained)
            );
        }
    }

    /** @return array<string,string> */
    private static function sourceManifest(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(substr(self::normalizePath($file->getPathname()), strlen($root)), '/');
            if ($relative === self::PAYLOAD_MANIFEST || PublishExclusions::excluded($relative)) {
                continue;
            }
            $files[$relative] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @return array<string,string>|null */
    private static function readPayloadManifest(string $target): ?array
    {
        $path = $target . '/' . self::PAYLOAD_MANIFEST;
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
            return null;
        }

        return array_filter($decoded['files'], 'is_string');
    }

    /** @param array<string,string> $files */
    private static function writePayloadManifest(string $target, array $files): void
    {
        ksort($files);
        $content = json_encode(
            ['schema' => 1, 'package' => 'supdger/sand-package', 'files' => $files],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ($content === false
            || file_put_contents($target . '/' . self::PAYLOAD_MANIFEST, $content . PHP_EOL, LOCK_EX) === false
        ) {
            throw new RuntimeException('无法写入 SandPackage 后端载荷安装记录：' . $target . '/' . self::PAYLOAD_MANIFEST);
        }
    }

    private static function configuredFrontendPath(string $basePath): ?string
    {
        $path = $basePath . '/composer.json';
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $configured = is_array($decoded) ? ($decoded['extra']['sandadmin']['frontend-path'] ?? null) : null;
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }
        $configured = self::normalizePath(trim($configured));

        return self::absolute($configured) ? $configured : $basePath . '/' . $configured;
    }

    private static function resolveWithinBoundary(string $candidate, string $boundary): string
    {
        $lexical = self::lexical($candidate);
        self::assertWithinBoundary($lexical, $boundary);
        $path = $boundary;
        foreach (explode('/', trim(substr($lexical, strlen($boundary)), '/')) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException(
                    "SandPackage 前端目标路径包含符号链接，拒绝写入：{$path}；"
                    . '请在宿主 composer.json 的 extra.sandadmin.frontend-path 指向真实目录'
                );
            }
        }
        $resolved = self::resolveExisting($lexical);
        self::assertWithinBoundary($resolved, $boundary);

        return $resolved;
    }

    private static function assertWithinBoundary(string $path, string $boundary): void
    {
        if ($path !== $boundary && str_starts_with($path . '/', $boundary . '/')) {
            return;
        }

        throw new RuntimeException(
            "SandPackage 前端目标目录越界，拒绝写入：{$path}（允许范围：{$boundary} 之内）；"
            . '请在宿主 composer.json 的 extra.sandadmin.frontend-path 显式声明前端目录（例如 "../sandadmin-artd"）'
        );
    }

    private static function resolveExisting(string $path): string
    {
        $missing = [];
        $probe = $path;
        while ($probe !== '' && $probe !== '/' && !file_exists($probe)) {
            $missing[] = basename($probe);
            $parent = self::normalizePath(dirname($probe));
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        $real = realpath($probe);
        if ($real === false) {
            return $path;
        }

        return self::normalizePath($real) . ($missing === [] ? '' : '/' . implode('/', array_reverse($missing)));
    }

    private static function lexical(string $path): string
    {
        $path = self::normalizePath($path);
        $absolute = self::absolute($path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return ($absolute && !preg_match('#^[A-Za-z]:#', $path) ? '/' : '') . implode('/', $segments);
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    private static function firstWriteOnly(string $relative): bool
    {
        foreach (self::FIRST_WRITE_ONLY as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function assertSafeSegments(string $base, string $relative): void
    {
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException("载荷路径包含符号链接，拒绝写入：{$path}");
            }
        }
    }

    private static function pruneEmptyDirectories(string $root, string $directory): void
    {
        while ($directory !== $root && str_starts_with($directory . '/', $root . '/')) {
            if (!is_dir($directory) || is_link($directory)) {
                return;
            }
            $entries = array_diff(scandir($directory) ?: [], ['.', '..']);
            foreach ($entries as $name) {
                if (!is_file($directory . '/' . $name) || !PublishExclusions::transient($name)) {
                    return;
                }
            }
            foreach ($entries as $name) {
                unlink($directory . '/' . $name);
            }
            if (!rmdir($directory)) {
                return;
            }
            $directory = self::normalizePath(dirname($directory));
        }
    }

    /** @param array<string,mixed> $report */
    private static function reportFrontend(array $report): void
    {
        if (($report['status'] ?? '') === 'skipped') {
            self::warn('SandPackage 前端发布已跳过：' . (string) ($report['reason'] ?? ''));

            return;
        }
        foreach ([
            'skipped' => 'SandPackage 前端以下文件已被本地修改，已跳过覆盖并保留：',
            'retained' => '新版本不再提供以下 SandPackage 前端文件，但其内容已被修改，已保留：',
        ] as $key => $message) {
            $items = $report[$key] ?? [];
            if (is_array($items) && $items !== []) {
                self::warn($message . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $items));
            }
        }
    }

    private static function warn(string $message): void
    {
        $line = '[sandadmin] ' . $message . PHP_EOL;
        if (defined('STDERR')) {
            fwrite(STDERR, $line);

            return;
        }
        $handle = fopen('php://stderr', 'wb');
        if (is_resource($handle)) {
            fwrite($handle, $line);
            fclose($handle);
        }
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
