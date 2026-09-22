<?php

namespace plugin\sandpackage\app\logic;

use Throwable;
use Saithink\Saipackage\service\Server;
use Saithink\Saipackage\service\Filesystem;
use Saithink\Saipackage\service\Depends;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\app\cache\UserMenuCache;
use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;
use plugin\sandpackage\app\service\FreshInstallRecovery;
use plugin\sandpackage\app\service\PluginStorage;
use plugin\sandpackage\app\service\AbnormalPluginCleanup;

/**
 * SaiPackage 6.0.2 / 82043f83 (MIT), with PostgreSQL and host compatibility.
 * See docs/architecture/SAIPACKAGE_POSTGRESQL_ADAPTATION.md.
 */
class InstallLogic
{
    public const UNINSTALLED = 0;
    public const INSTALLED = 1;
    public const WAIT_INSTALL = 2;
    public const CONFLICT_PENDING = 3;
    public const DEPENDENT_WAIT_INSTALL = 4;
    public const DIRECTORY_OCCUPIED = 5;
    public const RUNTIME_UNREGISTERED = 6;
    public const DEPLOYMENT_MISSING = 7;
    public const FAILED = 8;
    private const DRIVER = 'saipackage-pg-v1';
    private $operationLock = null;
    private $hostLock = null;
    private ?string $commandNonce = null;
    private ?string $commandType = null;
    private ?array $processRecord = null;
    private ?object $freshPdo = null;
    private PluginStorage $storage;

    /**
     * @var string 安装目录
     */
    protected string $installDir;

    /**
     * @var string 备份目录
     */
    protected string $backupsDir;

    /**
     * @var string 插件名称
     */
    protected string $appName = '';

    /**
     * @var string 插件根目录
     */
    protected string $appDir = '';

    public function __construct(string $appName = '')
    {
        $this->storage = new PluginStorage();
        $this->installDir = rtrim($this->storage->root(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->backupsDir = $this->installDir . 'backups' . DIRECTORY_SEPARATOR;
        if ($appName) {
            $this->assertAppName($appName);
            $this->appName = $appName;
            $this->appDir = $this->installDir . $appName . DIRECTORY_SEPARATOR;
        }
    }

    public function getInstallState()
    {
        if ($this->cleanupPending()) return self::FAILED;
        if (!is_dir($this->appDir)) {
            return is_dir(base_path() . '/plugin/' . $this->appName) ? self::RUNTIME_UNREGISTERED : self::UNINSTALLED;
        }
        $info = $this->getInfo();
        if ($info && isset($info['state'])) {
            if (!in_array($info['state'], [0, 1, 2, 3, 4, 5, 6, 7, 8, '0', '1', '2', '3', '4', '5', '6', '7', '8'], true)) return 99;
            if ((int) $info['state'] === self::INSTALLED && !is_dir(base_path() . '/plugin/' . $this->appName)) {
                return self::DEPLOYMENT_MISSING;
            }
            return (int) $info['state'];
        }

        // 目录已存在，但非正常的模块
        return Filesystem::dirIsEmpty($this->appDir) ? self::UNINSTALLED : self::DIRECTORY_OCCUPIED;
    }

    /**
     * 获取允许覆盖的目录
     * @return string[]
     */
    public function getAllowedPath(): array
    {
        $backend = 'plugin' . DIRECTORY_SEPARATOR . $this->appName;
        $frontend = env('FRONTEND_DIR', 'sandadmin-artd') . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $this->appName;
        return [
            $this->appDir . $backend => base_path() . DIRECTORY_SEPARATOR . $backend,
            $this->appDir . $frontend => dirname(base_path()) . DIRECTORY_SEPARATOR . $frontend
        ];
    }

    /**
     * 上传安装
     * @param mixed $file
     * @return array 模块的基本信息
     * @throws Throwable
     */
    public function upload(mixed $file): array
    {
        return $this->uploadFromPath($file->getRealPath());
    }

    /**
     * 从本地 zip 文件路径安装（用于在线下载后安装）
     * @param string $zipPath zip 文件完整路径
     * @return array 模块的基本信息
     * @throws Throwable
     */
    public function uploadFromPath(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) throw new ApiException('无法读取 ZIP 安装包');
        $temporary = null;
        try {
            $bytes = 0;
            $names = [];
            if ($zip->numFiles > 2048) throw new ApiException('安装包文件数量过多');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (!is_array($entry)) throw new ApiException('安装包目录不可读取');
                $name = $entry['name'];
                $parts = explode('/', rtrim($name, '/'));
                if ($name === '' || str_contains($name, '\\') || str_contains($name, ':')
                    || preg_match('/[\x00-\x1f]/', $name) || array_intersect($parts, ['', '.', '..'])
                    || isset($names[rtrim($name, '/')])) throw new ApiException('安装包包含不安全或重复路径');
                $names[rtrim($name, '/')] = true;
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) throw new ApiException('安装包不能包含符号链接');
                $bytes += $entry['size'];
                if ($bytes > 67108864) throw new ApiException('安装包解压大小超过限制');
            }
            $raw = $zip->getFromName('info.ini');
            $info = is_string($raw) ? parse_ini_string($raw, true, INI_SCANNER_TYPED) : false;
            if (!is_array($info)) throw new ApiException('插件的基础配置信息错误');
            $app = (string) ($info['app'] ?? '');
            $this->assertAppName($app);
            $this->appName = $app;
            $this->appDir = $this->installDir . $app . '/';
            $this->lock();
            $this->assertOrdinaryState();
            $old = $this->getInfo();
            $state = $this->getInstallState();
            $upgrade = $state === self::INSTALLED;
            if ($upgrade && !version_compare((string) ($info['version'] ?? ''), (string) $old['version'], '>')) {
                throw new ApiException('升级包版本必须高于已安装版本');
            }
            if (!$upgrade && $state !== self::UNINSTALLED) throw new ApiException('已有安装目录或待处理候选，不能覆盖');
            foreach (['app', 'title', 'about', 'author', 'version'] as $key) {
                if (!isset($info[$key]) || !is_scalar($info[$key])) throw new ApiException('该插件的基础配置信息不完善');
            }
            foreach (['install.sql', 'update.sql', 'uninstall.sql', 'config.json'] as $name) {
                if ($zip->locateName($name) === false) throw new ApiException('插件缺少 ' . $name);
            }
            $temporary = $this->installDir . 'upload-' . bin2hex(random_bytes(8));
            if (!mkdir($temporary, 0700) || !$zip->extractTo($temporary)) throw new ApiException('插件解压失败');
            $config = json_decode((string) file_get_contents($temporary . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($config)) throw new ApiException('插件配置格式错误');
            if (!empty($config['sand_platform'])) throw new ApiException('此包依赖旧 Sand 平台安装扩展，请使用兼容宿主；未执行安装');
            if (!is_dir($temporary . '/plugin/' . $app)) throw new ApiException('插件后端目录缺失');
            $info = array_intersect_key($info, array_flip(['app', 'title', 'about', 'author', 'version', 'url', 'email', 'support']));
            $info['state'] = self::WAIT_INSTALL;
            $info['lifecycle_driver'] = self::DRIVER;
            $info['package_sha256'] = hash_file('sha256', $zipPath);
            if ($upgrade) {
                $info['update'] = 1;
                $info['upgrade_from_version'] = (string) $old['version'];
            }
            if (!Server::setIni($temporary . '/', self::quoteInfo($info))) throw new ApiException('无法保存候选信息');
            if ($upgrade) {
                $this->safeDirectory($this->backupsDir);
                $backup = $this->backupsDir . $app . '-' . bin2hex(random_bytes(8));
                if (!rename($this->appDir, $backup)) throw new ApiException('无法备份旧安装包');
            }
            if (!rename($temporary, $this->appDir)) {
                if (isset($backup)) rename($backup, $this->appDir);
                throw new ApiException('无法放置新安装包');
            }
            $temporary = null;
            return $info;
        } finally {
            $zip->close();
            if ($temporary !== null && is_dir($temporary)) Filesystem::delDir($temporary);
            $this->unlock();
        }
    }


    /**
     * 安装或更新
     * @return array
     * @throws Throwable
     */
    public function install(bool $restart = true, ?string $confirmation = null): array
    {
        $this->lock();
        try {
            $this->assertOrdinaryState();
            $state = $this->getInstallState();
            if ($state !== self::WAIT_INSTALL) throw new ApiException('插件不处于等待安装状态');
            $this->checkPackage();
            $paths = $this->checkedPaths();

            echo '开始安装[' . $this->appName . ']' . PHP_EOL;

            $info = $this->getInfo();

            $isUpdate = ($info['update'] ?? 0) == 1;
            if ($isUpdate && $confirmation !== 'UPGRADE ' . $this->appName . '@' . ($info['upgrade_from_version'] ?? '') . '->' . $info['version']) {
                throw new ApiException('升级确认内容不匹配');
            }
            if ($isUpdate) $this->assertRuntimeVersion((string) $info['upgrade_from_version']);
            $recovery = $isUpdate ? null : $this->freshRecovery();
            if ($recovery !== null) $recovery->begin($restart);
            $this->setInfo(['operation_pending' => 1]);
            try {
                if (!$isUpdate) {
                    echo '安装数据库' . PHP_EOL;
                    $sql = $this->appDir . 'install.sql';
                    (new PostgresLifecycleSqlExecutor())->executeFile($sql, $this->recoveryConnection(), $recovery->observe(...));
                    $recovery->checkpoint();
                }

                if (isset($info['update']) && $info['update'] == 1) {
                    echo '更新数据库' . PHP_EOL;
                    $sql = $this->appDir . 'update.sql';
                    (new PostgresLifecycleSqlExecutor())->executeFile($sql);

                    unset($info['update']);
                    $info['operation_pending'] = 1;
                    $this->setInfo([], $info);
                }

                $this->deployFreshOrUpgrade($paths, $restart);
                if ($recovery !== null) $recovery->checkpoint();

                $info = $this->getInfo();
                unset($info['operation_pending']);
                $this->setInfo([], $info);
                if ($recovery !== null) $recovery->complete();
                return $info;
            } catch (Throwable $error) {
                $this->setInfo(['state' => self::FAILED, 'operation_pending' => 1]);
                if ($recovery !== null) {
                    try { $recovery->checkpoint(); } catch (Throwable $checkpointError) {
                        error_log('SandPackage recovery checkpoint unavailable: ' . $checkpointError->getMessage());
                    }
                }
                error_log('SandPackage ' . $this->appName . ': ' . $error);
                throw new ApiException('插件安装未完成，请检查服务日志；禁止直接重试');
            }
        } finally {
            $this->unlock();
        }
    }

    protected function recoveryConnection(): object
    {
        return $this->freshPdo ??= \think\facade\Db::connect('pgsql')->connect();
    }

    private function freshRecovery(): FreshInstallRecovery
    {
        return new FreshInstallRecovery($this->appName, rtrim($this->appDir, '/'),
            $this->getAllowedPath(), rtrim($this->installDir, '/'), $this->recoveryConnection(),
            $this->getInfo(...));
    }

    public function inspectFreshInstallRecovery(?array $plan = null): array
    {
        $this->lock();
        try { return $this->freshRecovery()->inspect($plan); }
        finally { $this->unlock(); }
    }

    public function recoverFreshInstall(string $action, string $confirmation, ?array $plan = null, bool $restart = false): array
    {
        $this->lock();
        try {
            return $this->freshRecovery()->recover($action, $confirmation, $plan, function () use ($restart): void {
                $this->checkPackage();
                $paths = $this->checkedPaths();
                // The original deployment was absent. The journal binds partial-copy
                // contents; only paths owned by this exact candidate may be rewritten.
                foreach ($paths as $source => $target) {
                    $sourceFiles = FreshInstallRecovery::tree($source);
                    foreach (FreshInstallRecovery::tree($target) ?? [] as $name => $hash) {
                        if (!array_key_exists($name, $sourceFiles) || ($sourceFiles[$name] === 'directory') !== ($hash === 'directory')) throw new ApiException('部署目录含有不属于原候选的路径');
                    }
                }
                $this->setInfo(['state' => self::WAIT_INSTALL]);
                try {
                    $this->deployFreshOrUpgrade($paths, $restart);
                    $this->freshRecovery()->checkpoint();
                    $info = $this->getInfo();
                    unset($info['operation_pending']);
                    $this->setInfo([], $info);
                } catch (Throwable $error) {
                    $this->setInfo(['state' => self::FAILED, 'operation_pending' => 1]);
                    throw $error;
                }
            }, $restart);
        } finally { $this->unlock(); }
    }

    private function deployFreshOrUpgrade(array $paths, bool $restart): void
    {
        $this->dependConflictHandle();
        echo '安装文件' . PHP_EOL;
        set_error_handler(static function (int $severity, string $message): never { throw new \RuntimeException($message); });
        try { Server::installByRelation($paths); }
        finally { restore_error_handler(); }
        $this->dependUpdateHandle();
        UserMenuCache::clearMenuCache();
        if ($restart && Server::restart() !== true) throw new ApiException('服务重载未完成');
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function uninstall(bool $restart = true): void
    {
        $this->lock();
        try {
            $this->assertOrdinaryState();
            $state = $this->getInstallState();
            if ($state != self::INSTALLED) {
                throw new ApiException('只有正常安装的插件才能卸载');
            }
            $pathRelation = $this->checkedPaths(false);
            $this->setInfo(['operation_pending' => 1]);
            try {

                echo '开始卸载[' . $this->appName . ']' . PHP_EOL;

                echo '卸载数据库' . PHP_EOL;
                $sql = $this->appDir . 'uninstall.sql';
                (new PostgresLifecycleSqlExecutor())->executeFile($sql);

                echo '备份文件' . PHP_EOL;
                $backFiles = [];
                $index = 1;
                foreach ($pathRelation as $key => $value) {
                    if (is_dir($value)) {
                        $backFiles[$this->appName . '-' . $index] = $value;
                        $index++;
                    }
                }
                $backupsZip = $this->backupsDir . $this->appName . '-uninstall-' . date('YmdHis') . '.zip';
                $this->safeDirectory($this->backupsDir);
                Filesystem::zipDir($backFiles, $backupsZip);

                echo '卸载文件' . PHP_EOL;
                foreach ($pathRelation as $key => $value) {
                    if (is_dir($value)) {
                        Filesystem::delDir($value);
                        if (is_dir($value)) throw new ApiException('插件文件未能完全移除');
                    }
                }

                // 删除临时目录
                Filesystem::delDir($this->appDir);
                if (is_dir($this->appDir)) throw new ApiException('插件登记目录未能移除');

                // 清理菜单缓存
                UserMenuCache::clearMenuCache();

                // 重启后端
                if ($restart && Server::restart() !== true) throw new ApiException('数据库卸载已执行，但服务重载未完成');
            } catch (Throwable $error) {
                if (is_dir($this->appDir)) $this->setInfo(['state' => self::FAILED]);
                error_log('SandPackage uninstall ' . $this->appName . ': ' . $error);
                throw new ApiException('插件卸载未完成，请检查服务日志；禁止直接重试');
            }
        } finally {
            $this->unlock();
        }
    }

    /** Inspection and execution share the existing host/app locks, never the ordinary uninstall gate. */
    public function inspectCleanup(): array
    {
        $this->lock();
        try {
            $this->assertCleanupState();
            return $this->abnormalCleanup()->inspect();
        } finally { $this->unlock(); }
    }

    public function cleanup(string $fingerprint, string $confirmApp): array
    {
        $this->lock();
        try {
            $this->assertCleanupState();
            $result = $this->abnormalCleanup()->cleanup($fingerprint, $confirmApp);
            try {
                if (UserMenuCache::clearMenuCache() === false) throw new \RuntimeException('菜单缓存未刷新');
            } catch (Throwable $error) {
                error_log('SandPackage cleanup cache ' . $this->appName . ': ' . $error->getMessage());
                $result['warning'] = '插件残留已清理，但菜单缓存刷新失败；请检查缓存服务后重新加载后台';
            }
            return $result;
        } finally { $this->unlock(); }
    }

    public function prepareCleanupPackage(array $package): array
    {
        $this->lock();
        try {
            if ($this->cleanupPending()) throw new ApiException('清理已开始，不能更换清理包；请继续当前清理');
            $this->assertCleanupState();
            if (($package['app'] ?? null) !== $this->appName || !is_string($package['version'] ?? null)
                || !preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $package['version'])
                || !is_string($package['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $package['sha256'])) throw new ApiException('补充清理包身份无效');
            foreach (['install_sql', 'uninstall_sql'] as $key) {
                if (!is_string($package[$key] ?? null) || strlen($package[$key]) > 4194304) throw new ApiException('补充清理包声明无效');
            }
            $path = $this->appDir . '.cleanup-package.json';
            $this->assertSafePath($path);
            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $body = json_encode(array_intersect_key($package, array_flip(['app', 'version', 'sha256', 'install_sql', 'uninstall_sql'])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new ApiException('无法保存补充清理包');
            try {
                if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) throw new ApiException('补充清理包未完整保存');
                if (!rename($temporary, $path)) throw new ApiException('无法替换补充清理包');
            } finally { fclose($handle); if (is_file($temporary)) unlink($temporary); }
            return ['app' => $this->appName, 'version' => $package['version']];
        } finally { $this->unlock(); }
    }

    public function cleanupPending(): bool
    {
        return AbnormalPluginCleanup::pending(rtrim($this->installDir, '/'), $this->appName);
    }

    private function abnormalCleanup(): AbnormalPluginCleanup
    {
        return new AbnormalPluginCleanup($this->appName, rtrim($this->appDir, '/'),
            $this->getAllowedPath(), rtrim($this->installDir, '/'), $this->recoveryConnection());
    }

    private function assertCleanupState(): void
    {
        if ($this->cleanupPending()) return;
        if ($this->getInstallState() !== self::DEPLOYMENT_MISSING) {
            throw new ApiException('此入口用于运行文件缺失的插件；请刷新状态后选择对应操作');
        }
        $info = $this->getInfo();
        foreach (['package_backup_id', 'registration_candidate', 'failed_upgrade', 'process_recovery_required', 'dependency_command_nonce'] as $key) {
            if (!empty($info[$key])) throw new ApiException('存在升级或依赖操作记录，请先完成对应恢复再清理');
        }
        foreach (glob($this->installDir . 'locks/' . $this->appName . '-*.json') ?: [] as $journal) {
            if (is_file($journal) || is_link($journal)) throw new ApiException('存在未完成的插件操作日志，请先完成对应恢复再清理');
        }
    }

    /**
     * 检查包是否完整
     * @throws Throwable
     */
    public function checkPackage(): bool
    {
        if (!is_dir($this->appDir)) {
            throw new ApiException('插件目录不存在');
        }
        $info = $this->getInfo();
        $infoKeys = ['app', 'title', 'about', 'author', 'version', 'state'];
        foreach ($infoKeys as $value) {
            if (!array_key_exists($value, $info)) {
                throw new ApiException('该插件的基础配置信息不完善');
            }
        }
        if ($info['app'] !== $this->appName) throw new ApiException('插件标识不匹配');
        foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $file) {
            if (!is_file($this->appDir . $file) || is_link($this->appDir . $file)) throw new ApiException('插件生命周期脚本缺失或不安全');
        }
        return true;
    }

    /**
     * 依赖冲突检查
     * @return bool
     * @throws Throwable
     */
    public function dependConflictHandle(): bool
    {
        $info = $this->getInfo();
        if ($info['state'] != self::WAIT_INSTALL && $info['state'] != self::CONFLICT_PENDING) {
            return false;
        }

        $coverFiles = [];// 要覆盖的文件-备份
        $depends = Server::getDepend($this->appDir);

        $serverDep = new Depends(base_path() . DIRECTORY_SEPARATOR . 'composer.json', 'composer');
        $webDep = new Depends(dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd') . DIRECTORY_SEPARATOR . 'package.json');

        // 如果有依赖更新，增加要备份的文件
        if ($depends) {
            foreach ($depends as $key => $item) {
                if (!$item) {
                    continue;
                }
                if ($key == 'require' || $key == 'require-dev') {
                    $coverFiles[] = base_path() . DIRECTORY_SEPARATOR . 'composer.json';
                    continue;
                }
                if ($key == 'dependencies' || $key == 'devDependencies') {
                    $coverFiles[] = dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd') . DIRECTORY_SEPARATOR . 'package.json';
                }
            }
        }

        // 备份将被覆盖的文件
        if ($coverFiles) {
            $this->safeDirectory($this->backupsDir);
            $backupsZip = $this->backupsDir . $this->appName . '-cover-' . date('YmdHis') . '.zip';
            Filesystem::zip($coverFiles, $backupsZip);
        }

        if ($depends) {
            $npm = false;
            $composer = false;

            // composer config 更新
            $composerConfig = Server::getConfig($this->appDir, 'composerConfig');
            if ($composerConfig) {
                $serverDep->setComposerConfig($composerConfig);
            }

            foreach ($depends as $key => $item) {
                if (!$item) {
                    continue;
                }
                if ($key == 'require') {
                    $composer = true;
                    $serverDep->addDepends($item, false, true);
                } elseif ($key == 'require-dev') {
                    $composer = true;
                    $serverDep->addDepends($item, true, true);
                } elseif ($key == 'dependencies') {
                    $npm = true;
                    $webDep->addDepends($item, false, true);
                } elseif ($key == 'devDependencies') {
                    $npm = true;
                    $webDep->addDepends($item, true, true);
                }
            }
            if ($npm) {
                $info['npm_dependent_wait_install'] = 1;
                $info['state'] = self::DEPENDENT_WAIT_INSTALL;
            }
            if ($composer) {
                $info['composer_dependent_wait_install'] = 1;
                $info['state'] = self::DEPENDENT_WAIT_INSTALL;
            }
            if ($info['state'] != self::DEPENDENT_WAIT_INSTALL) {
                // 无冲突
                $this->setInfo([
                    'state' => self::INSTALLED,
                ]);
            } else {
                $this->setInfo([], $info);
            }
        } else {
            // 无冲突
            $this->setInfo([
                'state' => self::INSTALLED,
            ]);
        }
        return true;
    }

    /**
     * 依赖升级处理
     * @throws Throwable
     */
    public function dependUpdateHandle(): void
    {
        $info = $this->getInfo();
        if ($info['state'] == self::DEPENDENT_WAIT_INSTALL) {
            $waitInstall = [];
            if (isset($info['composer_dependent_wait_install'])) {
                $waitInstall[] = 'composer_dependent_wait_install';
            }
            if (isset($info['npm_dependent_wait_install'])) {
                $waitInstall[] = 'npm_dependent_wait_install';
            }
            if (empty($waitInstall)) {
                $this->setInfo([
                    'state' => self::INSTALLED,
                ]);
            }
        }
    }

    /**
     * 获取模块基本信息
     */
    public function getInfo(): array
    {
        if ($this->appDir === '') return [];
        $this->assertSafePath($this->appDir . 'info.ini');
        $file = $this->appDir . 'info.ini';
        if (!is_file($file)) return [];
        if (filesize($file) > 16384) throw new ApiException('安装登记超出大小限制');
        $info = @parse_ini_file($file, true, INI_SCANNER_TYPED);
        if (!is_array($info) || ($info['app'] ?? null) !== $this->appName || !is_string($info['version'] ?? null)) {
            throw new ApiException('安装登记损坏或插件标识不匹配');
        }
        return $info;
    }

    /**
     * 设置模块基本信息
     * @throws Throwable
     */
    public function setInfo(array $kv = [], array $arr = []): bool
    {
        $this->assertSafePath($this->appDir . 'info.ini');
        if ($kv) {
            $info = $this->getInfo();
            foreach ($kv as $k => $v) {
                $info[$k] = $v;
            }
            if (!Server::setIni($this->appDir, self::quoteInfo($info))) throw new ApiException('无法保存安装状态');
            return true;
        } elseif ($arr) {
            if (!Server::setIni($this->appDir, self::quoteInfo($arr))) throw new ApiException('无法保存安装状态');
            return true;
        }
        throw new ApiException('参数错误');
    }

    private static function quoteInfo(array $info): array
    {
        $result = [];
        foreach ($info as $key => $value) {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', (string) $key)) throw new ApiException('安装记录字段无效');
            if (is_array($value)) {
                $result[$key] = self::quoteInfo($value);
            } elseif (is_string($value)) {
                if (str_contains($value, "\n") || str_contains($value, "\r")) throw new ApiException('安装记录不能包含换行');
                $result[$key] = '"' . addcslashes($value, "\\\"") . '"';
            } elseif (is_int($value)) {
                $result[$key] = $value;
            } else {
                throw new ApiException('安装记录值无效');
            }
        }
        return $result;
    }
    public static function presentInfo(array $info): array
    {
        $state = (int) ($info['state'] ?? self::UNINSTALLED);
        $blocked = $state < 0 || $state >= self::DIRECTORY_OCCUPIED || !empty($info['operation_pending'])
            || (($info['lifecycle_driver'] ?? '') !== self::DRIVER && $state !== self::INSTALLED);
        return [
            'state_text' => [0 => '未安装', 1 => '已安装', 2 => '等待安装', 3 => '等待处理依赖冲突', 4 => '等待依赖安装',
                5 => '安装目录占用', 6 => '未登记', 7 => '安装文件缺失', 8 => '安装失败', 99 => '安装信息异常'][$state] ?? '需要检查旧安装状态',
            'ordinary_actions_blocked' => $blocked,
            'recovery_reason' => $blocked ? (($info['lifecycle_driver'] ?? '') === self::DRIVER
                ? '操作未完成；请使用 sandpackage:recover inspect 检查恢复动作，不能直接重试'
                : '安装记录不兼容；请在原锁定宿主处理，不能自动重试') : '',
        ];
    }

    /**
     * Read-only repository preflight. It does not acquire locks, repair records
     * or write installation state.
     *
     * @return array{state:int,version:?string,installed_version:?string,blocked:bool,reason:string}
     */
    public function ordinaryStatus(): array
    {
        $state = 99;
        $version = null;
        try {
            $info = $this->getInfo();
            $state = (int) $this->getInstallState();
            $recordedVersion = $info['version'] ?? null;
            if (is_string($recordedVersion) && $recordedVersion !== '' && strlen($recordedVersion) <= 80) {
                $version = $recordedVersion;
            }
            $this->assertOrdinaryState();
            if ($info !== [] && ($info['lifecycle_driver'] ?? '') !== self::DRIVER) {
                throw new ApiException('安装记录属于旧生命周期，请从已安装插件管理页处理');
            }
            if ($state === self::UNINSTALLED) {
                return ['state' => $state, 'version' => $version, 'installed_version' => null, 'blocked' => false, 'reason' => ''];
            }
            if ($state === self::INSTALLED) {
                return ['state' => $state, 'version' => $version, 'installed_version' => $version, 'blocked' => false, 'reason' => ''];
            }
            $reason = match ($state) {
                self::WAIT_INSTALL => '已有待安装候选，请从已安装插件管理页继续',
                self::CONFLICT_PENDING => '存在依赖冲突候选，请从已安装插件管理页继续',
                self::DEPENDENT_WAIT_INSTALL => '存在待执行依赖任务，请从已安装插件管理页继续',
                default => '当前安装状态需要检查，请从已安装插件管理页处理',
            };
            return ['state' => $state, 'version' => $version, 'installed_version' => null, 'blocked' => false, 'reason' => $reason];
        } catch (Throwable $error) {
            $reason = match ($state) {
                self::DIRECTORY_OCCUPIED => '安装候选目录已被占用或内容无效，请从已安装插件管理页处理',
                self::RUNTIME_UNREGISTERED => '插件运行目录存在，但缺少安装登记，请从已安装插件管理页处理',
                self::DEPLOYMENT_MISSING => '安装记录存在，但插件运行目录缺失，请从已安装插件管理页处理',
                self::FAILED => '插件上次安装操作失败，请从已安装插件管理页恢复',
                default => $error instanceof ApiException
                    ? $error->getMessage()
                    : '无法安全确认本地安装状态，请从已安装插件管理页处理',
            };
            return ['state' => $state, 'version' => $version, 'installed_version' => null, 'blocked' => true, 'reason' => $reason];
        }
    }

    private function assertAppName(string $app): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)
            || in_array($app, ['sandadmin', 'sandpackage', 'saiadmin', 'saipackage', 'locks', 'backups', 'fresh-recovery', 'cleanup'], true)) {
            throw new ApiException('插件标识无效或属于宿主保留目录');
        }
    }

    private function assertOrdinaryState(): void
    {
        if (rtrim($this->installDir, DIRECTORY_SEPARATOR) !== rtrim($this->storage->root(), DIRECTORY_SEPARATOR)) {
            throw new ApiException('插件存储根已变化；请重新检查后再执行操作');
        }
        $info = $this->getInfo();
        $state = $this->getInstallState();
        if ($state < 0 || $state >= self::DIRECTORY_OCCUPIED || !empty($info['operation_pending'])
            || (!empty($info) && ($info['lifecycle_driver'] ?? '') !== self::DRIVER && $state !== self::INSTALLED)) {
            throw new ApiException('旧安装状态不兼容或操作未完成，请在原锁定宿主处理；未执行变更');
        }
        if ($state !== self::INSTALLED && !empty($info['package_backup_id'])) throw new ApiException('存在旧升级候选，不能执行常规安装');
        foreach (['registration_candidate', 'failed_upgrade', 'process_recovery_required', 'dependency_command_nonce'] as $key) {
            if (!empty($info[$key])) throw new ApiException('存在旧候选或未完成操作，不能执行常规安装');
        }
        if ($state === self::INSTALLED) {
            $this->assertRuntimeVersion((string) ($info['version'] ?? ''));
        }
        foreach (glob($this->installDir . 'locks/' . $this->appName . '-*.json') ?: [] as $journal) {
            if (is_file($journal) || is_link($journal)) throw new ApiException('存在旧操作日志，请在原锁定宿主处理');
        }
    }

    private function assertRuntimeVersion(string $version): void
    {
        $file = base_path() . '/plugin/' . $this->appName . '/config/app.php';
        $this->assertSafePath($file);
        $source = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($source) || !preg_match('/[\\\'"]version[\\\'"]\\s*=>\\s*[\\\'"]([^\\\'"]+)[\\\'"]/', $source, $match)
            || $match[1] !== $version) {
            throw new ApiException('安装记录与运行版本不一致或无法静态确认，请在原锁定宿主处理');
        }
    }

    private function checkedPaths(bool $deployment = true): array
    {
        $paths = [];
        foreach ($this->getAllowedPath() as $source => $target) {
            $this->assertSafePath($source);
            $this->assertSafePath($target);
            if (is_dir($source) || !$deployment && is_dir($target)) {
                foreach ([$source, $target] as $directory) {
                    if (!is_dir($directory)) continue;
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                        if ($entry->isLink()) throw new ApiException('插件目录不能包含符号链接');
                    }
                }
                $paths[$source] = $target;
            }
        }
        if ($deployment && !isset($paths[$this->appDir . 'plugin/' . $this->appName])) throw new ApiException('插件后端目录缺失');
        return $paths;
    }

    private function assertSafePath(string $path): void
    {
        if (!str_starts_with($path, '/')) throw new ApiException('插件路径必须为绝对路径');
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new ApiException('插件路径不安全');
            $current .= '/' . $part;
            if (is_link($current)) throw new ApiException('插件路径不能经过符号链接');
        }
    }

    private function safeDirectory(string $directory): void
    {
        $this->assertSafePath($directory);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new ApiException('无法创建安装目录');
    }

    private function lock(): void
    {
        if (rtrim($this->installDir, DIRECTORY_SEPARATOR) !== $this->storage->root()) throw new ApiException('插件存储根已变化；请重新执行操作');
        $this->safeDirectory($this->installDir . 'locks');
        $this->assertSafePath($this->appDir);
        $this->hostLock = $this->openLock($this->installDir . 'locks/upstream-host.lock');
        try {
            foreach (glob($this->installDir . 'locks/host-*.json') ?: [] as $journal) {
                if (is_file($journal) || is_link($journal)) throw new ApiException('宿主仍有依赖操作记录，请先检查原任务');
            }
            $this->operationLock = $this->openLock($this->installDir . 'locks/' . $this->appName . '-operation.lock');
        } catch (Throwable $error) {
            $this->unlock();
            throw $error;
        }
    }

    private function openLock(string $path)
    {
        $this->assertSafePath($path);
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new ApiException('已有安装操作正在执行');
        }
        return $lock;
    }

    private function unlock(): void
    {
        foreach ([$this->operationLock, $this->hostLock] as $lock) {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
        $this->operationLock = $this->hostLock = null;
    }

    public function beginDependencyCommand(string $type): string
    {
        if (!in_array($type, ['npm', 'composer'], true)) throw new ApiException('依赖任务类型错误');
        $this->lock();
        try {
            $this->assertOrdinaryState();
            $info = $this->getInfo();
            if ((int) ($info['state'] ?? -1) !== self::DEPENDENT_WAIT_INSTALL || empty($info[$type . '_dependent_wait_install'])) {
                throw new ApiException('当前没有可执行的依赖任务');
            }
            $this->commandType = $type;
            $this->commandNonce = bin2hex(random_bytes(16));
            $this->setInfo(['dependency_command_nonce' => $this->commandNonce]);
            return $this->commandNonce;
        } catch (Throwable $error) {
            $this->unlock();
            throw $error;
        }
    }

    public function acquireDependencyExecutionLock(string $type, string $nonce): void
    {
        // begin holds the host and app locks for the entire terminal session.
        $this->assertCommandOwner($type, $nonce);
    }

    public function releaseDependencyExecutionLock(): void
    {
        $this->unlock();
    }

    public function releaseDependencyCommand(): void
    {
        $this->unlock();
    }

    private function assertCommandOwner(string $type, ?string $nonce): void
    {
        if (!is_resource($this->hostLock) || !is_resource($this->operationLock)
            || $nonce === null || $nonce !== $this->commandNonce || $type !== $this->commandType
            || ($this->getInfo()['dependency_command_nonce'] ?? null) !== $nonce) {
            throw new ApiException('依赖任务回调不匹配');
        }
    }

    public function recordDependencyProcessStarted(string $type, string $nonce, int $launcherPid, int $processGroupId, array $descendantPids, int $startTime): void
    {
        $this->assertCommandOwner($type, $nonce);
        if ($launcherPid < 1 || $launcherPid !== $processGroupId) throw new ApiException('依赖进程未隔离');
        $this->processRecord = ['pid' => $launcherPid, 'pgid' => $processGroupId, 'descendants' => $descendantPids];
        $this->writeProcessRecord();
    }

    public function updateDependencyProcessJournal(string $type, string $nonce, array $descendantPids, string $phase, ?int $failureTime = null): void
    {
        $this->assertCommandOwner($type, $nonce);
        if ($this->processRecord === null) throw new ApiException('依赖进程记录不存在');
        foreach ($descendantPids as $pid) {
            if (!is_int($pid) || $pid < 1) throw new ApiException('依赖子进程记录无效');
        }
        $this->processRecord['descendants'] = $descendantPids;
        $this->writeProcessRecord();
    }

    private function writeProcessRecord(): void
    {
        // A crash leaves this marker for manual inspection. No lease recovery state machine.
        $path = $this->installDir . 'locks/host-upstream.process.json';
        $this->assertSafePath($path);
        $json = json_encode(['app' => $this->appName, 'nonce' => $this->commandNonce, 'process' => $this->processRecord], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) throw new ApiException('无法保存依赖进程记录');
    }

    public function confirmDependencyProcessReaped(string $type, string $nonce): void
    {
        $this->assertCommandOwner($type, $nonce);
        if ($this->processRecord === null || !function_exists('posix_kill')) throw new ApiException('无法确认依赖进程退出');
        $ids = array_merge([-$this->processRecord['pgid'], $this->processRecord['pid']], $this->processRecord['descendants']);
        foreach ($ids as $pid) {
            if (@posix_kill($pid, 0) || posix_get_last_error() !== 3) throw new ApiException('依赖进程尚未确认退出');
        }
        if (!unlink($this->installDir . 'locks/host-upstream.process.json')) throw new ApiException('无法清理依赖进程记录');
        $this->processRecord = null;
    }

    public function dependentInstallComplete(string $type, ?string $nonce = null, bool $restart = false): array
    {
        $this->assertCommandOwner($type, $nonce);
        if ($this->processRecord !== null) throw new ApiException('依赖进程尚未确认退出');
        $info = $this->getInfo();
        if ((int) $info['state'] !== self::DEPENDENT_WAIT_INSTALL || empty($info[$type . '_dependent_wait_install'])) {
            throw new ApiException('当前没有等待完成的依赖任务');
        }
        // Same completion flags as upstream; only a successful owned process clears its flag.
        unset($info[$type . '_dependent_wait_install'], $info['dependency_command_nonce']);
        $completed = !isset($info['npm_dependent_wait_install']) && !isset($info['composer_dependent_wait_install']);
        if ($completed) {
            UserMenuCache::clearMenuCache();
            if ($restart && Server::restart() !== true) throw new ApiException('依赖完成但服务重载未完成');
            $info['state'] = self::INSTALLED;
        }
        $this->setInfo([], $info);
        return ['advanced' => true, 'completed' => $completed];
    }

    public function dependencyCommandFailed(string $type, ?string $nonce = null): bool
    {
        $this->assertCommandOwner($type, $nonce);
        if ($this->processRecord !== null) throw new ApiException('依赖进程仍需检查，不能清理任务状态');
        $info = $this->getInfo();
        unset($info['dependency_command_nonce']);
        $this->setInfo([], $info);
        return true;
    }

}
