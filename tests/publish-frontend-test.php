<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$publisher = $root . '/tools/publish-frontend.php';
$target = sys_get_temp_dir() . '/sand-package-publish-' . bin2hex(random_bytes(6));

function runPackagePublisher(string $publisher, string $target): array
{
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($publisher) . ' ' . escapeshellarg($target) . ' 2>&1',
        $output,
        $code
    );
    return [$code, implode("\n", $output)];
}

function removePackageFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

try {
    mkdir($target, 0777, true);
    file_put_contents($target . '/package.json', "{}\n");
    [$code, $output] = runPackagePublisher($publisher, $target);
    if ($code !== 0 || !is_file($target . '/.sand-package-source-manifest.json')) {
        throw new RuntimeException("首次叠加发布失败：{$output}");
    }
    [$code, $output] = runPackagePublisher($publisher, $target);
    if ($code !== 0) {
        throw new RuntimeException("幂等叠加发布失败：{$output}");
    }
    $managed = $target . '/src/views/plugin/sandpackage/api/index.ts';
    file_put_contents($managed, "\n", FILE_APPEND);
    [$code, $output] = runPackagePublisher($publisher, $target);
    if ($code === 0 || !str_contains($output, '拒绝覆盖已修改的 SandPackage 前端源码')) {
        throw new RuntimeException("修改未被拒绝：{$output}");
    }
    fwrite(STDOUT, "publish SandPackage frontend tests passed\n");
} finally {
    removePackageFixture($target);
}
