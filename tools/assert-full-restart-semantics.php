<?php

declare(strict_types=1);

/**
 * Offline assertions: reload ≠ full_restart; process fingerprint triggers.
 * Run: php tools/assert-full-restart-semantics.php
 */

$root = dirname(__DIR__);
require_once $root . '/server/plugin/sandpackage/app/service/PluginProcessFingerprint.php';

use plugin\sandpackage\app\service\PluginProcessFingerprint;

function assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: $message\n");
}

$tmp = sys_get_temp_dir() . '/sandpackage-full-restart-' . bin2hex(random_bytes(4));
mkdir($tmp . '/empty/config', 0777, true);
mkdir($tmp . '/ai/config', 0777, true);
mkdir($tmp . '/ai2/config', 0777, true);

file_put_contents($tmp . '/empty/config/process.php', "<?php\nreturn [];\n");
file_put_contents($tmp . '/ai/config/process.php', <<<'PHP'
<?php
return [
    'sand_ai_task_worker' => [
        'handler' => 'app\\process\\TaskWorker',
        'count' => 1,
        'reloadable' => false,
    ],
];
PHP);
file_put_contents($tmp . '/ai2/config/process.php', <<<'PHP'
<?php
return [
    'sand_ai_task_worker' => [
        'handler' => 'app\\process\\TaskWorker',
        'count' => 2,
        'reloadable' => false,
    ],
];
PHP);

$empty = PluginProcessFingerprint::configPath($tmp . '/empty');
$ai = PluginProcessFingerprint::configPath($tmp . '/ai');
$ai2 = PluginProcessFingerprint::configPath($tmp . '/ai2');

assert_true(!PluginProcessFingerprint::declaresCustomProcesses($empty), 'empty process.php declares nothing');
assert_true(PluginProcessFingerprint::declaresCustomProcesses($ai), 'SandAI-like process.php declares custom processes');
assert_true(PluginProcessFingerprint::requiresFullRestart(false, $ai, null), 'fresh install with process.php requires full_restart');
assert_true(!PluginProcessFingerprint::requiresFullRestart(false, $empty, null), 'fresh install without processes stays reload');
assert_true(!PluginProcessFingerprint::requiresFullRestart(true, $ai, $ai), 'unchanged process fingerprint allows reload');
assert_true(PluginProcessFingerprint::requiresFullRestart(true, $ai2, $ai), 'changed count fingerprint requires full_restart');
assert_true(PluginProcessFingerprint::fingerprint($ai) !== PluginProcessFingerprint::fingerprint($ai2), 'fingerprints differ when count changes');
assert_true(PluginProcessFingerprint::processNames($ai) === ['sand_ai_task_worker'], 'processNames extracts worker name');

$server = file_get_contents($root . '/server/compat/Saithink/Saipackage/service/Server.php');
assert_true(str_contains($server, 'public static function reload(): bool'), 'Server::reload exists');
assert_true(str_contains($server, 'public static function fullRestart('), 'Server::fullRestart exists');
assert_true(str_contains($server, 'return self::reload();'), 'Server::restart aliases reload (legacy name)');
assert_true(str_contains($server, 'SIGUSR1'), 'reload path still uses SIGUSR1');
assert_true(str_contains($server, 'full-restart-worker.php') || str_contains(
    file_get_contents($root . '/server/plugin/sandpackage/tools/system-update-worker.php'),
    'fullRestartUnixHost',
), 'full restart runtime helper present');

$worker = file_get_contents($root . '/server/plugin/sandpackage/tools/system-update-worker.php');
assert_true(str_contains($worker, 'function reloadUnixHost'), 'reloadUnixHost kept for SIGUSR1');
assert_true(str_contains($worker, 'function fullRestartUnixHost'), 'fullRestartUnixHost is separate from reload');
assert_true(str_contains($worker, "restart', '-d'"), 'full restart invokes php start.php restart -d');

$logic = file_get_contents($root . '/server/plugin/sandpackage/app/logic/InstallLogic.php');
assert_true(str_contains($logic, 'process_registration_pending'), 'install ledger records process_registration_pending');
assert_true(str_contains($logic, 'dependency_service_action'), 'install ledger records dependency_service_action');
assert_true(!str_contains($logic, 'Server::restart('), 'InstallLogic no longer calls ambiguous Server::restart()');

$app = include $root . '/server/plugin/sandpackage/config/app.php';
assert_true(($app['version'] ?? null) === '0.2.23', 'version bumped to 0.2.23');

// cleanup
foreach ([$empty, $ai, $ai2] as $file) {
    @unlink($file);
}
@rmdir($tmp . '/empty/config');
@rmdir($tmp . '/empty');
@rmdir($tmp . '/ai/config');
@rmdir($tmp . '/ai');
@rmdir($tmp . '/ai2/config');
@rmdir($tmp . '/ai2');
@rmdir($tmp);

fwrite(STDOUT, "All full_restart semantics assertions passed.\n");
