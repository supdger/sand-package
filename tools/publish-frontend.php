<?php

declare(strict_types=1);

const SAND_PACKAGE_MANIFEST = '.sand-package-source-manifest.json';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/** @return array<string,string> */
function packageSourceManifest(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return $files;
}

/** @return array{version:string,files:array<string,string>}|null */
function readPackageManifest(string $target): ?array
{
    $path = $target . '/' . SAND_PACKAGE_MANIFEST;
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !is_string($decoded['version'] ?? null) || !is_array($decoded['files'] ?? null)) {
        fail("目标发布清单无效：{$path}");
    }
    return $decoded;
}

/** @param array<string,string> $files */
function assertPackageUnmodified(string $target, array $files): void
{
    foreach ($files as $relative => $hash) {
        $path = $target . '/' . $relative;
        if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) {
            fail("拒绝覆盖已修改的 SandPackage 前端源码：{$relative}");
        }
    }
}

/** @param array<string,string> $files */
function assertNoUnmanagedCollision(string $target, array $files): void
{
    foreach (array_keys($files) as $relative) {
        if (file_exists($target . '/' . $relative) || is_link($target . '/' . $relative)) {
            fail("目标已有未受管理的 SandPackage 前端文件：{$relative}");
        }
    }
}

function copyPackageTree(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
                fail("无法创建目录：{$destination}");
            }
            continue;
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            fail("无法创建目录：{$parent}");
        }
        if (!copy($item->getPathname(), $destination)) {
            fail("无法发布文件：{$relative}");
        }
    }
}

$target = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
if ($target === '' || $target === '/') {
    fail('用法：php tools/publish-frontend.php <sandadmin-artd 目标目录>');
}
if (!is_dir($target)) {
    fail('请先发布 Sand Core 前端源码，目标目录不存在');
}

$source = realpath(__DIR__ . '/../sandadmin-artd');
if ($source === false) {
    fail('SandPackage 前端源码不存在');
}
$files = packageSourceManifest($source);
$installed = readPackageManifest($target);
if ($installed === null) {
    assertNoUnmanagedCollision($target, $files);
} else {
    assertPackageUnmodified($target, $installed['files']);
}

copyPackageTree($source, $target);
$version = class_exists(\Composer\InstalledVersions::class)
    ? (\Composer\InstalledVersions::getPrettyVersion('supdger/sand-package') ?? 'dev-main')
    : 'dev-main';
$manifest = [
    'schema' => 1,
    'package' => 'supdger/sand-package',
    'version' => $version,
    'files' => $files,
];
if (file_put_contents(
    $target . '/' . SAND_PACKAGE_MANIFEST,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    LOCK_EX
) === false) {
    fail('无法写入 SandPackage 前端发布清单');
}

fwrite(STDOUT, "SandPackage 前端源码已发布到 {$target}（" . count($files) . " 个文件）" . PHP_EOL);
