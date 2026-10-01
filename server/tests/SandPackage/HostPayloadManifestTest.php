<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';

use plugin\sandpackage\app\service\HostPayloadManifest;

function hostPayloadZip(array $files, ?array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'sandpackage-host-payload-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary archive');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create archive');
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    if ($entries !== null) {
        $zip->addFromString('host-payload.json', json_encode(
            ['schema' => 1, 'app' => 'neutral-sample', 'files' => $entries],
            JSON_THROW_ON_ERROR,
        ));
    }
    $zip->close();
    return $path;
}

function hostPayloadInspect(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Cannot open archive');
    }
    try {
        return HostPayloadManifest::inspectArchive($zip, 'neutral-sample');
    } finally {
        $zip->close();
        unlink($path);
    }
}

function hostPayloadReject(string $path, string $message): void
{
    try {
        hostPayloadInspect($path);
        throw new RuntimeException('Expected rejection: ' . $message);
    } catch (RuntimeException $error) {
        if (str_starts_with($error->getMessage(), 'Expected rejection:')) {
            throw $error;
        }
    }
}

$appFile = 'app/Api/Neutral/Probe.php';
$configFile = 'config/neutral_sample_api.php';
$files = [$appFile => "<?php namespace app\\Api\\Neutral;\n", $configFile => "<?php return [];\n"];
$entries = [];
foreach ($files as $path => $contents) {
    $entries[] = ['path' => $path, 'sha256' => hash('sha256', $contents)];
}
if (count(hostPayloadInspect(hostPayloadZip($files, $entries))) !== 2) {
    throw new RuntimeException('Exact host payload declaration was not accepted');
}
hostPayloadReject(hostPayloadZip($files, null), 'undeclared host files');
hostPayloadReject(hostPayloadZip($files, array_slice($entries, 0, 1)), 'missing config file');
hostPayloadReject(hostPayloadZip($files, [
    ['path' => $appFile, 'sha256' => str_repeat('0', 64)],
    $entries[1],
]), 'wrong digest');
hostPayloadReject(hostPayloadZip($files, [
    $entries[0],
    $entries[0],
    $entries[1],
]), 'duplicate declaration');
hostPayloadReject(hostPayloadZip(['config/other_plugin_api.php' => "<?php return [];\n"], [
    ['path' => 'config/other_plugin_api.php', 'sha256' => hash('sha256', "<?php return [];\n")],
]), 'another plugin config');
$directory = sys_get_temp_dir() . '/sandpackage-host-manifest-' . bin2hex(random_bytes(8));
mkdir($directory);
try {
    foreach ($files as $path => $contents) {
        $target = $directory . '/' . $path;
        mkdir(dirname($target), 0700, true);
        file_put_contents($target, $contents);
    }
    file_put_contents($directory . '/host-payload.json', json_encode(
        ['schema' => 1, 'app' => 'neutral-sample', 'files' => $entries],
        JSON_THROW_ON_ERROR,
    ));
    if (count(HostPayloadManifest::inspectDirectory($directory, 'neutral-sample')) !== 2) {
        throw new RuntimeException('Extracted candidate declaration was not accepted');
    }
    file_put_contents($directory . '/' . $appFile, 'modified after upload');
    try {
        HostPayloadManifest::inspectDirectory($directory, 'neutral-sample');
        throw new RuntimeException('Expected extracted candidate drift rejection');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Expected extracted candidate drift rejection') {
            throw $error;
        }
    }
} finally {
    foreach ([$appFile, $configFile, 'host-payload.json'] as $path) {
        unlink($directory . '/' . $path);
    }
    rmdir($directory . '/app/Api/Neutral');
    rmdir($directory . '/app/Api');
    rmdir($directory . '/app');
    rmdir($directory . '/config');
    rmdir($directory);
}
echo "Host payload manifest checks passed\n";
