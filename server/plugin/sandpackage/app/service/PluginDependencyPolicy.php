<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use InvalidArgumentException;

/** Fixed plugin versions declared by the uploaded package, never by request input. */
final class PluginDependencyPolicy
{
    /** @param array<string,mixed> $config @return array<string,string> */
    public static function requirements(array $config, string $app): array
    {
        $raw = $config['plugin_dependencies'] ?? [];
        if (!is_array($raw) || (array_is_list($raw) && $raw !== []) || count($raw) > 8) {
            throw new InvalidArgumentException('插件依赖声明格式错误');
        }
        $requirements = [];
        foreach ($raw as $name => $declaration) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{1,63}\z/D', $name) !== 1
                || $name === $app || !is_array($declaration)
                || array_keys($declaration) !== ['version', 'sha256']
                || !is_string($declaration['version'])
                || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $declaration['version']) !== 1
                || !is_string($declaration['sha256'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $declaration['sha256']) !== 1) {
                throw new InvalidArgumentException('插件依赖声明格式错误');
            }
            $requirements[$name] = $declaration['version'];
        }
        return $requirements;
    }

    /** @param array<string,mixed> $config @return array<string,string> relative path to SHA-256 */
    public static function verifyBundles(string $root, array $config, string $app): array
    {
        $requirements = self::requirements($config, $app);
        $bundles = [];
        foreach ($requirements as $name => $version) {
            $relative = 'dependencies/' . $name . '-' . $version . '.zip';
            $path = $root . '/' . $relative;
            if (!is_file($path) || is_link($path)
                || !hash_equals($config['plugin_dependencies'][$name]['sha256'], (string) hash_file('sha256', $path))) {
                throw new InvalidArgumentException("插件依赖工件缺失或摘要不匹配：{$name}@{$version}");
            }
            $archive = new \ZipArchive();
            if ($archive->open($path) !== true) {
                throw new InvalidArgumentException("插件依赖工件不可读取：{$name}@{$version}");
            }
            try {
                $raw = $archive->getFromName('info.ini');
                $info = is_string($raw) ? parse_ini_string($raw, false, INI_SCANNER_RAW) : false;
                if (!is_array($info) || ($info['app'] ?? null) !== $name
                    || ($info['version'] ?? null) !== $version) {
                    throw new InvalidArgumentException("插件依赖工件身份不匹配：{$name}@{$version}");
                }
            } finally {
                $archive->close();
            }
            $bundles[$relative] = $config['plugin_dependencies'][$name]['sha256'];
        }
        return $bundles;
    }
}
