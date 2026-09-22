<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use plugin\sandadmin\exception\ApiException;
use Throwable;

/** Evidence for fresh installs only. The caller owns the normal host/app locks. */
final class FreshInstallRecovery
{
    private string $journalPath;

    public function __construct(
        private string $app,
        private string $candidate,
        private array $paths,
        private string $root,
        private object $pdo,
        private $readInfo,
    ) {
        $this->journalPath = $root . '/fresh-recovery/' . $app . '.json';
        self::safePath($this->journalPath);
    }

    public function begin(bool $restart = false): void
    {
        $previous = $this->journal();
        if ($previous !== null && !in_array($previous['phase'], ['complete', 'cleaned'], true)) {
            throw new ApiException('存在未完成的新装恢复记录');
        }
        foreach ($this->paths as $target) {
            if (file_exists($target)) throw new ApiException('全新安装的部署目录已存在');
        }
        if ($previous !== null) {
            $history = $this->journalPath . '.' . $previous['operation'] . '.history';
            self::safePath($history);
            $json = (string) file_get_contents($this->journalPath);
            if (is_file($history)) {
                if (file_get_contents($history) !== $json) throw new ApiException('上次恢复审计快照不匹配');
            } else {
                $handle = fopen($history, 'xb');
                if ($handle === false) throw new ApiException('无法保留上次恢复审计');
                try {
                    if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new ApiException('上次恢复审计未持久化');
                } finally { fclose($handle); }
            }
        }
        $binding = $this->binding();
        $this->save([
            'format' => 1, 'app' => $this->app, 'operation' => bin2hex(random_bytes(16)),
            'phase' => 'sql_not_committed', 'restart_required' => $restart, 'binding' => $binding,
            'before_database' => $binding['database'], 'candidate_identity' => $binding['candidate'],
            'events' => [['event' => 'begin', 'time' => gmdate('c')]],
        ]);
    }

    public function observe(string $phase): void
    {
        $record = $this->journalRequired();
        $record['phase'] = $phase;
        $this->save($record);
    }

    public function checkpoint(): void
    {
        $record = $this->journalRequired();
        $binding = $this->binding();
        $this->assertIdentity($record, $binding);
        $record['binding'] = $binding;
        if ($record['phase'] === 'sql_not_committed'
            && $record['before_database'] !== $record['binding']['database']) {
            $record['phase'] = 'sql_commit_unknown';
        }
        $record['events'][] = ['event' => 'checkpoint', 'phase' => $record['phase'], 'time' => gmdate('c')];
        $this->save($record);
    }

    public function complete(): void
    {
        $record = $this->journalRequired();
        $record['phase'] = 'complete';
        $record['events'][] = ['event' => 'complete', 'time' => gmdate('c')];
        $this->save($record);
    }

    public function inspect(?array $plan = null): array
    {
        $record = $this->journal();
        if ($record !== null && $record['phase'] === 'cleaned' && !is_dir($this->candidate)) {
            return ['app' => $this->app, 'phase' => 'cleaned', 'operation' => $record['operation'], 'actions' => []];
        }
        if ($record !== null && isset($record['cleanup_stage'])) {
            $cleanup = $this->inspectCleanup($record);
            if ($cleanup !== null) return $cleanup;
        }
        $this->assertFresh();
        $binding = $this->binding();
        if ($record !== null) $this->assertIdentity($record, $binding);
        $phase = $record['phase'] ?? 'sql_commit_unknown';
        $bound = $record !== null && ($record['binding'] === $binding || $this->startWindow($record, $binding) || $this->completionWindow($record, $binding));
        if (!$bound || !in_array($phase, ['sql_not_committed', 'sql_committed_deploy_pending'], true)) {
            $phase = 'sql_commit_unknown';
        }
        $actions = match ($phase) {
            'sql_not_committed' => ['cleanup-fresh'],
            'sql_committed_deploy_pending' => ['continue-fresh'],
            default => ['manual-cleanup-fresh'],
        };
        if ($plan !== null) $this->validatePlan($plan, $binding);
        $fingerprint = self::hash(['app' => $this->app, 'binding' => $binding, 'journal' => $record, 'plan' => $plan]);
        return ['app' => $this->app, 'version' => ($this->readInfo)()['version'], 'phase' => $phase,
            'binding' => $binding, 'fingerprint' => $fingerprint, 'actions' => $actions,
            'confirmation' => strtoupper($actions[0]) . ' ' . $this->app . ' ' . $fingerprint,
            'manual_plan_required' => $phase === 'sql_commit_unknown',
            'manual_plan_tables' => $this->declaredTables(),
            'restart_required' => $record['restart_required'] ?? false,
        ];
    }

    public function recover(string $action, string $confirmation, ?array $plan, callable $deploy, bool $restart = false): array
    {
        $record = $this->journal();
        if ($record !== null && ($record['last_confirmation'] ?? '') === $confirmation
            && in_array($record['phase'], ['complete', 'cleaned'], true)
            && ($record['phase'] === 'cleaned' ? !is_dir($this->candidate) : empty(($this->readInfo)()['operation_pending']))) {
            return ['app' => $this->app, 'phase' => $record['phase'], 'repeated' => true];
        }
        $inspection = $this->inspect($plan);
        if (!in_array($action, $inspection['actions'], true)
            || !hash_equals($inspection['confirmation'], $confirmation)) {
            throw new ApiException('恢复操作或现场指纹确认不匹配，请重新检查');
        }
        if ($action === 'manual-cleanup-fresh' && $plan === null) throw new ApiException('未知提交结果必须提供已审核的人工清理计划');
        $record ??= ['format' => 1, 'app' => $this->app, 'operation' => bin2hex(random_bytes(16)), 'events' => []];
        $record['binding'] = $inspection['binding'];
        $record['last_confirmation'] = $confirmation;
        $record['phase'] = $action === 'continue-fresh' ? 'sql_committed_deploy_pending' : 'sql_commit_unknown';
        if ($action === 'cleanup-fresh') {
            $record['cleanup_stage'] = 'archive_pending';
            $record['cleanup_expected_database'] = $inspection['binding']['database'];
            $record['archive'] = $this->root . '/fresh-recovery/' . $this->app . '-' . $record['operation'];
        }
        $record['events'][] = ['event' => $action, 'time' => gmdate('c'),
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
            'fingerprint' => $inspection['fingerprint'], 'plan' => $plan];
        $this->save($record); // Persist intent before any side effect.
        try {
            if ($action === 'continue-fresh') {
                if (!empty($record['restart_required']) && !$restart) throw new ApiException('原安装尚需服务重载；明确授权后使用 --restart 继续');
                $deploy();
                $this->complete();
                return ['app' => $this->app, 'phase' => 'complete', 'sql_executed' => false];
            }
            foreach ($this->paths as $target) {
                if (file_exists($target)) throw new ApiException('清理要求运行部署目录不存在；不得猜测删除运行文件');
            }
            if ($action === 'manual-cleanup-fresh') $this->cleanupDatabase($plan, $inspection['binding']['database']);
            $record = $this->journalRequired();
            $record['cleanup_stage'] = 'archive_pending';
            $record['cleanup_expected_database'] ??= $inspection['binding']['database'];
            $record['archive'] ??= $this->root . '/fresh-recovery/' . $this->app . '-' . $record['operation'];
            $this->save($record);
            $this->archiveCandidate();
            $record = $this->journalRequired();
            $record['phase'] = 'cleaned';
            $record['events'][] = ['event' => 'cleaned', 'time' => gmdate('c')];
            $this->save($record);
            return ['app' => $this->app, 'phase' => 'cleaned', 'sql_executed' => $action === 'manual-cleanup-fresh'];
        } catch (Throwable $error) {
            $record = $this->journalRequired();
            $record['phase'] = $action === 'continue-fresh' ? 'sql_committed_deploy_pending' : 'sql_commit_unknown';
            if ($action === 'continue-fresh') {
                $binding = $this->binding();
                $this->assertIdentity($record, $binding);
                $record['binding'] = $binding;
            }
            $record['events'][] = ['event' => 'recovery_failed', 'time' => gmdate('c')];
            $this->save($record);
            throw $error;
        }
    }

    private function inspectCleanup(array $record): ?array
    {
        $archive = $record['archive'] ?? null;
        $directory = is_dir($this->candidate) ? $this->candidate : $archive;
        if (!is_string($directory) || !is_dir($directory)) throw new ApiException('恢复候选及归档均不可核对');
        if (self::hash(self::tree($directory, ['info.ini'])) !== $record['binding']['candidate']) throw new ApiException('清理候选摘要已变化');
        self::safePath($directory . '/info.ini');
        $info = parse_ini_file($directory . '/info.ini', true, INI_SCANNER_TYPED);
        if (!is_array($info) || self::hash($info) !== $record['binding']['registry']) throw new ApiException('清理登记摘要已变化');
        foreach ($this->paths as $target) if (file_exists($target)) throw new ApiException('清理期间部署目录发生变化');
        $database = $this->database();
        if (($record['cleanup_stage'] ?? '') === 'commit_intent' && $database === $record['binding']['database']) return null;
        if ($database !== ($record['cleanup_expected_database'] ?? null)) throw new ApiException('清理提交未能确认或数据库再次变化；必须人工核对');
        $fingerprint = self::hash(['app' => $this->app, 'record' => $record, 'database' => $database, 'directory' => $directory]);
        return ['app' => $this->app, 'phase' => 'sql_commit_unknown', 'cleanup_stage' => $record['cleanup_stage'],
            'binding' => $record['binding'], 'fingerprint' => $fingerprint, 'actions' => ['finish-cleanup-fresh'],
            'confirmation' => 'FINISH-CLEANUP-FRESH ' . $this->app . ' ' . $fingerprint,
            'sql_executed' => false];
    }

    private function assertFresh(): void
    {
        $info = ($this->readInfo)();
        if (($info['lifecycle_driver'] ?? '') !== 'saipackage-pg-v1' || !empty($info['update'])
            || !empty($info['upgrade_from_version']) || !empty($info['package_backup_id'])
            || !empty($info['dependency_command_nonce']) || ($info['app'] ?? '') !== $this->app
            || empty($info['operation_pending']) && !$this->hasStartEvidence($info)) {
            throw new ApiException('此记录不是可检查的 PostgreSQL 全新安装失败现场');
        }
    }

    private function hasStartEvidence(array $info): bool
    {
        $record = $this->journal();
        return $record !== null && ($record['phase'] === 'sql_not_committed'
            && ($record['binding']['registry'] ?? '') === self::hash($info)
            || $record['phase'] === 'sql_committed_deploy_pending' && in_array($info['state'] ?? null, [1, 4], true));
    }

    private function startWindow(array $record, array $binding): bool
    {
        if ($record['phase'] !== 'sql_not_committed' || !isset($record['binding']['registry_info'])) return false;
        $expected = $record['binding'];
        $info = $expected['registry_info'];
        $info['operation_pending'] = 1;
        $expected['registry_info'] = $info;
        $expected['registry'] = self::hash($info);
        return $expected === $binding;
    }

    private function completionWindow(array $record, array $binding): bool
    {
        if ($record['phase'] !== 'sql_committed_deploy_pending' || !isset($record['binding']['registry_info'])) return false;
        $expected = $record['binding'];
        $info = $expected['registry_info'];
        unset($info['operation_pending']);
        $expected['registry_info'] = $info;
        $expected['registry'] = self::hash($info);
        return $expected === $binding;
    }

    private function assertIdentity(array $record, array $binding): void
    {
        if (($record['candidate_identity'] ?? $record['binding']['candidate']) !== $binding['candidate']
            || ($record['binding']['database']['identity'] ?? null) !== $binding['database']['identity']) {
            throw new ApiException('原候选或数据库身份已变化，禁止恢复');
        }
    }

    private function binding(): array
    {
        $info = ($this->readInfo)();
        $files = self::tree($this->candidate, ['info.ini']);
        $deployment = [];
        foreach ($this->paths as $source => $target) $deployment[$target] = self::tree($target);
        $hostFiles = [];
        foreach ([base_path() . '/composer.json', base_path() . '/composer.lock',
            dirname(base_path()) . '/' . env('FRONTEND_DIR', 'sandadmin-artd') . '/package.json',
            dirname(base_path()) . '/' . env('FRONTEND_DIR', 'sandadmin-artd') . '/pnpm-lock.yaml'] as $path) {
            self::safePath($path);
            $hostFiles[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        return ['host' => realpath(base_path()), 'candidate' => self::hash($files),
            'package_sha256' => $info['package_sha256'] ?? null,
            'install_sql' => $files['install.sql'] ?? null, 'uninstall_sql' => $files['uninstall.sql'] ?? null,
            'registry' => self::hash($info), 'registry_info' => $info, 'deployment' => self::hash($deployment),
            'host_dependencies' => self::hash($hostFiles), 'database' => $this->database()];
    }

    /** Only candidate-declared relations are read. Unrelated business tables are untouched. */
    private function database(): array
    {
        $identity = $this->rows("SELECT current_database() AS database, (SELECT oid FROM pg_database WHERE datname=current_database()) AS oid, current_user AS username, inet_server_addr()::text AS address, inet_server_port() AS port, pg_postmaster_start_time()::text AS started");
        $tables = [];
        foreach ($this->declaredTables() as $name) {
            [$schema, $table] = explode('.', $name);
            $relation = $this->rows("SELECT c.oid::text AS oid, c.relkind AS kind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=" . $this->pdo->quote($schema) . ' AND c.relname=' . $this->pdo->quote($table));
            if ($relation === []) continue;
            if (count($relation) !== 1 || $relation[0]['kind'] !== 'r') throw new ApiException('恢复指纹只支持普通表');
            $oid = (int) $relation[0]['oid'];
            $columns = $this->rows("SELECT a.attname, a.atttypid::text, a.atttypmod, a.attnotnull, a.attidentity, a.attgenerated, pg_get_expr(d.adbin,d.adrelid) AS default_expression FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=" . $oid . ' AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum');
            $constraints = $this->rows('SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=' . $oid . ' ORDER BY conname');
            $indexes = $this->rows('SELECT pg_get_indexdef(indexrelid) AS definition FROM pg_index WHERE indrelid=' . $oid . ' ORDER BY indexrelid');
            $triggers = $this->rows('SELECT pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid=' . $oid . ' ORDER BY oid');
            $sequences = $this->rows('SELECT c.oid::text AS oid, n.nspname AS schema, c.relname AS name FROM pg_depend d JOIN pg_class c ON c.oid=d.objid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE d.refobjid=' . $oid . " AND c.relkind='S' AND d.deptype IN ('a','i') ORDER BY c.oid");
            foreach ($sequences as &$sequence) $sequence['state'] = $this->rows('SELECT last_value::text, is_called FROM ' . self::identifier($sequence['schema']) . '.' . self::identifier($sequence['name']));
            unset($sequence);
            $rows = $this->rows('SELECT to_jsonb(t)::text AS row_value FROM ' . self::qualified($name) . ' t');
            $hashes = array_map(static fn (array $row): string => hash('sha256', $row['row_value']), $rows);
            sort($hashes);
            $tables[$name] = ['oid' => $oid, 'kind' => 'r', 'definition' => self::hash([$columns, $constraints, $indexes, $triggers]), 'data' => self::hash([$hashes, $sequences])];
        }
        return ['identity' => self::hash($identity), 'relations' => $tables];
    }

    private function rows(string $sql): array
    {
        $result = $this->pdo->query($sql);
        if ($result === false) throw new ApiException('无法读取数据库恢复指纹');
        return $result->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function declaredTables(): array
    {
        $candidate = is_dir($this->candidate) ? $this->candidate : ($this->journal()['archive'] ?? $this->candidate);
        $install = (string) file_get_contents($candidate . '/install.sql');
        $uninstall = (string) file_get_contents($candidate . '/uninstall.sql');
        $tables = [];
        foreach (self::createdTables($install) as $name) {
            $short = substr($name, strpos($name, '.') + 1);
            if (preg_match('/\b' . preg_quote($short, '/') . '\b/i', $uninstall)) $tables[] = $name;
        }
        sort($tables);
        return $tables;
    }

    private static function createdTables(string $sql): array
    {
        // This identifies names for human review; it is not proof that CREATE ran.
        preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?((?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*)\b/i', $sql, $matches);
        return array_values(array_unique(array_map(static fn (string $name): string => str_contains($name, '.') ? strtolower($name) : 'public.' . strtolower($name), $matches[1])));
    }

    private function protectedTables(): array
    {
        $protected = [];
        $scripts = array_merge(glob(base_path() . '/plugin/sandadmin/db/*') ?: [], glob($this->root . '/*/install.sql') ?: []);
        foreach ($scripts as $script) {
            if ($script === $this->candidate . '/install.sql' || !is_file($script)) continue;
            self::safePath($script);
            $protected = array_merge($protected, self::createdTables((string) file_get_contents($script)));
        }
        return array_unique($protected);
    }

    private function validatePlan(array $plan, array $binding): void
    {
        if (($plan['decision'] ?? '') !== 'fresh_install_partial'
            || ($plan['app'] ?? '') !== $this->app
            || ($plan['candidate'] ?? '') !== $binding['candidate']
            || !is_string($plan['reason'] ?? null) || trim($plan['reason']) === ''
            || !is_array($plan['drop_tables'] ?? null) || $plan['drop_tables'] === []
            || !is_array($plan['ownership'] ?? null)) {
            throw new ApiException('人工计划必须明确新装部分提交、应用、候选摘要、依据和逐表归属证据');
        }
        $allowed = $this->declaredTables();
        $protected = $this->protectedTables();
        foreach ($plan['drop_tables'] as $table) {
            if (!is_string($table) || isset(($this->journal()['before_database']['relations'] ?? [])[$table])
                || !in_array($table, $allowed, true) || in_array($table, $protected, true)
                || preg_match('/\.(?:sand_system_|sand_tool_|sa_)/', $table)
                || ($binding['database']['relations'][$table]['kind'] ?? '') !== 'r'
                || ($plan['ownership'][$table]['owner_app'] ?? '') !== $this->app
                || !is_string($plan['ownership'][$table]['evidence'] ?? null) || trim($plan['ownership'][$table]['evidence']) === '') {
                throw new ApiException('清理表必须有候选声明和人工归属证据；核心或其他插件声明表不可清理');
            }
        }
        if (count(array_unique($plan['drop_tables'])) !== count($plan['drop_tables'])) throw new ApiException('清理表清单重复');
        // Cleanup means all failed candidate tables are removed. Shared rows are
        // intentionally excluded; a plan cannot claim ownership of other tables.
        $existing = array_keys($binding['database']['relations']);
        sort($existing);
        $requested = $plan['drop_tables'];
        sort($requested);
        if ($existing !== $requested) throw new ApiException('清理计划必须覆盖全部已存在候选普通表');
    }

    private function cleanupDatabase(array $plan, array $expected): void
    {
        if ($this->pdo->inTransaction()) throw new ApiException('恢复不能接管已有事务');
        $this->pdo->beginTransaction();
        $commitAttempted = false;
        try {
            if ($this->rows("SELECT evtname FROM pg_event_trigger WHERE evtenabled <> 'D'") !== []) throw new ApiException('存在启用的数据库事件触发器，不能证明清理范围');
            $locks = array_map(self::qualified(...), $plan['drop_tables']);
            $this->execute('LOCK TABLE ' . implode(', ', $locks) . ' IN ACCESS EXCLUSIVE MODE NOWAIT');
            if ($this->database() !== $expected) throw new ApiException('数据库指纹已变化；未执行清理');
            $this->execute('DROP TABLE ' . implode(', ', $locks) . ' RESTRICT');
            $after = $this->database();
            if ($after['identity'] !== $expected['identity'] || $after['relations'] !== []) throw new ApiException('清理后置状态不匹配');
            $record = $this->journalRequired();
            $record['cleanup_expected_database'] = $after;
            $record['cleanup_stage'] = 'commit_intent';
            $record['archive'] = $this->root . '/fresh-recovery/' . $this->app . '-' . $record['operation'];
            $this->save($record);
            $commitAttempted = true;
            if (!$this->pdo->commit()) throw new ApiException('清理提交结果未知');
            $record['cleanup_stage'] = 'db_committed';
            $this->save($record);
        } catch (Throwable $error) {
            if (!$commitAttempted && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function execute(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) throw new ApiException('恢复 SQL 执行失败');
    }

    private function archiveCandidate(): void
    {
        $record = $this->journalRequired();
        $destination = $record['archive'];
        self::safePath($destination);
        if (is_dir($destination) && !is_dir($this->candidate)) return;
        if (file_exists($destination) || !rename($this->candidate, $destination)) throw new ApiException('无法归档失败候选；恢复仍保持阻断');
    }

    private function journal(): ?array
    {
        self::safePath($this->journalPath);
        if (!is_file($this->journalPath)) return null;
        $record = json_decode((string) file_get_contents($this->journalPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['app'] ?? '') !== $this->app || ($record['format'] ?? 0) !== 1) throw new ApiException('新装恢复记录无效');
        return $record;
    }

    private function journalRequired(): array
    {
        return $this->journal() ?? throw new ApiException('新装恢复记录不存在');
    }

    private function save(array $record): void
    {
        self::safePath($this->journalPath);
        $dir = dirname($this->journalPath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new ApiException('无法创建恢复记录目录');
        $temporary = $this->journalPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new ApiException('无法保存恢复记录');
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new ApiException('恢复记录未持久化');
        } finally { fclose($handle); }
        if (!rename($temporary, $this->journalPath)) throw new ApiException('无法替换恢复记录');
    }

    public static function tree(string $directory, array $exclude = []): ?array
    {
        self::safePath($directory);
        if (!file_exists($directory)) return null;
        if (!is_dir($directory)) throw new ApiException('恢复目录类型不匹配');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isLink()) throw new ApiException('恢复目录不能包含符号链接');
            $name = substr($entry->getPathname(), strlen(rtrim($directory, '/')) + 1);
            if (in_array($name, $exclude, true)) continue;
            if (!$entry->isDir() && !$entry->isFile()) throw new ApiException('恢复目录包含特殊文件');
            $files[$name] = $entry->isDir() ? 'directory' : hash_file('sha256', $entry->getPathname());
        }
        ksort($files);
        return $files;
    }

    private static function safePath(string $path): void
    {
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new ApiException('恢复路径无效');
            $current .= '/' . $part;
            if (is_link($current)) throw new ApiException('恢复路径不能经过符号链接');
        }
    }

    private static function qualified(string $name): string
    {
        $parts = explode('.', $name);
        if (count($parts) !== 2) throw new ApiException('恢复表名必须包含 schema');
        return self::identifier($parts[0]) . '.' . self::identifier($parts[1]);
    }

    private static function identifier(string $name): string { return '"' . str_replace('"', '""', $name) . '"'; }
    private static function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
}
