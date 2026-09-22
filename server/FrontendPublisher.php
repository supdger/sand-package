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
    private const CORE_MANIFEST = '.sand-core-source-manifest.json';
    private const MANIFEST = '.sand-package-source-manifest.json';

    public static function publish(string $target): void
    {
        $target = self::normalizePath($target);
        if (!is_dir($target) || !is_file($target . '/' . self::CORE_MANIFEST)) {
            throw new RuntimeException('Sand Core 前端基线不存在，无法发布 SandPackage 前端');
        }

        $source = realpath(dirname(__DIR__) . '/sandadmin-artd');
        if ($source === false || !is_dir($source)) {
            throw new RuntimeException('SandPackage 前端源码不存在');
        }

        $files = self::sourceManifest($source);
        $installed = self::readManifest($target);
        if ($installed === null) {
            self::assertNoUnmanagedCollision($target, $files);
        } else {
            self::assertUnmodified($target, $installed['files']);
        }

        self::copyTree($source, $target);
        self::writeManifest($target, $files);
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
            $relative = str_replace('\\', '/', $iterator->getSubPathName());
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** @return array{version:string,files:array<string,string>}|null */
    private static function readManifest(string $target): ?array
    {
        $path = $target . '/' . self::MANIFEST;
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)
            || !is_string($decoded['version'] ?? null)
            || !is_array($decoded['files'] ?? null)
        ) {
            throw new RuntimeException("SandPackage 前端发布清单无效：{$path}");
        }

        return $decoded;
    }

    /** @param array<string,string> $files */
    private static function assertUnmodified(string $target, array $files): void
    {
        foreach ($files as $relative => $hash) {
            $path = $target . '/' . $relative;
            if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException("拒绝覆盖已修改的 SandPackage 前端源码：{$relative}");
            }
        }
    }

    /** @param array<string,string> $files */
    private static function assertNoUnmanagedCollision(string $target, array $files): void
    {
        foreach (array_keys($files) as $relative) {
            if (file_exists($target . '/' . $relative) || is_link($target . '/' . $relative)) {
                throw new RuntimeException("目标已有未受管理的 SandPackage 前端文件：{$relative}");
            }
        }
    }

    private static function copyTree(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', $iterator->getSubPathName());
            $destination = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
                    throw new RuntimeException("无法创建目录：{$destination}");
                }
                continue;
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建目录：{$parent}");
            }
            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException("无法发布 SandPackage 前端文件：{$relative}");
            }
        }
    }

    /** @param array<string,string> $files */
    private static function writeManifest(string $target, array $files): void
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
            || file_put_contents($target . '/' . self::MANIFEST, $content . PHP_EOL, LOCK_EX) === false
        ) {
            throw new RuntimeException('无法写入 SandPackage 前端发布清单');
        }
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
