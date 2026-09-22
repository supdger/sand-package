<?php

declare(strict_types=1);

namespace {
    $root = sys_get_temp_dir() . '/sandpackage-storage-' . bin2hex(random_bytes(6));
    mkdir($root . '/server/plugin', 0700, true);
    mkdir($root . '/runtime', 0700, true);
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PluginStorage.php';

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
    file_put_contents(base_path('storage/sandpackage/unknown.lock'), 'not a disposable lock');
    reject(fn () => $storage->root(), 'unknown non-empty lock file counts as data and detects dual roots');
    echo "Plugin storage fixture passed (temporary filesystem only; no existing host data or database changed).\n";
}
