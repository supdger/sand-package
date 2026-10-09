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
     * 重启webman
     * @return bool
     */
    public static function restart(): bool
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
                fwrite(STDERR, "已核对当前宿主主进程；正在重载 HTTP worker。\n");
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
            // 所有子进程重启
            try {
                return posix_kill(posix_getppid(), SIGUSR1);
            } catch (\Throwable $e) {
                Log::error("平滑启动失败：" . $e->getMessage());
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
        if (!file_put_contents($infoFile, implode("\n", $ini) . "\n", LOCK_EX)) {
            throw new Exception("配置文件没有写入权限");
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
            copy_dir($source, $dest, true);
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
