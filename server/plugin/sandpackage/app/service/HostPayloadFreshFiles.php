<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;
use Throwable;

/** Forward-recoverable file deployment for a fresh, explicitly declared host payload. */
final class HostPayloadFreshFiles
{
    private string $journal;
    private string $ownership;

    /**
     * @param list<array{path:string,sha256:string}> $files
     */
    public function __construct(
        private readonly string $hostRoot,
        private readonly string $stateRoot,
        private readonly string $candidateRoot,
        private readonly string $app,
        private readonly array $files,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new RuntimeException('插件标识无效');
        }
        HostPayloadPlan::assertSafePath($hostRoot);
        HostPayloadPlan::assertSafePath($stateRoot);
        HostPayloadPlan::assertSafePath($candidateRoot);
        $this->journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.fresh.json';
        $this->ownership = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.owned.json';
        HostPayloadPlan::assertSafePath($this->journal);
        HostPayloadPlan::assertSafePath($this->ownership);
    }

    public function begin(): void
    {
        $this->preflight();
        if (file_exists($this->journal)) {
            $this->retireCleanedJournal();
        }
        if (file_exists($this->journal) || file_exists($this->ownership)) {
            throw new RuntimeException('宿主文件部署已有状态记录');
        }
        $this->save($this->journal, [
            'schema' => 1, 'id' => bin2hex(random_bytes(16)),
            'app' => $this->app, 'phase' => 'pending', 'files' => $this->files,
        ]);
    }

    public static function archiveAfterRemoval(string $hostRoot, string $stateRoot, string $app): void
    {
        $journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.fresh.json';
        HostPayloadPlan::assertSafePath($journal);
        if (!file_exists($journal)) return;
        $raw = is_file($journal) && filesize($journal) <= 1048576 ? file_get_contents($journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== 1 || ($record['app'] ?? null) !== $app
            || ($record['phase'] ?? null) !== 'complete' || !is_array($record['files'] ?? null)
            || !array_is_list($record['files']) || HostPayloadOwnership::read($stateRoot, $app) !== null) {
            throw new RuntimeException('新装文件记录未达到可归档的卸载状态');
        }
        HostPayloadPlan::normalizeFiles($record['files'], $app);
        foreach ($record['files'] as $entry) {
            $target = HostPayloadPlan::directoryPrefix($hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('卸载后仍有原新装宿主文件：' . $entry['path']);
            }
        }
        $history = $journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($journal, $history)) {
            throw new RuntimeException('无法归档新装文件记录');
        }
    }

    private function retireCleanedJournal(): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 1048576
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $this->app || ($record['phase'] ?? null) !== 'cleaned'
            || !is_array($record['files'] ?? null) || !array_is_list($record['files'])
            || file_exists($this->ownership)) {
            throw new RuntimeException('已有未结束的宿主文件部署记录');
        }
        HostPayloadPlan::normalizeFiles($record['files'], $this->app);
        foreach ($record['files'] as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('上次清理的宿主文件仍存在：' . $entry['path']);
            }
        }
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法保存上次宿主文件清理记录');
        }
    }

    public function preflight(): void
    {
        HostPayloadPlan::inspect($this->hostRoot, $this->app, $this->files);
        $this->verifySources();
    }

    public function hasJournal(): bool
    {
        HostPayloadPlan::assertSafePath($this->journal);
        return is_file($this->journal);
    }

    /** @return array{phase:?string,files:array<string,string|null>} */
    public function snapshot(): array
    {
        $phase = $this->hasJournal() ? $this->readJournal()['phase'] : null;
        $files = [];
        foreach ($this->files as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            $files[$entry['path']] = is_file($target) ? hash_file('sha256', $target) : null;
            if ($files[$entry['path']] === null && file_exists($target)) {
                throw new RuntimeException('宿主文件目标类型已变化：' . $entry['path']);
            }
        }
        return ['phase' => $phase, 'files' => $files];
    }

    public function cleanupIfStarted(): void
    {
        $this->assertCleanupSafe();
        if ($this->hasJournal()) {
            $phase = $this->readJournal()['phase'];
            if ($phase === 'pending') {
                $this->cleanup();
                return;
            }
            if ($phase !== 'cleaned') {
                throw new RuntimeException('已完成的宿主文件部署不能自动清理');
            }
        }
        foreach ($this->files as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('无部署记录的宿主目标文件已存在：' . $entry['path']);
            }
        }
    }

    public function assertCleanupSafe(): void
    {
        $snapshot = $this->snapshot();
        if (!in_array($snapshot['phase'], [null, 'pending', 'cleaned'], true)) {
            throw new RuntimeException('已完成的宿主文件部署不能作为未提交新装清理');
        }
        foreach ($this->files as $entry) {
            $actual = $snapshot['files'][$entry['path']];
            if ($actual !== null && ($snapshot['phase'] !== 'pending'
                || !hash_equals($entry['sha256'], $actual))) {
                throw new RuntimeException('宿主文件不属于可清理的新装候选：' . $entry['path']);
            }
        }
        if (file_exists($this->ownership)) {
            $owned = HostPayloadOwnership::read($this->stateRoot, $this->app);
            if ($snapshot['phase'] !== 'pending' || $owned !== $this->files) {
                throw new RuntimeException('宿主文件归属记录不属于待清理候选');
            }
        }
    }

    public function verifyInstalled(): void
    {
        if ($this->readJournal()['phase'] !== 'complete'
            || HostPayloadOwnership::read($this->stateRoot, $this->app) !== $this->files) {
            throw new RuntimeException('宿主文件归属记录尚未完成');
        }
        $this->assertOwnedFiles();
    }

    public function apply(): void
    {
        $record = $this->readJournal();
        if ($record['phase'] === 'complete') {
            $this->assertOwnedFiles();
            return;
        }
        if ($record['phase'] !== 'pending') {
            throw new RuntimeException('宿主文件部署阶段不可继续');
        }
        $this->verifySources();
        foreach ($this->files as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (file_exists($target) || is_link($target)) {
                if (!is_file($target) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $target))) {
                    throw new RuntimeException('宿主文件在部署过程中发生冲突：' . $entry['path']);
                }
                continue;
            }
            $this->copyExclusive(HostPayloadPlan::directoryPrefix($this->candidateRoot) . $entry['path'], $target, $entry['sha256']);
        }
        $this->assertOwnedFiles();
        $this->save($this->ownership, ['schema' => 1, 'app' => $this->app, 'files' => $this->files]);
        $record['phase'] = 'complete';
        $this->save($this->journal, $record);
    }

    public function cleanup(): void
    {
        $record = $this->readJournal();
        if ($record['phase'] !== 'pending') {
            throw new RuntimeException('只有未完成的新装文件部署可清理');
        }
        if (file_exists($this->ownership)) {
            HostPayloadPlan::assertSafePath($this->ownership);
            $ownership = json_decode((string) file_get_contents($this->ownership), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($ownership) || ($ownership['schema'] ?? null) !== 1
                || ($ownership['app'] ?? null) !== $this->app || ($ownership['files'] ?? null) !== $this->files) {
                throw new RuntimeException('宿主文件归属记录与清理候选不符');
            }
        }
        foreach ($this->files as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (!file_exists($target)) {
                continue;
            }
            if (!is_file($target) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $target))) {
                throw new RuntimeException('宿主文件已变化，不能自动清理：' . $entry['path']);
            }
        }
        foreach ($this->files as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            if (is_file($target) && !unlink($target)) {
                throw new RuntimeException('无法清理宿主文件：' . $entry['path']);
            }
        }
        if (is_file($this->ownership) && !unlink($this->ownership)) {
            throw new RuntimeException('无法清理宿主文件归属记录');
        }
        $record['phase'] = 'cleaned';
        $this->save($this->journal, $record);
    }

    /** @return array<string,mixed> */
    private function readJournal(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $record = is_file($this->journal) ? json_decode((string) file_get_contents($this->journal), true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== 1 || ($record['app'] ?? null) !== $this->app
            || ($record['files'] ?? null) !== $this->files || !is_string($record['phase'] ?? null)) {
            throw new RuntimeException('宿主文件部署记录缺失或与候选不符');
        }
        return $record;
    }

    private function verifySources(): void
    {
        foreach ($this->files as $entry) {
            if (!HostPayloadManifest::allowedPath($entry['path'], $this->app)) {
                throw new RuntimeException('宿主文件候选路径不安全');
            }
            $source = HostPayloadPlan::directoryPrefix($this->candidateRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($source);
            if (!is_file($source) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $source))) {
                throw new RuntimeException('宿主文件候选摘要不匹配：' . $entry['path']);
            }
        }
    }

    private function assertOwnedFiles(): void
    {
        foreach ($this->files as $entry) {
            $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
            HostPayloadPlan::assertSafePath($target);
            if (!is_file($target) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $target))) {
                throw new RuntimeException('宿主文件部署后摘要不匹配：' . $entry['path']);
            }
        }
    }

    private function copyExclusive(string $source, string $target, string $sha256): void
    {
        $parent = dirname($target);
        HostPayloadPlan::assertSafePath($parent);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建宿主文件目录');
        }
        HostPayloadPlan::assertSafePath($target);
        $temporary = $parent . '/.sandpackage-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (!copy($source, $temporary) || !hash_equals($sha256, (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException('宿主文件临时副本摘要不匹配');
            }
            if (!link($temporary, $target)) {
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
            throw new RuntimeException('无法创建宿主文件状态记录');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('宿主文件状态记录未持久化');
            }
        } catch (Throwable $error) {
            fclose($handle);
            unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('无法发布宿主文件状态记录');
        }
    }
}
