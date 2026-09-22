<?php
declare(strict_types=1);

// Filesystem integration with the real upstream services; SQL uses a recording
// connection. This test never connects to a database or restarts a service.
namespace think\facade {
    final class Db
    {
        public static array $sql = [];
        public static bool $fail = false;
        public static function connect(string $name = ''): object
        {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected database connection');
            return new class {
                public function connect(): object { return $this; }
                public function inTransaction(): bool { return false; }
                public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
                public function query(string $sql): object {
                    return new class($sql) {
                        public function __construct(private string $sql) {}
                        public function fetchAll(int $mode): array {
                            return str_contains($this->sql, 'current_database()') ? [['database' => 'recording-fixture', 'oid' => '1', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']] : [];
                        }
                    };
                }
                public function exec(string $sql): int {
                    Db::$sql[] = trim($sql);
                    if (Db::$fail && str_contains($sql, 'BROKEN SQL')) throw new \RuntimeException('isolated simulated SQL error');
                    return 1;
                }
            };
        }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache { public static function clearMenuCache(): void {} }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use Saithink\Saipackage\service\Server;
    use think\facade\Db;

    $root = realpath(sys_get_temp_dir()) . '/sandpackage-upstream-' . bin2hex(random_bytes(6));
    mkdir($root . '/server/plugin', 0755, true);
    mkdir($root . '/sandadmin-artd', 0755, true);
    ini_set('error_log', $root . '/expected-errors.log');
    function base_path($path = ''): string { global $root; return $root . '/server' . ($path ? '/' . $path : ''); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path ? '/' . $path : ''); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo "[PASS] $message\n";
    }
    function rejected(callable $operation, string $message): void {
        try { $operation(); } catch (\plugin\sandadmin\exception\ApiException) { echo "[PASS] $message\n"; return; }
        throw new RuntimeException($message);
    }
    function package(string $app, string $version, array $config = [], array $extra = []): string {
        global $root;
        $path = $root . '/' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('info.ini', "app = $app\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = $version\nstate = 0\n");
        $zip->addFromString('config.json', json_encode($config, JSON_THROW_ON_ERROR));
        $zip->addFromString('install.sql', "CREATE TABLE neutral_sample (id bigint);\n");
        $zip->addFromString('update.sql', "ALTER TABLE neutral_sample ADD COLUMN label text;\n");
        $zip->addFromString('uninstall.sql', "DROP TABLE neutral_sample;\n");
        $zip->addFromString("plugin/$app/config/app.php", "<?php return ['version' => '$version'];\n");
        $zip->addFromString("sandadmin-artd/src/views/plugin/$app/index.vue", '<template><div>Neutral ' . $version . '</div></template>');
        foreach ($extra as $name => $body) $zip->addFromString($name, $body);
        $zip->close();
        return $path;
    }

    file_put_contents(base_path('composer.json'), '{"name":"test/host","require":{}}');
    file_put_contents($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
    $logic = new InstallLogic();
    $info = $logic->uploadFromPath(package('neutral-sample', '1.0.0'));
    check($info['state'] === 2 && $info['lifecycle_driver'] === 'saipackage-pg-v1', 'ordinary package needs no recovery descriptor');
    $logic = new InstallLogic('neutral-sample');
    $info = $logic->install(false);
    check($info['state'] === 1 && !isset($info['operation_pending']), 'install returns final state');
    check(Db::$sql === ['BEGIN', 'CREATE TABLE neutral_sample (id bigint)', 'COMMIT'], 'first installation selects install.sql once');
    check(is_file(base_path('plugin/neutral-sample/config/app.php')), 'upstream service deployed backend files');
    check(is_file($root . '/sandadmin-artd/src/views/plugin/neutral-sample/index.vue'), 'upstream service deployed frontend source');
    $logic->setInfo(['title' => 'Neutral; "quoted"']);
    check($logic->getInfo()['title'] === 'Neutral; "quoted"', 'INI metadata preserves punctuation without injecting fields');
    $journal = base_path('storage/sandpackage/locks/neutral-sample-candidate.transaction.json');
    file_put_contents($journal, '{}');
    rejected(fn() => $logic->uninstall(false), 'old candidate journal blocks destructive lifecycle');
    unlink($journal);

    $legacyInstalled = $logic->getInfo();
    unset($legacyInstalled['lifecycle_driver']);
    $legacyInstalled['package_backup_id'] = 'historical-completed-backup';
    $logic->setInfo([], $legacyInstalled);
    $before = $logic->getInfo();
    rejected(fn() => $logic->uploadFromPath(package('neutral-sample', '2.0.0', [], ['../escape' => 'bad'])), 'ZIP traversal rejected before replacing installed package');
    check($logic->getInfo() === $before, 'invalid upgrade preserves installed metadata');
    rejected(fn() => new InstallLogic('sandadmin'), 'host core name rejected');
    rejected(fn() => new InstallLogic('../outside'), 'app path traversal rejected');
    $linkPackage = package('neutral-link', '1.0.0', [], ['plugin/neutral-link/link' => '/outside']);
    $zip = new ZipArchive();
    $zip->open($linkPackage);
    $zip->setExternalAttributesName('plugin/neutral-link/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    rejected(fn() => (new InstallLogic())->uploadFromPath($linkPackage), 'ZIP symlink rejected before extraction');

    $upgrade = package('neutral-sample', '2.0.0');
    $zip = new ZipArchive();
    $zip->open($upgrade);
    $zip->deleteName('sandadmin-artd/src/views/plugin/neutral-sample/index.vue');
    $zip->close();
    $logic->uploadFromPath($upgrade);
    check($logic->getInfo()['lifecycle_driver'] === 'saipackage-pg-v1', 'verified old installed state can stage an ordinary upgrade');
    Db::$sql = [];
    rejected(fn() => $logic->install(false, 'wrong'), 'upgrade confirmation mismatch executes no SQL');
    check(Db::$sql === [], 'confirmation rejection is side-effect free for SQL');
    $info = $logic->install(false, 'UPGRADE neutral-sample@1.0.0->2.0.0');
    check(Db::$sql === ['BEGIN', 'ALTER TABLE neutral_sample ADD COLUMN label text', 'COMMIT'], 'upgrade executes update.sql without repeating install.sql');
    check($info['state'] === 1 && !isset($info['update']), 'upgrade returns completed metadata');

    foreach ([6, 7, 8, 99] as $state) {
        $logic->setInfo(['state' => $state]);
        Db::$sql = [];
        rejected(fn() => $logic->install(false), "state $state blocks installation");
        rejected(fn() => $logic->uninstall(false), "state $state blocks deletion");
        check(Db::$sql === [] && is_dir(base_path('plugin/neutral-sample')), "state $state preserved SQL and files");
    }
    $logic->setInfo(['state' => 1]);
    Db::$sql = [];
    $logic->uninstall(false);
    check(Db::$sql === ['BEGIN', 'DROP TABLE neutral_sample', 'COMMIT'], 'uninstall selects uninstall.sql');
    check(!is_dir(base_path('plugin/neutral-sample')), 'uninstall removes only plugin deployment');
    check(!is_dir($root . '/sandadmin-artd/src/views/plugin/neutral-sample'), 'uninstall removes old frontend payload omitted by newer package');
    check(is_file(base_path('composer.json')), 'host manifest remains');

    $logic = new InstallLogic();
    $logic->uploadFromPath(package('neutral-error', '1.0.0', [], ['install.sql' => 'BROKEN SQL;']));
    $logic = new InstallLogic('neutral-error');
    Db::$sql = [];
    Db::$fail = true;
    rejected(fn() => $logic->install(false), 'SQL failure is reported');
    Db::$fail = false;
    check(end(Db::$sql) === 'ROLLBACK' && $logic->getInfo()['state'] === 8, 'SQL failure rolls back recording connection and cannot report installed');
    check(!is_dir(base_path('plugin/neutral-error')), 'SQL failure stops file deployment');
    rejected(fn() => $logic->install(false), 'failed operation cannot rerun SQL');
    (new InstallLogic())->uploadFromPath(package('neutral-copy', '1.0.0'));
    file_put_contents(base_path('plugin/neutral-copy'), 'occupied target');
    $copy = new InstallLogic('neutral-copy');
    rejected(fn() => $copy->install(false), 'upstream copy failure cannot report installation success');
    check($copy->getInfo()['state'] === 2 && empty($copy->getInfo()['operation_pending']), 'occupied deployment fails before SQL and preserves waiting candidate');

    echo "Lifecycle fixture retained at $root\n";
    $root .= '-dependencies';
    mkdir($root . '/server/plugin', 0755, true);
    mkdir($root . '/sandadmin-artd', 0755, true);
    file_put_contents(base_path('composer.json'), '{"name":"test/host","require":{}}');
    file_put_contents($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
    $logic = new InstallLogic();
    $logic->uploadFromPath(package('neutral-dep', '1.0.0', ['dependencies' => ['example-fixture' => '1.0.0']]));
    $logic = new InstallLogic('neutral-dep');
    check($logic->install(false)['state'] === 4, 'upstream dependency flags remain pending');
    $nonce = $logic->beginDependencyCommand('npm');
    $logic->acquireDependencyExecutionLock('npm', $nonce);
    rejected(fn() => $logic->dependentInstallComplete('npm', 'wrong'), 'foreign callback cannot complete dependency');
    check($logic->dependencyCommandFailed('npm', $nonce), 'failed command leaves dependency pending');
    $logic->releaseDependencyCommand();
    check($logic->getInfo()['state'] === 4, 'dependency failure does not report installed');
    $nonce = $logic->beginDependencyCommand('npm');
    $result = $logic->dependentInstallComplete('npm', $nonce);
    $logic->releaseDependencyCommand();
    check($result === ['advanced' => true, 'completed' => true] && $logic->getInfo()['state'] === 1, 'owned success callback completes upstream dependency flags');
    echo "Filesystem fixture retained at $root\n";
}
