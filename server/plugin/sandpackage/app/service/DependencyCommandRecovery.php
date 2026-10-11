<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use Closure;
use RuntimeException;

/** Retires an interrupted dependency owner's occupation without declaring its command successful. */
final class DependencyCommandRecovery
{
    private string $marker;
    private string $journal;
    private string $registry;

    public function __construct(
        private readonly string $storage,
        private readonly string $app,
        private readonly Closure $snapshot,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) throw new RuntimeException('依赖恢复插件标识无效');
        $this->marker = HostPayloadPlan::directoryPrefix($storage) . 'locks/host-upstream.process.json';
        $this->journal = HostPayloadPlan::directoryPrefix($storage) . 'host-payload/' . $app . '.dependency-recovery.json';
        $this->registry = HostPayloadPlan::directoryPrefix($storage) . $app . '/info.ini';
        foreach ([$this->marker, $this->journal, $this->registry] as $path) HostPayloadPlan::assertSafePath($path);
    }

    public function inspect(): array
    {
        $current = ($this->snapshot)();
        $checkpoint = $this->readCheckpoint();
        $raw = $this->read($this->marker);
        if ($checkpoint === null) {
            if ($raw === null) {
                $info = $current['info'];
                $reload = ($info['state'] ?? null) === 1 && in_array($info['dependency_restart_pending'] ?? 0, [1, 2], true);
                if (!$reload) throw new RuntimeException('没有可恢复的依赖进程记录或待完成重载意图');
                if (!empty($info['dependency_command_nonce']) || !empty($info['composer_dependent_wait_install'])
                    || !empty($info['npm_dependent_wait_install'])) throw new RuntimeException('依赖尚未全部完成，不能仅重载');
                return $this->result($current, null, 'reload_pending', ['restart-only'], null);
            }
            $marker = $this->validateMarker($raw);
            if ($this->completedCleanup($current['info'], $marker)) {
                $this->assertAbsent($marker['process']);
                return $this->result($current, $raw, 'completion_cleanup_pending', ['finish-dependency-cleanup'], null);
            }
            $this->assertWaiting($current['info'], $marker['nonce']);
            if (isset($marker['type']) && empty($current['info'][$marker['type'] . '_dependent_wait_install'])) throw new RuntimeException('原依赖类型与等待标志不符');
            $this->assertAbsent($marker['process']);
            return $this->result($current, $raw, 'interrupted', ['reset-dependency-waiting'], null);
        }
        $history = $this->history($checkpoint['marker_sha256']);
        $archived = $this->read($history);
        if ($raw !== null && hash('sha256', $raw) !== $checkpoint['marker_sha256']
            || $archived !== null && hash('sha256', $archived) !== $checkpoint['marker_sha256']
            || $raw === null && $archived === null
            || $raw !== null && $archived !== null) throw new RuntimeException('依赖原记录与恢复归档不符');
        $marker = $this->validateMarker($raw ?? $archived);
        $this->assertAbsent($marker['process']);
        $before = $checkpoint['before'];
        if ($checkpoint['purpose'] === 'completed-dependency') {
            if (!$this->completedCleanup($before['info'], $marker)) throw new RuntimeException('依赖成功/失败收尾登记与原记录不符');
        } else $this->assertWaiting($before['info'], $marker['nonce']);
        if ($checkpoint['purpose'] === 'interrupted-dependency' && isset($marker['type']) && empty($before['info'][$marker['type'] . '_dependent_wait_install'])) throw new RuntimeException('恢复原依赖类型与等待标志不符');
        $after = $this->after($checkpoint);
        if ($current !== $before && $current !== $after) throw new RuntimeException('依赖恢复现场与原检查已变化');
        if ($raw !== null && $current !== $before
            || $checkpoint['phase'] !== 'prepared' && $raw !== null
            || $checkpoint['phase'] === 'nonce_cleared' && $current !== $after
            || $checkpoint['phase'] === 'prepared' && $current !== $before) {
            throw new RuntimeException('依赖恢复登记与检查点阶段不符');
        }
        return $this->result($current, $raw ?? $archived, $checkpoint['phase'], [$checkpoint['purpose'] === 'completed-dependency' ? 'finish-dependency-cleanup' : 'reset-dependency-waiting'], $checkpoint);
    }

    /** @param callable(string):void|null $fault */
    public function recover(string $confirmation, ?callable $fault = null): array
    {
        $inspection = $this->inspect();
        if (!in_array($inspection['actions'], [['reset-dependency-waiting'], ['finish-dependency-cleanup']], true)
            || !hash_equals('RECOVER DEPENDENCY ' . $this->app . ' ' . $inspection['fingerprint'], $confirmation)) {
            throw new RuntimeException('请重新检查原依赖任务并使用本次完整确认');
        }
        $checkpoint = $this->readCheckpoint();
        if ($checkpoint === null) {
            $raw = $this->read($this->marker);
            $before = ($this->snapshot)();
            $finishing = $inspection['actions'] === ['finish-dependency-cleanup'];
            $next = $this->read($this->registry) ?? '';
            if (!$finishing) $next = $this->withoutNonce($next);
            $checkpoint = [
                'schema' => 1, 'app' => $this->app, 'id' => bin2hex(random_bytes(16)),
                'purpose' => $finishing ? 'completed-dependency' : 'interrupted-dependency', 'phase' => 'prepared',
                'marker_sha256' => hash('sha256', $raw),
                'before' => $before, 'registry_after_sha256' => hash('sha256', $next),
            ];
            // Reinspect immediately before the first durable recovery write.
            if ($this->inspect()['fingerprint'] !== $inspection['fingerprint']) throw new RuntimeException('依赖恢复写入前现场已变化');
            $this->save($checkpoint);
            $fault?->__invoke('prepared');
        }
        $this->inspect();
        $history = $this->history($checkpoint['marker_sha256']);
        if ($this->read($this->marker) !== null) {
            if (file_exists($history) || !rename($this->marker, $history)) throw new RuntimeException('不能归档原依赖进程记录');
            $fault?->__invoke('marker-renamed');
        }
        if ($checkpoint['phase'] === 'prepared') {
            $checkpoint['phase'] = 'marker_archived';
            $this->save($checkpoint);
            $fault?->__invoke('marker_archived');
        }
        $this->inspect();
        $raw = $this->read($this->registry);
        if ($checkpoint['purpose'] === 'interrupted-dependency' && hash('sha256', $raw) === $checkpoint['before']['registry_sha256']) {
            $next = $this->withoutNonce($raw);
            if (hash('sha256', $next) !== $checkpoint['registry_after_sha256']) throw new RuntimeException('依赖登记变化不符合预期');
            $this->write($this->registry, $next);
            $fault?->__invoke('nonce-cleared');
        }
        $checkpoint['phase'] = 'nonce_cleared';
        $this->save($checkpoint);
        $fault?->__invoke('nonce_cleared');
        $this->inspect();
        $closed = $this->journal . '.' . hash('sha256', $this->read($this->journal)) . '.history';
        HostPayloadPlan::assertSafePath($closed);
        if (file_exists($closed) || !rename($this->journal, $closed)) throw new RuntimeException('依赖恢复检查点未能归档');
        return ['app' => $this->app, 'action' => $inspection['actions'][0], 'state' => ($this->snapshot)()['info']['state'],
            'command_success_proven' => false, 'sql_replayed' => false, 'dependency_replayed' => false,
            'next_action' => $checkpoint['purpose'] === 'completed-dependency' ? '原登记已保留；检查剩余依赖或待处理重载' : '重新显式执行所需的正式依赖任务；不重跑插件安装'];
    }

    private function result(array $current, ?string $marker, string $phase, array $actions, ?array $checkpoint): array
    {
        return ['app' => $this->app, 'operation' => 'dependency-recovery', 'phase' => $phase,
            'state' => $current['info']['state'], 'actions' => $actions,
            'command_success_proven' => false,
            'restart_intent' => match ($current['info']['dependency_restart_pending'] ?? null) { 0 => 'not_requested', 1 => 'requested', default => 'unknown' },
            'reload_result' => ($current['info']['dependency_restart_status'] ?? null) === 'requesting' ? 'unknown' : 'not_requested',
            'fingerprint' => hash('sha256', json_encode([$current, $marker, $checkpoint], JSON_THROW_ON_ERROR))];
    }

    private function after(array $checkpoint): array
    {
        $after = $checkpoint['before'];
        if ($checkpoint['purpose'] === 'completed-dependency') return $after;
        unset($after['info']['dependency_command_nonce']);
        if (!array_key_exists('dependency_restart_pending', $after['info'])) $after['info']['dependency_restart_pending'] = 2;
        $after['registry_sha256'] = $checkpoint['registry_after_sha256'];
        $after['candidate']['info.ini'] = $checkpoint['registry_after_sha256'];
        return $after;
    }

    private function completedCleanup(array $info, array $marker): bool
    {
        if (($marker['phase'] ?? null) !== 'reaped' || !empty($info['dependency_command_nonce'])) return false;
        $success = ($info['dependency_completed_nonce'] ?? null) === $marker['nonce'];
        $failure = ($info['dependency_failed_nonce'] ?? null) === $marker['nonce'];
        if (!$success && !$failure) return false;
        if ($success && !empty($info[$marker['type'] . '_dependent_wait_install'])) throw new RuntimeException('成功依赖收尾仍保留本次等待标志');
        if ($failure && (($info['state'] ?? null) !== 4 || empty($info[$marker['type'] . '_dependent_wait_install']))) throw new RuntimeException('失败依赖收尾与等待标志不符');
        if (($info['state'] ?? null) === 1 && (!empty($info['composer_dependent_wait_install']) || !empty($info['npm_dependent_wait_install']))) throw new RuntimeException('已安装收尾仍有依赖等待标志');
        return true;
    }

    private function assertWaiting(array $info, string $nonce): void
    {
        if (($info['state'] ?? null) !== 4 || ($info['dependency_command_nonce'] ?? null) !== $nonce
            || (empty($info['composer_dependent_wait_install']) && empty($info['npm_dependent_wait_install']))) {
            throw new RuntimeException('依赖原任务与当前等待登记不符');
        }
    }

    private function validateMarker(string $raw): array
    {
        $record = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['app'] ?? null) !== $this->app
            || !is_string($record['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $record['nonce']) !== 1
            || !is_array($record['process'] ?? null) || count($record['process']) !== 3
            || !is_int($record['process']['pid'] ?? null) || $record['process']['pid'] < 1
            || ($record['process']['pgid'] ?? null) !== $record['process']['pid']
            || !is_array($record['process']['descendants'] ?? null) || !array_is_list($record['process']['descendants'])) {
            throw new RuntimeException('依赖进程记录损坏或不属于当前插件');
        }
        $legacy = count($record) === 3;
        if (!$legacy && (count($record) !== 7 || !in_array($record['type'] ?? null, ['composer', 'npm'], true)
            || !is_int($record['started_at'] ?? null) || $record['started_at'] < 1
            || !in_array($record['phase'] ?? null, ['started', 'tracked', 'exited', 'failure', 'RECOVERY_REQUIRED', 'reaped'], true)
            || ($record['failure_time'] !== null && (!is_int($record['failure_time']) || $record['failure_time'] < 1)))) {
            throw new RuntimeException('依赖进程新记录结构无效');
        }
        foreach ($record['process']['descendants'] as $pid) {
            if (!is_int($pid) || $pid < 1) throw new RuntimeException('依赖子进程记录无效');
        }
        return $record;
    }

    private function assertAbsent(array $process): void
    {
        if (!function_exists('posix_kill') || !function_exists('posix_get_last_error')) throw new RuntimeException('此平台无法证明原依赖进程已退出');
        foreach (array_unique(array_merge([-$process['pgid'], $process['pid']], $process['descendants'])) as $pid) {
            if (@posix_kill($pid, 0) || posix_get_last_error() !== 3) throw new RuntimeException('原进程仍存在、身份被复用或退出状态不明；不能恢复');
        }
    }

    private function readCheckpoint(): ?array
    {
        $raw = $this->read($this->journal);
        if ($raw === null) return null;
        $record = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($record) || count($record) !== 8 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $this->app || !in_array($record['purpose'] ?? null, ['interrupted-dependency', 'completed-dependency'], true)
            || !is_string($record['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || !in_array($record['phase'] ?? null, ['prepared', 'marker_archived', 'nonce_cleared'], true)
            || !is_array($record['before'] ?? null) || count($record['before']) !== 7
            || !is_array($record['before']['info'] ?? null) || !is_array($record['before']['candidate'] ?? null)
            || !is_array($record['before']['runtime'] ?? null) || !is_array($record['before']['owned'] ?? null)
            || !is_array($record['before']['transactions'] ?? null) || !is_array($record['before']['dependencies'] ?? null)
            || !is_string($record['before']['registry_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $record['before']['registry_sha256']) !== 1
            || ($record['before']['candidate']['info.ini'] ?? null) !== $record['before']['registry_sha256']
            || !is_string($record['marker_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['marker_sha256']) !== 1
            || !is_string($record['registry_after_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['registry_after_sha256']) !== 1) {
            throw new RuntimeException('依赖恢复检查点损坏');
        }
        return $record;
    }

    private function withoutNonce(string $raw): string
    {
        $info = parse_ini_string($raw, true, INI_SCANNER_TYPED);
        if (!is_array($info)) throw new RuntimeException('依赖原登记不能解析');
        // Older records did not preserve the install request's restart choice.
        $replacement = array_key_exists('dependency_restart_pending', $info) ? '' : "dependency_restart_pending = 2\n";
        $next = preg_replace('/^dependency_command_nonce\\s*=.*(?:\\r?\\n|$)/m', $replacement, $raw, -1, $count);
        if (!is_string($next) || $count !== 1) throw new RuntimeException('依赖原登记 nonce 不能精确移除');
        return $next;
    }

    private function history(string $hash): string
    {
        $path = $this->marker . '.' . $hash . '.history';
        HostPayloadPlan::assertSafePath($path);
        return $path;
    }

    private function read(string $path): ?string
    {
        HostPayloadPlan::assertSafePath($path);
        if (!file_exists($path)) return null;
        if (!is_file($path) || filesize($path) > 4194304) throw new RuntimeException('依赖恢复记录类型或大小不安全');
        $raw = file_get_contents($path);
        if (!is_string($raw)) throw new RuntimeException('依赖恢复记录不可读取');
        return $raw;
    }

    private function save(array $record): void
    {
        $this->write($this->journal, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function write(string $path, string $body): void
    {
        HostPayloadPlan::assertSafePath($path);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('不能准备依赖恢复目录');
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('不能暂存依赖恢复记录');
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('依赖恢复记录未持久化');
            if (!rename($temporary, $path)) throw new RuntimeException('不能保存依赖恢复记录');
        } finally { fclose($handle); if (is_file($temporary)) unlink($temporary); }
    }
}
