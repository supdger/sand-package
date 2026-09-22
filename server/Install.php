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
        self::installByRelation();
    }

    public static function update(): void
    {
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
        }
    }
}
