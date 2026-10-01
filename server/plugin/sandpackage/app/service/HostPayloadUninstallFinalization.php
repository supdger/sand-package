<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Crash-convergent audit archiving after confirmed uninstall file removal. */
final class HostPayloadUninstallFinalization
{
    private string $journal;

    /** @param list<string> $runtimeRoots */
    public function __construct(
        private readonly string $stateRoot,
        private readonly string $hostRoot,
        private readonly string $app,
        private readonly array $runtimeRoots,
        private readonly string $candidateRoot,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || count($runtimeRoots) !== 2) {
            throw new RuntimeException('卸载收尾身份无效');
        }
        foreach ([$stateRoot, $hostRoot, $candidateRoot, ...$runtimeRoots] as $path) {
            HostPayloadPlan::assertSafePath($path);
        }
        $this->journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.uninstall-finalization.json';
        HostPayloadPlan::assertSafePath($this->journal);
    }

    public function hasJournal(): bool
    {
        HostPayloadPlan::assertSafePath($this->journal);
        return is_file($this->journal);
    }

    public function retireCompleted(): void
    {
        if (!$this->hasJournal()) return;
        if ($this->read()['phase'] !== 'complete') {
            throw new RuntimeException('上次卸载收尾尚未完成');
        }
        $this->archiveCompleted();
    }

    public function begin(): void
    {
        if ($this->hasJournal()) {
            $previous = $this->read();
            if ($previous['phase'] !== 'complete') {
                $this->inspectSnapshot();
                return;
            }
            $this->archiveCompleted();
        }
        $this->assertRemoved();
        $change = new HostPayloadChangeFiles(
            $this->hostRoot, $this->stateRoot, $this->candidateRoot, $this->app, [],
        );
        $removal = new HostPayloadRemovalFiles(
            $this->stateRoot, $this->app, $this->runtimeRoots, $this->candidateRoot,
        );
        if ($change->inspectSnapshot()['phase'] !== 'complete'
            || $removal->inspectSnapshot()['phase'] !== 'complete') {
            throw new RuntimeException('卸载文件阶段尚未完成');
        }
        $documents = [];
        foreach ($this->paths() as $kind => $path) {
            HostPayloadPlan::assertSafePath($path);
            $raw = is_file($path) && filesize($path) <= 4194304
                ? file_get_contents($path) : false;
            if ($kind !== 'fresh' && !is_string($raw)) {
                throw new RuntimeException('卸载收尾缺少文件审计：' . $kind);
            }
            $documents[$kind] = is_string($raw) ? hash('sha256', $raw) : null;
        }
        $this->verifyDocuments($documents);
        $this->save([
            'schema' => 1, 'id' => bin2hex(random_bytes(16)),
            'app' => $this->app, 'phase' => 'pending', 'documents' => $documents,
        ]);
    }

    /** @return array{phase:string,fingerprint:string,archived:array<string,bool>} */
    public function inspectSnapshot(): array
    {
        $record = $this->read();
        $this->assertRemoved();
        $archived = $this->verifyDocuments($record['documents']);
        return [
            'phase' => $record['phase'],
            'fingerprint' => hash('sha256', json_encode([$record, $archived], JSON_THROW_ON_ERROR)),
            'archived' => $archived,
        ];
    }

    public function finish(): void
    {
        $snapshot = $this->inspectSnapshot();
        if ($snapshot['phase'] === 'complete') return;
        if (!$snapshot['archived']['fresh']) {
            HostPayloadFreshFiles::archiveAfterRemoval($this->hostRoot, $this->stateRoot, $this->app);
        }
        $snapshot = $this->inspectSnapshot();
        if (!$snapshot['archived']['change']) {
            (new HostPayloadChangeFiles(
                $this->hostRoot, $this->stateRoot, $this->candidateRoot, $this->app, [],
            ))->archiveFinished();
        }
        $snapshot = $this->inspectSnapshot();
        if (!$snapshot['archived']['removal']) {
            (new HostPayloadRemovalFiles(
                $this->stateRoot, $this->app, $this->runtimeRoots, $this->candidateRoot,
            ))->archiveFinished();
        }
        $snapshot = $this->inspectSnapshot();
        if (in_array(false, $snapshot['archived'], true)) {
            throw new RuntimeException('卸载文件审计尚未全部归档');
        }
        $record = $this->read();
        $record['phase'] = 'complete';
        $this->save($record);
    }

    /** @return array<string,string> */
    private function paths(): array
    {
        $root = HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app;
        return [
            'fresh' => $root . '.fresh.json',
            'change' => $root . '.change.json',
            'removal' => $root . '.removal.json',
        ];
    }

    /** @param array<string,string|null> $documents
     *  @return array<string,bool>
     */
    private function verifyDocuments(array $documents): array
    {
        if (array_keys($documents) !== ['fresh', 'change', 'removal']) {
            throw new RuntimeException('卸载收尾文件清单无效');
        }
        $archived = [];
        foreach ($this->paths() as $kind => $path) {
            $digest = $documents[$kind];
            if ($digest === null) {
                if ($kind !== 'fresh' || file_exists($path) || is_link($path)) {
                    throw new RuntimeException('卸载收尾文件意外出现：' . $kind);
                }
                $archived[$kind] = true;
                continue;
            }
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new RuntimeException('卸载收尾文件摘要无效');
            }
            $history = $path . '.' . $digest . '.history';
            HostPayloadPlan::assertSafePath($history);
            $active = is_file($path) && !is_link($path) && hash_file('sha256', $path) === $digest;
            $saved = is_file($history) && !is_link($history) && hash_file('sha256', $history) === $digest;
            if ($active === $saved || file_exists($path) && !$active || file_exists($history) && !$saved) {
                throw new RuntimeException('卸载审计位置或摘要不符：' . $kind);
            }
            $archived[$kind] = $saved;
            $raw = file_get_contents($saved ? $history : $path);
            $decoded = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
            if (!is_array($decoded) || ($decoded['app'] ?? null) !== $this->app
                || ($kind === 'fresh' && ($decoded['phase'] ?? null) !== 'complete')
                || ($kind === 'change' && ($decoded['phase'] ?? null) !== 'complete')
                || ($kind === 'removal' && ($decoded['phase'] ?? null) !== 'complete')) {
                throw new RuntimeException('卸载审计阶段或插件身份不符：' . $kind);
            }
            if ($kind === 'fresh' || $kind === 'change') {
                $files = $kind === 'fresh' ? ($decoded['files'] ?? null) : ($decoded['old'] ?? null);
                if (!is_array($files)) throw new RuntimeException('卸载审计宿主清单无效');
                HostPayloadPlan::normalizeFiles($files, $this->app);
                foreach ($files as $entry) {
                    $target = HostPayloadPlan::directoryPrefix($this->hostRoot) . $entry['path'];
                    HostPayloadPlan::assertSafePath($target);
                    if (file_exists($target) || is_link($target)) {
                        throw new RuntimeException('卸载后宿主文件重新出现：' . $entry['path']);
                    }
                }
            }
        }
        return $archived;
    }

    private function assertRemoved(): void
    {
        if (HostPayloadOwnership::read($this->stateRoot, $this->app) !== null) {
            throw new RuntimeException('卸载宿主文件归属仍存在');
        }
        foreach ([$this->candidateRoot, ...$this->runtimeRoots] as $root) {
            if (FreshInstallRecovery::tree($root) !== null) {
                throw new RuntimeException('卸载运行目录或候选仍存在');
            }
        }
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 1048576
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || count($record) !== 5 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $this->app
            || !is_string($record['id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || !in_array($record['phase'] ?? null, ['pending', 'complete'], true)
            || !is_array($record['documents'] ?? null)) {
            throw new RuntimeException('卸载收尾记录损坏');
        }
        return $record;
    }

    private function archiveCompleted(): void
    {
        $record = $this->read();
        if ($record['phase'] !== 'complete') throw new RuntimeException('卸载收尾尚未完成');
        $raw = file_get_contents($this->journal);
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法归档上次卸载收尾记录');
        }
    }

    /** @param array<string,mixed> $record */
    private function save(array $record): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        if (!is_dir($this->stateRoot) && !mkdir($this->stateRoot, 0700, true)) {
            throw new RuntimeException('无法创建卸载收尾记录目录');
        }
        $temporary = $this->journal . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $body = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('无法保存卸载收尾记录');
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('卸载收尾记录未完整写入');
            }
            if (!rename($temporary, $this->journal)) throw new RuntimeException('无法替换卸载收尾记录');
        } finally {
            fclose($handle);
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
