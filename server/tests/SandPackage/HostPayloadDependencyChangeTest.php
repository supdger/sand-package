<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadDependencyChange.php';

use plugin\sandpackage\app\service\HostPayloadDependencyChange;

function dependencyWrite(string $path, string $body): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $body);
}

function dependencyDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) dependencyDelete($entry->getPathname());
    rmdir($path);
}

function dependencyJson(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

$root = sys_get_temp_dir() . '/sandpackage-dependency-change-' . bin2hex(random_bytes(8));
$candidate = $root . '/candidate';
$composer = $root . '/host/server/composer.json';
$package = $root . '/host/frontend/package.json';
$state = $root . '/state';
$config = [
    'require' => ['fixture/lib' => '^1.2'],
    'dependencies' => ['fixture-ui' => '^2.0'],
    'composerConfig' => ['allow-plugins' => ['fixture/lib' => true]],
];
$oldComposer = ['name' => 'fixture/host', 'require' => ['php' => '^8.4'], 'config' => ['sort-packages' => true]];
$oldPackage = ['name' => 'fixture-host', 'dependencies' => ['vue' => '^3']];
dependencyWrite($candidate . '/config.json', dependencyJson($config));
dependencyWrite($composer, dependencyJson($oldComposer));
dependencyWrite($package, dependencyJson($oldPackage));
try {
    $change = new HostPayloadDependencyChange($state, 'neutral-host', $candidate, $composer, $package);
    $change->begin();
    if ($change->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Dependency manifest backup was not inspectable');
    }
    dependencyWrite($candidate . '/config.json', dependencyJson(['require' => ['fixture/lib' => '^9']]));
    try {
        $change->inspectSnapshot();
        throw new RuntimeException('Changed dependency declaration was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Changed dependency declaration was accepted') throw $error;
    }
    dependencyWrite($candidate . '/config.json', dependencyJson($config));
    $record = json_decode(file_get_contents($state . '/neutral-host.dependencies.json'), true, 32, JSON_THROW_ON_ERROR);
    $backupComposer = $state . '/neutral-host.dependency-backup-' . $record['id'] . '/composer.json';
    dependencyWrite($backupComposer, 'foreign backup');
    try {
        $change->inspectSnapshot();
        throw new RuntimeException('Changed dependency backup was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Changed dependency backup was accepted') throw $error;
    }
    dependencyWrite($backupComposer, dependencyJson($oldComposer));
    $newComposer = $oldComposer;
    $newComposer['require']['fixture/lib'] = '^1.2';
    $newComposer['config']['allow-plugins']['fixture/lib'] = true;
    dependencyWrite($composer, dependencyJson($newComposer));
    if ($change->inspectSnapshot()['phase'] !== 'pending') {
        throw new RuntimeException('Partial manifest publication was not inspectable');
    }
    $result = $change->apply();
    if ($result !== ['composer' => true, 'npm' => true]
        || $change->inspectSnapshot()['phase'] !== 'complete'
        || json_decode(file_get_contents($package), true)['dependencies']['fixture-ui'] !== '^2.0') {
        throw new RuntimeException('Dependency manifest publication did not converge');
    }
    dependencyWrite($composer, dependencyJson($oldComposer));
    try {
        $change->inspectSnapshot();
        throw new RuntimeException('Completed manifest drift was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Completed manifest drift was accepted') throw $error;
    }
    dependencyWrite($composer, dependencyJson($newComposer));
    $change->archiveFinished();
    if (is_file($state . '/neutral-host.dependencies.json')
        || count(glob($state . '/neutral-host.dependencies.json.*.history') ?: []) !== 1) {
        throw new RuntimeException('Completed manifest audit was not archived');
    }

    $rollbackState = $root . '/rollback-state';
    dependencyWrite($composer, dependencyJson($oldComposer));
    dependencyWrite($package, dependencyJson($oldPackage));
    $rollback = new HostPayloadDependencyChange($rollbackState, 'neutral-host', $candidate, $composer, $package);
    $rollback->begin();
    $rollback->markRolledBack();
    if ($rollback->inspectSnapshot()['phase'] !== 'rolled_back') {
        throw new RuntimeException('Unapplied dependency change was not marked rolled back');
    }
    $rollback->archiveFinished();

    $restoreState = $root . '/restore-state';
    dependencyWrite($composer, dependencyJson($oldComposer));
    dependencyWrite($package, dependencyJson($oldPackage));
    $restore = new HostPayloadDependencyChange($restoreState, 'neutral-host', $candidate, $composer, $package);
    $restore->begin();
    dependencyWrite($composer, dependencyJson($newComposer));
    $restore->restore();
    if ($restore->inspectSnapshot()['phase'] !== 'rolled_back'
        || file_get_contents($composer) !== dependencyJson($oldComposer)) {
        throw new RuntimeException('Partially published dependency manifests were not restored');
    }
    $restore->archiveFinished();
    echo "Host payload dependency manifest transaction passed\n";
} finally {
    dependencyDelete($root);
}
