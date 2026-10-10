<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;
use Throwable;

/** Forward-recoverable upgrade or uninstall of files already owned by a plugin. */
final class HostPayloadChangeFiles
{
    private string $journal;
    private string $ownership;

    /**
     * @param list<array{path:string,sha256:string}> $next
     */
    public function __construct(
        private readonly string $hostRoot,
        private readonly string $stateRoot,
        private readonly string $candidateRoot,
        private readonly string $app,
        private readonly array $next,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new RuntimeException('插件标识无效');
        }
        foreach ([$hostRoot, $stateRoot, $candidateRoot] as $path) {
            HostPayloadPlan::assertSafePath($path);
        }
        $this->journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.change.json';
        $this->ownership = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.owned.json';
    }

    /** @return array{add:list<string>,replace:list<string>,remove:list<string>} */
    public function begin(): array
    {
        $old = HostPayloadOwnership::read($this->stateRoot, $this->app) ?? [];
        $plan = HostPayloadPlan::inspect($this->hostRoot, $this->app, $this->next, $old);
        $this->verifyCandidates($this->next);
        if (file_exists($this->journal)) {
            $this->retireFinishedJournal();
        }
        $backup = HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app . '.backup.' . bin2hex(random_bytes(8));
        HostPayloadPlan::assertSafePath($backup);
        if (!is_dir($backup) && !mkdir($backup, 0700, true)) {
            throw new RuntimeException('无法创建宿主文件备份目录');
        }
        foreach ($old as $entry) {
            $source = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            $target = $backup . '/' . $entry['path'];
            $this->copyVerified($source, $target, $entry['sha256']);
        }
        $this->save($this->journal, [
            'schema' => 1, 'app' => $this->app, 'phase' => 'pending',
            'old' => $old, 'next' => $this->next, 'backup' => $backup,
        ]);
        return $plan;
    }

    private function retireFinishedJournal(): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 1048576
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== 1 || ($record['app'] ?? null) !== $this->app
            || !in_array($record['phase'] ?? null, ['complete', 'rolled_back'], true)
            || !is_array($record['old'] ?? null) || !is_array($record['next'] ?? null)
            || !is_string($record['backup'] ?? null)) {
            throw new RuntimeException('已有未结束的宿主文件变更记录');
        }
        HostPayloadPlan::normalizeFiles($record['old'], $this->app);
        HostPayloadPlan::normalizeFiles($record['next'], $this->app);
        HostPayloadPlan::assertSafePath($record['backup']);
        if (!str_starts_with($record['backup'], HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app . '.backup.')
            || !is_dir($record['backup'])) {
            throw new RuntimeException('宿主文件变更备份无效');
        }
        $expected = $record['phase'] === 'complete' ? $record['next'] : $record['old'];
        if (HostPayloadOwnership::read($this->stateRoot, $this->app) !== ($expected === [] ? null : $expected)) {
            throw new RuntimeException('宿主文件变更归属尚未收敛');
        }
        HostPayloadPlan::inspect($this->hostRoot, $this->app, $expected, $expected);
        $this->assertFinal($record['phase'] === 'complete' ? $record['old'] : $record['next'], $expected);
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法保存上次宿主文件变更记录');
        }
    }

    public function archiveFinished(): void
    {
        $this->retireFinishedJournal();
    }

    /** @return array{phase:string,fingerprint:string,old:list<array{path:string,sha256:string}>,next:list<array{path:string,sha256:string}>} */
    public function inspectSnapshot(): array
    {
        $record = $this->readJournal();
        $phase = $record['phase'];
        if (!in_array($phase, ['pending', 'complete', 'rolled_back'], true)) {
            throw new RuntimeException('宿主文件变更阶段无效');
        }
        $this->verifyCandidates($this->next);
        $expectedBackup = [];
        foreach ($record['old'] as $entry) {
            $parts = explode('/', $entry['path']);
            array_pop($parts);
            $directory = '';
            foreach ($parts as $part) {
                $directory = $directory === '' ? $part : $directory . '/' . $part;
                $expectedBackup[$directory] = 'directory';
            }
            $expectedBackup[$entry['path']] = $entry['sha256'];
        }
        ksort($expectedBackup, SORT_STRING);
        $backup = $record['backup'];
        HostPayloadPlan::assertSafePath($backup);
        if (!is_dir($backup)) {
            throw new RuntimeException('宿主文件备份目录缺失');
        }
        $actualBackup = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backup, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        ) as $entry) {
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new RuntimeException('宿主文件备份包含非预期类型');
            }
            $path = HostPayloadPlan::relativePath($entry->getPathname(), $backup);
            $actualBackup[$path] = $entry->isDir() ? 'directory' : hash_file('sha256', $entry->getPathname());
        }
        ksort($actualBackup, SORT_STRING);
        if ($actualBackup !== $expectedBackup) {
            throw new RuntimeException('宿主文件备份与变更记录不符');
        }
        $old = self::index($record['old']);
        $next = self::index($this->next);
        $ownership = HostPayloadOwnership::read($this->stateRoot, $this->app);
        $expectedOwnership = $phase === 'complete' ? $this->next : $record['old'];
        $allowedOwnership = [$expectedOwnership === [] ? null : $expectedOwnership];
        if ($phase === 'pending') {
            $allowedOwnership[] = $this->next === [] ? null : $this->next;
        }
        if (!in_array($ownership, $allowedOwnership, true)) {
            throw new RuntimeException('宿主文件归属与变更阶段不符');
        }
        $actualFiles = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($next))) as $path) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $path;
            HostPayloadPlan::assertSafePath($target);
            if (file_exists($target) && !is_file($target)) {
                throw new RuntimeException('宿主目标类型已变化：' . $path);
            }
            $actual = is_file($target) ? hash_file('sha256', $target) : null;
            $allowed = match ($phase) {
                'complete' => [$next[$path] ?? null],
                'rolled_back' => [$old[$path] ?? null],
                default => [$old[$path] ?? null, $next[$path] ?? null],
            };
            if (!in_array($actual, $allowed, true) || ($actual === null && is_link($target))) {
                throw new RuntimeException('宿主文件与变更阶段不符：' . $path);
            }
            $actualFiles[$path] = $actual;
        }
        ksort($actualFiles, SORT_STRING);
        return [
            'phase' => $phase,
            'fingerprint' => hash('sha256', json_encode(
                [$record, $actualBackup, $ownership, $actualFiles],
                JSON_THROW_ON_ERROR,
            )),
            'old' => $record['old'],
            'next' => $this->next,
        ];
    }

    /**
     * Verify a failed preflight without creating or retiring a file transaction.
     * @param list<array{path:string,sha256:string}> $installed
     * @return array{phase:string,fingerprint:string,old:array,next:array}
     */
    public function inspectNotStarted(array $installed, string $installedCandidate): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $this->verifyCandidates($this->next);
        $previous = null;
        if (file_exists($this->journal)) {
            $previous = (new self(
                $this->hostRoot, $this->stateRoot, $installedCandidate, $this->app, $installed,
            ))->inspectSnapshot();
            if ($previous['phase'] !== 'complete') {
                throw new RuntimeException('已有宿主文件事务未完成，不能按未开始恢复');
            }
        }
        if (HostPayloadOwnership::read($this->stateRoot, $this->app) !== ($installed === [] ? null : $installed)) {
            throw new RuntimeException('宿主文件归属与旧安装包不符');
        }
        HostPayloadPlan::inspect($this->hostRoot, $this->app, $installed, $installed);
        return [
            'phase' => 'not_started',
            'fingerprint' => hash('sha256', json_encode([$installed, $previous], JSON_THROW_ON_ERROR)),
            'old' => $installed,
            'next' => $this->next,
        ];
    }

    public function apply(): void
    {
        $record = $this->readJournal();
        if ($record['phase'] === 'complete') {
            $this->assertFinal($record['old'], $this->next);
            return;
        }
        if ($record['phase'] !== 'pending') {
            throw new RuntimeException('宿主文件变更阶段不可继续');
        }
        $this->verifyCandidates($this->next);
        $old = self::index($record['old']);
        $next = self::index($this->next);
        foreach (array_unique(array_merge(array_keys($old), array_keys($next))) as $path) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $path;
            HostPayloadPlan::assertSafePath($target);
            $current = is_file($target) ? hash_file('sha256', $target) : null;
            $previousHash = $old[$path] ?? null;
            $nextHash = $next[$path] ?? null;
            if ($nextHash === null) {
                if ($current === $previousHash && !unlink($target)) {
                    throw new RuntimeException('无法移除旧宿主文件：' . $path);
                }
                if ($current !== null && $current !== $previousHash) {
                    throw new RuntimeException('旧宿主文件已被外部修改：' . $path);
                }
                continue;
            }
            if ($current === $nextHash) {
                continue;
            }
            if ($current !== $previousHash || ($previousHash === null && (file_exists($target) || is_link($target)))) {
                throw new RuntimeException('宿主文件升级时发生冲突：' . $path);
            }
            $this->copyVerified(
                HostPayloadPlan::directoryPrefix($this->candidateRoot) . $path,
                $target,
                $nextHash,
                $previousHash,
            );
        }
        $this->assertFinal($record['old'], $this->next);
        if ($this->next === []) {
            if (is_file($this->ownership) && !unlink($this->ownership)) {
                throw new RuntimeException('无法移除宿主文件归属记录');
            }
        } else {
            $this->save($this->ownership, ['schema' => 1, 'app' => $this->app, 'files' => $this->next]);
        }
        $record['phase'] = 'complete';
        $this->save($this->journal, $record);
    }

    public function restore(): void
    {
        $record = $this->readJournal();
        if ($record['phase'] === 'rolled_back') {
            HostPayloadPlan::inspect($this->hostRoot, $this->app, $record['old'], $record['old']);
            return;
        }
        if (!in_array($record['phase'], ['pending', 'complete'], true)) {
            throw new RuntimeException('宿主文件变更阶段不可恢复');
        }
        $old = self::index($record['old']);
        $next = self::index($this->next);
        foreach ($record['old'] as $entry) {
            $source = $record['backup'] . '/' . $entry['path'];
            HostPayloadPlan::assertSafePath($source);
            if (!is_file($source) || hash_file('sha256', $source) !== $entry['sha256']) {
                throw new RuntimeException('旧宿主文件备份摘要不匹配：' . $entry['path']);
            }
        }
        foreach (array_unique(array_merge(array_keys($old), array_keys($next))) as $path) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $path;
            HostPayloadPlan::assertSafePath($target);
            $current = is_file($target) ? hash_file('sha256', $target) : null;
            if ($current !== null && $current !== ($old[$path] ?? null) && $current !== ($next[$path] ?? null)) {
                throw new RuntimeException('宿主文件恢复时发现外部修改：' . $path);
            }
            if ($current === null && (file_exists($target) || is_link($target))) {
                throw new RuntimeException('宿主文件恢复时目标类型变化：' . $path);
            }
        }
        foreach (array_unique(array_merge(array_keys($old), array_keys($next))) as $path) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $path;
            $current = is_file($target) ? hash_file('sha256', $target) : null;
            if (!isset($old[$path])) {
                if ($current !== null && !unlink($target)) {
                    throw new RuntimeException('无法移除升级新增文件：' . $path);
                }
                continue;
            }
            if ($current === $old[$path]) {
                continue;
            }
            $this->copyVerified(
                $record['backup'] . '/' . $path,
                $target,
                $old[$path],
                $current === null ? null : $next[$path],
            );
        }
        HostPayloadPlan::inspect($this->hostRoot, $this->app, $record['old'], $record['old']);
        if ($record['old'] === []) {
            if (is_file($this->ownership) && !unlink($this->ownership)) {
                throw new RuntimeException('无法清理宿主文件归属记录');
            }
        } else {
            $this->save($this->ownership, ['schema' => 1, 'app' => $this->app, 'files' => $record['old']]);
        }
        $record['phase'] = 'rolled_back';
        $this->save($this->journal, $record);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJournal(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $record = is_file($this->journal) ? json_decode((string) file_get_contents($this->journal), true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== 1 || ($record['app'] ?? null) !== $this->app
            || ($record['next'] ?? null) !== $this->next || !is_array($record['old'] ?? null)
            || !is_string($record['backup'] ?? null) || !is_string($record['phase'] ?? null)) {
            throw new RuntimeException('宿主文件变更记录缺失或与候选不符');
        }
        HostPayloadPlan::normalizeFiles($record['old'], $this->app);
        HostPayloadPlan::assertSafePath($record['backup']);
        if (!str_starts_with($record['backup'], HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app . '.backup.')) {
            throw new RuntimeException('宿主文件备份不属于当前插件状态目录');
        }
        return $record;
    }

    /** @param list<array{path:string,sha256:string}> $files */
    private function verifyCandidates(array $files): void
    {
        HostPayloadPlan::normalizeFiles($files, $this->app);
        foreach ($files as $entry) {
            $source = HostPayloadPlan::directoryPrefix($this->candidateRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($source);
            if (!is_file($source) || hash_file('sha256', $source) !== $entry['sha256']) {
                throw new RuntimeException('宿主文件候选摘要不匹配：' . $entry['path']);
            }
        }
    }

    /**
     * @param list<array{path:string,sha256:string}> $old
     * @param list<array{path:string,sha256:string}> $next
     */
    private function assertFinal(array $old, array $next): void
    {
        $newPaths = self::index($next);
        foreach ($next as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (!is_file($target) || hash_file('sha256', $target) !== $entry['sha256']) {
                throw new RuntimeException('升级后宿主文件摘要不匹配：' . $entry['path']);
            }
        }
        foreach ($old as $entry) {
            if (isset($newPaths[$entry['path']])) {
                continue;
            }
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('应移除的宿主文件仍存在：' . $entry['path']);
            }
        }
    }

    /** @param list<array{path:string,sha256:string}> $files @return array<string,string> */
    private static function index(array $files): array
    {
        $result = [];
        foreach ($files as $entry) {
            $result[$entry['path']] = $entry['sha256'];
        }
        return $result;
    }

    private function copyVerified(string $source, string $target, string $sha256, ?string $replaceHash = null): void
    {
        HostPayloadPlan::assertSafePath($source);
        HostPayloadPlan::assertSafePath($target);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建宿主文件目录');
        }
        HostPayloadPlan::assertSafePath($target);
        $temporary = $parent . '/.sandpackage-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (!copy($source, $temporary) || hash_file('sha256', $temporary) !== $sha256) {
                throw new RuntimeException('宿主文件临时副本摘要不匹配');
            }
            if ($replaceHash !== null) {
                if (!is_file($target) || hash_file('sha256', $target) !== $replaceHash) {
                    throw new RuntimeException('已归属宿主文件在替换前发生变化');
                }
                if (!rename($temporary, $target)) {
                    throw new RuntimeException('无法替换已归属宿主文件');
                }
            } elseif (!link($temporary, $target)) {
                throw new RuntimeException('宿主目标文件已被占用');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function save(string $path, array $record): void
    {
        HostPayloadPlan::assertSafePath($path);
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建宿主文件状态目录');
        }
        HostPayloadPlan::assertSafePath($path);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('无法创建宿主文件变更记录');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('宿主文件变更记录未持久化');
            }
        } catch (Throwable $error) {
            fclose($handle);
            unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('无法保存宿主文件变更记录');
        }
    }
}
