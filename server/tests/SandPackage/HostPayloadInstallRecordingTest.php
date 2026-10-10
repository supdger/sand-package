<?php

declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        public static array $sql = [];
        public static string $failNeedle = '';
        public static bool $schemaDrift = false;
        public static function connect(string $name): object
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
                            if (str_contains($this->sql, 'SELECT EXISTS(')) {
                                return [['present' => false]];
                            }
                            if (str_contains($this->sql, 'current_database()')) {
                                return [['database' => 'recording-fixture', 'oid' => '1', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']];
                            }
                            return Db::$schemaDrift && str_contains($this->sql, 'FROM pg_class')
                                ? [['relname' => 'external_schema_change']] : [];
                        }
                    };
                }
                public function exec(string $sql): int {
                    Db::$sql[] = trim($sql);
                    if (Db::$failNeedle !== '' && str_contains($sql, Db::$failNeedle)) {
                        throw new \RuntimeException('recorded SQL failure');
                    }
                    return 1;
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
                throw new \RuntimeException('injected cache failure');
            }
        }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\HostPayloadOwnership;
    use plugin\sandpackage\app\service\HostPayloadLifecycleJournal;
    use plugin\sandpackage\app\service\HostPayloadPlan;
    use plugin\sandpackage\app\service\PluginStorage;
    use Saithink\Saipackage\service\Server;
    use think\facade\Db;

    $root = sys_get_temp_dir() . '/sandpackage-host-install-' . bin2hex(random_bytes(8));
    mkdir($root . '/server/plugin', 0700, true);
    mkdir($root . '/sandadmin-artd', 0700, true);
    ini_set('error_log', $root . '/expected-errors.log');
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key, mixed $default = null): mixed { return $key === 'plugin.sandadmin.app.version' ? '0.1.0' : $default; }
    function hostInstallWrite(string $path, string $contents): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }
    function hostInstallDelete(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (new \FilesystemIterator($path) as $item) hostInstallDelete($item->getPathname());
        rmdir($path);
    }

    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    foreach (['HostPayloadManifest', 'HostPayloadPlan', 'HostPayloadOwnership', 'HostPayloadFreshFiles', 'HostPayloadChangeFiles', 'HostPayloadRuntimeChangeFiles', 'HostPayloadRemovalFiles', 'HostPayloadUninstallFinalization', 'HostPayloadDependencyChange', 'HostPayloadCandidateRollback', 'HostPayloadLifecycleJournal', 'PostgresHostCatalogFingerprint'] as $component) {
        require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/' . $component . '.php';
    }
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';

    $app = 'neutral-host';
    $storageRoot = (new PluginStorage())->root();
    $candidate = $storageRoot . DIRECTORY_SEPARATOR . $app;
    $payloadState = HostPayloadPlan::directoryPrefix($storageRoot) . 'host-payload';
    $appPath = 'app/Api/Neutral/Probe.php';
    $configPath = 'config/neutral_host_api.php';
    $sources = [
        $appPath => "<?php namespace app\\Api\\Neutral;\n",
        $configPath => "<?php return [];\n",
    ];
    $files = [];
    foreach ($sources as $path => $contents) {
        hostInstallWrite($candidate . '/' . $path, $contents);
        $files[] = ['path' => $path, 'sha256' => hash('sha256', $contents)];
    }
    hostInstallWrite($candidate . '/host-payload.json', json_encode(
        ['schema' => 1, 'app' => $app, 'files' => $files],
        JSON_THROW_ON_ERROR,
    ));
    hostInstallWrite($candidate . '/config.json', "{}\n");
    hostInstallWrite($candidate . '/install.sql', "CREATE TABLE neutral_host (id bigint);\n");
    hostInstallWrite($candidate . '/update.sql', "SELECT 'update';\n");
    hostInstallWrite($candidate . '/uninstall.sql', "DROP TABLE neutral_host;\n");
    hostInstallWrite($candidate . '/plugin/' . $app . '/config/app.php', "<?php return ['version' => '1.0.0'];\n");
    hostInstallWrite($candidate . '/sandadmin-artd/src/views/plugin/' . $app . '/index.vue', '<template>fixture</template>');
    hostInstallWrite(base_path('composer.json'), '{"name":"test/host","require":{}}');
    hostInstallWrite($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
    Server::setIni($candidate . '/', [
        'app' => $app, 'title' => 'Neutral', 'about' => 'Fixture', 'author' => 'Test',
        'version' => '1.0.0', 'support' => '">=0.1.0"', 'state' => InstallLogic::WAIT_INSTALL,
        'lifecycle_driver' => 'saipackage-pg-v1', 'package_sha256' => str_repeat('a', 64),
    ]);

    try {
        $logic = new InstallLogic($app);
        $info = $logic->install(false);
        if (($info['state'] ?? null) !== InstallLogic::INSTALLED
            || Db::$sql !== ['BEGIN', 'CREATE TABLE neutral_host (id bigint)', 'COMMIT']
            || !is_file(base_path('plugin/' . $app . '/config/app.php'))
            || !is_file($root . '/sandadmin-artd/src/views/plugin/' . $app . '/index.vue')
            || HostPayloadOwnership::read(base_path('storage/sandpackage/host-payload'), $app) !== $files) {
            throw new \RuntimeException('Integrated fresh install did not deploy all declared surfaces');
        }
        foreach ($sources as $path => $contents) {
            if (file_get_contents(base_path($path)) !== $contents) {
                throw new \RuntimeException('Integrated fresh install omitted host file: ' . $path);
            }
        }
        if ($logic->getInstallState() !== InstallLogic::INSTALLED) {
            throw new \RuntimeException('Complete host payload was not recognized as installed');
        }
        unlink(base_path($appPath));
        if ($logic->getInstallState() !== InstallLogic::DEPLOYMENT_MISSING) {
            throw new \RuntimeException('Missing host app file was not detected');
        }
        hostInstallWrite(base_path($appPath), $sources[$appPath]);
        $upgradeSources = [
            $appPath => "<?php namespace app\\Api\\Neutral;\n// upgraded\n",
            $configPath => "<?php return ['enabled' => true];\n",
        ];
        $upgradeFiles = [];
        foreach ($upgradeSources as $path => $contents) {
            hostInstallWrite($candidate . '/' . $path, $contents);
            $upgradeFiles[] = ['path' => $path, 'sha256' => hash('sha256', $contents)];
        }
        hostInstallWrite($candidate . '/host-payload.json', json_encode(
            ['schema' => 1, 'app' => $app, 'files' => $upgradeFiles],
            JSON_THROW_ON_ERROR,
        ));
        hostInstallWrite($candidate . '/plugin/' . $app . '/config/app.php', "<?php return ['version' => '2.0.0'];\n");
        $upgradeInfo = $logic->getInfo();
        $upgradeInfo['state'] = InstallLogic::WAIT_INSTALL;
        $upgradeInfo['version'] = '2.0.0';
        $upgradeInfo['update'] = 1;
        $upgradeInfo['upgrade_from_version'] = '1.0.0';
        $runtimeBefore = [];
        foreach ($logic->getAllowedPath() as $target) {
            $runtimeBefore[$target] = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($target);
        }
        $upgradeInfo['upgrade_runtime_tree_sha256'] = hash(
            'sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR),
        );
        $logic->setInfo([], $upgradeInfo);
        $upgraded = $logic->install(false, 'UPGRADE neutral-host@1.0.0->2.0.0');
        if (($upgraded['state'] ?? null) !== InstallLogic::INSTALLED
            || $logic->getInstallState() !== InstallLogic::INSTALLED
            || HostPayloadOwnership::read(base_path('storage/sandpackage/host-payload'), $app) !== $upgradeFiles) {
            throw new \RuntimeException('Integrated upgrade did not publish new host ownership');
        }
        foreach ($upgradeSources as $path => $contents) {
            if (file_get_contents(base_path($path)) !== $contents) {
                throw new \RuntimeException('Integrated upgrade omitted host file: ' . $path);
            }
        }
        require dirname(__DIR__, 2) . '/plugin/sandpackage/app/command/Recover.php';
        $tester = new \Symfony\Component\Console\Tester\CommandTester(
            new \plugin\sandpackage\app\command\Recover(),
        );
        \plugin\sandadmin\app\cache\UserMenuCache::$failOnce = true;
        try {
            $logic->uninstall(false);
            throw new \RuntimeException('Post-COMMIT uninstall cache failure unexpectedly completed');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        $uninstallInspection = $logic->inspectHostUninstallRecovery();
        if ($uninstallInspection['sql_phase'] !== 'sql_committed_deploy_pending'
            || $uninstallInspection['actions'] !== ['continue-uninstall']
            || $uninstallInspection['candidate_present']
            || $uninstallInspection['removal_phase'] !== 'pending') {
            throw new \RuntimeException('Committed uninstall failure did not retain a bounded continuation');
        }
        $sqlBeforeUninstallContinue = Db::$sql;
        $staleUninstallStatus = $tester->execute([
            'action' => 'continue-host-uninstall', 'app' => $app,
            '--confirmation' => 'CONTINUE-UNINSTALL ' . $app . ' ' . str_repeat('0', 64),
        ]);
        if ($staleUninstallStatus === 0 || Db::$sql !== $sqlBeforeUninstallContinue
            || $logic->getInstallState() !== InstallLogic::FAILED) {
            throw new \RuntimeException('Stale uninstall confirmation changed the failed operation');
        }
        $interruptedRemoval = new \plugin\sandpackage\app\service\HostPayloadRemovalFiles(
            $payloadState, $app, array_values($logic->getAllowedPath()), rtrim($candidate, '/'),
        );
        $interruptedRemoval->complete();
        $interruptedFinalization = new \plugin\sandpackage\app\service\HostPayloadUninstallFinalization(
            $payloadState, base_path(), $app,
            array_values($logic->getAllowedPath()), rtrim($candidate, '/'),
        );
        $interruptedFinalization->begin();
        \plugin\sandpackage\app\service\HostPayloadFreshFiles::archiveAfterRemoval(
            base_path(), $payloadState, $app,
        );
        $freshHistory = glob($payloadState . '/' . $app . '.fresh.json.*.history') ?: [];
        if (count($freshHistory) !== 1) {
            throw new \RuntimeException('Partial uninstall audit did not retain the first history file');
        }
        $freshHistoryBody = file_get_contents($freshHistory[0]);
        file_put_contents($freshHistory[0], $freshHistoryBody . "\n");
        try {
            $logic->inspectHostUninstallRecovery();
            throw new \RuntimeException('Modified archived uninstall audit was accepted');
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            if ($error->getMessage() === 'Modified archived uninstall audit was accepted') throw $error;
        }
        file_put_contents($freshHistory[0], $freshHistoryBody);
        $partialAudit = $interruptedFinalization->inspectSnapshot();
        $uninstallInspection = $logic->inspectHostUninstallRecovery();
        if ($partialAudit['archived'] !== ['fresh' => true, 'change' => false, 'removal' => false]
            || $uninstallInspection['finalization_phase'] !== 'pending'
            || $uninstallInspection['actions'] !== ['continue-uninstall']) {
            throw new \RuntimeException('Interrupted uninstall audit was not safely resumable');
        }
        $uninstallStatus = $tester->execute([
            'action' => 'continue-host-uninstall', 'app' => $app,
            '--confirmation' => 'CONTINUE-UNINSTALL ' . $app . ' ' . $uninstallInspection['fingerprint'],
        ]);
        $uninstallResult = json_decode(trim($tester->getDisplay()), true);
        if ($uninstallStatus !== 0
            || ($uninstallResult['state'] ?? null) !== InstallLogic::UNINSTALLED
            || ($uninstallResult['sql_replayed'] ?? null) !== false
            || Db::$sql !== $sqlBeforeUninstallContinue) {
            throw new \RuntimeException('Committed uninstall did not resume without SQL replay');
        }
        if ($logic->getInstallState() !== InstallLogic::UNINSTALLED
            || HostPayloadOwnership::read(base_path('storage/sandpackage/host-payload'), $app) !== null
            || is_file(base_path($appPath)) || is_file(base_path($configPath))
            || is_dir(base_path('plugin/' . $app))) {
            throw new \RuntimeException('Integrated uninstall left plugin or host files');
        }
        if (is_file($payloadState . '/' . $app . '.fresh.json')
            || is_file($payloadState . '/' . $app . '.change.json')
            || count(glob($payloadState . '/' . $app . '.fresh.json.*.history') ?: []) !== 1
            || count(glob($payloadState . '/' . $app . '.change.json.*.history') ?: []) !== 2) {
            throw new \RuntimeException('Integrated uninstall did not retain closed file-operation audit');
        }
        if (Db::$sql !== [
            'BEGIN', 'CREATE TABLE neutral_host (id bigint)', 'COMMIT',
            'BEGIN', "SELECT 'update'", 'COMMIT',
            'BEGIN', 'DROP TABLE neutral_host', 'COMMIT',
        ]) {
            throw new \RuntimeException('Integrated lifecycle replayed or skipped a SQL phase');
        }
        foreach ($sources as $path => $contents) {
            hostInstallWrite($candidate . '/' . $path, $contents);
        }
        hostInstallWrite($candidate . '/host-payload.json', json_encode(
            ['schema' => 1, 'app' => $app, 'files' => $files],
            JSON_THROW_ON_ERROR,
        ));
        hostInstallWrite($candidate . '/install.sql', "CREATE TABLE neutral_host (id bigint);\n");
        hostInstallWrite($candidate . '/update.sql', "SELECT 'update';\n");
        hostInstallWrite($candidate . '/uninstall.sql', "DROP TABLE neutral_host;\n");
        hostInstallWrite($candidate . '/config.json', "{}\n");
        hostInstallWrite($candidate . '/plugin/' . $app . '/config/app.php', "<?php return ['version' => '1.0.0'];\n");
        Server::setIni($candidate . '/', [
            'app' => $app, 'title' => 'Neutral', 'about' => 'Fixture', 'author' => 'Test',
            'version' => '1.0.0', 'support' => '">=0.1.0"', 'state' => InstallLogic::WAIT_INSTALL,
            'lifecycle_driver' => 'saipackage-pg-v1', 'package_sha256' => str_repeat('b', 64),
        ]);
        $reinstalled = $logic->install(false);
        if ($reinstalled['state'] !== InstallLogic::INSTALLED
            || HostPayloadOwnership::read($payloadState, $app) !== $files) {
            throw new \RuntimeException('Reinstall after controlled uninstall failed');
        }
        unlink($candidate . '/uninstall.sql');
        $sqlBeforeMissingScript = Db::$sql;
        try {
            $logic->uninstall(false);
            throw new \RuntimeException('Missing uninstall SQL unexpectedly completed');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        $missingScriptInspection = $logic->inspectHostUninstallRecovery();
        if ($missingScriptInspection['sql_phase'] !== 'sql_not_started'
            || $missingScriptInspection['actions'] !== ['restore-uninstall']
            || Db::$sql !== $sqlBeforeMissingScript) {
            throw new \RuntimeException('Pre-SQL uninstall failure did not retain the original plugin');
        }
        $missingScriptStatus = $tester->execute([
            'action' => 'restore-host-uninstall', 'app' => $app,
            '--confirmation' => 'RESTORE-UNINSTALL ' . $app . ' ' . $missingScriptInspection['fingerprint'],
        ]);
        if ($missingScriptStatus !== 0 || $logic->getInstallState() !== InstallLogic::INSTALLED) {
            throw new \RuntimeException('Pre-SQL uninstall failure was not recoverable');
        }
        hostInstallWrite($candidate . '/uninstall.sql', "DROP TABLE neutral_host;\n");
        Db::$failNeedle = 'DROP TABLE neutral_host';
        try {
            $logic->uninstall(false);
            throw new \RuntimeException('Failed uninstall SQL unexpectedly removed the plugin');
        } catch (\plugin\sandadmin\exception\ApiException) {
        } finally {
            Db::$failNeedle = '';
        }
        $rolledBackUninstall = $logic->inspectHostUninstallRecovery();
        if ($rolledBackUninstall['sql_phase'] !== 'sql_not_committed'
            || $rolledBackUninstall['actions'] !== ['restore-uninstall']
            || !$rolledBackUninstall['candidate_present']
            || $logic->getInstallState() !== InstallLogic::FAILED) {
            throw new \RuntimeException('Failed uninstall did not retain the exact original files');
        }
        $sqlBeforeUninstallRollback = Db::$sql;
        $staleRollbackStatus = $tester->execute([
            'action' => 'restore-host-uninstall', 'app' => $app,
            '--confirmation' => 'RESTORE-UNINSTALL ' . $app . ' ' . str_repeat('0', 64),
        ]);
        if ($staleRollbackStatus === 0 || Db::$sql !== $sqlBeforeUninstallRollback
            || $logic->getInstallState() !== InstallLogic::FAILED) {
            throw new \RuntimeException('Stale uninstall rollback confirmation changed the operation');
        }
        $rollbackStatus = $tester->execute([
            'action' => 'restore-host-uninstall', 'app' => $app,
            '--confirmation' => 'RESTORE-UNINSTALL ' . $app . ' ' . $rolledBackUninstall['fingerprint'],
        ]);
        $rollbackResult = json_decode(trim($tester->getDisplay()), true);
        if ($rollbackStatus !== 0
            || ($rollbackResult['state'] ?? null) !== InstallLogic::INSTALLED
            || ($rollbackResult['sql_replayed'] ?? null) !== false
            || Db::$sql !== $sqlBeforeUninstallRollback
            || $logic->getInstallState() !== InstallLogic::INSTALLED) {
            throw new \RuntimeException('Rolled-back uninstall did not restore the original plugin');
        }
        $failurePackage = $root . '/upgrade-failure.zip';
        $zip = new \ZipArchive();
        $zip->open($failurePackage, \ZipArchive::CREATE);
        foreach ([
            'info.ini' => "app = neutral-host\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = 2.0.0\nsupport = \">=0.1.0\"\n",
            'config.json' => "{}\n",
            'install.sql' => "CREATE TABLE neutral_host (id bigint);\n",
            'update.sql' => "BROKEN SQL;\n",
            'uninstall.sql' => "DROP TABLE neutral_host;\n",
            'plugin/' . $app . '/config/app.php' => "<?php return ['version' => '2.0.0'];\n",
        ] as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();
        $failureInfo = $logic->uploadFromPath($failurePackage);
        Db::$failNeedle = 'BROKEN SQL';
        try {
            $logic->install(false, 'UPGRADE neutral-host@1.0.0->2.0.0');
            throw new \RuntimeException('Broken upgrade unexpectedly succeeded');
        } catch (\plugin\sandadmin\exception\ApiException) {
            Db::$failNeedle = '';
        }
        $journal = new HostPayloadLifecycleJournal($payloadState, $app, 'upgrade', $failureInfo['package_sha256']);
        if ($journal->read()['phase'] !== 'sql_not_committed'
            || $logic->getInstallState() !== InstallLogic::FAILED
            || HostPayloadOwnership::read($payloadState, $app) !== $files) {
            throw new \RuntimeException('Failed upgrade did not preserve a known rollback and old files');
        }
        $inspection = $logic->inspectHostUpgradeRecovery();
        if ($inspection['sql_phase'] !== 'sql_not_committed'
            || $inspection['old_host_files'] !== 2 || $inspection['new_host_files'] !== 0
            || $inspection['actions'] !== ['restore-old-candidate']) {
            throw new \RuntimeException('Failed upgrade inspection did not bind exact old and new payloads');
        }
        $status = $tester->execute(['action' => 'inspect-host-upgrade', 'app' => $app]);
        $fromCli = json_decode(trim($tester->getDisplay()), true);
        if ($status !== 0 || ($fromCli['fingerprint'] ?? null) !== $inspection['fingerprint']) {
            throw new \RuntimeException('Official recovery CLI did not expose the bound read-only inspection');
        }
        Db::$schemaDrift = true;
        try {
            $logic->inspectHostUpgradeRecovery();
            throw new \RuntimeException('Changed PostgreSQL catalog was accepted after a known rollback');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        Db::$schemaDrift = false;
        $oldHostBody = file_get_contents(base_path($appPath));
        hostInstallWrite(base_path($appPath), 'foreign host edit');
        try {
            $logic->inspectHostUpgradeRecovery();
            throw new \RuntimeException('Changed owned host file was accepted');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        hostInstallWrite(base_path($appPath), $oldHostBody);
        $changeRecord = json_decode(
            file_get_contents($payloadState . '/' . $app . '.change.json'), true, 32, JSON_THROW_ON_ERROR,
        );
        $changeBackupFile = $changeRecord['backup'] . '/' . $appPath;
        $oldBackupBody = file_get_contents($changeBackupFile);
        hostInstallWrite($changeBackupFile, 'foreign backup edit');
        try {
            $logic->inspectHostUpgradeRecovery();
            throw new \RuntimeException('Changed file-transaction backup was accepted');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        hostInstallWrite($changeBackupFile, $oldBackupBody);
        $candidateSql = $candidate . '/update.sql';
        $originalSql = file_get_contents($candidateSql);
        hostInstallWrite($candidateSql, "SELECT 'tampered';\n");
        try {
            $logic->inspectHostUpgradeRecovery();
            throw new \RuntimeException('Tampered upgrade candidate was accepted');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        hostInstallWrite($candidateSql, $originalSql);
        $runtimeConfig = base_path('plugin/' . $app . '/config/app.php');
        $originalRuntimeConfig = file_get_contents($runtimeConfig);
        hostInstallWrite($runtimeConfig, "<?php return ['version' => 'tampered'];\n");
        try {
            $logic->inspectHostUpgradeRecovery();
            throw new \RuntimeException('Changed old runtime was accepted after a known SQL rollback');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        hostInstallWrite($runtimeConfig, $originalRuntimeConfig);
        $backupPath = base_path('storage/sandpackage/backups/' . $failureInfo['upgrade_backup_id']);
        $originalBackupSql = file_get_contents($backupPath . '/install.sql');
        hostInstallWrite($backupPath . '/install.sql', "SELECT 'tampered';\n");
        try {
            $logic->inspectHostUpgradeRecovery();
            throw new \RuntimeException('Tampered old backup was accepted');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        hostInstallWrite($backupPath . '/install.sql', $originalBackupSql);
        $sqlBeforeRecovery = Db::$sql;
        $rejected = $tester->execute([
            'action' => 'restore-host-upgrade', 'app' => $app,
            '--confirmation' => 'RESTORE ' . $app . ' stale-fingerprint',
        ]);
        if ($rejected === 0 || Db::$sql !== $sqlBeforeRecovery
            || $logic->getInfo()['version'] !== '2.0.0') {
            throw new \RuntimeException('Stale recovery confirmation changed the failed upgrade');
        }
        $status = $tester->execute([
            'action' => 'restore-host-upgrade', 'app' => $app,
            '--confirmation' => 'RESTORE ' . $app . ' ' . $inspection['fingerprint'],
        ]);
        $recovered = json_decode(trim($tester->getDisplay()), true);
        if ($status !== 0 || ($recovered['state'] ?? null) !== InstallLogic::INSTALLED
            || ($recovered['sql_replayed'] ?? null) !== false
            || Db::$sql !== $sqlBeforeRecovery
            || $logic->getInfo()['version'] !== '1.0.0'
            || HostPayloadOwnership::read($payloadState, $app) !== $files
            || !is_dir($recovered['failed_candidate'] ?? '')
            || $journal->read()['phase'] !== 'rolled_back') {
            throw new \RuntimeException('Known SQL rollback did not restore exact old files without SQL replay');
        }
        $retryInfo = $logic->uploadFromPath($failurePackage);
        Db::$failNeedle = 'BROKEN SQL';
        try {
            $logic->install(false, 'UPGRADE neutral-host@1.0.0->2.0.0');
            throw new \RuntimeException('Second broken upgrade unexpectedly succeeded');
        } catch (\plugin\sandadmin\exception\ApiException) {
            Db::$failNeedle = '';
        }
        $retrySource = $candidate;
        $retryChange = new \plugin\sandpackage\app\service\HostPayloadChangeFiles(
            base_path(), $payloadState, $retrySource, $app, [],
        );
        $retryChange->restore();
        $retryRuntime = new \plugin\sandpackage\app\service\HostPayloadRuntimeChangeFiles(
            $payloadState, $app, $logic->getAllowedPath(), $retryInfo['upgrade_runtime_tree_sha256'],
        );
        $retryRuntime->markRolledBack();
        $retryRuntime->archiveFinished();
        $retryDependencies = new \plugin\sandpackage\app\service\HostPayloadDependencyChange(
            $payloadState, $app, $retrySource, base_path('composer.json'),
            $root . '/sandadmin-artd/package.json',
        );
        $retryDependencies->markRolledBack();
        $retryDependencies->archiveFinished();
        $retryRollback = new \plugin\sandpackage\app\service\HostPayloadCandidateRollback(
            $storageRoot, $app,
            $retryInfo['upgrade_backup_id'],
            $retryInfo['upgrade_backup_tree_sha256'],
            $retryInfo['candidate_tree_sha256'],
        );
        try {
            $retryRollback->restore(static function (string $phase): void {
                if ($phase === 'quarantined') throw new \RuntimeException('injected quarantine interruption');
            });
            throw new \RuntimeException('Expected quarantine interruption');
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'injected quarantine interruption') throw $error;
        }
        if (is_dir($retrySource)) {
            throw new \RuntimeException('Interrupted recovery did not isolate failed candidate');
        }
        $retryInspection = $logic->inspectHostUpgradeRecovery();
        if ($retryInspection['rollback_phase'] !== 'quarantined'
            || $retryInspection['actions'] !== ['restore-old-candidate']) {
            throw new \RuntimeException('Interrupted recovery was not discoverable');
        }
        $sqlBeforeResume = Db::$sql;
        $status = $tester->execute([
            'action' => 'restore-host-upgrade', 'app' => $app,
            '--confirmation' => 'RESTORE ' . $app . ' ' . $retryInspection['fingerprint'],
        ]);
        $resumed = json_decode(trim($tester->getDisplay()), true);
        if ($status !== 0 || ($resumed['state'] ?? null) !== InstallLogic::INSTALLED
            || Db::$sql !== $sqlBeforeResume
            || $logic->getInfo()['version'] !== '1.0.0') {
            throw new \RuntimeException('Interrupted rollback did not resume without SQL replay');
        }
        $committedPackage = $root . '/upgrade-committed.zip';
        $zip = new \ZipArchive();
        $zip->open($committedPackage, \ZipArchive::CREATE);
        foreach ([
            'info.ini' => "app = neutral-host\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = 2.0.0\nsupport = \">=0.1.0\"\n",
            'config.json' => '{"require":{"fixture/library":"^1.0"}}',
            'install.sql' => "CREATE TABLE neutral_host (id bigint);\n",
            'update.sql' => "SELECT 'committed update';\n",
            'uninstall.sql' => "DROP TABLE neutral_host;\n",
            'plugin/' . $app . '/config/app.php' => "<?php return ['version' => '2.0.0'];\n",
        ] as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();
        $committedInfo = $logic->uploadFromPath($committedPackage);
        \plugin\sandadmin\app\cache\UserMenuCache::$failOnce = true;
        try {
            $logic->install(false, 'UPGRADE neutral-host@1.0.0->2.0.0');
            throw new \RuntimeException('Post-COMMIT cache failure unexpectedly completed upgrade');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        $committedJournal = new HostPayloadLifecycleJournal(
            $payloadState, $app, 'upgrade', $committedInfo['package_sha256'],
        );
        $currentJournal = HostPayloadLifecycleJournal::inspect($payloadState, $app);
        if (($currentJournal['package_sha256'] ?? null) !== $committedInfo['package_sha256']) {
            throw new \RuntimeException('Committed lifecycle journal did not start');
        }
        if ($committedJournal->read()['phase'] !== 'sql_committed_deploy_pending'
            || $logic->getInstallState() !== InstallLogic::FAILED) {
            throw new \RuntimeException('Committed upgrade failure did not retain pending lifecycle state');
        }
        $committedInspection = $logic->inspectHostUpgradeRecovery();
        if ($committedInspection['actions'] !== ['continue-upgrade']
            || $committedInspection['runtime_phase'] !== 'complete'
            || $committedInspection['dependency_phase'] !== 'complete'
            || $committedInspection['file_phase'] !== 'complete') {
            throw new \RuntimeException('Committed upgrade inspection did not bind completed file phases');
        }
        $sqlBeforeContinue = Db::$sql;
        $status = $tester->execute([
            'action' => 'continue-host-upgrade', 'app' => $app,
            '--confirmation' => 'CONTINUE ' . $app . ' ' . $committedInspection['fingerprint'],
        ]);
        $continued = json_decode(trim($tester->getDisplay()), true);
        $manifest = json_decode(file_get_contents(base_path('composer.json')), true);
        if ($status !== 0
            || ($continued['state'] ?? null) !== InstallLogic::DEPENDENT_WAIT_INSTALL
            || ($continued['sql_replayed'] ?? null) !== false
            || ($continued['dependency_wait']['composer'] ?? null) !== true
            || ($manifest['require']['fixture/library'] ?? null) !== '^1.0'
            || Db::$sql !== $sqlBeforeContinue
            || $logic->getInstallState() !== InstallLogic::DEPENDENT_WAIT_INSTALL) {
            throw new \RuntimeException('Committed upgrade did not resume without replaying SQL or dependency edits');
        }
        $freshApp = 'neutral-fresh-dep';
        $freshCandidate = base_path('storage/sandpackage/' . $freshApp);
        $freshPath = 'config/neutral_fresh_dep_api.php';
        $freshBody = "<?php return ['enabled' => true];\n";
        hostInstallWrite($freshCandidate . '/' . $freshPath, $freshBody);
        hostInstallWrite($freshCandidate . '/host-payload.json', json_encode([
            'schema' => 1, 'app' => $freshApp,
            'files' => [['path' => $freshPath, 'sha256' => hash('sha256', $freshBody)]],
        ], JSON_THROW_ON_ERROR));
        hostInstallWrite($freshCandidate . '/config.json', '{"require":{"fixture/fresh":"^2.0"}}');
        hostInstallWrite($freshCandidate . '/install.sql', "CREATE TABLE neutral_fresh_dep (id bigint);\n");
        hostInstallWrite($freshCandidate . '/update.sql', "SELECT 'fresh update';\n");
        hostInstallWrite($freshCandidate . '/uninstall.sql', "DROP TABLE neutral_fresh_dep;\n");
        hostInstallWrite($freshCandidate . '/plugin/' . $freshApp . '/config/app.php',
            "<?php return ['version' => '1.0.0'];\n");
        Server::setIni($freshCandidate . '/', [
            'app' => $freshApp, 'title' => 'Fresh dependency', 'about' => 'Fixture',
            'author' => 'Test', 'version' => '1.0.0', 'support' => '">=0.1.0"', 'state' => InstallLogic::WAIT_INSTALL,
            'lifecycle_driver' => 'saipackage-pg-v1', 'package_sha256' => str_repeat('c', 64),
        ]);
        $freshLogic = new InstallLogic($freshApp);
        \plugin\sandadmin\app\cache\UserMenuCache::$failOnce = true;
        try {
            $freshLogic->install(false);
            throw new \RuntimeException('Post-COMMIT fresh dependency failure unexpectedly completed');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        $freshInspection = $freshLogic->inspectFreshInstallRecovery();
        if ($freshInspection['phase'] !== 'sql_committed_deploy_pending'
            || $freshInspection['actions'] !== ['continue-fresh']) {
            throw new \RuntimeException('Fresh dependency failure did not retain a safe continuation');
        }
        $freshSqlBeforeContinue = Db::$sql;
        $freshContinued = $freshLogic->recoverFreshInstall(
            'continue-fresh', $freshInspection['confirmation'], null, false,
        );
        $freshManifest = json_decode(file_get_contents(base_path('composer.json')), true);
        if (($freshContinued['sql_executed'] ?? null) !== false
            || Db::$sql !== $freshSqlBeforeContinue
            || ($freshManifest['require']['fixture/fresh'] ?? null) !== '^2.0'
            || $freshLogic->getInstallState() !== InstallLogic::DEPENDENT_WAIT_INSTALL) {
            throw new \RuntimeException('Fresh dependency recovery replayed SQL or lost the manifest update');
        }
        $cleanupApp = 'neutral-pre-dep';
        $cleanupCandidate = base_path('storage/sandpackage/' . $cleanupApp);
        $cleanupPath = 'config/neutral_pre_dep_api.php';
        $cleanupBody = "<?php return [];\n";
        hostInstallWrite($cleanupCandidate . '/' . $cleanupPath, $cleanupBody);
        hostInstallWrite($cleanupCandidate . '/host-payload.json', json_encode([
            'schema' => 1, 'app' => $cleanupApp,
            'files' => [['path' => $cleanupPath, 'sha256' => hash('sha256', $cleanupBody)]],
        ], JSON_THROW_ON_ERROR));
        hostInstallWrite($cleanupCandidate . '/config.json', '{"require":{"fixture/pre":"^3.0"}}');
        hostInstallWrite($cleanupCandidate . '/install.sql', "BROKEN SQL;\n");
        hostInstallWrite($cleanupCandidate . '/update.sql', "SELECT 'pre update';\n");
        hostInstallWrite($cleanupCandidate . '/uninstall.sql', "SELECT 'pre uninstall';\n");
        hostInstallWrite($cleanupCandidate . '/plugin/' . $cleanupApp . '/config/app.php',
            "<?php return ['version' => '1.0.0'];\n");
        Server::setIni($cleanupCandidate . '/', [
            'app' => $cleanupApp, 'title' => 'Pre dependency', 'about' => 'Fixture',
            'author' => 'Test', 'version' => '1.0.0', 'support' => '">=0.1.0"', 'state' => InstallLogic::WAIT_INSTALL,
            'lifecycle_driver' => 'saipackage-pg-v1', 'package_sha256' => str_repeat('d', 64),
        ]);
        $cleanupLogic = new InstallLogic($cleanupApp);
        $composerBeforeCleanup = file_get_contents(base_path('composer.json'));
        Db::$failNeedle = 'BROKEN SQL';
        try {
            $cleanupLogic->install(false);
            throw new \RuntimeException('Failed fresh SQL unexpectedly completed');
        } catch (\plugin\sandadmin\exception\ApiException) {
        } finally {
            Db::$failNeedle = '';
        }
        $cleanupInspection = $cleanupLogic->inspectFreshInstallRecovery();
        if ($cleanupInspection['phase'] !== 'sql_not_committed'
            || $cleanupInspection['actions'] !== ['cleanup-fresh']) {
            throw new \RuntimeException('Failed fresh SQL did not retain a bounded cleanup');
        }
        $cleaned = $cleanupLogic->recoverFreshInstall(
            'cleanup-fresh', $cleanupInspection['confirmation'], null, false,
        );
        if (($cleaned['phase'] ?? null) !== 'cleaned'
            || file_get_contents(base_path('composer.json')) !== $composerBeforeCleanup
            || is_dir($cleanupCandidate)) {
            throw new \RuntimeException('Fresh cleanup changed dependency manifests or left the candidate');
        }
        $sandAiZip = getenv('SANDAI_UNIFIED_CANDIDATE_ZIP');
        if (is_string($sandAiZip) && $sandAiZip !== '') {
            $sqlBeforeSandAiUpload = Db::$sql;
            $sandAiLogic = new InstallLogic('sand-ai');
            $sandAiInfo = $sandAiLogic->uploadFromPath($sandAiZip);
            if (($sandAiInfo['app'] ?? null) !== 'sand-ai'
                || ($sandAiInfo['state'] ?? null) !== InstallLogic::WAIT_INSTALL
                || Db::$sql !== $sqlBeforeSandAiUpload
                || !is_file(base_path('storage/sandpackage/sand-ai/host-payload.json'))
                || is_dir(base_path('plugin/sand-ai'))) {
                throw new \RuntimeException('Unified SandAI ZIP did not stage through the official upload path');
            }
            $sandAiInstalled = $sandAiLogic->install(false);
            if (($sandAiInstalled['state'] ?? null) !== InstallLogic::DEPENDENT_WAIT_INSTALL
                || $sandAiLogic->getInstallState() !== InstallLogic::DEPENDENT_WAIT_INSTALL
                || !is_file(base_path('app/Api/Controller/ChatController.php'))
                || !is_file(base_path('config/sand_ai_iam_context.php'))
                || !is_file(base_path('plugin/sand-ai/config/app.php'))) {
                throw new \RuntimeException('Unified SandAI ZIP did not deploy all surfaces through install');
            }
        }
        echo "Host payload recording lifecycle and failed-upgrade inspection passed\n";
    } finally {
        hostInstallDelete($root);
    }
}
