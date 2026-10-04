<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/server/plugin/sandpackage/tools/system-update-worker.php';

/** Shared strict filesystem rules for the two operator-facing update tools. */
final class SandSystemUpdateReleaseTools
{
    public const STATIC_MANIFEST = '.sand-system-static-manifest.json';

    public static function relative(string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new RuntimeException('文件相对路径无效');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                throw new RuntimeException('文件相对路径无效：' . $path);
            }
        }
        return $path;
    }

    public static function absolute(string $path): string
    {
        $normalized = SandSystemUpdateRuntime::normalizePath($path);
        if ($normalized === '' || preg_match('#^[A-Za-z]:$#', $normalized)) throw new RuntimeException('拒绝文件系统根目录');
        SandSystemUpdateRuntime::safePath($normalized);
        return $normalized;
    }

    /** @return array<string,string> */
    public static function tree(string $root, ?string $ignored = null): array
    {
        self::absolute($root);
        if (!is_dir($root)) throw new RuntimeException('目录不存在：' . $root);
        $files = [];
        $walk = static function (string $directory, string $prefix) use (&$walk, &$files, $ignored): void {
            $entries = scandir($directory);
            if ($entries === false) throw new RuntimeException('无法读取目录：' . $directory);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $relative = self::relative($prefix . $entry);
                $path = $directory . '/' . $entry;
                if (PHP_OS_FAMILY === 'Windows') self::absolute($path);
                if (is_link($path)) throw new RuntimeException('拒绝符号链接文件：' . $relative);
                if (is_dir($path)) { $walk($path, $relative . '/'); continue; }
                if (!is_file($path)) throw new RuntimeException('拒绝非常规文件：' . $relative);
                if ($relative === $ignored) continue;
                $hash = hash_file('sha256', $path);
                if (!is_string($hash)) throw new RuntimeException('无法读取文件：' . $relative);
                $files[$relative] = $hash;
            }
        };
        $walk($root, '');
        ksort($files, SORT_STRING);
        return $files;
    }

    /** Matches worker verifyPackages payload exclusions. */
    public static function excluded(string $path): bool
    {
        $parts = explode('/', $path);
        if (isset($parts[1]) && in_array($parts[1], ['node_modules', 'dist', '.DS_Store', '.idea', '.vscode'], true)) return true;
        return preg_match('#(?:^|/)\.(?:env(?:[^/]*)?|DS_Store|idea|vscode)(?:/|$)#', $path) === 1;
    }

    /** @param list<string> $argv */
    public static function command(array $argv, string $cwd): string
    {
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) throw new RuntimeException('无法启动 Git');
        fclose($pipes[0]);
        // These read-only Git calls have bounded output; archive writes directly to its output file.
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Git 检查失败：' . trim((string) $error));
        return (string) $output;
    }

    public static function json(string $path, array $data): void
    {
        self::absolute($path);
        if (!is_dir(dirname($path))) throw new RuntimeException('输出父目录不存在');
        if (file_exists($path) && !is_file($path)) throw new RuntimeException('输出必须是普通文件');
        $temporary = tempnam(dirname($path), '.sand-update-');
        if ($temporary === false) throw new RuntimeException('无法创建输出文件');
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) throw new RuntimeException('无法写入输出文件');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
