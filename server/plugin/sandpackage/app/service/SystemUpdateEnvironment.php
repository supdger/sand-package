<?php
declare(strict_types=1);
namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Resolve host-owned deployment facts; never accept deployment paths from HTTP input. */
final class SystemUpdateEnvironment
{
    public static function resolve(string $server, array $settings, array $process, string $public, string $pid): array
    {
        $frontend = \SandSystemUpdateRuntime::normalizePath($settings['frontend'] ?? dirname($server) . '/sandadmin-artd');
        $static = $settings['static'] ?? '';
        if ($static === '') $static = \SandSystemUpdateRuntime::normalizePath($public) . '/admin';
        $deployment = [];
        if (empty($settings['reload']) || empty($settings['health'])) {
            $listen = $process['webman']['listen'] ?? '';
            if (!is_string($listen) || !preg_match('#^http://(0\.0\.0\.0|127\.0\.0\.1|localhost|\[::\]):([0-9]{1,5})$#D', $listen, $match) || (int)$match[2] > 65535 || (int)$match[2] < 1) {
                throw new RuntimeException('框架未识别当前宿主的本地 HTTP 监听，尚不能验证升级后的服务');
            }
            if (empty($settings['health']) && !function_exists('curl_init')) throw new RuntimeException('PHP cURL 不可用，框架无法验证升级后的宿主 HTTP 服务');
            $public = \SandSystemUpdateRuntime::normalizePath($public);
            $static = \SandSystemUpdateRuntime::normalizePath($static);
            if (empty($settings['health']) && !str_starts_with(\SandSystemUpdateRuntime::pathKey($static) . '/', \SandSystemUpdateRuntime::pathKey($public) . '/')) throw new RuntimeException('框架无法通过宿主 HTTP 验证公共目录之外的静态部署');
            $deployment = ['url' => 'http://127.0.0.1:' . $match[2], 'pid_file' => \SandSystemUpdateRuntime::normalizePath($pid), 'probe' => '/app/sandpackage/systemUpdate/probe', 'static_uri' => '/' . ltrim(substr($static, strlen($public)), '/') . '/index.html'];
            if (empty($settings['reload'])) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $wrapper = $server . '/windows.php';
                    $source = is_file($wrapper) ? file_get_contents($wrapper) : '';
                    $monitor = $process['monitor']['constructor'] ?? [];
                    $paths = $monitor['monitorDir'] ?? [];
                    if (!is_string($source) || !str_contains($source, 'checkAllFilesChange()') || !str_contains($source, 'popen_processes($processFiles)') || !str_contains($source, 'taskkill /F /T /PID $pid')
                        || !in_array($server . '/config', array_map([\SandSystemUpdateRuntime::class, 'normalizePath'], $paths), true) || !in_array('php', $monitor['monitorExtensions'] ?? [], true)) {
                        throw new RuntimeException('框架未识别当前 Windows 宿主的进程监督器，不能安全重载此服务');
                    }
                    $deployment['supervisor'] = \SandSystemUpdateRuntime::windowsSupervisor($server, getmypid());
                    $deployment['reload_mode'] = 'windows-monitor';
                    $deployment['trigger'] = $server . '/config/server.php';
                } else {
                    if (!is_file($server . '/start.php') || !is_file($pid)) throw new RuntimeException('框架未找到当前宿主的 Workerman 主进程记录，不能安全重载此服务');
                    $deployment['master'] = \SandSystemUpdateRuntime::unixHostMaster($server, $pid, posix_getppid());
                    $deployment['reload_mode'] = 'workerman';
                }
            }
        }
        return ['frontend' => $frontend, 'static' => \SandSystemUpdateRuntime::normalizePath($static), 'deployment' => $deployment,
            'frontend_base' => empty($settings['static']) ? '/admin/' : null];
    }

    /** Resolve installed Windows wrappers to known native PHP/Node script entrypoints. */
    public static function nativeWindowsCommand(string $tool, array $directories, string $php): array
    {
        $node = '';
        foreach ($directories as $directory) if (is_file($directory . '/node.exe')) { $node = $directory . '/node.exe'; break; }
        foreach ($directories as $directory) {
            $directory = \SandSystemUpdateRuntime::normalizePath($directory);
            if (is_file($directory . '/' . $tool . '.exe')) return [$directory . '/' . $tool . '.exe'];
            $scripts = $tool === 'composer' ? ['composer.phar'] : ['pnpm.cjs', 'node_modules/pnpm/bin/pnpm.cjs', 'node_modules/corepack/dist/pnpm.js'];
            foreach ($scripts as $script) {
                $file = $directory . '/' . $script;
                if (!is_file($file)) continue;
                \SandSystemUpdateRuntime::safePath($file);
                if ($tool === 'composer') return [$php, $file];
                if ($node !== '') return [$node, $file];
            }
        }
        throw new RuntimeException('框架未找到已安装的 ' . $tool . ' 原生入口，当前环境缺少升级所需工具');
    }

    /** Read-only readiness. Missing baseline is provisionable, not a user setup task. */
    public static function staticFiles(array $plan): array
    {
        $static = $plan['static'];
        foreach (['server', 'frontend', 'static', 'root', 'storage'] as $key) \SandSystemUpdateRuntime::safePath($plan[$key]);
        if (\SandSystemUpdateRuntime::overlaps($static, $plan['frontend']) || str_starts_with(\SandSystemUpdateRuntime::pathKey($plan['server']) . '/', \SandSystemUpdateRuntime::pathKey($static) . '/')) throw new RuntimeException('静态目录与宿主或前端源码重叠');
        foreach (['app', 'config', 'storage', 'runtime', 'vendor', 'plugin'] as $protected) if (\SandSystemUpdateRuntime::overlaps($static, $plan['server'] . '/' . $protected)) throw new RuntimeException('静态目录与宿主受保护目录重叠');
        $files = \SandSystemUpdateRuntime::tree($static);
        if (isset($files[\SandSystemUpdateRuntime::STATIC_MANIFEST])) {
            $manifest = \SandSystemUpdateRuntime::readJson($static . '/' . \SandSystemUpdateRuntime::STATIC_MANIFEST);
            unset($files[\SandSystemUpdateRuntime::STATIC_MANIFEST]);
            if ($files !== ($manifest['files'] ?? null)) throw new RuntimeException('当前静态文件包含本地修改，框架已保护现有部署');
            return $files;
        }
        if (!$files) return [];
        $dist = \SandSystemUpdateRuntime::tree($plan['frontend'] . '/dist');
        if (!isset($dist['index.html']) || isset($dist[\SandSystemUpdateRuntime::STATIC_MANIFEST]) || $files !== $dist) throw new RuntimeException('现有静态部署与当前构建不一致，框架已保护现有文件；升级未执行');
        return $files;
    }

    /** Called only inside the update lock during the user's upgrade inspection. */
    public static function prepareStatic(array $plan): void
    {
        $files = self::staticFiles($plan);
        $manifest = $plan['static'] . '/' . \SandSystemUpdateRuntime::STATIC_MANIFEST;
        if (is_file($manifest)) return;
        if (!is_dir($plan['static']) && !mkdir($plan['static'], 0755, true) && !is_dir($plan['static'])) throw new RuntimeException('框架无法创建专用静态发布目录');
        \SandSystemUpdateRuntime::writeJson($manifest, ['files' => $files]);
    }
}
