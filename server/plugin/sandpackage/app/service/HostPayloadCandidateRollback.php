<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Preserves the failed upgrade candidate while restoring its exact previous package. */
final class HostPayloadCandidateRollback
{
    private string $journal;
    private string $candidate;
    private string $backup;

    public function __construct(
        private readonly string $storageRoot,
        private readonly string $app,
        private readonly string $backupId,
        private readonly string $oldTreeSha256,
        private readonly string $newTreeSha256,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || preg_match('/^' . preg_quote($app, '/') . '-[a-f0-9]{16}$/D', $backupId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $oldTreeSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $newTreeSha256) !== 1) {
            throw new RuntimeException('旧候选恢复身份无效');
        }
        $root = HostPayloadPlan::directoryPath($storageRoot);
        $this->candidate = $root . '/' . $app;
        $this->backup = $root . '/backups/' . $backupId;
        $this->journal = $root . '/host-payload/' . $app . '.rollback.json';
        foreach ([$this->candidate, $this->backup, $this->journal] as $path) {
            HostPayloadPlan::assertSafePath($path);
        }
    }

    /** @return array{backup_id:string,old_tree_sha256:string,new_tree_sha256:string,source:string,phase:string}|null */
    public static function pendingState(string $storageRoot, string $app): ?array
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new RuntimeException('旧候选恢复插件标识无效');
        }
        $journal = HostPayloadPlan::directoryPrefix($storageRoot) . 'host-payload/' . $app . '.rollback.json';
        HostPayloadPlan::assertSafePath($journal);
        if (!file_exists($journal)) return null;
        $raw = is_file($journal) && filesize($journal) <= 1048576
            ? file_get_contents($journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record)) throw new RuntimeException('旧候选恢复记录损坏');
        $rollback = new self(
            $storageRoot, $app,
            (string) ($record['backup_id'] ?? ''),
            (string) ($record['old_tree_sha256'] ?? ''),
            (string) ($record['new_tree_sha256'] ?? ''),
        );
        $record = $rollback->read();
        $failed = $rollback->path($record, 'failed');
        $source = is_dir($failed) ? $failed : $rollback->candidate;
        $tree = FreshInstallRecovery::tree($source);
        if (!is_array($tree) || self::digest($tree) !== $record['failed_tree_sha256']) {
            throw new RuntimeException('失败升级候选已变化或缺失');
        }
        if ($source === $failed) {
            $restored = FreshInstallRecovery::tree($rollback->candidate);
            if ($restored !== null && self::digest($restored) !== $rollback->oldTreeSha256) {
                throw new RuntimeException('旧候选恢复目标已被其他内容占用');
            }
        }
        return [
            'backup_id' => $rollback->backupId,
            'old_tree_sha256' => $rollback->oldTreeSha256,
            'new_tree_sha256' => $rollback->newTreeSha256,
            'source' => $source,
            'phase' => $record['phase'],
        ];
    }

    /**
     * @param callable(string):void|null $fault
     * @return array{phase:string,failed_candidate:string,resumed:bool}
     */
    public function restore(?callable $fault = null): array
    {
        $oldTree = FreshInstallRecovery::tree($this->backup);
        if (!is_array($oldTree) || self::digest($oldTree) !== $this->oldTreeSha256) {
            throw new RuntimeException('升级前候选备份已变化');
        }
        $resumed = is_file($this->journal);
        if ($resumed) {
            $record = $this->read();
        } else {
            $nextTree = FreshInstallRecovery::tree($this->candidate, ['info.ini']);
            $fullTree = FreshInstallRecovery::tree($this->candidate);
            if (!is_array($nextTree) || !is_array($fullTree)
                || self::digest($nextTree) !== $this->newTreeSha256) {
                throw new RuntimeException('失败升级候选已变化');
            }
            $record = [
                'schema' => 1, 'app' => $this->app, 'id' => bin2hex(random_bytes(16)),
                'backup_id' => $this->backupId,
                'old_tree_sha256' => $this->oldTreeSha256,
                'new_tree_sha256' => $this->newTreeSha256,
                'failed_tree_sha256' => self::digest($fullTree),
                'phase' => 'prepared',
            ];
            $this->save($record);
            $fault?->__invoke('prepared');
        }
        $stage = $this->path($record, 'stage');
        $failed = $this->path($record, 'failed');
        $candidateTree = FreshInstallRecovery::tree($this->candidate);
        $failedTree = FreshInstallRecovery::tree($failed);
        $oldPresent = is_array($candidateTree) && self::digest($candidateTree) === $this->oldTreeSha256;
        $newPresent = is_array($candidateTree) && self::digest($candidateTree) === $record['failed_tree_sha256'];
        if ($candidateTree !== null && !$oldPresent && !$newPresent
            || $failedTree !== null && (!is_array($failedTree)
                || self::digest($failedTree) !== $record['failed_tree_sha256'])
            || $oldPresent && $failedTree === null
            || $oldPresent && (file_exists($stage) || is_link($stage))
            || $newPresent && $failedTree !== null
            || $candidateTree === null && $failedTree === null) {
            throw new RuntimeException('旧候选恢复目录状态与日志不符');
        }
        if ($oldPresent) {
            $record['phase'] = 'restored';
            $this->save($record);
            return ['phase' => 'restored', 'failed_candidate' => $failed, 'resumed' => $resumed];
        }
        $stageTree = FreshInstallRecovery::tree($stage);
        if (!is_array($stageTree) || self::digest($stageTree) !== $this->oldTreeSha256) {
            if ($stageTree !== null) {
                foreach ($stageTree as $path => $hash) {
                    if (!array_key_exists($path, $oldTree) || $oldTree[$path] !== $hash) {
                        throw new RuntimeException('旧候选暂存包含非备份文件或内容变化');
                    }
                }
                $this->removeStage($stage);
            }
            $this->copyBackup($stage);
            $this->syncFiles($stage);
            $stageTree = FreshInstallRecovery::tree($stage);
            if (!is_array($stageTree) || self::digest($stageTree) !== $this->oldTreeSha256) {
                throw new RuntimeException('升级前候选暂存摘要不匹配');
            }
        }
        $record['phase'] = 'staged';
        $this->save($record);
        $fault?->__invoke('staged');
        if ($newPresent) {
            if (!rename($this->candidate, $failed)) {
                throw new RuntimeException('无法隔离失败升级候选');
            }
            $record['phase'] = 'quarantined';
            $this->save($record);
            $fault?->__invoke('quarantined');
        }
        if (!rename($stage, $this->candidate)) {
            throw new RuntimeException('无法恢复升级前候选');
        }
        $record['phase'] = 'restored';
        $this->save($record);
        $fault?->__invoke('restored');
        return ['phase' => 'restored', 'failed_candidate' => $failed, 'resumed' => $resumed];
    }

    public function archiveFinished(): void
    {
        $record = $this->read();
        if ($record['phase'] !== 'restored'
            || file_exists($this->path($record, 'stage')) || is_link($this->path($record, 'stage'))
            || self::digest(FreshInstallRecovery::tree($this->candidate)) !== $this->oldTreeSha256
            || self::digest(FreshInstallRecovery::tree($this->path($record, 'failed'))) !== $record['failed_tree_sha256']) {
            throw new RuntimeException('旧候选恢复尚未完成');
        }
        $raw = file_get_contents($this->journal);
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法归档旧候选恢复记录');
        }
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 1048576
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || count($record) !== 8 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $this->app
            || !is_string($record['id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || ($record['backup_id'] ?? null) !== $this->backupId
            || ($record['old_tree_sha256'] ?? null) !== $this->oldTreeSha256
            || ($record['new_tree_sha256'] ?? null) !== $this->newTreeSha256
            || !is_string($record['failed_tree_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $record['failed_tree_sha256']) !== 1
            || !in_array($record['phase'] ?? null, ['prepared', 'staged', 'quarantined', 'restored'], true)) {
            throw new RuntimeException('旧候选恢复记录损坏');
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function path(array $record, string $kind): string
    {
        $path = HostPayloadPlan::directoryPrefix($this->storageRoot) . 'host-payload/'
            . $this->app . '.rollback-' . $record['id'] . '.' . $kind;
        HostPayloadPlan::assertSafePath($path);
        return $path;
    }

    private function copyBackup(string $stage): void
    {
        HostPayloadPlan::assertSafePath($stage);
        if (file_exists($stage) || is_link($stage) || !mkdir($stage, 0700)) {
            throw new RuntimeException('无法创建旧候选暂存目录');
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->backup, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $entry) {
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new RuntimeException('升级前候选备份包含非法类型');
            }
            $relative = substr($entry->getPathname(), strlen(HostPayloadPlan::directoryPath($this->backup)) + 1);
            $target = $stage . '/' . $relative;
            HostPayloadPlan::assertSafePath($target);
            if ($entry->isDir()) {
                if (!mkdir($target, 0700) && !is_dir($target)) {
                    throw new RuntimeException('无法复制旧候选目录');
                }
            } elseif (!copy($entry->getPathname(), $target)) {
                throw new RuntimeException('无法复制旧候选文件');
            }
        }
    }

    private function removeStage(string $stage): void
    {
        HostPayloadPlan::assertSafePath($stage);
        if (!is_dir($stage)) throw new RuntimeException('旧候选暂存类型异常');
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new RuntimeException('旧候选暂存含有非法类型');
            }
            if ($entry->isDir() ? !rmdir($entry->getPathname()) : !unlink($entry->getPathname())) {
                throw new RuntimeException('无法清理旧候选暂存');
            }
        }
        if (!rmdir($stage)) throw new RuntimeException('无法清理旧候选暂存目录');
    }

    private function syncFiles(string $stage): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        ) as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                throw new RuntimeException('旧候选暂存包含非法文件');
            }
            // Windows FlushFileBuffers requires a writable handle even after copy().
            $handle = fopen($entry->getPathname(), 'r+b');
            if ($handle === false) throw new RuntimeException('无法打开旧候选暂存文件');
            try {
                if (!fsync($handle)) throw new RuntimeException('旧候选暂存文件未持久化');
            } finally {
                fclose($handle);
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function save(array $record): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $parent = dirname($this->journal);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建旧候选恢复状态目录');
        }
        $temporary = $this->journal . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('无法创建旧候选恢复记录');
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('旧候选恢复记录未持久化');
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
            throw new RuntimeException('无法发布旧候选恢复记录');
        }
    }

    /** @param array<string,string>|null $tree */
    private static function digest(?array $tree): string
    {
        return is_array($tree) ? hash('sha256', json_encode($tree, JSON_THROW_ON_ERROR)) : '';
    }
}
