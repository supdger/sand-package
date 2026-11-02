<?php

declare(strict_types=1);

namespace SandAdmin\Package;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FrontendPublisher
{
    public const RECOVERY_COMMAND = 'composer run-script sand:publish';

    private const CORE_MANIFEST = '.sand-core-source-manifest.json';
    private const MANIFEST = '.sand-package-source-manifest.json';
    private const PENDING = '.sand-package-source-manifest.json.pending';

    /**
     * @return array{
     *     status:string,reason:string,
     *     written:list<string>,adopted:list<string>,skipped:list<string>,
     *     removed:list<string>,retained:list<string>,recovered:bool
     * }
     */
    public static function publish(string $target): array
    {
        $report = self::emptyReport();
        $mode = self::mode();
        if ($mode === 'skip') {
            $report['status'] = 'skipped';
            $report['reason'] = '环境变量 SAND_FRONTEND_PUBLISH=skip，已跳过 SandPackage 前端发布';

            return $report;
        }

        $source = realpath(dirname(__DIR__) . '/sandadmin-artd');
        if ($source === false || !is_dir($source)) {
            throw new RuntimeException('SandPackage 前端源码不存在：' . dirname(__DIR__) . '/sandadmin-artd');
        }
        $source = self::normalizePath($source);

        $target = self::normalizePath($target);
        if ($target === '' || $target === '/' || $target === $source) {
            throw new RuntimeException("SandPackage 前端目标目录无效：{$target}");
        }
        if (!is_dir($target) || !is_file($target . '/' . self::CORE_MANIFEST)) {
            $report['status'] = 'skipped';
            $report['reason'] = 'Sand Core 前端基线未就绪（缺少 ' . $target . '/' . self::CORE_MANIFEST
                . '），已跳过 SandPackage 前端发布；core 前端发布后执行 `' . self::RECOVERY_COMMAND . '` 补齐';

            return $report;
        }

        $force = $mode === 'force';
        $files = self::sourceManifest($source);
        $installed = self::readManifest($target . '/' . self::MANIFEST, $force);
        $pending = self::readManifest($target . '/' . self::PENDING, true);
        $owned = $installed['files'] ?? [];
        $inflight = $pending['files'] ?? [];
        $report['recovered'] = $pending !== null;

        if (!$force) {
            self::assertAdoptable($target, $files, $owned, $inflight);
        }

        self::writePending($target, $files);
        self::copyTree($source, $target, $files, $owned, $inflight, $force, $report);
        self::removeObsolete($target, $owned, $files, $force, $report);
        self::commitPending($target);

        $report['status'] = 'published';

        return $report;
    }

    /** @return array<string,string> */
    private static function sourceManifest(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(
                substr(self::normalizePath($file->getPathname()), strlen($root)),
                '/'
            );
            if (PublishExclusions::publishExcluded($relative)) {
                continue;
            }
            $files[$relative] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @return array{version:string,files:array<string,string>}|null */
    private static function readManifest(string $path, bool $lenient): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)
            || !is_string($decoded['version'] ?? null)
            || !is_array($decoded['files'] ?? null)
        ) {
            if ($lenient) {
                return null;
            }

            throw new RuntimeException(
                "SandPackage 前端发布清单无效：{$path}；修复或删除该文件后执行 `"
                . self::RECOVERY_COMMAND . '`，或使用 SAND_FRONTEND_PUBLISH=force 重建'
            );
        }
        /** @var array<string,string> $decodedFiles */
        $decodedFiles = array_filter($decoded['files'], 'is_string');

        return ['version' => $decoded['version'], 'files' => $decodedFiles];
    }

    /**
     * 目标非空且无清单时按内容寻址：逐字节相同视为已发布，内容不同才拒绝。
     *
     * @param array<string,string> $files
     * @param array<string,string> $owned
     * @param array<string,string> $inflight
     */
    private static function assertAdoptable(string $target, array $files, array $owned, array $inflight): void
    {
        $conflicts = [];
        foreach (array_diff_key($files, $owned) as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (is_link($path)) {
                $conflicts[] = $relative . '（符号链接）';
                continue;
            }
            if (!file_exists($path)) {
                continue;
            }
            if (!is_file($path)) {
                $conflicts[] = $relative . '（目标不是普通文件）';
                continue;
            }
            $current = (string) hash_file('sha256', $path);
            if (hash_equals($hash, $current) || hash_equals($inflight[$relative] ?? '', $current)) {
                continue;
            }
            $conflicts[] = $relative . '（内容与发布源不同）';
        }
        if ($conflicts === []) {
            return;
        }

        throw new RuntimeException(
            '目标已有与 SandPackage 发布源内容不同且未受清单管理的前端文件，拒绝覆盖：' . PHP_EOL
            . '  - ' . implode(PHP_EOL . '  - ', $conflicts) . PHP_EOL
            . '恢复方式：备份或还原上述文件后执行 `' . self::RECOVERY_COMMAND
            . '`；确认可丢弃本地改动时使用 SAND_FRONTEND_PUBLISH=force；暂不发布前端使用 SAND_FRONTEND_PUBLISH=skip'
        );
    }

    /**
     * @param array<string,string> $files
     * @param array<string,string> $owned
     * @param array<string,string> $inflight
     * @param array{written:list<string>,adopted:list<string>,skipped:list<string>,...} $report
     */
    private static function copyTree(
        string $source,
        string $target,
        array $files,
        array $owned,
        array $inflight,
        bool $force,
        array &$report
    ): void {
        foreach ($files as $relative => $hash) {
            self::assertSafeSegments($target, $relative);
            $destination = $target . '/' . $relative;
            if (is_link($destination)) {
                if (!$force) {
                    throw new RuntimeException("发布目标是符号链接，拒绝写入：{$destination}");
                }
                unlink($destination);
            } elseif (is_file($destination)) {
                $current = (string) hash_file('sha256', $destination);
                if (hash_equals($hash, $current)) {
                    $report['adopted'][] = $relative;
                    continue;
                }
                $ours = hash_equals($owned[$relative] ?? '', $current)
                    || hash_equals($inflight[$relative] ?? '', $current);
                if (!$force && !$ours) {
                    $report['skipped'][] = $relative;
                    continue;
                }
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建目录：{$parent}");
            }
            if (!copy($source . '/' . $relative, $destination)) {
                throw new RuntimeException("无法发布 SandPackage 前端文件：{$relative}");
            }
            $report['written'][] = $relative;
        }
    }

    /**
     * 旧清单登记而新版载荷不再提供的文件：哈希未变才删除，已修改保留并告警。
     *
     * @param array<string,string> $owned
     * @param array<string,string> $files
     * @param array{removed:list<string>,retained:list<string>,...} $report
     */
    private static function removeObsolete(
        string $target,
        array $owned,
        array $files,
        bool $force,
        array &$report
    ): void {
        $directories = [];
        foreach (array_diff_key($owned, $files) as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (is_link($path)) {
                $report['retained'][] = $relative;
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            if (!$force && !hash_equals($hash, (string) hash_file('sha256', $path))) {
                $report['retained'][] = $relative;
                continue;
            }
            if (!unlink($path)) {
                $report['retained'][] = $relative;
                continue;
            }
            $report['removed'][] = $relative;
            $directories[dirname($path)] = true;
        }
        foreach (array_keys($directories) as $directory) {
            self::pruneEmptyDirectories($target, $directory);
        }
    }

    /** @param array<string,string> $files */
    private static function writePending(string $target, array $files): void
    {
        $manifest = [
            'schema' => 1,
            'package' => 'supdger/sand-package',
            'version' => class_exists(InstalledVersions::class)
                ? (InstalledVersions::getPrettyVersion('supdger/sand-package') ?? 'dev-main')
                : 'dev-main',
            'files' => $files,
        ];
        $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($content === false
            || file_put_contents($target . '/' . self::PENDING, $content . PHP_EOL, LOCK_EX) === false
        ) {
            throw new RuntimeException('无法写入 SandPackage 前端发布清单草稿：' . $target . '/' . self::PENDING);
        }
    }

    private static function commitPending(string $target): void
    {
        if (!rename($target . '/' . self::PENDING, $target . '/' . self::MANIFEST)) {
            throw new RuntimeException('无法生效 SandPackage 前端发布清单：' . $target . '/' . self::MANIFEST);
        }
    }

    private static function assertSafeSegments(string $base, string $relative): void
    {
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException("发布路径包含符号链接，拒绝写入：{$path}");
            }
        }
    }

    private static function pruneEmptyDirectories(string $root, string $directory): void
    {
        while ($directory !== $root && str_starts_with($directory . '/', $root . '/')) {
            if (!is_dir($directory) || is_link($directory) || !self::effectivelyEmpty($directory)) {
                return;
            }
            foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
                unlink($directory . '/' . $name);
            }
            if (!rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    private static function effectivelyEmpty(string $directory): bool
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            if (!is_file($directory . '/' . $name) || !PublishExclusions::transient($name)) {
                return false;
            }
        }

        return true;
    }

    private static function mode(): string
    {
        $value = getenv('SAND_FRONTEND_PUBLISH');
        if (!is_string($value)) {
            return '';
        }
        $value = strtolower(trim($value));

        return in_array($value, ['skip', 'force'], true) ? $value : '';
    }

    /**
     * @return array{
     *     status:string,reason:string,
     *     written:list<string>,adopted:list<string>,skipped:list<string>,
     *     removed:list<string>,retained:list<string>,recovered:bool
     * }
     */
    private static function emptyReport(): array
    {
        return [
            'status' => 'published',
            'reason' => '',
            'written' => [],
            'adopted' => [],
            'skipped' => [],
            'removed' => [],
            'retained' => [],
            'recovered' => false,
        ];
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
