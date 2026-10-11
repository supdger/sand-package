<?php

declare(strict_types=1);

// Run with -d disable_functions=posix_kill,posix_get_last_error: a boundary simulation, not Windows acceptance.
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/DependencyCommandRecovery.php';

use plugin\sandpackage\app\service\DependencyCommandRecovery;

if (function_exists('posix_kill') || function_exists('posix_get_last_error')) throw new RuntimeException('This test requires disabled POSIX process inspection');
$root = sys_get_temp_dir() . '/sandpackage-dependency-no-posix-' . bin2hex(random_bytes(8));
mkdir($root . '/locks', 0700, true);
$marker = $root . '/locks/host-upstream.process.json';
$nonce = str_repeat('a', 32);
file_put_contents($marker, json_encode(['app'=>'neutral-host','nonce'=>$nonce,'process'=>['pid'=>99999999,'pgid'=>99999999,'descendants'=>[]]], JSON_THROW_ON_ERROR));
$original = file_get_contents($marker);
try {
    $recovery = new DependencyCommandRecovery($root, 'neutral-host', static fn(): array => ['info'=>['state'=>4,'dependency_command_nonce'=>$nonce,'composer_dependent_wait_install'=>1]]);
    try { $recovery->inspect(); throw new RuntimeException('Missing process inspection was accepted'); }
    catch (RuntimeException $error) {
        if ($error->getMessage() !== '此平台无法证明原依赖进程已退出') throw $error;
    }
    if (file_get_contents($marker) !== $original || is_dir($root . '/host-payload')) throw new RuntimeException('Unsupported process proof made recovery writes');
    echo "Dependency recovery without POSIX safely rejected; no marker or journal writes\n";
} finally {
    if (!str_starts_with($root, sys_get_temp_dir() . '/sandpackage-dependency-no-posix-') || is_link($root)) throw new RuntimeException('Unsafe owned fixture cleanup');
    unlink($marker); rmdir($root . '/locks'); rmdir($root);
}
