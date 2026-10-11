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
        'symlink-file', 'symlink-config', 'disabled-catalog', 'incompatible-dependency', 'continue-fresh',
        'existing-higher', 'existing-higher-preview', 'existing-build', 'existing-lower', 'existing-prerelease', 'existing-invalid', 'upgrade-higher',
        'existing-preview-zero', 'existing-invalid-preview', 'existing-invalid-preview-member', 'existing-alpha-zero', 'existing-build-zero',
        'continue-fresh-higher', 'bundle-sha', 'bundle-version', 'bundle-app', 'candidate-version', 'candidate-sha',
        'core-major-lower', 'core-minor-lower', 'core-patch-lower', 'core-long-lower', 'core-long-higher',
        'core-large-equal', 'same-core-preview-zero'];
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
    function zipActivation(string $path, string $app, array $files, array $config = [], string $version = '1.0.0'): void {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) throw new RuntimeException('ZIP creation failed');
        $common = [
            'info.ini' => "app=$app\ntitle=Fixture\nabout=Dependency activation\nauthor=Test\nversion=$version\nsupport=\">=0.1.0\"\n",
            'config.json' => json_encode($config, JSON_THROW_ON_ERROR),
            'install.sql' => "SELECT '$app-install';", 'update.sql' => '', 'uninstall.sql' => '',
            "plugin/$app/config/app.php" => '<?php return ["version"=>' . var_export($version, true) . '];',
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
        $temporary = realpath(sys_get_temp_dir());
        $actual = realpath($root);
        if ($temporary === false || $actual === false || dirname($actual) !== $temporary
            || !str_starts_with(basename($actual), 'sandpackage-activation-') || is_link($root)) return;
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
        if (\Webman\Config::get('plugin.sand-iam.app.version') !== ($GLOBALS['expected_dependency_version'] ?? '1.0.0')) throw new \RuntimeException('Dependency config not loaded');
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
    $minimumVersion = match ($scenario) {
        'core-major-lower', 'core-large-equal' => '9223372036854775809.0.0',
        'core-minor-lower' => '1.9223372036854775809.0',
        'core-patch-lower' => '1.0.9223372036854775809',
        'core-long-lower' => str_repeat('9', 128) . '.0.0',
        'core-long-higher' => str_repeat('8', 128) . '.0.0',
        default => '1.0.0',
    };
    $dependencyZip = $root . '/dependency.zip';
    zipActivation($dependencyZip, 'sand-iam', [
        'plugin/sand-iam/config/autoload.php' => $autoload,
        'plugin/sand-iam/config/route.php' => '<?php throw new RuntimeException("Routes must not execute during activation");',
        'plugin/sand-iam/app/functions.php' => $loader,
        'plugin/sand-iam/app/runtime/ServiceCatalog.php' => $catalog,
    ], [], $minimumVersion);
    if (in_array($scenario, ['bundle-version', 'bundle-app'], true)) {
        zipActivation($dependencyZip, $scenario === 'bundle-app' ? 'other-dependency' : 'sand-iam', [], [],
            $scenario === 'bundle-version' ? '2.0.0' : '1.0.0');
    }
    $targetZip = $root . '/target.zip';
    zipActivation($targetZip, 'neutral-target', [
        'dependencies/sand-iam-' . $minimumVersion . '.zip' => file_get_contents($dependencyZip),
    ], [
        'plugin_dependencies' => ['sand-iam' => ['version' => $minimumVersion, 'sha256' => $scenario === 'bundle-sha' ? str_repeat('0', 64) : hash_file('sha256', $dependencyZip)]],
        'service_catalog' => ['service' => ['code' => 'neutral_target', 'name' => 'Neutral target'],
            'actions' => ['neutral_target.read' => 'Read']],
    ]);
    expectActivation(!class_exists(\plugin\SandIam\app\runtime\ServiceCatalog::class), 'dependency port is initially unavailable');
    $installedVersion = match ($scenario) {
        'core-major-lower' => '9223372036854775808.0.0',
        'core-minor-lower' => '1.9223372036854775808.0',
        'core-patch-lower' => '1.0.9223372036854775808',
        'core-long-lower' => str_repeat('8', 128) . '.0.0',
        'core-long-higher' => str_repeat('9', 128) . '.0.0',
        'core-large-equal' => $minimumVersion,
        'same-core-preview-zero' => '1.0.0-0',
        'existing-higher', 'continue-fresh-higher', 'upgrade-higher' => '1.1.0',
        'existing-higher-preview' => '1.1.0-foo',
        'existing-preview-zero' => '1.1.0-0',
        'existing-invalid-preview' => '1.1.0-01',
        'existing-invalid-preview-member' => '1.1.0-alpha.01',
        'existing-alpha-zero' => '1.1.0-alpha01',
        'existing-build-zero' => '1.0.0+01',
        'existing-build' => '1.0.0+build.1',
        'existing-lower' => '0.9.0',
        'existing-prerelease' => '1.0.0-foo',
        default => '1.0.0',
    };
    $GLOBALS['expected_dependency_version'] = $installedVersion;
    $installedZip = $dependencyZip;
    if ($installedVersion !== $minimumVersion) {
        $installedZip = $root . '/installed-dependency.zip';
        zipActivation($installedZip, 'sand-iam', [
            'plugin/sand-iam/config/autoload.php' => $autoload,
            'plugin/sand-iam/app/functions.php' => $loader,
            'plugin/sand-iam/app/runtime/ServiceCatalog.php' => $catalog,
        ], [], $installedVersion);
    }
    if (in_array($scenario, ['bundle-sha', 'bundle-version', 'bundle-app'], true)) {
        rejectsActivation(fn () => (new InstallLogic())->uploadFromPath($targetZip), '插件依赖工件');
        expectActivation(Db::$sql === [], 'invalid bundled SHA or identity executes zero SQL');
        expectActivation((new InstallLogic('neutral-target'))->getInstallState() === InstallLogic::UNINSTALLED,
            'invalid bundled identity does not stage a target candidate');
        exit(0);
    }
    if (in_array($scenario, ['candidate-version', 'candidate-sha'], true)) {
        (new InstallLogic())->uploadFromPath($dependencyZip);
        $dependency = new InstallLogic('sand-iam');
        $dependency->setInfo($scenario === 'candidate-version' ? ['version' => '1.1.0'] : ['package_sha256' => str_repeat('0', 64)]);
        (new InstallLogic())->uploadFromPath($targetZip);
        rejectsActivation(fn () => (new InstallLogic('neutral-target'))->install(false), '候选包与声明不一致');
        expectActivation(Db::$sql === [], 'waiting dependency still requires exact version and SHA before SQL');
        exit(0);
    }
    if ($scenario !== 'automatic') {
        (new InstallLogic())->uploadFromPath($installedZip);
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
        $dependency->setInfo(['version' => '0.9.0']);
    }
    if ($scenario === 'existing-invalid') (new InstallLogic('sand-iam'))->setInfo(['version' => '999invalid']);
    $failures = ['activation-failure' => '运行加载失败', 'loader-failure' => '运行加载失败',
        'external-file' => '运行加载失败', 'symlink-file' => '不兼容',
        'symlink-config' => '运行加载失败', 'disabled-catalog' => '目录预检失败',
        'incompatible-dependency' => '不兼容', 'existing-lower' => '不兼容', 'existing-prerelease' => '不兼容', 'existing-invalid' => '不兼容',
        'existing-invalid-preview' => '不兼容', 'existing-invalid-preview-member' => '不兼容',
        'core-major-lower' => '不兼容', 'core-minor-lower' => '不兼容', 'core-patch-lower' => '不兼容',
        'core-long-lower' => '不兼容', 'same-core-preview-zero' => '不兼容'];
    if (in_array($scenario, ['continue-fresh', 'continue-fresh-higher'], true)) {
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
    if ($scenario === 'upgrade-higher') {
        $upgradeZip = $root . '/target-upgrade.zip';
        zipActivation($upgradeZip, 'neutral-target', [
            'dependencies/sand-iam-' . $minimumVersion . '.zip' => file_get_contents($dependencyZip),
        ], ['plugin_dependencies' => ['sand-iam' => ['version' => $minimumVersion,
            'sha256' => hash_file('sha256', $dependencyZip)]]], '2.0.0');
        $target->uploadFromPath($upgradeZip);
        $target->install(false, 'UPGRADE neutral-target@1.0.0->2.0.0');
        expectActivation($target->getInfo()['version'] === '2.0.0'
            && (new InstallLogic('sand-iam'))->getInfo()['version'] === '1.1.0',
            'ordinary target upgrade accepts the exact higher installed dependency without downgrading');
        expectActivation(count(array_filter(Db::$sql, static fn (string $sql): bool => str_contains($sql, 'sand-iam-install'))) === 1,
            'target upgrade does not reinstall or replay dependency SQL');
    }
    if ($scenario === 'existing') {
        $anotherZip = $root . '/another.zip';
        zipActivation($anotherZip, 'another-target', ['dependencies/sand-iam-' . $minimumVersion . '.zip' => file_get_contents($dependencyZip)],
            ['plugin_dependencies' => ['sand-iam' => ['version' => $minimumVersion, 'sha256' => hash_file('sha256', $dependencyZip)]]]);
        (new InstallLogic())->uploadFromPath($anotherZip);
        (new InstallLogic('another-target'))->install(false);
        expectActivation($GLOBALS['dependency_loader_calls'] === 1, 'reusing the active dependency does not rerun loader side effects');
    }
}
