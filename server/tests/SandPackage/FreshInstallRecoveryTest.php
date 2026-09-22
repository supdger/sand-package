<?php

declare(strict_types=1);

namespace think\facade {
    final class Db {
        public static object $pdo;
        public static function connect(string $name): object { return new class { public function connect(): object { return Db::$pdo; } }; }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache {
        public static bool $fail = false;
        public static function clearMenuCache(): void { if (self::$fail) throw new \RuntimeException('injected post-copy failure'); }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\FreshInstallRecovery;
    use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;
    use plugin\sandadmin\app\cache\UserMenuCache;
    use think\facade\Db;

    final class FaultPdo {
        public array $tables = [];
        public array $executed = [];
        public string $identity = 'isolated-recording-pdo';
        public bool $loseCommit = false;
        public bool $eventTrigger = false;
        private ?array $before = null;
        public function inTransaction(): bool { return $this->before !== null; }
        public function beginTransaction(): bool { $this->exec('BEGIN'); return true; }
        public function commit(): bool { $this->exec('COMMIT'); return true; }
        public function rollBack(): bool { $this->exec('ROLLBACK'); return true; }
        public function quote(string $text): string { return "'" . str_replace("'", "''", $text) . "'"; }
        public function exec(string $sql): int {
            $sql = trim($sql);
            $this->executed[] = $sql;
            if ($sql === 'BEGIN') { $this->before = $this->tables; return 0; }
            if ($sql === 'ROLLBACK') { if ($this->before !== null) $this->tables = $this->before; $this->before = null; return 0; }
            if ($sql === 'COMMIT') {
                $this->before = null;
                if ($this->loseCommit) { $this->loseCommit = false; throw new RuntimeException('COMMIT acknowledgement lost after actual commit'); }
                return 0;
            }
            if (str_contains($sql, 'BROKEN')) throw new RuntimeException('injected statement failure');
            if (preg_match('/CREATE TABLE (\w+)/', $sql, $m)) $this->tables['public.' . $m[1]] = ['oid' => (string) (100 + count($this->tables)), 'kind' => 'r', 'rows' => ['{"id":1}']];
            if (str_starts_with($sql, 'DROP TABLE')) {
                if (!str_ends_with($sql, ' RESTRICT')) throw new RuntimeException('unsafe cleanup');
                preg_match_all('/"([^"]+)"\."([^"]+)"/', $sql, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) unset($this->tables[$match[1] . '.' . $match[2]]);
            }
            return 1;
        }
        public function query(string $sql): object {
            $rows = [];
            if (str_contains($sql, 'current_database()')) $rows = [['database' => $this->identity, 'oid' => '7', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']];
            elseif (str_contains($sql, 'pg_event_trigger')) $rows = $this->eventTrigger ? [['evtname' => 'untrusted_event']] : [];
            elseif (str_contains($sql, 'c.relname=') && preg_match("/n.nspname='([^']+)' AND c.relname='([^']+)'/", $sql, $m)) {
                $table = $this->tables[$m[1] . '.' . $m[2]] ?? null;
                if ($table !== null) $rows = [['oid' => $table['oid'], 'kind' => $table['kind']]];
            } elseif (str_starts_with($sql, 'SELECT to_jsonb') && preg_match('/FROM "([^"]+)"\."([^"]+)"/', $sql, $m)) {
                $rows = array_map(static fn (string $row): array => ['row_value' => $row], $this->tables[$m[1] . '.' . $m[2]]['rows']);
            } elseif (str_contains($sql, 'FROM pg_attribute')) $rows = [['attname' => 'id', 'atttypid' => '20']];
            return new class($rows) { public function __construct(private array $rows) {} public function fetchAll(int $mode): array { return $this->rows; } };
        }
    }

    $root = realpath(sys_get_temp_dir()) . '/sandpackage-fresh-' . bin2hex(random_bytes(6));
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path !== '' ? '/' . $path : ''); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path !== '' ? '/' . $path : ''); }
    function env(string $name, mixed $default = null): mixed { return $default; }
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';

    function expect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "[PASS] $message\n"; }
    function rejects(callable $call, string $message): void { try { $call(); } catch (Throwable $e) { echo "[PASS] $message\n"; return; } throw new RuntimeException($message); }
    function fixture(string $sql, string $uninstall = 'DROP TABLE probe_one, probe_two;'): InstallLogic {
        global $root;
        $root .= '-n';
        mkdir(base_path('plugin'), 0700, true);
        mkdir(dirname(base_path()) . '/sandadmin-artd', 0700, true);
        file_put_contents(base_path('composer.json'), '{"require":{}}');
        file_put_contents(dirname(base_path()) . '/sandadmin-artd/package.json', '{"dependencies":{}}');
        ini_set('error_log', $root . '/expected-errors.log');
        Db::$pdo = new FaultPdo();
        UserMenuCache::$fail = false;
        $zip = new ZipArchive();
        $path = $root . '/candidate.zip';
        $zip->open($path, ZipArchive::CREATE);
        foreach (['info.ini' => "app=probe-package\ntitle=Probe\nabout=Recovery fixture\nauthor=Test\nversion=1.0.0\n",
            'config.json' => '{}', 'install.sql' => $sql, 'update.sql' => '', 'uninstall.sql' => $uninstall,
            'plugin/probe-package/config/app.php' => "<?php return ['version'=>'1.0.0'];"] as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
        (new InstallLogic())->uploadFromPath($path);
        return new InstallLogic('probe-package');
    }
    function plan(InstallLogic $logic): array {
        $inspection = $logic->inspectFreshInstallRecovery();
        $tables = array_keys($inspection['binding']['database']['relations']);
        return ['decision' => 'fresh_install_partial', 'app' => 'probe-package', 'candidate' => $inspection['binding']['candidate'],
            'reason' => 'Fixture operator verified both tables were created only by the failed fresh install.', 'drop_tables' => $tables,
            'ownership' => array_fill_keys($tables, ['owner_app' => 'probe-package', 'evidence' => 'Isolated fixture setup and failed candidate SQL'])];
    }
    function service(InstallLogic $logic): FreshInstallRecovery {
        return new FreshInstallRecovery('probe-package', base_path('storage/sandpackage/probe-package'), $logic->getAllowedPath(), base_path('storage/sandpackage'), Db::$pdo, $logic->getInfo(...));
    }

    $logic = fixture("SELECT 'unterminated;");
    rejects(fn () => $logic->install(false), 'pre-SQL parse failure is blocked');
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_not_committed' && Db::$pdo->executed === [], 'pre-SQL failure is inspectable without executed SQL');
    $logic->recoverFreshInstall('cleanup-fresh', $inspect['confirmation']);
    expect($logic->getInfo() === [], 'not-committed cleanup archives registry and permits a later upload');
    expect($logic->recoverFreshInstall('cleanup-fresh', $inspect['confirmation'])['repeated'], 'same successful cleanup is idempotent');

    (new InstallLogic())->uploadFromPath($root . '/candidate.zip');
    rejects(fn () => (new InstallLogic('probe-package'))->install(false), 'new attempt keeps prior cleanup audit');
    expect(count(glob(base_path('storage/sandpackage/fresh-recovery/*.history'))) === 1, 'completed operation audit survives the next install');

    $logic = fixture('CREATE TABLE probe_one (id bigint); BROKEN;');
    rejects(fn () => $logic->install(false), 'implicit transaction failure propagates');
    expect(Db::$pdo->tables === [] && $logic->inspectFreshInstallRecovery()['phase'] === 'sql_not_committed', 'confirmed rollback before any commit permits deterministic cleanup');

    $logic = fixture('CREATE TABLE probe_one (id bigint);');
    service($logic)->begin(); // Process dies between durable begin and registry pending.
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_not_committed', 'durable begin without registry pending remains recoverable');
    $logic->recoverFreshInstall('cleanup-fresh', $inspect['confirmation']);

    $logic = fixture('CREATE TABLE probe_one (id bigint);');
    service($logic)->begin();
    $logic->setInfo(['operation_pending' => 1]); // Process dies before first SQL observer.
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_not_committed', 'pending registry persisted before first SQL remains safely recoverable');
    $logic->recoverFreshInstall('cleanup-fresh', $inspect['confirmation']);

    $logic = fixture('BEGIN; CREATE TABLE probe_one (id bigint); COMMIT; BEGIN; BROKEN; COMMIT;');
    rejects(fn () => $logic->install(false), 'partial explicit commit fails closed');
    expect(isset(Db::$pdo->tables['public.probe_one']) && $logic->inspectFreshInstallRecovery()['phase'] === 'sql_commit_unknown', 'earlier commit survives and is never classified as rolled back');
    $manual = plan($logic);
    $inspect = $logic->inspectFreshInstallRecovery($manual);
    rejects(fn () => $logic->recoverFreshInstall('manual-cleanup-fresh', 'stale', $manual), 'manual cleanup rejects stale confirmation');
    Db::$pdo->eventTrigger = true;
    rejects(fn () => $logic->recoverFreshInstall('manual-cleanup-fresh', $inspect['confirmation'], $manual), 'enabled event trigger prevents unbounded DROP effects');
    Db::$pdo->eventTrigger = false;
    $inspect = $logic->inspectFreshInstallRecovery($manual);
    $result = $logic->recoverFreshInstall('manual-cleanup-fresh', $inspect['confirmation'], $manual);
    expect($result['phase'] === 'cleaned' && Db::$pdo->tables === [], 'operator-confirmed partial install cleans only exact candidate tables');
    expect(count(array_filter(Db::$pdo->executed, static fn (string $sql): bool => str_starts_with($sql, 'DROP TABLE'))) === 1, 'cleanup uses one bounded DROP statement');

    $logic = fixture('CREATE TABLE probe_one (id bigint);');
    Db::$pdo->loseCommit = true;
    rejects(fn () => $logic->install(false), 'install COMMIT confirmation loss is surfaced');
    expect($logic->inspectFreshInstallRecovery()['phase'] === 'sql_commit_unknown' && isset(Db::$pdo->tables['public.probe_one']), 'ROLLBACK after lost COMMIT cannot claim not committed');
    unlink(base_path('storage/sandpackage/fresh-recovery/probe-package.json')); // Pre-journal frozen host record.
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_commit_unknown', 'old driver failure without new evidence is unknown');
    $manual = plan($logic);
    $inspect = $logic->inspectFreshInstallRecovery($manual);
    Db::$pdo->loseCommit = true;
    rejects(fn () => $logic->recoverFreshInstall('manual-cleanup-fresh', $inspect['confirmation'], $manual), 'cleanup COMMIT confirmation loss does not archive candidate');
    expect(is_dir(base_path('storage/sandpackage/probe-package')), 'unknown cleanup commit keeps original registry');
    $finish = $logic->inspectFreshInstallRecovery();
    expect($finish['actions'] === ['finish-cleanup-fresh'], 'exact cleanup postcondition gives explicit finish confirmation');
    $count = count(Db::$pdo->executed);
    $logic->recoverFreshInstall('finish-cleanup-fresh', $finish['confirmation']);
    expect(count(Db::$pdo->executed) === $count, 'finish after confirmed postcondition never reruns cleanup SQL');

    $logic = fixture('CREATE TABLE probe_one (id bigint); BROKEN;');
    rejects(fn () => $logic->install(false), 'prepare archive interruption');
    $inspect = $logic->inspectFreshInstallRecovery();
    $journal = base_path('storage/sandpackage/fresh-recovery/probe-package.json');
    $record = json_decode(file_get_contents($journal), true);
    $archive = base_path('storage/sandpackage/fresh-recovery/probe-package-' . $record['operation']);
    file_put_contents($archive, 'injected archive conflict');
    rejects(fn () => $logic->recoverFreshInstall('cleanup-fresh', $inspect['confirmation']), 'archive failure remains resumable');
    unlink($archive);
    $finish = $logic->inspectFreshInstallRecovery();
    expect($finish['actions'] === ['finish-cleanup-fresh'], 'archive interruption offers an explicit finish action');
    // Simulate archive rename succeeded but final journal write was interrupted.
    rename(base_path('storage/sandpackage/probe-package'), $archive);
    $finish = $logic->inspectFreshInstallRecovery();
    $logic->recoverFreshInstall('finish-cleanup-fresh', $finish['confirmation']);
    expect($logic->getInfo() === [], 'already archived candidate can finish after a process interruption');

    $logic = fixture('BEGIN; CREATE TABLE probe_one (id bigint); COMMIT; BEGIN; BROKEN; COMMIT;');
    rejects(fn () => $logic->install(false), 'prepare cleanup pre-COMMIT interruption');
    $manual = plan($logic); $inspect = $logic->inspectFreshInstallRecovery($manual);
    $journal = base_path('storage/sandpackage/fresh-recovery/probe-package.json');
    $record = json_decode(file_get_contents($journal), true);
    $record['phase'] = 'sql_commit_unknown'; $record['cleanup_stage'] = 'commit_intent';
    $record['cleanup_expected_database'] = ['identity' => $inspect['binding']['database']['identity'], 'relations' => []];
    $record['archive'] = base_path('storage/sandpackage/fresh-recovery/probe-package-' . $record['operation']);
    file_put_contents($journal, json_encode($record)); // Transaction was rolled back on process death.
    $inspect = $logic->inspectFreshInstallRecovery($manual);
    expect($inspect['actions'] === ['manual-cleanup-fresh'], 'exact original DB after cleanup interruption permits a new explicit cleanup');
    $logic->recoverFreshInstall('manual-cleanup-fresh', $inspect['confirmation'], $manual);

    $logic = fixture('BEGIN; CREATE TABLE probe_one (id bigint); COMMIT; BEGIN; BROKEN; COMMIT;');
    rejects(fn () => $logic->install(false), 'prepare ownership and database drift checks');
    $manual = plan($logic);
    mkdir(base_path('storage/sandpackage/other-plugin'), 0700, true);
    file_put_contents(base_path('storage/sandpackage/other-plugin/install.sql'), 'CREATE TABLE probe_one (id bigint);');
    rejects(fn () => $logic->inspectFreshInstallRecovery($manual), 'another registered candidate declaration prevents cross-plugin cleanup');
    unlink(base_path('storage/sandpackage/other-plugin/install.sql'));
    $bad = $manual; $bad['ownership']['public.probe_one']['owner_app'] = 'other-plugin';
    rejects(fn () => $logic->inspectFreshInstallRecovery($bad), 'foreign owner cannot authorize candidate cleanup');
    $inspect = $logic->inspectFreshInstallRecovery($manual);
    Db::$pdo->tables['public.probe_one']['rows'][] = '{"id":2}';
    rejects(fn () => $logic->recoverFreshInstall('manual-cleanup-fresh', $inspect['confirmation'], $manual), 'candidate table data drift invalidates a confirmed plan');
    Db::$pdo->identity = 'different-database';
    rejects(fn () => $logic->inspectFreshInstallRecovery(), 'database identity drift cannot acquire replacement evidence');

    $logic = fixture('CREATE TABLE probe_one (id bigint);');
    UserMenuCache::$fail = true;
    rejects(fn () => $logic->install(false), 'post-copy failure is surfaced');
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_committed_deploy_pending', 'SQL success plus later failure records committed deployment pending');
    $count = count(Db::$pdo->executed);
    rejects(fn () => $logic->recoverFreshInstall('continue-fresh', $inspect['confirmation']), 'recovery deployment may fail again');
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_committed_deploy_pending', 'second deployment failure retains proven commit state');
    UserMenuCache::$fail = false;
    $logic->recoverFreshInstall('continue-fresh', $inspect['confirmation']);
    expect(count(Db::$pdo->executed) === $count && $logic->getInfo()['state'] === 1, 'deployment continuation completes without replaying install SQL');

    $logic = fixture('CREATE TABLE probe_one (id bigint);');
    UserMenuCache::$fail = true;
    rejects(fn () => $logic->install(false), 'prepare completion crash window');
    // Reproduce the successful deployment checkpoint immediately before the
    // process dies between clearing pending and writing complete.
    $logic->setInfo(['state' => 1]);
    service($logic)->checkpoint();
    $info = $logic->getInfo(); unset($info['operation_pending']); $logic->setInfo([], $info);
    $inspect = $logic->inspectFreshInstallRecovery();
    expect($inspect['phase'] === 'sql_committed_deploy_pending', 'successful deployment with cleared pending can finish its journal');
    UserMenuCache::$fail = false;
    $logic->recoverFreshInstall('continue-fresh', $inspect['confirmation']);

    $logic = fixture('CREATE TABLE probe_one (id bigint);');
    UserMenuCache::$fail = true;
    rejects(fn () => $logic->install(false), 'prepare drift case');
    $inspect = $logic->inspectFreshInstallRecovery();
    file_put_contents(base_path('storage/sandpackage/probe-package/install.sql'), 'CREATE TABLE altered (id bigint);');
    rejects(fn () => $logic->recoverFreshInstall('continue-fresh', $inspect['confirmation']), 'candidate SQL drift cannot be blessed by a new inspection');

    $logic = fixture('CREATE TABLE probe_one (id bigint); BROKEN;');
    rejects(fn () => $logic->install(false), 'prepare concurrent recovery case');
    $lock = fopen(base_path('storage/sandpackage/locks/upstream-host.lock'), 'c+');
    flock($lock, LOCK_EX | LOCK_NB);
    rejects(fn () => $logic->inspectFreshInstallRecovery(), 'second recovery cannot bypass the live host operation lock');
    flock($lock, LOCK_UN); fclose($lock);
    $appLock = fopen(base_path('storage/sandpackage/locks/probe-package-operation.lock'), 'c+');
    flock($appLock, LOCK_EX | LOCK_NB);
    rejects(fn () => $logic->inspectFreshInstallRecovery(), 'second recovery cannot bypass the application lock');
    flock($appLock, LOCK_UN); fclose($appLock);
    $inspect = $logic->inspectFreshInstallRecovery();
    $info = $logic->getInfo(); $info['title'] = 'drift'; $logic->setInfo([], $info);
    rejects(fn () => $logic->recoverFreshInstall('cleanup-fresh', $inspect['confirmation']), 'registry drift invalidates a previously confirmed action');

    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/command/Recover.php';
    $logic = fixture('BEGIN; CREATE TABLE probe_one (id bigint); COMMIT; BEGIN; BROKEN; COMMIT;');
    rejects(fn () => $logic->install(false), 'prepare original official CLI reproduction');
    unlink(base_path('storage/sandpackage/fresh-recovery/probe-package.json'));
    $command = new \plugin\sandpackage\app\command\Recover();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
    $status = $tester->execute(['action' => 'inspect', 'app' => 'probe-package']);
    $cli = json_decode(trim($tester->getDisplay()), true);
    expect($status === 0 && $cli['phase'] === 'sql_commit_unknown', 'official recover inspect routes old PostgreSQL failure to new inspection');
    $manual = plan($logic);
    $planFile = $root . '/reviewed-plan.json'; file_put_contents($planFile, json_encode($manual));
    $status = $tester->execute(['action' => 'inspect', 'app' => 'probe-package', '--plan' => $planFile]);
    $cli = json_decode(trim($tester->getDisplay()), true);
    expect($status === 0 && str_starts_with($cli['confirmation'], 'MANUAL-CLEANUP-FRESH'), 'official CLI binds the reviewed plan to its confirmation');
    $status = $tester->execute(['action' => 'manual-cleanup-fresh', 'app' => 'probe-package', '--plan' => $planFile, '--confirmation' => $cli['confirmation']]);
    $cli = json_decode(trim($tester->getDisplay()), true);
    expect($status === 0 && $cli['phase'] === 'cleaned', 'official CLI performs confirmed cleanup without the legacy recovery path');

    echo "Fresh install fault-injection fixtures retained at $root\n";
}
