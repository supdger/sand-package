<?php

declare(strict_types=1);

// Real ZIP staging, Webman config loading and package-local autoloading.
// PostgreSQL is a recording connection; no database or host service is used.
namespace think\facade {
    final class Db {
        public static array $sql = [];
        public static function connect(string $name): object {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected database connection');
            return new class {
                private bool $transaction = false;
                public function connect(): object { return $this; }
                public function inTransaction(): bool { return $this->transaction; }
                public function beginTransaction(): bool { $this->transaction = true; return true; }
                public function commit(): bool { $this->transaction = false; return true; }
                public function rollBack(): bool { $this->transaction = false; return true; }
                public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
                public function exec(string $sql): int { Db::$sql[] = trim($sql); return 1; }
                public function query(string $sql): object {
                    $rows = [];
                    if (str_contains($sql, 'SELECT EXISTS(')) $rows = [['present' => false]];
                    elseif (str_contains($sql, 'current_database()')) {
                        $rows = [['database' => 'recording-dependency', 'oid' => '1', 'username' => 'fixture',
                            'address' => null, 'port' => null, 'started' => 'fixed']];
                    }
                    return new class($rows) {
                        public function __construct(private array $rows) {}
                        public function fetchAll(int $mode): array { return $this->rows; }
                    };
                }
            };
        }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache {
        public static bool $failOnce = false;
        public static function clearMenuCache(): void {
            if (self::$failOnce) {
                self::$failOnce = false;
                throw new \RuntimeException('injected deployment failure');
            }
        }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use Saithink\Saipackage\service\Filesystem;
    use think\facade\Db;
    use Webman\Config;

    $scenarios = ['automatic', 'existing', 'activation-failure', 'loader-failure', 'external-file',
        'symlink-file', 'symlink-config', 'disabled-catalog', 'incompatible-dependency', 'continue-fresh'];
    if ($argc === 1) {
        foreach ($scenarios as $scenario) {
            echo "[SCENARIO] $scenario\n";
            $process = proc_open([PHP_BINARY, __FILE__, $scenario], [STDIN, STDOUT, STDERR], $pipes);
            if (!is_resource($process) || proc_close($process) !== 0) exit(1);
        }
        echo "[PASS] all dependency activation scenarios\n";
        exit(0);
    }
    $scenario = $argv[1];
    if (!in_array($scenario, $scenarios, true)) throw new RuntimeException('Unknown scenario');
    $root = realpath(sys_get_temp_dir()) . '/sandpackage-activation-' . bin2hex(random_bytes(8));
    function base_path(string|bool $path = ''): string {
        global $root;
        return $root . '/server' . (is_string($path) && $path !== '' ? '/' . $path : '');
    }
    function runtime_path(string $path = ''): string {
        global $root;
        return $root . '/runtime' . ($path !== '' ? '/' . $path : '');
    }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(?string $key = null, mixed $default = null): mixed {
        return $key === 'plugin.sandadmin.app.version' ? '0.2.5' : Config::get($key, $default);
    }
    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    $source = dirname(__DIR__, 2);
    spl_autoload_register(static function (string $class) use ($source): void {
        foreach (['plugin\\sandpackage\\' => '/plugin/sandpackage/', 'Saithink\\Saipackage\\' => '/compat/Saithink/Saipackage/'] as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $file = $source . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) require_once $file;
            }
        }
    }, true, true);
    function writeActivation(string $file, string $content): void {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
        file_put_contents($file, $content);
    }
    function expectActivation(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo "[PASS] $message\n";
    }
    function zipActivation(string $path, string $app, array $files, array $config = []): void {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) throw new RuntimeException('ZIP creation failed');
        $common = [
            'info.ini' => "app=$app\ntitle=Fixture\nabout=Dependency activation\nauthor=Test\nversion=1.0.0\nsupport=\">=0.1.0\"\n",
            'config.json' => json_encode($config, JSON_THROW_ON_ERROR),
            'install.sql' => "SELECT '$app-install';", 'update.sql' => '', 'uninstall.sql' => '',
            "plugin/$app/config/app.php" => '<?php return ["version"=>"1.0.0"];',
        ];
        foreach (array_merge($common, $files) as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
    }
    function rejectsActivation(callable $operation, string $needle): void {
        try { $operation(); } catch (\plugin\sandadmin\exception\ApiException $error) {
            expectActivation($error->getCode() === 400 && str_contains($error->getMessage(), $needle), "failure reports $needle");
            return;
        }
        throw new RuntimeException('Expected activation rejection');
    }
    function targetSqlActivation(): array {
        return array_values(array_filter(Db::$sql, static fn (string $sql): bool => str_contains($sql, 'neutral-target-install')));
    }
    mkdir(base_path('plugin'), 0700, true);
    mkdir($root . '/sandadmin-artd', 0700, true);
    writeActivation(base_path('composer.json'), '{"require":{}}');
    writeActivation($root . '/sandadmin-artd/package.json', '{"dependencies":{}}');
    ini_set('error_log', $root . '/expected-errors.log');
    register_shutdown_function(static function () use ($root): void {
        Filesystem::delDir($root);
    });
    $loader = <<<'PHP'
<?php
$GLOBALS['dependency_loader_calls'] = ($GLOBALS['dependency_loader_calls'] ?? 0) + 1;
if (($GLOBALS['dependency_loader_fail'] ?? false) === true) throw new RuntimeException('injected loader failure');
spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\SandIam\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
}, true, true);
PHP;
    $catalog = <<<'PHP'
<?php
namespace plugin\SandIam\app\runtime;
final class ServiceCatalog {
    public function inspectServiceActions(array $service, array $actions): array {
        if (\Webman\Config::get('plugin.sand-iam.app.version') !== '1.0.0') throw new \RuntimeException('Dependency config not loaded');
        if (empty($GLOBALS['recovery_inspection'])
            && array_filter(\think\facade\Db::$sql, static fn(string $sql): bool => str_contains($sql, 'neutral-target-install'))) {
            throw new \RuntimeException('Catalog preflight ran after target SQL');
        }
        if (!empty($GLOBALS['fail_target_deployment'])) {
            $GLOBALS['fail_target_deployment'] = false;
            \plugin\sandadmin\app\cache\UserMenuCache::$failOnce = true;
        }
        $GLOBALS['catalog_inspected'] = true;
        return ['service_code'=>$service['code'], 'can_register'=>!($GLOBALS['catalog_disabled'] ?? false)];
    }
    public function registerServiceActions(array $service, array $actions): array {
        $GLOBALS['catalog_registered'] = true;
        return [];
    }
}
PHP;
    $autoload = '<?php return ["files"=>[base_path("plugin/sand-iam/app/functions.php")]];';
    $dependencyZip = $root . '/dependency.zip';
    zipActivation($dependencyZip, 'sand-iam', [
        'plugin/sand-iam/config/autoload.php' => $autoload,
        'plugin/sand-iam/config/route.php' => '<?php throw new RuntimeException("Routes must not execute during activation");',
        'plugin/sand-iam/app/functions.php' => $loader,
        'plugin/sand-iam/app/runtime/ServiceCatalog.php' => $catalog,
    ]);
    $targetZip = $root . '/target.zip';
    zipActivation($targetZip, 'neutral-target', [
        'dependencies/sand-iam-1.0.0.zip' => file_get_contents($dependencyZip),
    ], [
        'plugin_dependencies' => ['sand-iam' => ['version' => '1.0.0', 'sha256' => hash_file('sha256', $dependencyZip)]],
        'service_catalog' => ['service' => ['code' => 'neutral_target', 'name' => 'Neutral target'],
            'actions' => ['neutral_target.read' => 'Read']],
    ]);
    expectActivation(!class_exists(\plugin\SandIam\app\runtime\ServiceCatalog::class), 'dependency port is initially unavailable');
    if ($scenario !== 'automatic') {
        (new InstallLogic())->uploadFromPath($dependencyZip);
        (new InstallLogic('sand-iam'))->install(false);
        expectActivation(!class_exists(\plugin\SandIam\app\runtime\ServiceCatalog::class), 'install(false) alone leaves the original worker loader absent');
    }
    (new InstallLogic())->uploadFromPath($targetZip);
    $target = new InstallLogic('neutral-target');
    $candidate = $target->getInfo();
    $runtimeConfig = base_path('plugin/sand-iam/config/autoload.php');
    if ($scenario === 'activation-failure') writeActivation($runtimeConfig, '<?php throw new RuntimeException("injected config failure");');
    if ($scenario === 'loader-failure') $GLOBALS['dependency_loader_fail'] = true;
    if ($scenario === 'disabled-catalog') $GLOBALS['catalog_disabled'] = true;
    if ($scenario === 'external-file') {
        writeActivation($root . '/external.php', '<?php $GLOBALS["external_executed"] = true;');
        writeActivation($runtimeConfig, '<?php return ["files"=>[' . var_export($root . '/external.php', true) . ']];');
    }
    if ($scenario === 'symlink-file') {
        writeActivation($root . '/external.php', '<?php $GLOBALS["external_executed"] = true;');
        unlink(base_path('plugin/sand-iam/app/functions.php'));
        symlink($root . '/external.php', base_path('plugin/sand-iam/app/functions.php'));
    }
    if ($scenario === 'symlink-config') {
        rename($runtimeConfig, $root . '/autoload.php');
        symlink($root . '/autoload.php', $runtimeConfig);
    }
    if ($scenario === 'incompatible-dependency') {
        $dependency = new InstallLogic('sand-iam');
        $dependency->setInfo(['version' => '2.0.0']);
    }
    $failures = ['activation-failure' => '运行加载失败', 'loader-failure' => '运行加载失败',
        'external-file' => '运行加载失败', 'symlink-file' => '不兼容',
        'symlink-config' => '运行加载失败', 'disabled-catalog' => '目录预检失败',
        'incompatible-dependency' => '不兼容'];
    if ($scenario === 'continue-fresh') {
        $GLOBALS['fail_target_deployment'] = true;
        try {
            $target->install(false);
            throw new RuntimeException('Injected target deployment failure was not observed');
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            expectActivation(str_contains($error->getMessage(), '插件安装未完成'), 'post-SQL deployment failure enters official recovery');
        }
        expectActivation(empty($GLOBALS['catalog_registered']), 'interrupted deployment has not registered the catalog');
        $inspection = $target->inspectFreshInstallRecovery();
        expectActivation($inspection['phase'] === 'sql_committed_deploy_pending'
            && $inspection['actions'] === ['continue-fresh'], 'recovery binds the original committed candidate');
        $GLOBALS['recovery_inspection'] = true;
        $GLOBALS['catalog_disabled'] = true;
        $sqlBefore = Db::$sql;
        rejectsActivation(fn () => $target->recoverFreshInstall('continue-fresh', $inspection['confirmation']), '目录预检失败');
        expectActivation(Db::$sql === $sqlBefore && $target->getInstallState() === InstallLogic::FAILED,
            'recovery retains failed state and does not replay SQL when the catalog is disabled');
        $GLOBALS['catalog_disabled'] = false;
        $inspection = $target->inspectFreshInstallRecovery();
        $result = $target->recoverFreshInstall('continue-fresh', $inspection['confirmation']);
        expectActivation($result['phase'] === 'complete' && $result['sql_executed'] === false && Db::$sql === $sqlBefore,
            'official continue-fresh completes the original candidate with zero SQL replay');
        expectActivation($target->recoverFreshInstall('continue-fresh', $inspection['confirmation'])['repeated'],
            'completed recovery is idempotent');
    } elseif (isset($failures[$scenario])) {
        rejectsActivation(fn () => $target->install(false), $failures[$scenario]);
        expectActivation(targetSqlActivation() === [], 'activation or catalog rejection executes zero target SQL');
        expectActivation($target->getInfo() === $candidate && $target->getInstallState() === InstallLogic::WAIT_INSTALL,
            'rejection preserves the exact waiting candidate and package identity');
        expectActivation((new InstallLogic('sand-iam'))->getInstallState() === InstallLogic::INSTALLED,
            'already installed dependency remains installed');
        expectActivation(empty($GLOBALS['external_executed']), 'external or symlink file never executes');
        if ($scenario !== 'activation-failure') exit(0);
        writeActivation($runtimeConfig, $autoload);
        $target->install(false);
        expectActivation($target->getInfo()['package_sha256'] === $candidate['package_sha256'],
            'after a loading fault is removed, the same candidate continues without reupload');
    } else {
        $target->install(false);
    }
    expectActivation((new InstallLogic('sand-iam'))->getInstallState() === InstallLogic::INSTALLED
        && $target->getInstallState() === InstallLogic::INSTALLED, 'one target installation completes dependency and target');
    expectActivation(count(targetSqlActivation()) === 1, 'target SQL executes exactly once');
    expectActivation(count(array_filter(Db::$sql, static fn (string $sql): bool => str_contains($sql, 'sand-iam-install'))) === 1,
        'dependency SQL executes once, including an already compatible installation');
    expectActivation(($GLOBALS['dependency_loader_calls'] ?? 0) === 1
        && !empty($GLOBALS['catalog_inspected']) && !empty($GLOBALS['catalog_registered']),
        'official autoload files expose a real callable catalog before SQL and register after deployment');
    if ($scenario === 'existing') {
        $anotherZip = $root . '/another.zip';
        zipActivation($anotherZip, 'another-target', ['dependencies/sand-iam-1.0.0.zip' => file_get_contents($dependencyZip)],
            ['plugin_dependencies' => ['sand-iam' => ['version' => '1.0.0', 'sha256' => hash_file('sha256', $dependencyZip)]]]);
        (new InstallLogic())->uploadFromPath($anotherZip);
        (new InstallLogic('another-target'))->install(false);
        expectActivation($GLOBALS['dependency_loader_calls'] === 1, 'reusing the active dependency does not rerun loader side effects');
    }
}
