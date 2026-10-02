<?php

declare(strict_types=1);

require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadRuntimeChangeFiles.php';

use plugin\sandpackage\app\service\FreshInstallRecovery;
use plugin\sandpackage\app\service\HostPayloadRuntimeChangeFiles;

function runtimeWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $contents);
}

function runtimeDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) runtimeDelete($entry->getPathname());
    rmdir($path);
}

$root = sys_get_temp_dir() . '/sandpackage-runtime-change-' . bin2hex(random_bytes(8));
$source = $root . '/candidate/plugin/neutral-host';
$target = $root . '/host/plugin/neutral-host';
$frontendSource = $root . '/candidate/frontend/neutral-host';
$frontendTarget = $root . '/host/frontend/neutral-host';
$state = $root . '/state';
runtimeWrite($target . '/old-only.php', "<?php // retained\n");
runtimeWrite($target . '/common.php', "<?php // old\n");
runtimeWrite($target . '/config/app.php', "<?php return ['version' => '1.0.0'];\n");
runtimeWrite($source . '/common.php', "<?php // new\n");
runtimeWrite($source . '/config/app.php', "<?php return ['version' => '2.0.0'];\n");
runtimeWrite($source . '/new.php', "<?php // added\n");
runtimeWrite($frontendSource . '/index.vue', '<template>new</template>');
$paths = [$source => $target, $frontendSource => $frontendTarget];
$before = [];
foreach ($paths as $destination) $before[$destination] = FreshInstallRecovery::tree($destination);
$beforeHash = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
try {
    $change = new HostPayloadRuntimeChangeFiles($state, 'neutral-host', $paths, $beforeHash);
    $change->begin();
    if ($change->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Runtime backup was not inspectable before deployment');
    }
    $record = json_decode(file_get_contents($state . '/neutral-host.runtime.json'), true, 32, JSON_THROW_ON_ERROR);
    $backupFile = $state . '/neutral-host.runtime-backup-' . $record['id'] . '/target-1/common.php';
    runtimeWrite($backupFile, "<?php // foreign\n");
    try {
        $change->inspectSnapshot();
        throw new RuntimeException('Changed runtime backup was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Changed runtime backup was accepted') throw $error;
    }
    runtimeWrite($backupFile, "<?php // old\n");
    runtimeWrite($source . '/new.php', "<?php // foreign\n");
    try {
        $change->inspectSnapshot();
        throw new RuntimeException('Changed runtime candidate was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Changed runtime candidate was accepted') throw $error;
    }
    runtimeWrite($source . '/new.php', "<?php // added\n");
    runtimeWrite($target . '/common.php', "<?php // new\n");
    runtimeWrite($target . '/new.php', "<?php // added\n");
    if ($change->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Partial atomic deployment was not inspectable');
    }
    $change->apply();
    $change->apply();
    if ($change->inspectSnapshot()['phase'] !== 'complete'
        || file_get_contents($target . '/old-only.php') !== "<?php // retained\n"
        || file_get_contents($target . '/config/app.php') !== "<?php return ['version' => '2.0.0'];\n"
        || file_get_contents($frontendTarget . '/index.vue') !== '<template>new</template>') {
        throw new RuntimeException('Runtime deployment did not converge and retain old-only files');
    }
    runtimeWrite($target . '/new.php', "<?php // foreign\n");
    try {
        $change->inspectSnapshot();
        throw new RuntimeException('Runtime drift was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Runtime drift was accepted') throw $error;
    }
    $attachSource = $root . '/attach-candidate/plugin/attached';
    $attachTarget = $root . '/attach-host/plugin/attached';
    runtimeWrite($attachSource . '/first.php', "<?php // first\n");
    runtimeWrite($attachSource . '/second.php', "<?php // second\n");
    $attachPaths = [$attachSource => $attachTarget];
    $attachBefore = [$attachTarget => null];
    $attachChange = new HostPayloadRuntimeChangeFiles(
        $root . '/attach-state', 'attached', $attachPaths,
        hash('sha256', json_encode($attachBefore, JSON_THROW_ON_ERROR)),
    );
    $attachChange->begin();
    runtimeWrite($attachTarget . '/first.php', "<?php // first\n");
    if ($attachChange->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Partial existing-schema attachment was not inspectable');
    }
    $attachChange->apply();
    if ($attachChange->inspectSnapshot()['phase'] !== 'complete'
        || file_get_contents($attachTarget . '/second.php') !== "<?php // second\n") {
        throw new RuntimeException('Existing-schema attachment did not resume remaining files');
    }
    echo "Host payload runtime atomic change and snapshot passed\n";
} finally {
    runtimeDelete($root);
}
