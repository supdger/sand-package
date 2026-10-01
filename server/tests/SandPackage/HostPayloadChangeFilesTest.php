<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadOwnership.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadChangeFiles.php';

use plugin\sandpackage\app\service\HostPayloadChangeFiles;
use plugin\sandpackage\app\service\HostPayloadOwnership;

function changeWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $contents);
}

function changeDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) changeDelete($entry->getPathname());
    rmdir($path);
}

function changeReject(callable $run, string $message): void
{
    try { $run(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$root = sys_get_temp_dir() . '/sandpackage-host-change-' . bin2hex(random_bytes(8));
$host = $root . '/host';
$state = $root . '/state';
$candidate = $root . '/candidate';
$appPath = 'app/Api/Neutral/Probe.php';
$configPath = 'config/neutral_sample_api.php';
$oldBody = "<?php // old\n";
$newBody = "<?php // new\n";
$old = [['path' => $appPath, 'sha256' => hash('sha256', $oldBody)]];
$next = [
    ['path' => $appPath, 'sha256' => hash('sha256', $newBody)],
    ['path' => $configPath, 'sha256' => hash('sha256', "<?php return [];\n")],
];
mkdir($host, 0700, true);
mkdir($candidate, 0700, true);
changeWrite($host . '/' . $appPath, $oldBody);
changeWrite($state . '/neutral-sample.owned.json', json_encode(
    ['schema' => 1, 'app' => 'neutral-sample', 'files' => $old],
    JSON_THROW_ON_ERROR,
));
changeWrite($candidate . '/' . $appPath, $newBody);
changeWrite($candidate . '/' . $configPath, "<?php return [];\n");
try {
    $change = new HostPayloadChangeFiles($host, $state, $candidate, 'neutral-sample', $next);
    $plan = $change->begin();
    if ($plan !== ['add' => [$configPath], 'replace' => [$appPath], 'remove' => []]) {
        throw new RuntimeException('Upgrade plan did not bind old and new ownership');
    }
    if ($change->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Pending file transaction was not inspectable');
    }
    $backupFile = (glob($state . '/neutral-sample.backup.*') ?: [])[0] . '/' . $appPath;
    changeWrite($backupFile, 'backup drift');
    changeReject(static fn () => $change->inspectSnapshot(), 'backup drift');
    changeWrite($backupFile, $oldBody);
    changeWrite($host . '/' . $appPath, $newBody);
    if ($change->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Partially applied file transaction was not inspectable');
    }
    $change->apply();
    $change->apply();
    if ($change->inspectSnapshot()['phase'] !== 'complete') {
        throw new RuntimeException('Completed file transaction was not inspectable');
    }
    if (file_get_contents($host . '/' . $appPath) !== $newBody
        || HostPayloadOwnership::read($state, 'neutral-sample') !== $next) {
        throw new RuntimeException('Upgrade did not publish new files and ownership');
    }
    changeWrite($host . '/' . $appPath, 'foreign modification');
    changeReject(static fn () => $change->inspectSnapshot(), 'inspector must reject foreign modification');
    changeReject(static fn () => $change->apply(), 'upgrade result drift');
    changeReject(static fn () => $change->restore(), 'restore must reject foreign modification');
    changeWrite($host . '/' . $appPath, $newBody);
    $change->restore();
    $change->restore();
    if ($change->inspectSnapshot()['phase'] !== 'rolled_back') {
        throw new RuntimeException('Rolled-back file transaction was not inspectable');
    }
    if (file_get_contents($host . '/' . $appPath) !== $oldBody
        || file_exists($host . '/' . $configPath)
        || HostPayloadOwnership::read($state, 'neutral-sample') !== $old) {
        throw new RuntimeException('Restore did not recover exact old files and ownership');
    }
    $retry = new HostPayloadChangeFiles($host, $state, $candidate, 'neutral-sample', $next);
    $retry->begin();
    $retry->apply();
    if (HostPayloadOwnership::read($state, 'neutral-sample') !== $next) {
        throw new RuntimeException('Recovered upgrade could not start a new audited change');
    }
    $afterUpgrade = new HostPayloadChangeFiles($host, $state, $candidate, 'neutral-sample', []);
    $afterUpgrade->begin();
    $afterUpgrade->apply();
    if (HostPayloadOwnership::read($state, 'neutral-sample') !== null
        || file_exists($host . '/' . $appPath) || file_exists($host . '/' . $configPath)
        || count(glob($state . '/neutral-sample.change.json.*.history') ?: []) !== 2) {
        throw new RuntimeException('Completed change did not permit audited removal');
    }

    $removeHost = $root . '/remove-host';
    $removeState = $root . '/remove-state';
    mkdir($removeHost, 0700);
    changeWrite($removeHost . '/' . $appPath, $oldBody);
    changeWrite($removeState . '/neutral-sample.owned.json', json_encode(
        ['schema' => 1, 'app' => 'neutral-sample', 'files' => $old],
        JSON_THROW_ON_ERROR,
    ));
    $remove = new HostPayloadChangeFiles($removeHost, $removeState, $candidate, 'neutral-sample', []);
    if ($remove->begin() !== ['add' => [], 'replace' => [], 'remove' => [$appPath]]) {
        throw new RuntimeException('Uninstall plan did not identify owned file');
    }
    $remove->apply();
    if ($remove->inspectSnapshot()['phase'] !== 'complete') {
        throw new RuntimeException('Removed file transaction was not inspectable');
    }
    if (file_exists($removeHost . '/' . $appPath)
        || HostPayloadOwnership::read($removeState, 'neutral-sample') !== null) {
        throw new RuntimeException('Uninstall file phase left owned file or record');
    }
    $remove->restore();
    if (file_get_contents($removeHost . '/' . $appPath) !== $oldBody
        || HostPayloadOwnership::read($removeState, 'neutral-sample') !== $old) {
        throw new RuntimeException('Uninstall file phase could not restore old ownership');
    }
    echo "Host payload upgrade and remove file phases passed\n";
} finally {
    changeDelete($root);
}
