<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Forward-only deletion of the exact runtime and candidate trees recorded before uninstall SQL. */
final class HostPayloadRemovalFiles
{
    private string $journal;

    /** @param list<string> $runtimeRoots */
    public function __construct(
        private readonly string $stateRoot,
        private readonly string $app,
        private readonly array $runtimeRoots,
        private readonly string $candidateRoot,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || count($runtimeRoots) !== 2
            || count(array_unique($runtimeRoots)) !== 2
            || in_array($candidateRoot, $runtimeRoots, true)) {
            throw new RuntimeException('卸载文件身份无效');
        }
        foreach ([$stateRoot, $candidateRoot, ...$runtimeRoots] as $root) {
            HostPayloadPlan::assertSafePath($root);
        }
        $this->journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.removal.json';
        HostPayloadPlan::assertSafePath($this->journal);
    }

    public function begin(): void
    {
        if (file_exists($this->journal)) {
            if (!in_array($this->read()['phase'], ['complete', 'cancelled'], true)) {
                throw new RuntimeException('已有未完成的卸载文件记录');
            }
            $this->archiveFinished();
        }
        $trees = [];
        foreach ($this->roots() as $root) {
            $trees[$root] = FreshInstallRecovery::tree($root);
        }
        if ($trees[$this->candidateRoot] === null || $trees[$this->runtimeRoots[0]] === null) {
            throw new RuntimeException('受控卸载缺少登记候选或后端运行目录');
        }
        $this->save([
            'schema' => 1, 'id' => bin2hex(random_bytes(16)),
            'app' => $this->app, 'phase' => 'pending',
            'roots' => $this->roots(), 'trees' => $trees,
        ]);
    }

    /** @return array{phase:string,fingerprint:string,remaining:array<string,array<string,string>|null>,unchanged:bool} */
    public function inspectSnapshot(): array
    {
        $record = $this->read();
        $remaining = [];
        $unchanged = true;
        foreach ($record['roots'] as $root) {
            $current = FreshInstallRecovery::tree($root);
            $prior = $record['trees'][$root];
            if ($record['phase'] === 'complete' && $current !== null) {
                throw new RuntimeException('已完成卸载的目录重新出现');
            }
            if ($record['phase'] === 'cancelled' && $current !== $prior) {
                throw new RuntimeException('已取消卸载的目录不等于原始快照');
            }
            if ($current !== null) {
                if ($prior === null) throw new RuntimeException('卸载目录出现未知内容');
                foreach ($current as $path => $hash) {
                    if (($prior[$path] ?? null) !== $hash) {
                        throw new RuntimeException('卸载目录包含外部修改：' . $path);
                    }
                }
            }
            if ($current !== $prior) $unchanged = false;
            $remaining[$root] = $current;
        }
        return [
            'phase' => $record['phase'],
            'fingerprint' => hash('sha256', json_encode([$record, $remaining], JSON_THROW_ON_ERROR)),
            'remaining' => $remaining,
            'unchanged' => $unchanged,
        ];
    }

    public function cancel(): void
    {
        $snapshot = $this->inspectSnapshot();
        if (!$snapshot['unchanged']) throw new RuntimeException('卸载目录已改变，不能按 SQL 回滚恢复');
        if ($snapshot['phase'] === 'cancelled') return;
        if ($snapshot['phase'] !== 'pending') throw new RuntimeException('已完成卸载不能取消');
        $record = $this->read();
        $record['phase'] = 'cancelled';
        $this->save($record);
    }

    public function applyRuntime(): void
    {
        $this->inspectSnapshot();
        foreach ($this->runtimeRoots as $root) $this->remove($root);
    }

    public function applyCandidate(): void
    {
        $snapshot = $this->inspectSnapshot();
        foreach ($this->runtimeRoots as $root) {
            if ($snapshot['remaining'][$root] !== null) {
                throw new RuntimeException('运行目录尚未完全移除');
            }
        }
        $this->remove($this->candidateRoot);
    }

    public function complete(): void
    {
        $snapshot = $this->inspectSnapshot();
        foreach ($snapshot['remaining'] as $remaining) {
            if ($remaining !== null) throw new RuntimeException('卸载目录尚未完全移除');
        }
        $record = $this->read();
        $record['phase'] = 'complete';
        $this->save($record);
    }

    public function archiveFinished(): void
    {
        $record = $this->read();
        if (!in_array($record['phase'], ['complete', 'cancelled'], true)) {
            throw new RuntimeException('未结束卸载不能归档');
        }
        $raw = file_get_contents($this->journal);
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法归档卸载文件记录');
        }
    }

    private function remove(string $root): void
    {
        $current = $this->inspectSnapshot()['remaining'][$root];
        if ($current === null) return;
        $paths = array_keys($current);
        usort($paths, static function (string $a, string $b): int {
            return substr_count($b, '/') <=> substr_count($a, '/')
                ?: strcmp($b, $a);
        });
        foreach ($paths as $path) {
            $target = $root . '/' . $path;
            HostPayloadPlan::assertSafePath($target);
            if ($current[$path] === 'directory') {
                if (is_dir($target) && !rmdir($target)) {
                    throw new RuntimeException('无法移除卸载目录：' . $path);
                }
            } elseif (is_file($target)) {
                if (hash_file('sha256', $target) !== $current[$path] || !unlink($target)) {
                    throw new RuntimeException('卸载文件已变化或无法移除：' . $path);
                }
            }
        }
        HostPayloadPlan::assertSafePath($root);
        if (is_dir($root) && !rmdir($root)) {
            throw new RuntimeException('卸载根目录含有额外内容');
        }
    }

    /** @return list<string> */
    private function roots(): array
    {
        return [...$this->runtimeRoots, $this->candidateRoot];
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 4194304
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || count($record) !== 6 || ($record['schema'] ?? null) !== 1
            || !is_string($record['id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || ($record['app'] ?? null) !== $this->app
            || !in_array($record['phase'] ?? null, ['pending', 'complete', 'cancelled'], true)
            || ($record['roots'] ?? null) !== $this->roots()
            || !is_array($record['trees'] ?? null)
            || array_keys($record['trees']) !== $this->roots()) {
            throw new RuntimeException('卸载文件记录损坏');
        }
        foreach ($record['trees'] as $tree) {
            if ($tree === null) continue;
            if (!is_array($tree)) throw new RuntimeException('卸载文件快照无效');
            foreach ($tree as $path => $hash) {
                if (!is_string($path) || $path === '' || str_starts_with($path, '/')
                    || str_contains('/' . $path . '/', '/../')
                    || str_contains('/' . $path . '/', '/./')
                    || !is_string($hash)
                    || $hash !== 'directory' && preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new RuntimeException('卸载文件快照无效');
                }
            }
        }
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function save(array $record): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        if (!is_dir($this->stateRoot) && !mkdir($this->stateRoot, 0700, true)) {
            throw new RuntimeException('无法建立卸载记录目录');
        }
        $temporary = $this->journal . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $body = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('无法保存卸载文件记录');
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('卸载文件记录未完整写入');
            }
            if (!rename($temporary, $this->journal)) throw new RuntimeException('无法替换卸载文件记录');
        } finally {
            fclose($handle);
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
