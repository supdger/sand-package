<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';

use plugin\sandpackage\app\service\HostPayloadPlan;
use plugin\sandpackage\app\service\HostPayloadManifest;

foreach ([
    ['C:\\fixture\\candidate\\', true, 'C:\\fixture\\candidate/'],
    ['C:/fixture/candidate/', true, 'C:/fixture/candidate/'],
    ['C:\\', true, 'C:/'],
    ['C:/', true, 'C:/'],
    ['\\\\server\\share\\', true, '\\\\server\\share/'],
    ['/', false, '/'],
    ['/fixture/literal\\', false, '/fixture/literal\\/'],
] as [$directory, $windows, $prefix]) {
    if (HostPayloadPlan::directoryPrefix($directory, $windows) !== $prefix) {
        throw new RuntimeException('Directory root or trailing separator was changed incorrectly');
    }
    if ($windows) HostPayloadPlan::assertSafePath($prefix . 'child/file.php', true);
}
foreach (['C:', 'C:\\fixture\\\\invalid', '\\\\server\\share\\\\invalid'] as $directory) {
    try { HostPayloadPlan::directoryPrefix($directory, true); }
    catch (RuntimeException) { continue; }
    throw new RuntimeException('Drive-relative or embedded duplicate separators were accepted');
}
foreach ([
    ['C:\\fixture\\candidate\\app\\Api\\Probe.php', 'C:\\fixture\\candidate\\', 'app/Api/Probe.php'],
    ['C:/fixture/candidate/app\\Api\\Probe.php', 'C:\\fixture\\candidate/', 'app/Api/Probe.php'],
    ['\\\\server\\share\\candidate\\config\\neutral_sample_api.php', '\\\\server\\share\\candidate', 'config/neutral_sample_api.php'],
    ['C:\\fixture\\backup\\app\\Api', 'C:\\fixture\\backup', 'app/Api'],
    ['C:\\fixture\\backup\\app\\Api\\Probe.php', 'C:\\fixture\\backup', 'app/Api/Probe.php'],
] as [$pathname, $root, $expected]) {
    $relative = HostPayloadPlan::relativePath($pathname, $root, true);
    if ($relative !== $expected) throw new RuntimeException('Windows manifest or recovery inventory key mismatch');
    if (str_ends_with($expected, '.php') && !HostPayloadManifest::allowedPath($relative, 'neutral-sample')) {
        throw new RuntimeException('Canonical Windows payload file was rejected');
    }
}
foreach ([
    ['/fixture/candidate/app\\Api\\Probe.php', '/fixture/candidate', false],
    ['/fixture/candidate2/app/Api/Probe.php', '/fixture/candidate', false],
    ['C:\\fixture\\other\\app\\Api\\Probe.php', 'C:\\fixture\\candidate', true],
    ['C:\\fixture\\candidate\\app\\..\\Probe.php', 'C:\\fixture\\candidate', true],
] as [$pathname, $root, $windows]) {
    try {
        HostPayloadPlan::relativePath($pathname, $root, $windows);
    } catch (RuntimeException) {
        continue;
    }
    throw new RuntimeException('Unsafe inventory pathname was accepted');
}
if (DIRECTORY_SEPARATOR === '/') {
    $fixture = sys_get_temp_dir() . '/sandpackage-posix-backslash-' . bin2hex(random_bytes(6));
    mkdir($fixture . '/app', 0700, true);
    $literal = $fixture . '/app/Api\\Probe.php';
    file_put_contents($literal, '<?php');
    try {
        try {
            HostPayloadManifest::inspectDirectory($fixture, 'neutral-sample');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== '宿主载荷相对路径无效') throw $error;
            echo "Host payload native relative upload and backup keys passed; Windows cases are synthetic; POSIX literal backslash rejected\n";
            return;
        }
        throw new RuntimeException('POSIX literal backslash filename was reinterpreted');
    } finally {
        unlink($literal);
        rmdir($fixture . '/app');
        rmdir($fixture);
    }
}
echo "Host payload native relative upload and backup keys passed; Windows cases are synthetic\n";
