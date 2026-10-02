<?php

declare(strict_types=1);

namespace {
    $root = sys_get_temp_dir() . '/sandpackage-storage-' . bin2hex(random_bytes(6));
    mkdir($root . '/server/plugin', 0700, true);
    mkdir($root . '/runtime', 0700, true);
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadLifecycleJournal.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadOwnership.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadChangeFiles.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadRemovalFiles.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadUninstallFinalization.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadCandidateRollback.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PluginStorage.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';

    function pass(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo "[PASS] $message\n";
    }
    function reject(callable $call, string $message): void {
        try { $call(); } catch (\plugin\sandadmin\exception\ApiException) { echo "[PASS] $message\n"; return; }
        throw new RuntimeException($message);
    }
    function record(string $root, string $app, string $version = '1.0.0'): void {
        mkdir($root . '/' . $app, 0700, true);
        file_put_contents($root . '/' . $app . '/info.ini', "app=\"$app\"\nversion=\"$version\"\nstate=1\n");
    }

    if (($argv[1] ?? '') === '--windows-paths') {
        // Exercise Windows path parsing on any CI host; no Windows filesystem I/O is claimed.
        define('plugin\\sandpackage\\app\\service\\DIRECTORY_SEPARATOR', '\\');
        $storage = new \plugin\sandpackage\app\service\PluginStorage();
        $missing = 'sandpackage-missing-' . bin2hex(random_bytes(8));
        foreach ([
            'D:\\htdocs\\' . $missing,
            'D:/htdocs/' . $missing,
            'D:\\htdocs/' . $missing . '\\storage',
            '\\\\server\\share\\' . $missing,
            '//server/share/' . $missing,
            '\\\\server/share\\' . $missing,
        ] as $path) {
            pass($storage->hasData($path) === false, 'accept absent absolute Windows path: ' . $path);
        }
        foreach ([
            '', 'relative/storage', 'D:storage', '\\storage', '/storage',
            '\\\\server', '\\\\server\\', '\\\\.\\C:\\storage', '\\\\?\\C:\\storage',
            'D:\\htdocs\\..\\storage', 'D:/htdocs/./storage', 'D:/htdocs//storage',
            '\\\\server\\share\\..\\storage', '//server/../storage',
            "D:/storage\0/unsafe",
        ] as $path) {
            reject(fn () => $storage->hasData($path), 'reject unsafe or relative Windows path: ' . json_encode($path));
        }
        echo "Windows path parsing fixture passed (host filesystem semantics remain native).\n";
        exit(0);
    }

    $storage = new \plugin\sandpackage\app\service\PluginStorage();
    pass($storage->root() === base_path('storage/sandpackage'), 'empty roots select the new persistent storage root');
    mkdir(runtime_path('sandpackage/locks'), 0700, true);
    file_put_contents(runtime_path('sandpackage/locks/idle.lock'), '');
    pass($storage->root() === base_path('storage/sandpackage'), 'an idle empty legacy lock does not split storage roots');
    record(runtime_path('sandpackage'), 'legacy-sample');
    pass($storage->root() === runtime_path('sandpackage'), 'non-empty legacy root remains whole-root compatible');
    record(base_path('storage/sandpackage'), 'new-sample');
    reject(fn () => $storage->root(), 'two non-empty roots fail closed');
    unlink(base_path('storage/sandpackage/new-sample/info.ini'));
    rmdir(base_path('storage/sandpackage/new-sample'));
    record(base_path('plugin'), 'orphan-sample');
    $runtime = $storage->runtimePlugins();
    pass(($runtime['orphan-sample']['state'] ?? null) === 6, 'registered-shaped runtime plugin without registry is state 6');
    mkdir(base_path('plugin/composer-dependency'), 0700, true);
    pass(!isset($storage->runtimePlugins()['composer-dependency']), 'unknown dependency directory without metadata is not listed');
    file_put_contents(base_path('plugin/orphan-sample/info.ini'), 'app="different-app"');
    pass(($storage->runtimePlugins()['orphan-sample']['state'] ?? null) === 99, 'damaged runtime metadata remains visible and blocked');
    $migrationRuntime = $root . '/migration-runtime';
    $migrationServer = $root . '/migration-server';
    record($migrationRuntime . '/sandpackage', 'migration-sample');
    mkdir($migrationRuntime . '/sandpackage/locks', 0700, true);
    $migration = new \plugin\sandpackage\app\service\PluginStorage($migrationRuntime, $migrationServer);
    $lock = fopen($migrationRuntime . '/sandpackage/locks/live.lock', 'c+');
    flock($lock, LOCK_EX | LOCK_NB);
    reject(fn () => $migration->migrate(), 'active legacy lock rejects migration inspection');
    flock($lock, LOCK_UN); fclose($lock);
    mkdir($migrationRuntime . '/sandpackage/fresh-recovery', 0700, true);
    file_put_contents($migrationRuntime . '/sandpackage/fresh-recovery/pending.json', '{}');
    reject(fn () => $migration->migrate(), 'nonterminal recovery directory rejects migration');
    unlink($migrationRuntime . '/sandpackage/fresh-recovery/pending.json');
    rmdir($migrationRuntime . '/sandpackage/fresh-recovery');
    $before = file_get_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini');
    $check = $migration->migrate();
    pass(!$check['migrated'] && !is_dir($migrationServer), 'dry-run does not create destination or change registry');
    file_put_contents($migrationRuntime . '/sandpackage/metadata.json', json_encode(['archive' => $migrationRuntime . '/sandpackage/archive']));
    reject(fn () => $migration->migrate(true), 'escaped JSON absolute old-root reference rejects relocation');
    pass(file_get_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini') === $before, 'rejected migration preserves registration bytes');
    unlink($migrationRuntime . '/sandpackage/metadata.json');
    file_put_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini', "app=\"migration-sample\"\nversion=\"1.0.0\"\nstate=2\n");
    reject(fn () => $migration->migrate(true), 'unfinished candidate rejects relocation');
    file_put_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini', $before);
    foreach (['state' => '1.5', 'registration_candidate' => '1', 'dependency_command_nonce' => '"unfinished"', 'failed_upgrade' => '1', 'operation_pending' => '1'] as $field => $value) {
        $invalid = $field === 'state' ? str_replace('state=1', 'state=' . $value, $before) : $before . $field . '=' . $value . "\n";
        file_put_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini', $invalid);
        reject(fn () => $migration->migrate(true), "$field unfinished or invalid metadata rejects migration");
        pass(file_get_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini') === $invalid, "$field rejected migration retains exact original record");
    }
    file_put_contents($migrationRuntime . '/sandpackage/migration-sample/info.ini', $before);
    mkdir($migrationRuntime . '/sandpackage/fresh-recovery');
    $audit = json_encode(['format' => 1, 'app' => 'migration-sample', 'phase' => 'complete', 'operation' => str_repeat('a', 32), 'events' => [], 'binding' => ['host' => $migrationServer]]);
    file_put_contents($migrationRuntime . '/sandpackage/fresh-recovery/migration-sample.json', $audit);
    pass($migration->migrate()['migrated'] === false, 'valid completed fresh-install audit does not block migration check');
    $migrated = $migration->migrate(true);
    pass(file_get_contents($migrationServer . '/storage/sandpackage/fresh-recovery/migration-sample.json') === $audit, 'completed audit preserved byte-for-byte during migration');
    pass($migrated['migrated'] && !file_exists($migrationRuntime . '/sandpackage'), 'explicit apply atomically relocates the complete old root');
    pass(file_get_contents($migrationServer . '/storage/sandpackage/migration-sample/info.ini') === $before, 'successful relocation preserves registration bytes');
    rmdir($migrationRuntime);
    pass(isset($migration->managedRecords()['migration-sample']), 'persistent registration survives runtime directory removal');
    pass(!isset($migration->managedRecords()['locks']), 'internal locks directory is not a plugin');
    symlink($migrationServer . '/storage/sandpackage', $root . '/linked-root');
    $linked = new \plugin\sandpackage\app\service\PluginStorage($root, $root . '/linked-root/../fake');
    reject(fn () => $linked->root(), 'traversal in storage root is rejected');
    $linkServer = $root . '/link-server';
    mkdir($linkServer, 0700);
    symlink($migrationServer . '/storage', $linkServer . '/storage');
    reject(fn () => (new \plugin\sandpackage\app\service\PluginStorage($root . '/absent', $linkServer))->root(), 'symlink parent cannot redirect persistent storage');
    record(base_path('plugin'), 'unsafe-sample');
    unlink(base_path('plugin/unsafe-sample/info.ini'));
    symlink($migrationServer . '/storage/sandpackage/migration-sample/info.ini', base_path('plugin/unsafe-sample/info.ini'));
    pass(($storage->runtimePlugins()['unsafe-sample']['state'] ?? null) === 99, 'symlink metadata is diagnosed without following it');
    mkdir(base_path('plugin/sandadmin'), 0700, true);
    file_put_contents(base_path('plugin/sandadmin/info.ini'), 'app="sandadmin"');
    pass(!isset($storage->runtimePlugins()['sandadmin']), 'host core is excluded from business inventory');
    mkdir(runtime_path('sandpackage/cleanup'), 0700, true);
    $pendingPath = runtime_path('sandpackage/cleanup/orphan-cleanup.json');
    file_put_contents($pendingPath, json_encode(['app' => 'orphan-cleanup', 'phase' => 'files_pending']));
    pass(isset($storage->managedRecords()['orphan-cleanup']), 'cleanup remains discoverable after candidate was archived');
    pass((new \plugin\sandpackage\app\logic\InstallLogic('orphan-cleanup'))->getInstallState() === 8, 'pending cleanup blocks ordinary reinstall even without candidate');
    pass(!isset($storage->managedRecords()['cleanup']), 'cleanup journal directory is not a plugin');
    file_put_contents($pendingPath, json_encode(['app' => 'orphan-cleanup', 'phase' => 'cleaned']));
    pass(!isset($storage->managedRecords()['orphan-cleanup']), 'finished cleanup no longer appears in plugin inventory');
    pass((new \plugin\sandpackage\app\logic\InstallLogic('orphan-cleanup'))->getInstallState() === 0, 'finished cleanup permits uninstalled state');
    $hostPayloadDirectory = runtime_path('sandpackage/host-payload');
    $journal = new \plugin\sandpackage\app\service\HostPayloadLifecycleJournal(
        $hostPayloadDirectory, 'orphan-host-files', 'uninstall', str_repeat('a', 64),
    );
    $journal->begin();
    $journal->observe('sql_committed_deploy_pending');
    $orphanPath = 'app/Api/Neutral/Orphan.php';
    $orphanBody = "<?php // orphan\n";
    mkdir(dirname(base_path($orphanPath)), 0700, true);
    file_put_contents(base_path($orphanPath), $orphanBody);
    $orphanOwned = [['path' => $orphanPath, 'sha256' => hash('sha256', $orphanBody)]];
    file_put_contents($hostPayloadDirectory . '/orphan-host-files.owned.json', json_encode(
        ['schema' => 1, 'app' => 'orphan-host-files', 'files' => $orphanOwned], JSON_THROW_ON_ERROR,
    ));
    $orphanChange = new \plugin\sandpackage\app\service\HostPayloadChangeFiles(
        base_path(), $hostPayloadDirectory, runtime_path('sandpackage/orphan-host-files'),
        'orphan-host-files', [],
    );
    $orphanChange->begin();
    $orphanChange->apply();
    pass(($storage->managedRecords()['orphan-host-files']['state'] ?? null) === 8,
        'unfinished host-file uninstall remains visible after candidate deletion');
    pass((new \plugin\sandpackage\app\logic\InstallLogic('orphan-host-files'))->getInstallState() === 8,
        'unfinished host-file uninstall blocks ordinary reinstall without candidate');
    $inspection = (new \plugin\sandpackage\app\logic\InstallLogic('orphan-host-files'))->inspectHostUninstallRecovery();
    pass($inspection['sql_phase'] === 'sql_committed_deploy_pending'
        && $inspection['candidate_present'] === false
        && $inspection['actions'] === [],
        'orphaned host-file uninstall has a read-only fingerprint without inventing recovery actions');
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/command/Recover.php';
    $tester = new \Symfony\Component\Console\Tester\CommandTester(
        new \plugin\sandpackage\app\command\Recover(),
    );
    $status = $tester->execute(['action' => 'inspect-host-uninstall', 'app' => 'orphan-host-files']);
    $cliInspection = json_decode(trim($tester->getDisplay()), true);
    pass($status === 0 && ($cliInspection['fingerprint'] ?? null) === $inspection['fingerprint'],
        'official recovery CLI exposes the orphaned uninstall inspection');
    pass(!isset($storage->managedRecords()['host-payload']), 'host-payload journal directory is not a plugin');
    file_put_contents(base_path('storage/sandpackage/unknown.lock'), 'not a disposable lock');
    reject(fn () => $storage->root(), 'unknown non-empty lock file counts as data and detects dual roots');
    echo "Plugin storage fixture passed (temporary filesystem only; no existing host data or database changed).\n";
}
