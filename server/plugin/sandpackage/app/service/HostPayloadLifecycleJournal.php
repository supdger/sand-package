<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Durable SQL outcome marker for host-file upgrades and uninstalls. */
final class HostPayloadLifecycleJournal
{
    private string $path;

    public function __construct(
        private readonly string $stateRoot,
        private readonly string $app,
        private readonly string $operation,
        private readonly string $packageSha256,
        private readonly ?object $connection = null,
        private readonly bool $restartRequested = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || !in_array($operation, ['upgrade', 'uninstall'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $packageSha256) !== 1) {
            throw new RuntimeException('宿主文件生命周期身份无效');
        }
        $this->path = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.lifecycle.json';
        HostPayloadPlan::assertSafePath($this->path);
    }

    public static function pending(string $stateRoot, string $app): bool
    {
        $path = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.lifecycle.json';
        HostPayloadPlan::assertSafePath($path);
        if (!file_exists($path)) return false;
        $raw = is_file($path) && filesize($path) <= 1048576 ? file_get_contents($path) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32) : null;
        return !is_array($record) || count($record) !== 9 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $app
            || !is_string($record['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || !in_array($record['operation'] ?? null, ['upgrade', 'uninstall'], true)
            || !is_string($record['package_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $record['package_sha256']) !== 1
            || !array_key_exists('database_before', $record) || !array_key_exists('database_after', $record)
            || !is_bool($record['restart_requested'] ?? null)
            || !self::validFingerprint($record['database_before'] ?? null)
            || !self::validFingerprint($record['database_after'] ?? null)
            || !in_array($record['phase'] ?? null, ['complete', 'rolled_back'], true);
    }

    /** @return array{schema:int,id:string,app:string,operation:string,package_sha256:string,phase:string,database_before:?array,database_after:?array,restart_requested:bool}|null */
    public static function inspect(string $stateRoot, string $app): ?array
    {
        $path = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.lifecycle.json';
        HostPayloadPlan::assertSafePath($path);
        if (!file_exists($path)) return null;
        $raw = is_file($path) && filesize($path) <= 1048576 ? file_get_contents($path) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || count($record) !== 9 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $app
            || !is_string($record['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || !in_array($record['operation'] ?? null, ['upgrade', 'uninstall'], true)
            || !is_string($record['package_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $record['package_sha256']) !== 1
            || !array_key_exists('database_before', $record) || !array_key_exists('database_after', $record)
            || !is_bool($record['restart_requested'] ?? null)
            || !in_array($record['phase'] ?? null,
                ['sql_not_started', 'sql_not_committed', 'sql_commit_unknown', 'sql_committed_deploy_pending', 'complete', 'rolled_back'], true)
            || !self::validFingerprint($record['database_before'] ?? null)
            || !self::validFingerprint($record['database_after'] ?? null)) {
            throw new RuntimeException('宿主文件生命周期记录损坏');
        }
        return $record;
    }

    public function begin(): void
    {
        if (file_exists($this->path)) {
            $previous = self::inspect($this->stateRoot, $this->app);
            if ($previous === null || !in_array($previous['phase'], ['complete', 'rolled_back'], true)) {
                throw new RuntimeException('已有未完成的宿主文件生命周期操作');
            }
            $this->archive(true);
        }
        $this->save([
            'schema' => 1, 'id' => bin2hex(random_bytes(16)),
            'app' => $this->app, 'operation' => $this->operation,
            'package_sha256' => $this->packageSha256, 'phase' => 'sql_not_started',
            'database_before' => $this->connection !== null
                ? PostgresHostCatalogFingerprint::capture($this->connection, $this->app) : null,
            'database_after' => null,
            'restart_requested' => $this->restartRequested,
        ]);
    }

    public function observe(string $phase): void
    {
        if (!in_array($phase, ['sql_not_committed', 'sql_commit_unknown', 'sql_committed_deploy_pending'], true)) {
            throw new RuntimeException('宿主文件生命周期 SQL 阶段无效');
        }
        $record = $this->read();
        if ($phase === 'sql_not_committed' && $this->connection !== null) {
            $current = PostgresHostCatalogFingerprint::capture($this->connection, $this->app);
            $record['phase'] = $current === $record['database_before']
                ? 'sql_not_committed' : 'sql_commit_unknown';
            $this->save($record);
            return;
        }
        if ($phase === 'sql_committed_deploy_pending' && $this->connection !== null) {
            $record['phase'] = 'sql_commit_unknown';
            $this->save($record);
            $record['database_after'] = PostgresHostCatalogFingerprint::capture($this->connection, $this->app);
        }
        $record['phase'] = $phase;
        $this->save($record);
    }

    public function complete(): void
    {
        $record = $this->read();
        if ($record['phase'] !== 'sql_committed_deploy_pending'
            || $this->connection !== null && ($record['database_after'] === null
                || PostgresHostCatalogFingerprint::capture($this->connection, $this->app) !== $record['database_after'])) {
            throw new RuntimeException('数据库尚未确认提交，不能完成宿主文件生命周期');
        }
        $record['phase'] = 'complete';
        $this->save($record);
        $this->archive();
    }

    public function completeRollback(): void
    {
        $record = $this->read();
        if (!($record['operation'] === 'upgrade'
                    && in_array($record['phase'], ['sql_not_started', 'sql_not_committed'], true)
                || $record['operation'] === 'uninstall'
                    && in_array($record['phase'], ['sql_not_started', 'sql_not_committed'], true))
            || $this->connection !== null
                && PostgresHostCatalogFingerprint::capture($this->connection, $this->app) !== $record['database_before']) {
            throw new RuntimeException('数据库未确认保持原状，不能结束生命周期恢复');
        }
        $record['phase'] = 'rolled_back';
        $this->save($record);
    }

    /** @return array{schema:int,id:string,app:string,operation:string,package_sha256:string,phase:string,database_before:?array,database_after:?array,restart_requested:bool} */
    public function read(): array
    {
        $record = self::inspect($this->stateRoot, $this->app);
        if ($record === null
            || ($record['operation'] ?? null) !== $this->operation
            || ($record['package_sha256'] ?? null) !== $this->packageSha256) {
            throw new RuntimeException('宿主文件生命周期记录损坏或候选已变化');
        }
        return $record;
    }

    private function archive(bool $priorOperation = false): void
    {
        $record = $priorOperation
            ? self::inspect($this->stateRoot, $this->app) : $this->read();
        if ($record === null) throw new RuntimeException('宿主文件生命周期记录缺失');
        if (!in_array($record['phase'], ['complete', 'rolled_back'], true)) {
            throw new RuntimeException('未完成的宿主文件生命周期不能归档');
        }
        $raw = file_get_contents($this->path);
        $history = $this->path . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->path, $history)) {
            throw new RuntimeException('无法归档宿主文件生命周期记录');
        }
    }

    private static function validFingerprint(mixed $value): bool
    {
        return $value === null
            || is_array($value) && count($value) === 2
                && is_string($value['identity'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $value['identity']) === 1
                && is_string($value['schema'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $value['schema']) === 1;
    }

    /** @param array<string,mixed> $record */
    private function save(array $record): void
    {
        HostPayloadPlan::assertSafePath($this->path);
        $parent = dirname($this->path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建宿主文件生命周期状态目录');
        }
        $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('无法创建宿主文件生命周期记录');
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('宿主文件生命周期记录未持久化');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $this->path)) {
            unlink($temporary);
            throw new RuntimeException('无法发布宿主文件生命周期记录');
        }
    }
}
