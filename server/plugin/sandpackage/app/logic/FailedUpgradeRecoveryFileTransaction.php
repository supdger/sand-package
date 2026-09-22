<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use plugin\sandadmin\exception\ApiException;

/**
 * Crash-convergent transaction for restoring allowlisted runtime directories.
 *
 * Callers must first verify the backup and runtime manifests. This class then
 * rechecks each source manifest, persists an exact-path journal, quarantines
 * current targets, atomically renames staged copies, and retains quarantine as
 * rollback evidence. It never reads or writes a plugin registry or database.
 */
final class FailedUpgradeRecoveryFileTransaction
{
    public function __construct(private readonly string $managedRoot)
    {
    }

    public function hasPending(string $app): bool
    {
        $this->assertApp($app);
        return file_exists($this->journalPath($app));
    }

    /**
     * @param list<string> $sources
     * @param list<string> $targets
     * @param list<array<string,string>> $expectedManifests
     * @param callable(string):void|null $fault
     * @return array{id:string,resumed:bool,runtime_manifest_hash:string}
     */
    public function restore(
        string $app,
        string $backupId,
        string $runtimeManifestHash,
        array $sources,
        array $targets,
        array $expectedManifests,
        ?callable $fault = null,
    ): array {
        $this->assertInputs($app, $backupId, $runtimeManifestHash, $sources, $targets, $expectedManifests);
        $this->preparePrivateRoots($app);
        $journal = $this->journalPath($app);
        if (file_exists($journal)) {
            $payload = $this->readJournal($app, $backupId, $runtimeManifestHash, $sources, $targets, $expectedManifests);
            $this->converge($payload, $sources, $targets, $expectedManifests, $fault);
            return ['id' => (string) $payload['id'], 'resumed' => true, 'runtime_manifest_hash' => $runtimeManifestHash];
        }

        $id = bin2hex(random_bytes(16));
        $root = $this->operationRoot($app, $id);
        $stageRoot = $root . DIRECTORY_SEPARATOR . 'stage';
        $quarantineRoot = $root . DIRECTORY_SEPARATOR . 'quarantine';
        $this->preparePrivateDirectory($root);
        $this->preparePrivateDirectory($stageRoot);
        $this->preparePrivateDirectory($quarantineRoot);
        $entries = [];
        foreach ($sources as $index => $_source) {
            $stage = $stageRoot . DIRECTORY_SEPARATOR . 'target-' . ($index + 1);
            $quarantine = $quarantineRoot . DIRECTORY_SEPARATOR . 'target-' . ($index + 1);
            $entries[] = ['target' => $targets[$index], 'stage' => $stage, 'quarantine' => $quarantine];
        }
        $payload = [
            'schema' => 'sandpackage-runtime-restore/v2',
            'app' => $app,
            'id' => $id,
            'backup_id' => $backupId,
            'runtime_manifest_hash' => $runtimeManifestHash,
            'phase' => 'prepared',
            'entries' => $entries,
        ];
        $this->writeJournal($journal, $payload);
        if ($fault !== null) $fault('runtime_restore.journal.prepared');
        foreach ($sources as $index => $source) {
            $this->copyDirectory($source, $entries[$index]['stage']);
            $this->fsyncTree($entries[$index]['stage']);
            if ($this->manifest($entries[$index]['stage']) !== $expectedManifests[$index]) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：升级前运行文件暂存清单不匹配', 400);
            }
        }
        $payload['phase'] = 'staged';
        $this->writeJournal($journal, $payload);
        if ($fault !== null) $fault('runtime_restore.journal.staged');
        $this->converge($payload, $sources, $targets, $expectedManifests, $fault);
        return ['id' => $id, 'resumed' => false, 'runtime_manifest_hash' => $runtimeManifestHash];
    }

    /** @return array<string,string> */
    public function manifest(string $directory): array
    {
        $this->assertSafeDirectory($directory);
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            $stat = @lstat($item->getPathname());
            $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            if ($item->isLink() || !$item->isFile() || !is_array($stat)
                || (($stat['mode'] & 0170000) !== 0100000) || (($stat['mode'] & 0002) !== 0)
                || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件包含链接或非普通文件', 400);
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件摘要失败', 400);
            }
            $manifest[str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName())] = $hash;
        }
        ksort($manifest, SORT_STRING);
        return $manifest;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $sources
     * @param list<string> $targets
     * @param list<array<string,string>> $expected
     * @param callable(string):void|null $fault
     */
    private function converge(array $payload, array $sources, array $targets, array $expected, ?callable $fault): void
    {
        $this->assertOperationPaths($payload);
        $journal = $this->journalPath((string) $payload['app']);
        foreach ($targets as $index => $target) {
            /** @var array{target:string,stage:string,quarantine:string} $entry */
            $entry = $payload['entries'][$index];
            $stage = $entry['stage'];
            $quarantine = $entry['quarantine'];
            if ($this->matchesManifest($target, $expected[$index])) {
                continue;
            }
            if (!$this->matchesManifest($stage, $expected[$index])) {
                $this->rebuildStage($sources[$index], $stage, $expected[$index]);
            }
            if (file_exists($target)) {
                if (!is_dir($target) || is_link($target) || file_exists($quarantine)
                    || !rename($target, $quarantine)) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法隔离当前运行文件', 400);
                }
                $payload['phase'] = 'quarantined_' . ($index + 1);
                $this->writeJournal($journal, $payload);
                if ($fault !== null) $fault('runtime_restore.quarantined.' . ($index + 1));
            }
            if (!rename($stage, $target)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法原子恢复升级前运行文件', 400);
            }
            $payload['phase'] = 'restored_' . ($index + 1);
            $this->writeJournal($journal, $payload);
            if ($fault !== null) $fault('runtime_restore.restored.' . ($index + 1));
        }
        foreach ($targets as $index => $target) {
            if (!$this->matchesManifest($target, $expected[$index])) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复后摘要不匹配', 400);
            }
        }
        $payload['phase'] = 'verified';
        $this->writeJournal($journal, $payload);
        if ($fault !== null) $fault('runtime_restore.journal.verified');
        if (!unlink($journal)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录无法完成', 400);
        }
    }

    /**
     * @param list<string> $sources
     * @param list<string> $targets
     * @param list<array<string,string>> $expected
     * @return array<string,mixed>
     */
    private function readJournal(string $app, string $backupId, string $hash, array $sources, array $targets, array $expected): array
    {
        $journal = $this->journalPath($app);
        $stat = @lstat($journal);
        if (!is_array($stat) || is_link($journal) || (($stat['mode'] & 0170000) !== 0100000)
            || (($stat['mode'] & 0777) !== 0600) || (($stat['mode'] & 0002) !== 0)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不安全', 400);
        }
        $payload = json_decode((string) file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
        $id = $payload['id'] ?? null;
        $entries = $payload['entries'] ?? null;
        if (!is_array($payload) || ($payload['schema'] ?? null) !== 'sandpackage-runtime-restore/v2'
            || ($payload['app'] ?? null) !== $app || ($payload['backup_id'] ?? null) !== $backupId
            || ($payload['runtime_manifest_hash'] ?? null) !== $hash
            || !is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || !is_array($entries) || count($entries) !== count($targets)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不完整', 400);
        }
        $root = $this->operationRoot($app, $id);
        $this->assertOperationPaths($payload);
        foreach ($targets as $index => $target) {
            $entry = $entries[$index] ?? null;
            if (!is_array($entry)
                || ($entry['target'] ?? null) !== $target
                || ($entry['stage'] ?? null) !== $root . DIRECTORY_SEPARATOR . 'stage' . DIRECTORY_SEPARATOR . 'target-' . ($index + 1)
                || ($entry['quarantine'] ?? null) !== $root . DIRECTORY_SEPARATOR . 'quarantine' . DIRECTORY_SEPARATOR . 'target-' . ($index + 1)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录路径不匹配', 400);
            }
            if ($this->manifest($sources[$index]) !== $expected[$index]) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：升级前备份摘要已变化', 400);
            }
        }
        return $payload;
    }

    /** @param list<string> $sources @param list<string> $targets @param list<array<string,string>> $expected */
    private function assertInputs(string $app, string $backupId, string $hash, array $sources, array $targets, array $expected): void
    {
        $this->assertApp($app);
        if (preg_match('/^' . preg_quote($app, '/') . '-package-[0-9]{14}-[a-f0-9]{12}$/D', $backupId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || $sources === [] || count($sources) !== count($targets) || count($targets) !== count($expected)
            || count($targets) !== count(array_unique($targets, SORT_STRING))) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复输入不完整', 400);
        }
        foreach ($sources as $index => $source) {
            if ($this->manifest($source) !== $expected[$index]
                || !is_string($targets[$index]) || $targets[$index] === '' || str_contains($targets[$index], "\0")) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复输入摘要不匹配', 400);
            }
            $parent = realpath(dirname($targets[$index]));
            if ($parent === false || is_link(dirname($targets[$index]))
                || rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($targets[$index]) !== $targets[$index]) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复目标不受控', 400);
            }
        }
    }

    private function matchesManifest(string $directory, array $expected): bool
    {
        return is_dir($directory) && !is_link($directory) && $this->manifest($directory) === $expected;
    }

    private function preparePrivateRoots(string $app): void
    {
        $this->preparePrivateDirectory($this->managedRoot);
        $this->preparePrivateDirectory(rtrim($this->managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks');
        $this->preparePrivateDirectory(rtrim($this->managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime-restores');
        $this->preparePrivateDirectory(rtrim($this->managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime-restores' . DIRECTORY_SEPARATOR . $app);
    }

    private function preparePrivateDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        if (is_link($directory)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：私有恢复目录不安全', 400);
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法创建私有恢复目录', 400);
        }
        if (is_link($directory) || !chmod($directory, 0700)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：私有恢复目录不安全', 400);
        }
        $this->assertSafeDirectory($directory);
        $root = rtrim($this->managedRoot, DIRECTORY_SEPARATOR);
        if (rtrim($directory, DIRECTORY_SEPARATOR) !== $root) {
            $parent = dirname($directory);
            $this->assertSafeDirectory($parent);
            $parentReal = realpath($parent);
            $actual = realpath($directory);
            if ($parentReal === false || $actual === false
                || $actual !== rtrim($parentReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($directory)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：私有恢复目录不在受控父链内', 400);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertOperationPaths(array $payload): void
    {
        $app = $payload['app'] ?? null;
        $id = $payload['id'] ?? null;
        if (!is_string($app) || !is_string($id)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不完整', 400);
        }
        $appRoot = rtrim($this->managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime-restores' . DIRECTORY_SEPARATOR . $app;
        $root = $this->operationRoot($app, $id);
        foreach ([$appRoot, $root, $root . DIRECTORY_SEPARATOR . 'stage', $root . DIRECTORY_SEPARATOR . 'quarantine'] as $directory) {
            clearstatcache(true, $directory);
            if (is_link($directory)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复操作目录已被链接替换', 400);
            }
            $this->assertSafeDirectory($directory);
        }
        $entries = $payload['entries'] ?? null;
        if (!is_array($entries)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不完整', 400);
        }
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不完整', 400);
            foreach (['stage' => 'stage', 'quarantine' => 'quarantine'] as $field => $bucket) {
                $path = $entry[$field] ?? null;
                $expected = $root . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . 'target-' . ((int) $index + 1);
                clearstatcache(true, is_string($path) ? $path : '');
                if (!is_string($path) || $path !== $expected || is_link($path)) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复操作路径已被替换', 400);
                }
                if (file_exists($path)) {
                    $parent = dirname($path);
                    $parentReal = realpath($parent);
                    $actual = realpath($path);
                    if ($parentReal === false || $actual === false
                        || $actual !== rtrim($parentReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path)) {
                        throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复操作路径不在受控父链内', 400);
                    }
                }
            }
        }
    }

    private function assertSafeDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($stat) || is_link($directory) || (($stat['mode'] & 0170000) !== 0040000)
            || (($stat['mode'] & 0002) !== 0) || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复目录安全属性不符合要求', 400);
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->assertSafeDirectory($source);
        if (file_exists($target)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复暂存目录已存在', 400);
        }
        if (!mkdir($target, 0700, true)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法创建恢复暂存目录', 400);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：备份包含链接文件', 400);
            }
            $destination = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法复制恢复目录', 400);
                }
            } elseif (!$item->isFile() || !copy($item->getPathname(), $destination)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法复制恢复文件', 400);
            }
        }
    }

    /** @param array<string,string> $expected */
    private function rebuildStage(string $source, string $stage, array $expected): void
    {
        clearstatcache(true, $stage);
        if (file_exists($stage) || is_link($stage)) {
            if (!is_dir($stage) || is_link($stage)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：中断恢复的暂存路径不安全', 400);
            }
            $this->removeSafeTree($stage);
        }
        $this->copyDirectory($source, $stage);
        $this->fsyncTree($stage);
        if (!$this->matchesManifest($stage, $expected)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：中断恢复的暂存文件摘要不匹配', 400);
        }
    }

    private function removeSafeTree(string $directory): void
    {
        $this->assertSafeDirectory($directory);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            clearstatcache(true, $path);
            $stat = @lstat($path);
            $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            if (!is_array($stat) || $item->isLink() || (($stat['mode'] & 0002) !== 0)
                || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：中断恢复的暂存文件不安全', 400);
            }
            if ($item->isDir()) {
                if (($stat['mode'] & 0170000) !== 0040000 || !@rmdir($path)) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法安全清理中断恢复暂存目录', 400);
                }
            } elseif (($stat['mode'] & 0170000) !== 0100000 || !@unlink($path)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法安全清理中断恢复暂存文件', 400);
            }
        }
        if (!@rmdir($directory)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法安全清理中断恢复暂存目录', 400);
        }
    }

    private function fsyncTree(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复暂存文件不安全', 400);
            }
            $handle = fopen($item->getPathname(), 'rb');
            if ($handle === false || (function_exists('fsync') && !fsync($handle))) {
                if (is_resource($handle)) fclose($handle);
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复暂存文件未能持久化', 400);
            }
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $payload */
    private function writeJournal(string $file, array $payload): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法创建恢复事务记录', 400);
        }
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle)) || !chmod($temporary, 0600)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法持久化恢复事务记录', 400);
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法提交恢复事务记录', 400);
        }
    }

    private function journalPath(string $app): string
    {
        return rtrim($this->managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'locks'
            . DIRECTORY_SEPARATOR . $app . '-runtime-restore.transaction.json';
    }

    private function operationRoot(string $app, string $id): string
    {
        return rtrim($this->managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime-restores'
            . DIRECTORY_SEPARATOR . $app . DIRECTORY_SEPARATOR . $id;
    }

    private function assertApp(string $app): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：插件标识非法', 400);
        }
    }
}
