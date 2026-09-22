<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$publisher = $root . '/tools/publish-frontend.php';
$target = sys_get_temp_dir() . '/sand-package-publish-' . bin2hex(random_bytes(6));
$installedRoot = sys_get_temp_dir() . '/sand-package-installed-' . bin2hex(random_bytes(6));

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

    $installedPackage = $installedRoot . '/vendor/supdger/sand-package';
    mkdir($installedPackage . '/tools', 0777, true);
    mkdir($installedPackage . '/sandadmin-artd/src/views/plugin/sandpackage/api', 0777, true);
    copy($publisher, $installedPackage . '/tools/publish-frontend.php');
    file_put_contents(
        $installedPackage . '/sandadmin-artd/src/views/plugin/sandpackage/api/index.ts',
        "export {}\n"
    );
    mkdir($installedRoot . '/vendor/composer', 0777, true);
    file_put_contents(
        $installedRoot . '/vendor/composer/InstalledVersions.php',
        "<?php\nnamespace Composer;\nfinal class InstalledVersions { public static function getPrettyVersion(string \$package): ?string { return \$package === 'supdger/sand-package' ? '0.1.2' : null; } }\n"
    );
    file_put_contents(
        $installedRoot . '/vendor/autoload.php',
        "<?php\nspl_autoload_register(static function (string \$class): void { if (\$class === 'Composer\\\\InstalledVersions') { require __DIR__ . '/composer/InstalledVersions.php'; } });\n"
    );
    mkdir($installedRoot . '/published', 0777, true);
    file_put_contents($installedRoot . '/published/package.json', "{}\n");
    [$code, $output] = runPackagePublisher(
        $installedPackage . '/tools/publish-frontend.php',
        $installedRoot . '/published'
    );
    $installedManifest = json_decode(
        (string) file_get_contents($installedRoot . '/published/.sand-package-source-manifest.json'),
        true
    );
    if ($code !== 0 || ($installedManifest['version'] ?? null) !== '0.1.2') {
        throw new RuntimeException("安装形态未记录 Composer 版本：{$output}");
    }

    fwrite(STDOUT, "publish SandPackage frontend tests passed\n");
} finally {
    removePackageFixture($target);
    removePackageFixture($installedRoot);
}
