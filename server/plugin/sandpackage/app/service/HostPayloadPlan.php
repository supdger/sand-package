<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Read-only per-file plan for app/config payloads in shared host directories. */
final class HostPayloadPlan
{
    /** Remove only the current platform's trailing separators, retaining absolute roots. */
    public static function directoryPath(string $path, ?bool $windows = null): string
    {
        $windows ??= DIRECTORY_SEPARATOR === '\\';
        $normalized = $windows ? str_replace('\\', '/', $path) : $path;
        if (str_contains($windows && str_starts_with($normalized, '//') ? substr($normalized, 2) : $normalized, '//')) {
            throw new RuntimeException('宿主目录包含重复分隔符');
        }
        $trimmed = rtrim($path, $windows ? '/\\' : '/');
        if ($trimmed === '' && str_starts_with($path, '/')) return '/';
        if ($windows && preg_match('/^[A-Za-z]:$/D', $trimmed) === 1) {
            if (strlen($path) === 2) throw new RuntimeException('宿主目录不能使用驱动器相对路径');
            return $trimmed . '/';
        }
        return $trimmed;
    }

    /** Directory prefix for appending a relative pathname without duplicate separators. */
    public static function directoryPrefix(string $path, ?bool $windows = null): string
    {
        $directory = self::directoryPath($path, $windows);
        return rtrim($directory, '/') . '/';
    }

    /** Canonical manifest key only; callers keep the native pathname for file I/O. */
    public static function relativePath(string $pathname, string $root, ?bool $windows = null): string
    {
        $windows ??= DIRECTORY_SEPARATOR === '\\';
        if ($windows) {
            $pathname = str_replace('\\', '/', $pathname);
            $root = str_replace('\\', '/', $root);
        }
        $prefix = rtrim($root, '/') . '/';
        if (!str_starts_with($pathname, $prefix)) {
            throw new RuntimeException('宿主载荷文件不在声明目录内');
        }
        $relative = substr($pathname, strlen($prefix));
        if ($relative === '' || str_contains($relative, '\\') || preg_match('/[\x00-\x1f]/', $relative)
            || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) {
            throw new RuntimeException('宿主载荷相对路径无效');
        }
        return $relative;
    }

    /**
     * @param list<array{path:string,sha256:string}> $incoming
     * @param list<array{path:string,sha256:string}> $owned
     * @return array{add:list<string>,replace:list<string>,remove:list<string>}
     */
    public static function inspect(string $hostRoot, string $app, array $incoming, array $owned = []): array
    {
        self::assertSafePath($hostRoot);
        if (!is_dir($hostRoot)) {
            throw new RuntimeException('宿主后端目录不存在');
        }
        $next = self::normalizeFiles($incoming, $app);
        $previous = self::normalizeFiles($owned, $app);
        $plan = ['add' => [], 'replace' => [], 'remove' => []];

        foreach ($previous as $path => $sha256) {
            $target = HostPayloadPlan::directoryPrefix($hostRoot) . $path;
            self::assertSafePath($target);
            if (!is_file($target) || !hash_equals($sha256, (string) hash_file('sha256', $target))) {
                throw new RuntimeException('已归属的宿主文件缺失或被外部修改：' . $path);
            }
            if (!isset($next[$path])) {
                $plan['remove'][] = $path;
            }
        }
        foreach ($next as $path => $sha256) {
            $target = HostPayloadPlan::directoryPrefix($hostRoot) . $path;
            self::assertSafePath($target);
            if (!isset($previous[$path])) {
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('宿主文件与插件载荷冲突：' . $path);
                }
                $plan['add'][] = $path;
            } elseif (!hash_equals($previous[$path], $sha256)) {
                $plan['replace'][] = $path;
            }
        }
        return $plan;
    }

    /**
     * @param list<array{path:string,sha256:string}> $files
     * @return array<string,string>
     */
    public static function normalizeFiles(array $files, string $app): array
    {
        $indexed = [];
        foreach ($files as $entry) {
            $path = $entry['path'] ?? null;
            $sha256 = $entry['sha256'] ?? null;
            if (!is_string($path) || !HostPayloadManifest::allowedPath($path, $app)
                || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
                || isset($indexed[$path])) {
                throw new RuntimeException('宿主文件归属记录无效');
            }
            $indexed[$path] = $sha256;
        }
        ksort($indexed, SORT_STRING);
        return $indexed;
    }

    public static function assertSafePath(string $path, ?bool $windows = null): void
    {
        $windows ??= DIRECTORY_SEPARATOR === '\\';
        if (str_contains($path, "\0")) throw new RuntimeException('宿主目标路径无效');
        $current = '';
        if ($windows) {
            $path = str_replace('\\', '/', $path);
            if (preg_match('~^[a-z]:/~i', $path)) {
                $current = substr($path, 0, 2);
                $path = substr($path, 3);
            } elseif (preg_match('~^//([^/]+)/([^/]+)(?:/|$)~', $path, $match)
                && !in_array($match[1], ['.', '..', '?'], true)
                && !in_array($match[2], ['.', '..'], true)) {
                $current = '//' . $match[1] . '/' . $match[2];
                $path = substr($path, strlen($match[0]));
            } else {
                throw new RuntimeException('宿主目标路径无效');
            }
        } elseif (str_starts_with($path, '/')) {
            $path = ltrim($path, '/');
        } else {
            throw new RuntimeException('宿主目标路径无效');
        }
        if ($path === '') throw new RuntimeException('宿主目标路径无效');
        $parts = explode('/', rtrim($path, '/'));
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('宿主目标路径无效');
            }
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new RuntimeException('宿主目标路径经过符号链接');
            }
            if ($index < count($parts) - 1 && file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('宿主目标路径的父级不是目录');
            }
        }
    }
}
