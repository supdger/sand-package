<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Atomic per-file deployment for a host-payload package's plugin and frontend trees. */
final class HostPayloadRuntimeChangeFiles
{
    private string $journal;

    /** @param array<string,string> $paths */
    public function __construct(
        private readonly string $stateRoot,
        private readonly string $app,
        private readonly array $paths,
        private readonly string $priorSha256,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $priorSha256) !== 1
            || $paths === [] || count($paths) !== count(array_unique(array_values($paths)))) {
            throw new RuntimeException('运行文件变更身份无效');
        }
        foreach ($paths as $source => $target) {
            HostPayloadPlan::assertSafePath($source);
            HostPayloadPlan::assertSafePath($target);
        }
        $this->journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.runtime.json';
        HostPayloadPlan::assertSafePath($this->journal);
    }

    public function begin(): void
    {
        if (file_exists($this->journal)) {
            $previous = $this->read();
            if (!in_array($previous['phase'], ['complete', 'rolled_back'], true)) {
                throw new RuntimeException('已有未完成的运行文件变更记录');
            }
            $this->inspectSnapshot();
            $this->archiveFinished();
        }
        $old = [];
        $entries = [];
        foreach ($this->paths as $source => $target) {
            $prior = FreshInstallRecovery::tree($target);
            $next = FreshInstallRecovery::tree($source);
            $old[$target] = $prior;
            self::merge($prior, $next);
            $entries[] = ['source' => $source, 'target' => $target, 'old' => $prior, 'next' => $next];
        }
        if (self::digest($old) !== $this->priorSha256) {
            throw new RuntimeException('升级前运行目录与上传时摘要不一致');
        }
        $id = bin2hex(random_bytes(16));
        $backup = HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app . '.runtime-backup-' . $id;
        HostPayloadPlan::assertSafePath($backup);
        if (!mkdir($backup, 0700, true)) throw new RuntimeException('无法创建运行文件备份目录');
        foreach ($entries as $index => $entry) {
            if ($entry['old'] !== null) {
                $this->copyTree($entry['target'], $backup . '/target-' . ($index + 1));
            }
        }
        $record = [
            'schema' => 1, 'app' => $this->app, 'id' => $id,
            'phase' => 'pending', 'prior_sha256' => $this->priorSha256, 'entries' => $entries,
        ];
        $this->save($record);
        $this->inspectSnapshot();
    }

    public function hasJournal(): bool
    {
        HostPayloadPlan::assertSafePath($this->journal);
        return is_file($this->journal);
    }

    /** @return array{phase:string,fingerprint:string,expected:array<string,array<string,string>|null>} */
    public function inspectSnapshot(): array
    {
        $record = $this->read();
        $backup = $this->backupRoot($record);
        $expectedBackup = [];
        $actual = [];
        $expected = [];
        foreach ($record['entries'] as $index => $entry) {
            $source = FreshInstallRecovery::tree($entry['source']);
            if ($record['phase'] !== 'rolled_back' && $source !== $entry['next']) {
                throw new RuntimeException('升级候选运行文件已变化');
            }
            $backupPath = $backup . '/target-' . ($index + 1);
            $backupTree = FreshInstallRecovery::tree($backupPath);
            if ($backupTree !== $entry['old']) {
                throw new RuntimeException('升级前运行文件备份已变化');
            }
            if ($backupTree !== null) {
                $expectedBackup['target-' . ($index + 1)] = 'directory';
                foreach ($backupTree as $path => $hash) {
                    $expectedBackup['target-' . ($index + 1) . DIRECTORY_SEPARATOR . $path] = $hash;
                }
            }
            $current = FreshInstallRecovery::tree($entry['target']);
            $next = self::merge($entry['old'], $entry['next']);
            self::assertAllowed($current, $entry['old'], $next, $record['phase']);
            $actual[$entry['target']] = $current;
            $expected[$entry['target']] = $next;
        }
        ksort($expectedBackup, SORT_STRING);
        if (FreshInstallRecovery::tree($backup) !== $expectedBackup) {
            throw new RuntimeException('运行文件备份包含未知内容');
        }
        return [
            'phase' => $record['phase'],
            'fingerprint' => self::digest([$record, $expectedBackup, $actual]),
            'expected' => $expected,
        ];
    }

    public function apply(): void
    {
        $snapshot = $this->inspectSnapshot();
        if ($snapshot['phase'] === 'complete') return;
        $record = $this->read();
        foreach ($record['entries'] as $entry) {
            if ($entry['next'] === null) continue;
            $old = $entry['old'] ?? [];
            foreach ($entry['next'] as $path => $hash) {
                if ($hash !== 'directory') continue;
                $target = $entry['target'] . '/' . $path;
                HostPayloadPlan::assertSafePath($target);
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('无法建立插件运行目录');
                }
            }
            if (!is_dir($entry['target']) && !mkdir($entry['target'], 0755, true)
                && !is_dir($entry['target'])) {
                throw new RuntimeException('无法建立插件运行根目录');
            }
            foreach ($entry['next'] as $path => $hash) {
                if ($hash === 'directory') continue;
                $source = $entry['source'] . '/' . $path;
                $target = $entry['target'] . '/' . $path;
                HostPayloadPlan::assertSafePath($source);
                HostPayloadPlan::assertSafePath($target);
                $current = is_file($target) ? hash_file('sha256', $target) : null;
                if ($current === $hash) continue;
                if ($current !== ($old[$path] ?? null)) {
                    throw new RuntimeException('运行文件在发布前被改写：' . $path);
                }
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new RuntimeException('无法建立插件运行目录');
                }
                $temporary = $parent . '/.sandpackage-' . bin2hex(random_bytes(8)) . '.tmp';
                try {
                    if (!copy($source, $temporary) || hash_file('sha256', $temporary) !== $hash) {
                        throw new RuntimeException('运行文件暂存摘要不匹配');
                    }
                    if ($current === null) {
                        if (!link($temporary, $target)) throw new RuntimeException('运行文件目标被占用');
                    } elseif (hash_file('sha256', $target) !== $current || !rename($temporary, $target)) {
                        throw new RuntimeException('运行文件在替换前被改写');
                    }
                } finally {
                    if (is_file($temporary)) unlink($temporary);
                }
            }
        }
        foreach ($record['entries'] as $entry) {
            if (FreshInstallRecovery::tree($entry['target']) !== self::merge($entry['old'], $entry['next'])) {
                throw new RuntimeException('运行文件发布后摘要不匹配');
            }
        }
        $record['phase'] = 'complete';
        $this->save($record);
    }

    public function archiveFinished(): void
    {
        $record = $this->read();
        if (!in_array($record['phase'], ['complete', 'rolled_back'], true)) {
            throw new RuntimeException('运行文件变更尚未完成');
        }
        $this->inspectSnapshot();
        $raw = file_get_contents($this->journal);
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法归档运行文件变更记录');
        }
    }

    public function markRolledBack(): void
    {
        $this->inspectSnapshot();
        $record = $this->read();
        if ($record['phase'] === 'rolled_back') return;
        if ($record['phase'] !== 'pending') {
            throw new RuntimeException('已发布的运行文件不能标记为 SQL 回滚');
        }
        foreach ($record['entries'] as $entry) {
            if (FreshInstallRecovery::tree($entry['target']) !== $entry['old']) {
                throw new RuntimeException('运行文件已变化，不能声明升级回滚');
            }
        }
        $record['phase'] = 'rolled_back';
        $this->save($record);
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 1048576
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || count($record) !== 6 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $this->app
            || !is_string($record['id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || !in_array($record['phase'] ?? null, ['pending', 'complete', 'rolled_back'], true)
            || ($record['prior_sha256'] ?? null) !== $this->priorSha256
            || !is_array($record['entries'] ?? null)
            || count($record['entries']) !== count($this->paths)) {
            throw new RuntimeException('运行文件变更记录损坏');
        }
        $index = 0;
        foreach ($this->paths as $source => $target) {
            $entry = $record['entries'][$index++] ?? null;
            if (!is_array($entry) || count($entry) !== 4
                || ($entry['source'] ?? null) !== $source || ($entry['target'] ?? null) !== $target
                || !array_key_exists('old', $entry) || !array_key_exists('next', $entry)
                || !is_array($entry['old']) && $entry['old'] !== null
                || !is_array($entry['next']) && $entry['next'] !== null) {
                throw new RuntimeException('运行文件变更路径记录不匹配');
            }
            self::merge($entry['old'], $entry['next']);
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function backupRoot(array $record): string
    {
        $path = HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app . '.runtime-backup-' . $record['id'];
        HostPayloadPlan::assertSafePath($path);
        return $path;
    }

    /** @param array<string,string>|null $old @param array<string,string>|null $next @return array<string,string>|null */
    private static function merge(?array $old, ?array $next): ?array
    {
        if ($old === null && $next === null) return null;
        $merged = $old ?? [];
        foreach ($next ?? [] as $path => $hash) {
            if (!is_string($path) || $path === '' || str_starts_with($path, '/')
                || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)
                || !is_string($hash) || $hash !== 'directory'
                    && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || isset($merged[$path]) && ($merged[$path] === 'directory') !== ($hash === 'directory')) {
                throw new RuntimeException('新旧运行文件目录冲突');
            }
            $merged[$path] = $hash;
        }
        ksort($merged, SORT_STRING);
        return $merged;
    }

    /** @param array<string,string>|null $current @param array<string,string>|null $old @param array<string,string>|null $next */
    private static function assertAllowed(?array $current, ?array $old, ?array $next, string $phase): void
    {
        if ($phase === 'rolled_back' && $current !== $old) {
            throw new RuntimeException('已回滚的运行文件发生变化');
        }
        if ($phase === 'rolled_back') return;
        if ($phase === 'complete' && $current !== $next) {
            throw new RuntimeException('已完成的运行文件发生变化');
        }
        if ($phase === 'complete') return;
        if ($current === null && $old !== null) {
            throw new RuntimeException('原运行目录已消失');
        }
        $known = array_unique(array_merge(array_keys($old ?? []), array_keys($next ?? [])));
        foreach ($current ?? [] as $path => $hash) {
            if (!in_array($path, $known, true)
                || $hash !== ($old[$path] ?? null) && $hash !== ($next[$path] ?? null)) {
                throw new RuntimeException('运行文件包含非本次升级内容');
            }
        }
        foreach ($old ?? [] as $path => $hash) {
            if (!isset($current[$path])) {
                throw new RuntimeException('原运行文件已消失');
            }
        }
    }

    private function copyTree(string $source, string $target): void
    {
        HostPayloadPlan::assertSafePath($target);
        if (!mkdir($target, 0700)) throw new RuntimeException('无法创建运行文件备份');
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('原运行目录含有非法文件');
            }
            $relative = substr($entry->getPathname(), strlen(HostPayloadPlan::directoryPath($source)) + 1);
            $path = $target . '/' . $relative;
            HostPayloadPlan::assertSafePath($path);
            if ($entry->isDir()) {
                if (!mkdir($path, 0700) && !is_dir($path)) throw new RuntimeException('无法备份运行目录');
            } elseif (!copy($entry->getPathname(), $path)) {
                throw new RuntimeException('无法备份运行文件');
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function save(array $record): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $parent = dirname($this->journal);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建运行文件状态目录');
        }
        $temporary = $this->journal . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('无法创建运行文件日志');
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('运行文件日志未持久化');
            }
        } catch (Throwable $error) {
            fclose($handle);
            unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
        if (!rename($temporary, $this->journal)) {
            unlink($temporary);
            throw new RuntimeException('无法保存运行文件日志');
        }
    }

    private static function digest(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
