<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

/**
 * Fingerprint plugin config/process.php so install/upgrade can choose reload vs full_restart.
 *
 * Webman does not re-read process.php on SIGUSR1 reload; custom processes need an official
 * full restart (php start.php restart semantics). This helper never patches workerman/webman.
 */
final class PluginProcessFingerprint
{
    public static function configPath(string $pluginRoot): string
    {
        return rtrim($pluginRoot, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'process.php';
    }

    /** True when the plugin declares at least one custom process (not an empty return []). */
    public static function declaresCustomProcesses(string $processFile): bool
    {
        if (!is_file($processFile) || is_link($processFile) || !is_readable($processFile)) {
            return false;
        }
        if (filesize($processFile) > 1048576) {
            return false;
        }
        $table = self::tryLoadTable($processFile);
        if ($table !== []) {
            return true;
        }
        // Installer workers may lack new handler classes yet; SandAI returns [] until they load.
        $source = (string) file_get_contents($processFile);
        return (bool) preg_match("/['\"]handler['\"]\s*=>/", $source);
    }

    /**
     * Stable fingerprint: process name → handler / count / reloadable when the table loads;
     * otherwise a normalized source digest so process.php edits still force full_restart.
     */
    public static function fingerprint(string $processFile): string
    {
        if (!is_file($processFile) || is_link($processFile) || !is_readable($processFile)) {
            return hash('sha256', 'process:absent');
        }
        if (filesize($processFile) > 1048576) {
            return hash('sha256', 'process:too-large');
        }
        $table = self::tryLoadTable($processFile);
        if ($table !== []) {
            return self::fingerprintTable($table);
        }
        $source = (string) file_get_contents($processFile);
        $normalized = preg_replace('/\s+/', ' ', $source) ?? $source;
        return hash('sha256', 'process:source:' . $normalized);
    }

    /** @param array<string,mixed> $table */
    public static function fingerprintTable(array $table): string
    {
        $normalized = [];
        foreach ($table as $name => $config) {
            if (!is_string($name) || $name === '' || !is_array($config)) {
                continue;
            }
            $handler = $config['handler'] ?? null;
            if (is_string($handler)) {
                $handlerName = $handler;
            } elseif (is_object($handler)) {
                $handlerName = $handler::class;
            } else {
                $handlerName = get_debug_type($handler);
            }
            $normalized[$name] = [
                'handler' => $handlerName,
                'count' => array_key_exists('count', $config) ? $config['count'] : null,
                'reloadable' => array_key_exists('reloadable', $config) ? $config['reloadable'] : null,
            ];
        }
        ksort($normalized);
        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> */
    public static function processNames(string $processFile): array
    {
        if (!is_file($processFile) || is_link($processFile) || !is_readable($processFile)) {
            return [];
        }
        $table = self::tryLoadTable($processFile);
        if ($table !== []) {
            $names = [];
            foreach ($table as $name => $config) {
                if (is_string($name) && $name !== '' && is_array($config)) {
                    $names[] = $name;
                }
            }
            sort($names);
            return $names;
        }
        $source = (string) file_get_contents($processFile);
        preg_match_all("/['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]\s*=>\s*\[[^\]]*['\"]handler['\"]/s", $source, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        sort($names);
        return $names;
    }

    /** Decide whether install/upgrade must full_restart instead of SIGUSR1 reload. */
    public static function requiresFullRestart(bool $isUpdate, string $candidateProcessFile, ?string $installedProcessFile): bool
    {
        if (!self::declaresCustomProcesses($candidateProcessFile)) {
            return false;
        }
        if (!$isUpdate) {
            return true;
        }
        $previous = is_string($installedProcessFile) ? self::fingerprint($installedProcessFile) : hash('sha256', 'process:absent');
        return !hash_equals($previous, self::fingerprint($candidateProcessFile));
    }

    /** @return array<string,mixed> */
    private static function tryLoadTable(string $processFile): array
    {
        try {
            /** @var mixed $result */
            $result = (static function (string $file): mixed {
                return include $file;
            })($processFile);
            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
