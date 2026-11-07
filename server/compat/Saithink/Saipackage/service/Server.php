<?php

namespace Saithink\Saipackage\service;

use Exception;
use Throwable;
use think\facade\Db;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 版本类
 */
class Server
{

    /**
     * Historical name used across installers. This is SIGUSR1 HTTP worker reload,
     * NOT php start.php restart. Prefer reload() or fullRestart() explicitly.
     *
     * @return bool
     */
    public static function restart(): bool
    {
        return self::reload();
    }

    /**
     * Smooth-reload HTTP workers (SIGUSR1). Does not re-read config/process.php
     * or fork newly declared custom processes.
     */
    public static function reload(): bool
    {
        // Console commands have no Workerman worker; their parent owns the
        // command invocation, not the host service.
        if (Worker::getAllWorkers() === []) {
            try {
                if (!function_exists('posix_kill')) throw new \RuntimeException('当前平台无法从恢复 CLI 安全重载服务；请使用宿主正式服务控制入口重载');
                $pidFile = config('server.pid_file');
                if (!is_string($pidFile) || $pidFile === '') throw new \RuntimeException('宿主未配置 Workerman 主进程记录，恢复 CLI 已拒绝重载');
                require_once dirname(__DIR__, 4) . '/plugin/sandpackage/tools/system-update-worker.php';
                $server = base_path();
                $master = \SandSystemUpdateRuntime::unixHostMaster($server, $pidFile);
                $listen = config('process.webman.listen', config('server.listen', ''));
                if (!is_string($listen)) throw new \RuntimeException('宿主 HTTP 监听配置无效，恢复 CLI 已拒绝重载');
                $workers = \SandSystemUpdateRuntime::unixHttpWorkers($server, $master, $listen);
                if ($workers === []) throw new \RuntimeException('当前宿主没有可核对的 HTTP worker，恢复 CLI 已拒绝重载');
                fwrite(STDERR, "已核对当前宿主主进程；正在重载 HTTP worker（SIGUSR1，不会重读 process.php）。\n");
                \SandSystemUpdateRuntime::reloadUnixHost($server, $pidFile, $master);
                $deadline = microtime(true) + 15;
                do {
                    if (\SandSystemUpdateRuntime::unixHostMaster($server, $pidFile, $master['pid']) !== $master) throw new \RuntimeException('服务重载期间宿主主进程身份已变化');
                    $current = \SandSystemUpdateRuntime::unixHttpWorkers($server, $master, $listen);
                    if (count($current) === count($workers) && array_intersect_assoc($workers, $current) === []) {
                        fwrite(STDERR, "当前宿主 HTTP worker 已完成轮换。\n");
                        return true;
                    }
                    usleep(100000);
                } while (microtime(true) < $deadline);
                throw new \RuntimeException('已发送重载信号，但 HTTP worker 未在 15 秒内完成轮换；请 inspect 恢复现场后再继续，禁止重跑已提交 SQL');
            } catch (\Throwable $e) {
                Log::error('服务重载失败：' . $e->getMessage());
                throw $e;
            }
        }
        if (function_exists('posix_kill')) {
            // SIGUSR1 to the Workerman master — reload only, not full restart.
            try {
                return posix_kill(posix_getppid(), SIGUSR1);
            } catch (\Throwable $e) {
                Log::error("平滑重载失败：" . $e->getMessage());
                return false;
            }
        } else {
            // 重启当前子进程
            Timer::add(1, function () {
                Worker::stopAll();
            });
        }
        return true;
    }

    /**
     * Official full restart so the master re-reads process.php and forks new custom processes.
     * Equivalent to `php start.php restart` (or stop+start -d). Never patches workerman/webman.
     *
     * @param list<string> $expectedProcessNames Optional process titles that must appear after rotation.
     * @return bool true when the restart completed and (when names given) processes were observed.
     */
    /**
     * @param list<string> $expectedProcessNames
     * @param array{registry?:string,clear_pending?:bool} $options
     */
    public static function fullRestart(array $expectedProcessNames = [], array $options = []): bool
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                throw new \RuntimeException('Windows 完整重启请通过宿主监督器 stop/start；安装器不会将 SIGUSR1 重载当作 process 注册成功');
            }
            if (!function_exists('posix_kill')) {
                throw new \RuntimeException('当前平台无法安全执行完整重启；请使用宿主正式服务控制入口执行 php start.php restart');
            }
            $pidFile = config('server.pid_file');
            if (!is_string($pidFile) || $pidFile === '') {
                throw new \RuntimeException('宿主未配置 Workerman 主进程记录，已拒绝完整重启');
            }
            require_once dirname(__DIR__, 4) . '/plugin/sandpackage/tools/system-update-worker.php';
            $server = base_path();
            $requestParent = Worker::getAllWorkers() === [] ? null : posix_getppid();
            $master = \SandSystemUpdateRuntime::unixHostMaster($server, $pidFile, $requestParent);

            if (Worker::getAllWorkers() === []) {
                fwrite(STDERR, "已核对当前宿主主进程；正在执行完整重启（php start.php restart 语义，会重读 process.php）。\n");
                $next = \SandSystemUpdateRuntime::fullRestartUnixHost($server, $pidFile, $master, PHP_BINARY, self::hostListeners());
                if ($expectedProcessNames !== []) {
                    \SandSystemUpdateRuntime::assertUnixCustomProcessesRunning($server, $next, $expectedProcessNames);
                }
                fwrite(STDERR, "宿主主进程已轮换" . ($expectedProcessNames !== [] ? '，自定义进程已出现' : '') . "。\n");
                return true;
            }

            self::detachFullRestart($server, $pidFile, $master, $expectedProcessNames, $options);
            return true;
        } catch (\Throwable $e) {
            Log::error('服务完整重启失败：' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Listen addresses declared by the host (config/process.php + legacy
     * config/server.php). Passed to the restart worker so it can wait for the
     * ports to release and confirm the rotated master accepts connections.
     *
     * @return list<string>
     */
    private static function hostListeners(): array
    {
        $listeners = [];
        $processes = config('process');
        if (is_array($processes)) {
            foreach ($processes as $definition) {
                if (is_array($definition) && is_string($definition['listen'] ?? null) && $definition['listen'] !== '') {
                    $listeners[] = $definition['listen'];
                }
            }
        }
        $server = config('server.listen');
        if (is_string($server) && $server !== '') {
            $listeners[] = $server;
        }
        return array_values(array_unique($listeners));
    }

    /**
     * @param list<string> $expectedProcessNames
     * @param array{registry?:string,clear_pending?:bool} $options
     */
    private static function detachFullRestart(string $server, string $pidFile, array $master, array $expectedProcessNames, array $options = []): void
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('无法启动独立完整重启进程（proc_open 不可用）');
        }
        $php = PHP_BINARY;
        $worker = dirname(__DIR__, 4) . '/plugin/sandpackage/tools/full-restart-worker.php';
        $payload = [
            'server' => $server,
            'pid_file' => $pidFile,
            'master' => $master,
            'expected' => array_values($expectedProcessNames),
            'php' => $php,
            'listeners' => self::hostListeners(),
            'registry' => is_string($options['registry'] ?? null) ? $options['registry'] : null,
            'clear_pending' => ($options['clear_pending'] ?? false) === true,
        ];
        $dir = $server . '/runtime/sandpackage';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法准备完整重启任务目录');
        }
        $payloadFile = $dir . '/full-restart-' . bin2hex(random_bytes(8)) . '.json';
        if (file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('无法写入完整重启任务');
        }
        $null = \SandSystemUpdateRuntime::nullDevice();
        $pipes = [];
        // This HTTP worker inherited the master listen sockets; proc_open only
        // redirects the listed descriptors. Spawn through a POSIX fd scrub so the
        // helper cannot keep the port bound after the old master exits.
        $process = proc_open(
            \SandSystemUpdateRuntime::unixFdScrubCommand([$php, $worker, $payloadFile]),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            $server,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            @unlink($payloadFile);
            throw new \RuntimeException('无法启动独立完整重启进程');
        }
        // Launcher double-forks and exits 0 quickly; waiting here is safe and confirms spawn.
        $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException('独立完整重启进程启动失败');
        }
    }

    public static function getInstalledIds(string $dir): array
    {
        $installedIds = [];
        $installed    = self::installedList($dir);
        foreach ($installed as $item) {
            $installedIds[] = $item['app'];
        }
        return $installedIds;
    }

    /**
     * 获取模块ini
     * @param string $dir 模块目录路径
     */
    public static function getIni(string $dir): array
    {
        $infoFile = $dir . 'info.ini';
        $info     = [];
        if (is_file($infoFile)) {
            $info = parse_ini_file($infoFile, true, INI_SCANNER_TYPED) ?: [];
            if (!$info) return [];
        }
        return $info;
    }

    /**
     * 设置模块ini
     * @param string $dir 模块目录路径
     * @param array  $arr 新的ini数据
     * @return bool
     * @throws Throwable
     */
    public static function setIni(string $dir, array $arr): bool
    {
        $infoFile = $dir . 'info.ini';
        $ini      = [];
        foreach ($arr as $key => $val) {
            if (is_array($val)) {
                $ini[] = "[$key]";
                foreach ($val as $ikey => $ival) {
                    $ini[] = "$ikey = $ival";
                }
            } else {
                $ini[] = "$key = $val";
            }
        }
        // Atomic registration write: a crash mid-write must leave the previous
        // complete info.ini in place, never a truncated one.
        $body = implode("\n", $ini) . "\n";
        if (str_contains($infoFile, "\0")) {
            throw new Exception("安装登记路径不安全");
        }
        $temporary = $infoFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new Exception("配置文件没有写入权限");
        }
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) {
                throw new Exception("安装登记未完整写入");
            }
            if (!rename($temporary, $infoFile)) {
                throw new Exception("无法替换安装登记文件");
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) unlink($temporary);
        }
        return true;
    }

    public static function installedList(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $installedDir  = scandir($dir);
        $installedList = [];
        foreach ($installedDir as $item) {
            if ($item === '.' or $item === '..' || is_file($dir . $item)) {
                continue;
            }
            $tempDir = $dir . $item . DIRECTORY_SEPARATOR;
            if (!is_dir($tempDir)) {
                continue;
            }
            $info = self::getIni($tempDir);
            if (!isset($info['app'])) {
                continue;
            }
            $installedList[] = $info;
        }
        return $installedList;
    }

    /**
     * Runtime environment files (`.env`, `.env.<name>`) are operator-owned: an
     * existing destination file is never overwritten by install/upgrade.
     * `.env.example` and other templates stay managed payload and update normally.
     */
    public static function isRuntimeEnvName(string $basename): bool
    {
        return $basename === '.env'
            || (str_starts_with($basename, '.env.') && !str_ends_with($basename, '.example'));
    }

    /**
     * installByRelation
     * @param $pathRelation
     * @return void
     */
    public static function installByRelation($pathRelation): void
    {
        foreach ($pathRelation as $source => $dest) {
            $parent_dir = dirname($dest);
            if (!is_dir($parent_dir)) {
                mkdir($parent_dir, 0777, true);
            }
            $preserved = self::preserveRuntimeEnvFiles($source, $dest);
            try {
                copy_dir($source, $dest, true);
            } finally {
                self::restoreRuntimeEnvFiles($preserved);
            }
        }
    }

    /**
     * Move every destination runtime-env file aside before a wholesale copy so
     * the package payload cannot overwrite operator ports/credentials. Only
     * paths the package itself ships are candidates; files that exist solely on
     * the host are outside copy_dir's reach and need no guard.
     *
     * @return array<string,string> destination path => parked path
     */
    private static function preserveRuntimeEnvFiles(string $source, string $dest): array
    {
        $preserved = [];
        if (!is_dir($source)) {
            $relative = basename($source);
            if (self::isRuntimeEnvName($relative) && is_file($dest)) {
                $parked = $dest . '.sandkeep-' . bin2hex(random_bytes(6));
                if (!@rename($dest, $parked)) throw new \RuntimeException('无法保留已有环境文件：' . $dest);
                $preserved[$dest] = $parked;
            }
            return $preserved;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $item) {
            $basename = $item->getBasename();
            if (!self::isRuntimeEnvName($basename)) continue;
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if (!is_file($target) || is_link($target)) continue;
            $parked = $target . '.sandkeep-' . bin2hex(random_bytes(6));
            if (!@rename($target, $parked)) throw new \RuntimeException('无法保留已有环境文件：' . $target);
            $preserved[$target] = $parked;
        }
        return $preserved;
    }

    /** @param array<string,string> $preserved destination path => parked path */
    private static function restoreRuntimeEnvFiles(array $preserved): void
    {
        foreach ($preserved as $target => $parked) {
            if (is_file($target) && !@unlink($target)) {
                throw new \RuntimeException('无法保留已有环境文件：' . $target);
            }
            if (!@rename($parked, $target)) {
                throw new \RuntimeException('无法保留已有环境文件：' . $target);
            }
        }
    }

    /**
     * 执行sql语句
     * @param string $sql_file
     * @return bool
     * @throws Exception
     */
    public static function importSql(string $sql_file): bool
    {
        if (is_file($sql_file)) {
            $lines = file($sql_file);
            $tempLine = '';
            foreach ($lines as $line) {
                if (str_starts_with($line, '--') || $line == '' || str_starts_with($line, '/*')) {
                    continue;
                }
                $tempLine .= $line;
                if (str_ends_with(trim($line), ';')) {
                    try {
                        Db::execute($tempLine);
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage());
                    }
                    $tempLine = '';
                }
            }
        }
        return true;
    }

    public static function getConfig(string $dir, $key = ''): array
    {
        $configFile = $dir . 'config.json';
        if (!is_dir($dir) || !is_file($configFile)) {
            return [];
        }
        $configContent = @file_get_contents($configFile);
        $configContent = json_decode($configContent, true);
        if (!$configContent) {
            return [];
        }
        if ($key) {
            return $configContent[$key] ?? [];
        }
        return $configContent;
    }

    public static function getDepend(string $dir, string $key = ''): array
    {
        if ($key) {
            return self::getConfig($dir, $key);
        }
        $configContent = self::getConfig($dir);
        $dependKey     = ['require', 'require-dev', 'dependencies', 'devDependencies'];
        $dependArray   = [];
        foreach ($dependKey as $item) {
            if (array_key_exists($item, $configContent) && $configContent[$item]) {
                $dependArray[$item] = $configContent[$item];
            }
        }
        return $dependArray;
    }
}
