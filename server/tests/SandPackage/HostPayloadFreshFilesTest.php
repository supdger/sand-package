<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadFreshFiles.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadOwnership.php';

use plugin\sandpackage\app\service\HostPayloadFreshFiles;
use plugin\sandpackage\app\service\HostPayloadOwnership;

function freshWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $contents);
}

function freshReject(callable $run, string $message): void
{
    try {
        $run();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

function freshRemove(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) {
        freshRemove($entry->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/sandpackage-host-fresh-' . bin2hex(random_bytes(8));
$host = $root . '/host';
$state = $root . '/state';
$candidate = $root . '/candidate';
mkdir($host, 0700, true);
mkdir($candidate, 0700, true);
$appPath = 'app/Api/Neutral/Probe.php';
$configPath = 'config/neutral_sample_api.php';
$contents = [$appPath => "<?php // service\n", $configPath => "<?php return [];\n"];
$files = [];
foreach ($contents as $path => $content) {
    freshWrite($candidate . '/' . $path, $content);
    $files[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
}
try {
    $fresh = new HostPayloadFreshFiles($host, $state, $candidate, 'neutral-sample', $files);
    $fresh->preflight();
    $fresh->begin();
    if ($fresh->snapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Fresh deployment pending state is not inspectable');
    }
    freshWrite($host . '/' . $appPath, $contents[$appPath]);
    $fresh->apply();
    $fresh->apply();
    $fresh->verifyInstalled();
    foreach ($contents as $path => $content) {
        if (file_get_contents($host . '/' . $path) !== $content) {
            throw new RuntimeException('Fresh deployment did not publish exact host file');
        }
    }
    if (!is_file($state . '/neutral-sample.owned.json')) {
        throw new RuntimeException('Fresh deployment did not publish ownership record');
    }
    if (HostPayloadOwnership::read($state, 'neutral-sample') !== $files) {
        throw new RuntimeException('Fresh ownership record did not retain exact file hashes');
    }
    freshWrite($host . '/' . $appPath, 'foreign modification');
    freshReject(static fn () => $fresh->apply(), 'installed file drift');
    freshWrite($state . '/neutral-sample.owned.json', '{"schema":1,"app":"another-plugin","files":[]}');
    freshReject(static fn () => HostPayloadOwnership::read($state, 'neutral-sample'), 'foreign ownership record');

    $otherHost = $root . '/other-host';
    $otherState = $root . '/other-state';
    mkdir($otherHost, 0700);
    $partial = new HostPayloadFreshFiles($otherHost, $otherState, $candidate, 'neutral-sample', $files);
    $partial->begin();
    freshWrite($otherHost . '/' . $appPath, $contents[$appPath]);
    freshWrite($otherState . '/neutral-sample.owned.json', json_encode(
        ['schema' => 1, 'app' => 'neutral-sample', 'files' => $files],
        JSON_THROW_ON_ERROR,
    ));
    $partial->cleanup();
    if (file_exists($otherHost . '/' . $appPath) || file_exists($otherState . '/neutral-sample.owned.json')) {
        throw new RuntimeException('Cleaned pending deployment left an owned file or record');
    }
    $partial->begin();
    if ($partial->snapshot()['phase'] !== 'pending'
        || count(glob($otherState . '/neutral-sample.fresh.json.*.history') ?: []) !== 1) {
        throw new RuntimeException('Cleaned deployment did not allow a new audited attempt');
    }
    freshReject(static fn () => $partial->begin(), 'pending host deployment cannot be replaced');
    $emptyHost = $root . '/empty-host';
    mkdir($emptyHost, 0700);
    $empty = new HostPayloadFreshFiles($emptyHost, $root . '/empty-state', $candidate, 'neutral-sample', $files);
    $empty->cleanupIfStarted();
    $conflictHost = $root . '/conflict-host';
    mkdir($conflictHost, 0700);
    freshWrite($conflictHost . '/' . $appPath, 'foreign');
    $conflict = new HostPayloadFreshFiles($conflictHost, $root . '/conflict-state', $candidate, 'neutral-sample', $files);
    freshReject(static fn () => $conflict->begin(), 'fresh target collision');
    echo "Host payload fresh file deployment passed\n";
} finally {
    freshRemove($root);
}
