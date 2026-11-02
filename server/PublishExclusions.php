<?php

declare(strict_types=1);

namespace SandAdmin\Package;

/**
 * 发布排除规则的单一来源。
 *
 * 前端发布器、composer archive 与 git export-ignore 三个通道全部由本类派生，
 * 一致性由 tools/assert-publish-exclusions.php 断言，不一致即失败。
 * DIRECTORIES / FILES / FILE_PREFIXES 与 sand-core 的 SandAdmin\Core\PublishExclusions 同步。
 */
final class PublishExclusions
{
    /** 任意层级都不进入发布面的目录名。 */
    public const DIRECTORIES = [
        '.git',
        '.github',
        '.husky',
        '.idea',
        '.vscode',
        'dist',
        'node_modules',
        'tests',
        'vendor',
    ];

    /** 任意层级都不进入发布面的文件名。 */
    public const FILES = [
        '.DS_Store',
        '.gitattributes',
        'commitlint.config.cjs',
        'lint-staged.config.cjs',
        'lint-staged.config.js',
        'lint-staged.config.mjs',
        'Thumbs.db',
        'composer.lock',
        'desktop.ini',
    ];

    /** 任意层级都不进入发布面的文件名前缀。 */
    public const FILE_PREFIXES = [
        '._',
    ];

    /** 本包专有的包根相对路径（以 / 开头）。 */
    public const PATHS = [
        '/sandadmin-artd/.env',
        '/sandadmin-artd/.env.development',
        '/tools',
    ];

    /** 判定目录是否为空时忽略的易失文件名。 */
    public const TRANSIENT_FILES = [
        '.DS_Store',
        'Thumbs.db',
        'desktop.ini',
    ];

    /** 判定目录是否为空时忽略的易失文件名前缀。 */
    public const TRANSIENT_FILE_PREFIXES = [
        '._',
    ];

    /** 相对发布源根目录的路径是否被排除。 */
    public static function excluded(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, self::DIRECTORIES, true) || in_array($segment, self::FILES, true)) {
                return true;
            }
            foreach (self::FILE_PREFIXES as $prefix) {
                if (str_starts_with($segment, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 发布源根（sandadmin-artd）下不随载荷发布的路径。
     *
     * @return list<string>
     */
    public static function sourcePaths(): array
    {
        $prefix = '/sandadmin-artd/';
        $paths = [];
        foreach (self::PATHS as $path) {
            if (str_starts_with($path, $prefix)) {
                $paths[] = substr($path, strlen($prefix));
            }
        }

        return $paths;
    }

    /** 相对发布源根目录的路径是否不进入前端载荷。 */
    public static function publishExcluded(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        return self::excluded($relative) || in_array($relative, self::sourcePaths(), true);
    }

    /** 目录内容是否只剩易失文件。 */
    public static function transient(string $name): bool
    {
        if (in_array($name, self::TRANSIENT_FILES, true)) {
            return true;
        }
        foreach (self::TRANSIENT_FILE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * composer.json archive.exclude 的期望值。
     *
     * @return list<string>
     */
    public static function archiveExclude(): array
    {
        $patterns = [];
        foreach (self::DIRECTORIES as $directory) {
            $patterns[] = '/**/' . $directory;
        }
        foreach (self::FILES as $file) {
            $patterns[] = '/**/' . $file;
        }
        foreach (self::FILE_PREFIXES as $prefix) {
            $patterns[] = '/**/' . $prefix . '*';
        }
        foreach (self::PATHS as $path) {
            $patterns[] = $path;
        }
        sort($patterns);

        return $patterns;
    }

    /**
     * .gitattributes export-ignore 的期望行。
     *
     * @return list<string>
     */
    public static function exportIgnore(): array
    {
        $lines = [];
        foreach (self::DIRECTORIES as $directory) {
            $lines[] = $directory . '/ export-ignore';
        }
        foreach (self::FILES as $file) {
            $lines[] = $file . ' export-ignore';
        }
        foreach (self::FILE_PREFIXES as $prefix) {
            $lines[] = $prefix . '* export-ignore';
        }
        foreach (self::PATHS as $path) {
            $lines[] = $path . ' export-ignore';
        }
        sort($lines);

        return $lines;
    }
}
