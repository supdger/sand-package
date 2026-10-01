<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';

use plugin\sandpackage\app\service\HostPayloadPlan;

function planWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $contents);
}

function planReject(callable $run, string $message): void
{
    try {
        $run();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$root = sys_get_temp_dir() . '/sandpackage-host-plan-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    HostPayloadPlan::assertSafePath($root . '/nested/file.php');
    HostPayloadPlan::assertSafePath('C:\\host\\app/mixed.php', true);
    HostPayloadPlan::assertSafePath('\\\\server\\share\\app\\file.php', true);
    planReject(static fn () => HostPayloadPlan::assertSafePath('C:\\host\\..\\escape.php', true), 'Windows traversal');
    planReject(static fn () => HostPayloadPlan::assertSafePath('C:relative.php', true), 'drive-relative path');
    planReject(static fn () => HostPayloadPlan::assertSafePath('\\\\server\\..\\file.php', true), 'invalid UNC share');
    planReject(static fn () => HostPayloadPlan::assertSafePath('relative/file.php'), 'relative POSIX path');
    $appPath = 'app/Api/Neutral/Probe.php';
    $configPath = 'config/neutral_sample_api.php';
    $old = 'old service';
    $new = 'new service';
    $incoming = [
        ['path' => $appPath, 'sha256' => hash('sha256', $new)],
        ['path' => $configPath, 'sha256' => hash('sha256', 'config')],
    ];

    $fresh = HostPayloadPlan::inspect($root, 'neutral-sample', $incoming);
    if ($fresh !== ['add' => [$appPath, $configPath], 'replace' => [], 'remove' => []]) {
        throw new RuntimeException('Fresh install plan must add only declared files');
    }
    planWrite($root . '/' . $appPath, 'foreign service');
    planReject(static fn () => HostPayloadPlan::inspect($root, 'neutral-sample', $incoming), 'foreign file collision');
    planWrite($root . '/' . $appPath, $old);
    $owned = [['path' => $appPath, 'sha256' => hash('sha256', $old)]];
    $upgrade = HostPayloadPlan::inspect($root, 'neutral-sample', $incoming, $owned);
    if ($upgrade !== ['add' => [$configPath], 'replace' => [$appPath], 'remove' => []]) {
        throw new RuntimeException('Upgrade plan must distinguish new and owned files');
    }
    planWrite($root . '/' . $appPath, 'modified after installation');
    planReject(static fn () => HostPayloadPlan::inspect($root, 'neutral-sample', $incoming, $owned), 'owned file drift');
    planWrite($root . '/' . $appPath, $old);
    $uninstall = HostPayloadPlan::inspect($root, 'neutral-sample', [], $owned);
    if ($uninstall !== ['add' => [], 'replace' => [], 'remove' => [$appPath]]) {
        throw new RuntimeException('Uninstall plan must remove only owned files');
    }
    symlink($root . '/missing-parent', $root . '/config');
    planReject(static fn () => HostPayloadPlan::inspect($root, 'neutral-sample', $incoming, $owned), 'symlink parent');
    unlink($root . '/config');
    planReject(static fn () => HostPayloadPlan::inspect($root, 'neutral-sample', [
        ['path' => 'config/other_plugin_api.php', 'sha256' => hash('sha256', 'foreign')],
    ]), 'foreign config namespace');
    echo "Host payload ownership plans passed\n";
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
