<?php

namespace SandAdmin\Package;

final class Install
{
    public const WEBMAN_PLUGIN = true;

    private const PATH_RELATION = [
        'plugin/sandpackage' => 'plugin/sandpackage',
    ];

    public static function install(): void
    {
        self::retireLegacyTest(false);
        FrontendPublisher::publish(self::frontendTarget());
        self::installByRelation();
    }

    public static function update(): void
    {
        self::retireLegacyTest(false);
        FrontendPublisher::publish(self::frontendTarget());
        self::installByRelation();
    }

    public static function uninstall(): void
    {
        foreach (self::PATH_RELATION as $destination) {
            $path = base_path() . '/' . $destination;
            if (!is_dir($path) && !is_file($path) && !is_link($path)) {
                continue;
            }
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            remove_dir($path);
        }
    }

    private static function installByRelation(): void
    {
        foreach (self::PATH_RELATION as $source => $destination) {
            $target = base_path() . '/' . $destination;
            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            copy_dir(__DIR__ . '/' . $source, $target, true);
            self::retireLegacyTest(true);
        }
    }

    /** Retire only the exact official test payload installed by versions through 0.1.9. */
    private static function retireLegacyTest(bool $remove): void
    {
        $directory = base_path() . '/plugin/sandpackage/tests';
        $file = $directory . '/lifecycle_non_db_contract_test.php';
        if (!file_exists($file) && !is_link($file)) return;
        foreach ([base_path(), base_path() . '/plugin', dirname($directory), $directory, $file] as $path) {
            if (is_link($path)) throw new \RuntimeException('历史官方测试载荷路径不安全，拒绝升级并保留文件：' . $file);
        }
        if (!is_file($file)
            || hash_file('sha256', $file) !== '9f45dff5331fecc244f0e647d206cb14c59e56df0a703e467a76a482133d248b') {
            throw new \RuntimeException('历史官方测试载荷已被修改或路径不安全，拒绝升级并保留文件：' . $file);
        }
        if ($remove && !unlink($file)) {
            throw new \RuntimeException('无法移除已核对的历史官方测试载荷：' . $file);
        }
        // Preserve the directory and every other file; unknown content is never removed.
    }

    private static function frontendTarget(): string
    {
        $basePath = rtrim(str_replace('\\', '/', base_path()), '/');

        return basename($basePath) === 'server'
            ? dirname($basePath) . '/sandadmin-artd'
            : $basePath . '/sandadmin-artd';
    }
}
