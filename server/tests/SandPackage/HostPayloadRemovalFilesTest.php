<?php

declare(strict_types=1);

require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadRemovalFiles.php';

use plugin\sandpackage\app\service\HostPayloadRemovalFiles;

$root = sys_get_temp_dir() . '/sandpackage-removal-' . bin2hex(random_bytes(8));
$runtime = [$root . '/plugin/neutral-host', $root . '/frontend/neutral-host'];
$candidate = $root . '/storage/neutral-host';
$state = $root . '/storage/host-payload';

function removalWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $contents);
}
function removalDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) removalDelete($entry->getPathname());
    rmdir($path);
}

try {
    removalWrite($runtime[0] . '/config/app.php', 'backend');
    removalWrite($runtime[0] . '/app/service.php', 'service');
    removalWrite($runtime[1] . '/index.vue', 'frontend');
    removalWrite($candidate . '/info.ini', 'candidate');
    removalWrite($candidate . '/plugin/neutral-host/config/app.php', 'candidate-backend');
    $removal = new HostPayloadRemovalFiles($state, 'neutral-host', $runtime, $candidate);
    $removal->begin();
    unlink($runtime[0] . '/app/service.php');
    if ($removal->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Partial removal was not inspectable');
    }
    removalWrite($runtime[0] . '/config/app.php', 'foreign');
    try {
        $removal->inspectSnapshot();
        throw new RuntimeException('Modified runtime file was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Modified runtime file was accepted') throw $error;
    }
    removalWrite($runtime[0] . '/config/app.php', 'backend');
    removalWrite($runtime[1] . '/extra.vue', 'foreign');
    try {
        $removal->applyRuntime();
        throw new RuntimeException('Unknown runtime file was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Unknown runtime file was accepted') throw $error;
    }
    unlink($runtime[1] . '/extra.vue');
    $removal->applyRuntime();
    if (is_dir($runtime[0]) || is_dir($runtime[1])) {
        throw new RuntimeException('Runtime removal was incomplete');
    }
    $removal->applyCandidate();
    $removal->complete();
    if (is_dir($candidate) || $removal->inspectSnapshot()['phase'] !== 'complete') {
        throw new RuntimeException('Candidate removal was incomplete');
    }
    $removal->archiveFinished();
    if (is_file($state . '/neutral-host.removal.json')
        || count(glob($state . '/neutral-host.removal.json.*.history') ?: []) !== 1) {
        throw new RuntimeException('Completed removal audit was not archived');
    }
    removalWrite($runtime[0] . '/config/app.php', 'backend-again');
    removalWrite($candidate . '/info.ini', 'candidate-again');
    $removal->begin();
    if (!$removal->inspectSnapshot()['unchanged']) {
        throw new RuntimeException('New uninstall did not retain original files');
    }
    $removal->cancel();
    if ($removal->inspectSnapshot()['phase'] !== 'cancelled'
        || !$removal->inspectSnapshot()['unchanged']) {
        throw new RuntimeException('Cancelled uninstall did not retain original files');
    }
    $removal->archiveFinished();
    echo "Host payload runtime and candidate removal snapshot passed\n";
} finally {
    removalDelete($root);
}
