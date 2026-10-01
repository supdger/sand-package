<?php

declare(strict_types=1);

use SandAdmin\Package\Install;

$source = dirname(__DIR__, 2);
$autoload = getenv('SANDPACKAGE_PACKAGE_AUTOLOAD');
if (is_string($autoload) && $autoload !== '') require $autoload;
require_once $source . '/FrontendPublisher.php';
require_once $source . '/Install.php';
$fixture = sys_get_temp_dir() . '/sandpackage-composer-hook-' . bin2hex(random_bytes(6));
mkdir($fixture . '/server', 0700, true);
mkdir($fixture . '/sandadmin-artd', 0700, true);
function base_path(): string { global $fixture; return $fixture . '/server'; }
function copy_dir(string $from, string $to, bool $overwrite): void {
    if (!is_dir($to)) mkdir($to, 0700, true);
    foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry) {
        $destination = $to . '/' . $entry->getFilename();
        if ($entry->isDir()) copy_dir($entry->getPathname(), $destination, $overwrite);
        elseif (!copy($entry->getPathname(), $destination)) throw new RuntimeException('Copy failed');
    }
}
function composerHookExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo '[PASS] ' . $message . PHP_EOL;
}
try {
    try {
        Install::install();
        throw new RuntimeException('Missing core baseline was accepted');
    } catch (RuntimeException $error) {
        composerHookExpect(str_contains($error->getMessage(), 'Sand Core 前端基线不存在')
            && !is_dir(base_path() . '/plugin/sandpackage'), 'missing core frontend prevents partial backend publication');
    }
    file_put_contents($fixture . '/sandadmin-artd/.sand-core-source-manifest.json', '{}');
    Install::install();
    $manifestPath = $fixture . '/sandadmin-artd/.sand-package-source-manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    composerHookExpect(($manifest['package'] ?? null) === 'supdger/sand-package'
        && is_file(base_path() . '/plugin/sandpackage/app/service/HostPayloadManifest.php')
        && is_file(base_path() . '/plugin/sandpackage/app/service/ExistingSchemaManifest.php'),
        'Composer install publishes matching frontend and complete candidate backend');
    foreach ($manifest['files'] as $path => $sha256) {
        composerHookExpect(hash_file('sha256', $fixture . '/sandadmin-artd/' . $path) === $sha256,
            'published frontend hash matches ' . $path);
    }
    Install::update();
    composerHookExpect(hash_file('sha256', $manifestPath) === hash('sha256', json_encode(
        $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL), 'Composer update is idempotent');
    $managed = $fixture . '/sandadmin-artd/src/views/plugin/sandpackage/api/index.ts';
    file_put_contents($managed, "\n// consumer edit\n", FILE_APPEND);
    $changed = hash_file('sha256', $managed);
    try {
        Install::update();
        throw new RuntimeException('Consumer edit was overwritten');
    } catch (RuntimeException $error) {
        composerHookExpect(str_contains($error->getMessage(), '拒绝覆盖已修改')
            && hash_file('sha256', $managed) === $changed, 'Composer update preserves consumer frontend edits');
    }
    echo "Composer frontend lifecycle checks passed; no database or service operations\n";
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (!file_exists($path)) return;
        if (is_file($path)) { unlink($path); return; }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) $remove($item->getPathname());
        rmdir($path);
    };
    $remove($fixture);
}
