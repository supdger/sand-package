<?php

declare(strict_types=1);

// Opt-in only. Creates ONE explicitly authorized schema in an existing database.
// Does not bootstrap Webman, load host credentials, start/reload services or touch plugins.
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
    use plugin\sandadmin\app\cache\UserMenuCache;
    use think\facade\Db;

    const SCHEMA = 'host003_recovery_probe_20260917';
    if (($argv[1] ?? '') !== '--authorize-schema=' . SCHEMA) {
        fwrite(STDERR, "Not run. Requires explicit task approval and --authorize-schema=" . SCHEMA . "\n");
        exit(2);
    }
    $dsn = getenv('HOST003_TEST_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) throw new RuntimeException('Explicit existing PostgreSQL test DSN required');
    $pdo = new PDO($dsn, getenv('HOST003_TEST_USER') ?: null, getenv('HOST003_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET lock_timeout='2s'");
    $pdo->exec("SET statement_timeout='30s'");
    $expectedDatabase = getenv('HOST003_EXPECTED_DATABASE');
    if (!is_string($expectedDatabase) || $expectedDatabase === '' || $pdo->query('SELECT current_database()')->fetchColumn() !== $expectedDatabase) {
        throw new RuntimeException('Explicit expected database name is missing or does not match');
    }
    $created = false;
    $root = realpath(sys_get_temp_dir()) . '/sandpackage-real-fresh-' . bin2hex(random_bytes(6));
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path !== '' ? '/' . $path : ''); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path !== '' ? '/' . $path : ''); }
    function env(string $name, mixed $default = null): mixed { return $default; }
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
    final class CommitFault {
        public bool $loseCommit = false;
        public int $creates = 0;
        public function __construct(private PDO $pdo) {}
        public function query(string $sql): PDOStatement|false { return $this->pdo->query($sql); }
        public function quote(string $value): string|false { return $this->pdo->quote($value); }
        public function inTransaction(): bool { return $this->pdo->inTransaction(); }
        public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
        public function rollBack(): bool { return $this->pdo->rollBack(); }
        public function commit(): bool {
            $result = $this->pdo->commit();
            if ($this->loseCommit) { $this->loseCommit = false; throw new RuntimeException('injected commit acknowledgement loss'); }
            return $result;
        }
        public function exec(string $sql): int|false {
            if (str_starts_with(trim($sql), 'CREATE TABLE')) $this->creates++;
            $result = $this->pdo->exec($sql);
            if (trim($sql) === 'COMMIT' && $this->loseCommit) { $this->loseCommit = false; throw new RuntimeException('injected commit acknowledgement loss'); }
            return $result;
        }
    }
    $connection = new CommitFault($pdo);
    Db::$pdo = $connection;
    function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "[PASS] $message\n"; }
    function fails(callable $call): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected injected failure was not observed'); }
    function packageFixture(string $sql): InstallLogic {
        global $root;
        $root .= '-n';
        mkdir(base_path('plugin'), 0700, true);
        mkdir(dirname(base_path()) . '/sandadmin-artd', 0700, true);
        file_put_contents(base_path('composer.json'), '{"require":{}}');
        file_put_contents(dirname(base_path()) . '/sandadmin-artd/package.json', '{"dependencies":{}}');
        ini_set('error_log', $root . '/expected-errors.log');
        $path = $root . '/fixture.zip'; $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
        foreach (['info.ini' => "app=host-probe\ntitle=Probe\nabout=Authorized isolated probe\nauthor=Test\nversion=1.0.0\n", 'config.json'=>'{}',
            'install.sql'=>$sql, 'update.sql'=>'', 'uninstall.sql'=>'DROP TABLE ' . SCHEMA . '.probe_one, ' . SCHEMA . '.probe_two RESTRICT;',
            'plugin/host-probe/config/app.php'=>"<?php return ['version'=>'1.0.0'];"] as $name=>$body) $zip->addFromString($name,$body);
        $zip->close(); (new InstallLogic())->uploadFromPath($path); return new InstallLogic('host-probe');
    }
    function clean(InstallLogic $logic): void {
        $inspect = $logic->inspectFreshInstallRecovery();
        $plan = null;
        if ($inspect['phase'] === 'sql_commit_unknown') {
            $tables = array_keys($inspect['binding']['database']['relations']);
            $plan = ['decision'=>'fresh_install_partial', 'app'=>'host-probe', 'candidate'=>$inspect['binding']['candidate'],
                'reason'=>'This script created the explicitly authorized isolated schema and failed candidate tables.', 'drop_tables'=>$tables,
                'ownership'=>array_fill_keys($tables,['owner_app'=>'host-probe','evidence'=>'Exact schema authorization and CREATE statements in this isolated fixture'])];
            $inspect = $logic->inspectFreshInstallRecovery($plan);
        }
        $logic->recoverFreshInstall($inspect['actions'][0],$inspect['confirmation'],$plan);
    }
    try {
        // No IF NOT EXISTS: a pre-existing schema is never reused or deleted.
        $pdo->exec('CREATE SCHEMA ' . SCHEMA);
        $created = true;
        $logic = packageFixture("SELECT 'unterminated;"); fails(fn()=>$logic->install(false));
        check($logic->inspectFreshInstallRecovery()['phase']==='sql_not_committed','before-SQL failure'); clean($logic);
        $logic = packageFixture('CREATE TABLE ' . SCHEMA . '.probe_one(id bigint); SELECT 1/0;'); fails(fn()=>$logic->install(false));
        check($logic->inspectFreshInstallRecovery()['phase']==='sql_not_committed','real transaction rollback'); clean($logic);
        $logic = packageFixture('BEGIN; CREATE TABLE ' . SCHEMA . '.probe_one(id bigint); COMMIT; BEGIN; SELECT 1/0; COMMIT;'); fails(fn()=>$logic->install(false));
        check($logic->inspectFreshInstallRecovery()['phase']==='sql_commit_unknown','real partial commit'); clean($logic);
        $logic = packageFixture('CREATE TABLE ' . SCHEMA . '.probe_one(id bigint);'); $connection->loseCommit=true; fails(fn()=>$logic->install(false));
        check($logic->inspectFreshInstallRecovery()['phase']==='sql_commit_unknown','real COMMIT plus injected lost response'); clean($logic);
        $logic = packageFixture('CREATE TABLE ' . SCHEMA . '.probe_one(id bigint);'); UserMenuCache::$fail=true; fails(fn()=>$logic->install(false));
        $inspect=$logic->inspectFreshInstallRecovery(); $creates=$connection->creates;
        check($inspect['phase']==='sql_committed_deploy_pending','real SQL committed before injected deployment failure');
        fails(fn()=>$logic->recoverFreshInstall('continue-fresh',$inspect['confirmation']));
        UserMenuCache::$fail=false; $inspect=$logic->inspectFreshInstallRecovery(); $logic->recoverFreshInstall('continue-fresh',$inspect['confirmation']);
        check($connection->creates===$creates,'repeated deployment recovery never repeats SQL');
        check($logic->recoverFreshInstall('continue-fresh',$inspect['confirmation'])['repeated'],'successful recovery repeat is idempotent');
        echo "Real PostgreSQL state checks passed; actual service reload and frozen consumer remain untested.\n";
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($created) {
            $pdo->exec('DROP TABLE IF EXISTS ' . SCHEMA . '.probe_one, ' . SCHEMA . '.probe_two RESTRICT');
            $pdo->exec('DROP SCHEMA ' . SCHEMA . ' RESTRICT');
        }
    }
}
