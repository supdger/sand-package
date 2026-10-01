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
use plugin\sandpackage\app\service\HostPayloadManifest;
use plugin\sandpackage\app\service\HostPayloadFreshFiles;
use plugin\sandpackage\app\service\HostPayloadOwnership;
use plugin\sandpackage\app\service\HostPayloadPlan;
use plugin\sandpackage\app\service\HostPayloadChangeFiles;
use plugin\sandpackage\app\service\HostPayloadRuntimeChangeFiles;
use plugin\sandpackage\app\service\HostPayloadRemovalFiles;
use plugin\sandpackage\app\service\HostPayloadUninstallFinalization;
use plugin\sandpackage\app\service\HostPayloadDependencyChange;
use plugin\sandpackage\app\service\HostPayloadCandidateRollback;
use plugin\sandpackage\app\service\HostPayloadLifecycleJournal;
use plugin\sandpackage\app\service\PostgresHostCatalogFingerprint;
use plugin\sandpackage\app\service\ExistingSchemaManifest;
use plugin\sandpackage\app\service\PluginDependencyPolicy;
use plugin\sandpackage\app\service\PluginServiceCatalogPolicy;
use plugin\sandpackage\app\service\HostVersionCompatibility;

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
        if ($this->appName !== '' && HostPayloadLifecycleJournal::pending(
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload', $this->appName,
        )) return self::FAILED;
        if (!is_dir($this->appDir)) {
            return is_dir(base_path() . '/plugin/' . $this->appName) ? self::RUNTIME_UNREGISTERED : self::UNINSTALLED;
        }
        $info = $this->getInfo();
        if ($info && isset($info['state'])) {
            if (!empty($info['existing_schema_attach_pending'])) return self::FAILED;
            if (!in_array($info['state'], [0, 1, 2, 3, 4, 5, 6, 7, 8, '0', '1', '2', '3', '4', '5', '6', '7', '8'], true)) return 99;
            if ((int) $info['state'] === self::INSTALLED && !is_dir(base_path() . '/plugin/' . $this->appName)) {
                return self::DEPLOYMENT_MISSING;
            }
            if ((int) $info['state'] === self::INSTALLED && empty($info['operation_pending'])
                && !$this->hostPayloadDeployed()) {
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
            try {
                $hostFiles = HostPayloadManifest::inspectArchive($zip, $app);
            } catch (Throwable $error) {
                throw new ApiException('受控宿主载荷清单无效：' . $error->getMessage(), 400);
            }
            $this->appName = $app;
            $this->appDir = $this->installDir . $app . DIRECTORY_SEPARATOR;
            $this->lock();
            $this->assertOrdinaryState();
            $this->retireRuntimeChangeIfFinished();
            $this->retireDependencyChangeIfFinished();
            $rollback = HostPayloadCandidateRollback::pendingState(HostPayloadPlan::directoryPath($this->installDir), $this->appName);
            if ($rollback !== null) {
                if ($rollback['phase'] !== 'restored') {
                    throw new ApiException('旧候选恢复尚未完成，不能上传新包');
                }
                (new HostPayloadCandidateRollback(
                    HostPayloadPlan::directoryPath($this->installDir), $this->appName,
                    $rollback['backup_id'], $rollback['old_tree_sha256'], $rollback['new_tree_sha256'],
                ))->archiveFinished();
            }
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
            try {
                PluginDependencyPolicy::verifyBundles($temporary, $config, $app);
                PluginServiceCatalogPolicy::declaration($config, $app);
            } catch (\InvalidArgumentException $error) {
                throw new ApiException($error->getMessage(), 400);
            }
            if (!is_dir($temporary . '/plugin/' . $app)) throw new ApiException('插件后端目录缺失');
            try {
                if (HostPayloadManifest::inspectDirectory($temporary, $app) !== $hostFiles) {
                    throw new \RuntimeException('解压后的宿主载荷与 ZIP 清单不一致');
                }
            } catch (Throwable $error) {
                throw new ApiException('受控宿主载荷解压复核失败：' . $error->getMessage(), 400);
            }
            $info = array_intersect_key($info, array_flip(['app', 'title', 'about', 'author', 'version', 'url', 'email', 'support']));
            $info['state'] = self::WAIT_INSTALL;
            $info['lifecycle_driver'] = self::DRIVER;
            $info['package_sha256'] = hash_file('sha256', $zipPath);
            if (is_file($temporary . '/existing-schema.json')) $info['existing_schema_available'] = 1;
            $candidateTree = FreshInstallRecovery::tree($temporary, ['info.ini']);
            if (!is_array($candidateTree)) throw new ApiException('候选安装目录不可读取');
            $info['candidate_tree_sha256'] = hash('sha256', json_encode($candidateTree, JSON_THROW_ON_ERROR));
            if ($upgrade) {
                $info['update'] = 1;
                $info['upgrade_from_version'] = (string) $old['version'];
                if (!empty($old['existing_schema_attached'])) $info['existing_schema_attached'] = 1;
                $previousTree = FreshInstallRecovery::tree(HostPayloadPlan::directoryPath($this->appDir));
                if (!is_array($previousTree)) throw new ApiException('旧安装候选不可读取');
                $backupId = $app . '-' . bin2hex(random_bytes(8));
                $backup = $this->backupsDir . $backupId;
                $this->assertSafePath($backup);
                $info['upgrade_backup_id'] = $backupId;
                $info['upgrade_backup_tree_sha256'] = hash('sha256', json_encode($previousTree, JSON_THROW_ON_ERROR));
                $runtimeBefore = [];
                foreach ($this->getAllowedPath() as $target) {
                    $runtimeBefore[$target] = FreshInstallRecovery::tree($target);
                }
                $info['upgrade_runtime_tree_sha256'] = hash('sha256', json_encode($runtimeBefore, JSON_THROW_ON_ERROR));
            }
            if (!Server::setIni($temporary . '/', self::quoteInfo($info))) throw new ApiException('无法保存候选信息');
            if ($upgrade) {
                $this->safeDirectory($this->backupsDir);
                if (file_exists($backup) || is_link($backup)) throw new ApiException('旧安装备份目标已存在');
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
    public function install(
        bool $restart = true,
        ?string $confirmation = null,
        bool $attachExistingSchema = false,
        string $database = '',
    ): array
    {
        $this->assertHostCompatibility($this->getInfo());
        $this->preparePluginDependencies();
        $this->lock();
        try {
            $this->assertOrdinaryState();
            $state = $this->getInstallState();
            if ($state !== self::WAIT_INSTALL) throw new ApiException('插件不处于等待安装状态');
            $this->checkPackage();
            $this->assertHostCompatibility($this->getInfo());
            $this->assertPluginDependencies();
            $serviceCatalog = $this->preflightPluginServiceCatalog();
            $paths = $this->checkedPaths();
            $info = $this->getInfo();
            $isUpdate = ($info['update'] ?? 0) == 1;
            if ($attachExistingSchema && $isUpdate) throw new ApiException('已有 schema 重接仅适用于首次安装');
            if ($isUpdate && $confirmation !== 'UPGRADE ' . $this->appName . '@' . ($info['upgrade_from_version'] ?? '') . '->' . $info['version']) {
                throw new ApiException('升级确认内容不匹配');
            }
            $attachProof = $attachExistingSchema ? $this->existingSchemaAttachProof($info, $database) : null;
            if ($attachProof !== null
                && $confirmation !== 'ATTACH ' . $this->appName . '@' . $info['version'] . ' ' . $attachProof['token']) {
                throw new ApiException('已有 schema 重接确认内容不匹配');
            }
            if ($isUpdate) $this->assertRuntimeVersion((string) $info['upgrade_from_version']);
            if (!$isUpdate && !$attachExistingSchema
                && ExistingSchemaManifest::hasExistingTables($this->recoveryConnection(), $this->appName)) {
                throw new ApiException('数据库已有该插件表，普通安装会重放 install.sql；请先完成受控旧表归档或现有 schema 重接');
            }
            $hostPayload = $isUpdate ? null : $this->hostPayloadFiles();
            if ($attachExistingSchema && $hostPayload !== null) {
                throw new ApiException('现有 schema 重接暂不支持带额外宿主应用载荷的插件包');
            }
            $hostChange = $isUpdate ? $this->hostPayloadChangeFiles() : null;
            $runtimeChange = $hostChange !== null
                ? $this->hostPayloadRuntimeChangeFiles($info)
                : ($attachExistingSchema ? $this->existingSchemaAttachRuntimeChange() : null);
            $dependencyChange = $hostChange !== null || $hostPayload !== null
                ? $this->hostPayloadDependencyChange() : null;
            $hostJournal = $hostChange !== null
                ? $this->hostPayloadLifecycleJournal('upgrade', $info, $restart) : null;
            $hostPayload?->preflight();
            echo '开始安装[' . $this->appName . ']' . PHP_EOL;
            $recovery = ($isUpdate || $attachExistingSchema) ? null : $this->freshRecovery($hostPayload, $dependencyChange);
            if ($recovery !== null) $recovery->begin($restart);
            $pending = ['operation_pending' => 1];
            if ($attachExistingSchema) {
                $pending['existing_schema_attach_pending'] = 1;
                $pending['existing_schema_attached'] = 1;
                $pending['existing_schema_attach_database'] = $database;
                $pending['existing_schema_attach_token'] = $attachProof['token'];
            }
            $this->setInfo($pending);
            try {
                $hostJournal?->begin();
                $hostChange?->begin();
                $runtimeChange?->begin();
                $dependencyChange?->begin();
                if ($recovery !== null && $dependencyChange !== null) $recovery->checkpoint();
                if (!$isUpdate && !$attachExistingSchema) {
                    echo '安装数据库' . PHP_EOL;
                    $sql = $this->appDir . 'install.sql';
                    (new PostgresLifecycleSqlExecutor())->executeFile($sql, $this->recoveryConnection(), $recovery->observe(...));
                    $recovery->checkpoint();
                }

                if (isset($info['update']) && $info['update'] == 1) {
                    echo '更新数据库' . PHP_EOL;
                    $sql = $this->appDir . 'update.sql';
                    (new PostgresLifecycleSqlExecutor())->executeFile(
                        $sql,
                        $hostJournal !== null ? $this->recoveryConnection() : null,
                        $hostJournal !== null ? $hostJournal->observe(...) : null,
                    );

                    unset($info['update']);
                    $info['operation_pending'] = 1;
                    $this->setInfo([], $info);
                }

                $this->deployFreshOrUpgrade(
                    $paths, $serviceCatalog === null && $restart, $hostPayload, $hostChange, $runtimeChange, $dependencyChange,
                );
                $hostPayload?->verifyInstalled();
                if ($serviceCatalog !== null) {
                    [$port, $declaration] = $serviceCatalog;
                    echo '登记插件服务目录' . PHP_EOL;
                    $port->registerServiceActions($declaration['service'], $declaration['actions']);
                    if ($restart && Server::restart() !== true) throw new ApiException('服务重载未完成');
                }
                if ($recovery !== null) $recovery->checkpoint();
                if ($attachProof !== null && $this->existingSchemaAttachProof($info, $database)['token'] !== $attachProof['token']) {
                    throw new ApiException('已有 schema 在重接期间发生变化');
                }

                $info = $this->getInfo();
                unset($info['operation_pending'], $info['existing_schema_attach_pending']);
                $this->setInfo([], $info);
                if ($recovery !== null) $recovery->complete();
                $hostJournal?->complete();
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

    /** @return array{app:string,version:string,database:string,package_sha256:string,candidate_tree_sha256:string,manifest_sha256:string,identity:string,schema:string,ledger_rows:int,table_count:int,token:string,confirmation:string} */
    public function inspectExistingSchemaAttach(string $database): array
    {
        $this->lock();
        try {
            $this->assertOrdinaryState();
            if ($this->getInstallState() !== self::WAIT_INSTALL) {
                throw new ApiException('插件不处于等待安装状态');
            }
            $this->checkPackage();
            if ($this->hostPayloadFiles() !== null) {
                throw new ApiException('现有 schema 重接暂不支持带额外宿主应用载荷的插件包');
            }
            return $this->existingSchemaAttachProof($this->getInfo(), $database);
        } finally {
            $this->unlock();
        }
    }

    /** @return array<string,mixed> */
    public function inspectExistingSchemaAttachRecovery(): array
    {
        $this->lock();
        try { return $this->inspectExistingSchemaAttachRecoveryLocked(); }
        finally { $this->unlock(); }
    }

    /** @return array<string,mixed> */
    private function inspectExistingSchemaAttachRecoveryLocked(): array
    {
        $info = $this->getInfo();
        if ($this->getInstallState() !== self::FAILED
            || empty($info['existing_schema_attach_pending'])
            || empty($info['existing_schema_attached'])
            || empty($info['operation_pending'])
            || !is_string($info['existing_schema_attach_database'] ?? null)
            || !is_string($info['existing_schema_attach_token'] ?? null)) {
            throw new ApiException('没有可继续的现有 schema 重接');
        }
        $this->checkPackage();
        if ($this->hostPayloadFiles() !== null) {
            throw new ApiException('此恢复入口尚不支持带宿主应用载荷的重接包');
        }
        $proof = $this->existingSchemaAttachProof($info, $info['existing_schema_attach_database']);
        if (!hash_equals($info['existing_schema_attach_token'], $proof['token'])) {
            throw new ApiException('现有 schema 或候选包已变化，不能继续重接');
        }
        $runtime = $this->existingSchemaAttachRuntimeChangeFromJournal();
        if ($runtime->hasJournal()) {
            $snapshot = $runtime->inspectSnapshot();
            if ($snapshot['phase'] === 'rolled_back') {
                throw new ApiException('重接运行文件已回滚，不能继续');
            }
            $phase = $snapshot['phase'];
            $runtimeFingerprint = $snapshot['fingerprint'];
        } else {
            $empty = [];
            foreach ($this->getAllowedPath() as $target) {
                if (FreshInstallRecovery::tree($target) !== null) {
                    throw new ApiException('重接运行文件缺少事务记录，不能猜测覆盖');
                }
                $empty[$target] = null;
            }
            $phase = 'not_started';
            $runtimeFingerprint = hash('sha256', json_encode($empty, JSON_THROW_ON_ERROR));
        }
        $fingerprint = hash('sha256', json_encode([
            'app' => $this->appName, 'proof' => $proof['token'],
            'phase' => $phase, 'runtime' => $runtimeFingerprint,
        ], JSON_THROW_ON_ERROR));
        return [
            'app' => $this->appName, 'version' => $info['version'],
            'database' => $info['existing_schema_attach_database'],
            'phase' => $phase, 'fingerprint' => $fingerprint,
            'confirmation' => 'CONTINUE-ATTACH ' . $this->appName . ' ' . $fingerprint,
            'sql_executed' => false, 'restart_required' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function continueExistingSchemaAttach(string $confirmation): array
    {
        $this->lock();
        try {
            $inspection = $this->inspectExistingSchemaAttachRecoveryLocked();
            if (!hash_equals($inspection['confirmation'], $confirmation)) {
                throw new ApiException('重接恢复确认内容与当前现场不一致');
            }
            $runtime = $this->existingSchemaAttachRuntimeChangeFromJournal();
            try {
                if (!$runtime->hasJournal()) $runtime->begin();
                $runtime->apply();
                $info = $this->getInfo();
                $proof = $this->existingSchemaAttachProof($info, $info['existing_schema_attach_database']);
                if (!hash_equals($info['existing_schema_attach_token'], $proof['token'])) {
                    throw new ApiException('恢复期间现有 schema 发生变化');
                }
                UserMenuCache::clearMenuCache();
                $info['state'] = self::INSTALLED;
                unset($info['operation_pending'], $info['existing_schema_attach_pending']);
                $this->setInfo([], $info);
                return ['app' => $this->appName, 'state' => self::INSTALLED,
                    'sql_executed' => false, 'restart_required' => true];
            } catch (Throwable $error) {
                $this->setInfo(['state' => self::FAILED, 'operation_pending' => 1]);
                error_log('SandPackage attach recovery ' . $this->appName . ': ' . $error);
                throw new ApiException('重接恢复未完成，请检查服务日志；数据库未执行安装脚本');
            }
        } finally {
            $this->unlock();
        }
    }

    /** @return array{app:string,version:string,database:string,package_sha256:string,candidate_tree_sha256:string,manifest_sha256:string,identity:string,schema:string,ledger_rows:int,table_count:int,token:string,confirmation:string} */
    private function existingSchemaAttachProof(array $info, string $database): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $database) !== 1
            || ($info['update'] ?? 0) == 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($info['package_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($info['candidate_tree_sha256'] ?? '')) !== 1) {
            throw new ApiException('已有 schema 重接数据库或安装状态无效');
        }
        $connection = $this->recoveryConnection();
        try {
            $manifest = ExistingSchemaManifest::inspect(
                $this->appDir, $this->appName, (string) $info['version'], $database, $connection,
            );
            $catalog = PostgresHostCatalogFingerprint::capture($connection, $this->appName);
        } catch (Throwable $error) {
            throw new ApiException('已有 schema 重接预检失败：' . $error->getMessage(), 400);
        }
        $proof = [
            'app' => $this->appName,
            'version' => (string) $info['version'],
            'database' => $database,
            'package_sha256' => (string) ($info['package_sha256'] ?? ''),
            'candidate_tree_sha256' => (string) $info['candidate_tree_sha256'],
            'manifest_sha256' => $manifest['manifest_sha256'],
            'identity' => $catalog['identity'],
            'schema' => $catalog['schema'],
            'ledger_rows' => $manifest['ledger_rows'],
            'table_count' => $manifest['table_count'],
        ];
        $proof['token'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));
        $proof['confirmation'] = 'ATTACH ' . $this->appName . '@' . $info['version'] . ' ' . $proof['token'];
        return $proof;
    }

    private function freshRecovery(
        ?HostPayloadFreshFiles $hostPayload = null,
        ?HostPayloadDependencyChange $dependencyChange = null,
    ): FreshInstallRecovery
    {
        return new FreshInstallRecovery($this->appName, HostPayloadPlan::directoryPath($this->appDir),
            $this->getAllowedPath(), HostPayloadPlan::directoryPath($this->installDir), $this->recoveryConnection(),
            $this->getInfo(...), $hostPayload, $dependencyChange);
    }

    public function inspectFreshInstallRecovery(?array $plan = null): array
    {
        $this->lock();
        try {
            $hostPayload = $this->hostPayloadFiles();
            return $this->freshRecovery(
                $hostPayload, $hostPayload !== null ? $this->hostPayloadDependencyChange() : null,
            )->inspect($plan);
        }
        finally { $this->unlock(); }
    }

    public function inspectHostUpgradeRecovery(): array
    {
        $this->lock();
        try { return $this->inspectHostUpgradeRecoveryLocked(); }
        finally { $this->unlock(); }
    }

    private function inspectHostUpgradeRecoveryLocked(): array
    {
            $stateRoot = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload';
            $record = HostPayloadLifecycleJournal::inspect($stateRoot, $this->appName);
            if ($record === null || $record['operation'] !== 'upgrade') {
                throw new ApiException('没有可检查的受控宿主文件升级记录');
            }
            $expectedDatabase = in_array($record['phase'], ['sql_not_started', 'sql_not_committed', 'rolled_back'], true)
                ? $record['database_before'] : $record['database_after'];
            if ($expectedDatabase !== null
                && PostgresHostCatalogFingerprint::capture($this->recoveryConnection(), $this->appName) !== $expectedDatabase) {
                throw new ApiException('升级失败现场的 PostgreSQL 身份或插件表结构已变化');
            }
            $rollback = HostPayloadCandidateRollback::pendingState(HostPayloadPlan::directoryPath($this->installDir), $this->appName);
            $source = $rollback['source'] ?? HostPayloadPlan::directoryPath($this->appDir);
            $info = $this->readPackageInfo($source);
            $backupId = $info['upgrade_backup_id'] ?? null;
            $backupHash = $info['upgrade_backup_tree_sha256'] ?? null;
            $candidateHash = $info['candidate_tree_sha256'] ?? null;
            $runtimeHash = $info['upgrade_runtime_tree_sha256'] ?? null;
            if (($info['app'] ?? null) !== $this->appName
                || ($info['package_sha256'] ?? null) !== $record['package_sha256']
                || !is_string($backupId)
                || preg_match('/^' . preg_quote($this->appName, '/') . '-[a-f0-9]{16}$/D', $backupId) !== 1
                || !is_string($backupHash) || preg_match('/^[a-f0-9]{64}$/D', $backupHash) !== 1
                || !is_string($candidateHash) || preg_match('/^[a-f0-9]{64}$/D', $candidateHash) !== 1
                || !is_string($runtimeHash) || preg_match('/^[a-f0-9]{64}$/D', $runtimeHash) !== 1) {
                throw new ApiException('升级候选与原备份的身份记录不完整');
            }
            if ($rollback !== null && ($rollback['backup_id'] !== $backupId
                || $rollback['old_tree_sha256'] !== $backupHash
                || $rollback['new_tree_sha256'] !== $candidateHash)) {
                throw new ApiException('旧候选恢复日志与失败升级身份不符');
            }
            $backup = $this->backupsDir . $backupId;
            $this->assertSafePath($backup);
            $oldTree = FreshInstallRecovery::tree($backup);
            $nextTree = FreshInstallRecovery::tree($source, ['info.ini']);
            if (!is_array($oldTree) || !is_array($nextTree)
                || !hash_equals($backupHash, hash('sha256', json_encode($oldTree, JSON_THROW_ON_ERROR)))
                || !hash_equals($candidateHash, hash('sha256', json_encode($nextTree, JSON_THROW_ON_ERROR)))) {
                throw new ApiException('升级候选或精确旧备份已变化');
            }
            $old = HostPayloadManifest::inspectDirectory($backup, $this->appName);
            $next = HostPayloadManifest::inspectDirectory($source, $this->appName);
            try {
                $change = (new HostPayloadChangeFiles(
                    base_path(), $stateRoot, $source, $this->appName, $next,
                ))->inspectSnapshot();
            } catch (Throwable $error) {
                throw new ApiException('升级文件事务现场不可信：' . $error->getMessage());
            }
            if ($change['old'] !== $old) {
                throw new ApiException('升级文件事务与精确旧备份不符');
            }
            $owned = HostPayloadOwnership::read($stateRoot, $this->appName);
            $actual = [];
            foreach (array_unique(array_merge(
                array_column($old, 'path'),
                array_column($next, 'path'),
            )) as $path) {
                $target = base_path() . '/' . $path;
                HostPayloadPlan::assertSafePath($target);
                if (file_exists($target) && !is_file($target)) {
                    throw new ApiException('升级宿主目标类型已变化：' . $path);
                }
                $actual[$path] = is_file($target) ? hash_file('sha256', $target) : null;
            }
            ksort($actual, SORT_STRING);
            $runtime = [];
            foreach ($this->getAllowedPath() as $target) {
                $runtime[$target] = FreshInstallRecovery::tree($target);
            }
            if (in_array($record['phase'], ['sql_not_started', 'sql_not_committed', 'rolled_back'], true)
                && !hash_equals($runtimeHash, hash('sha256', json_encode($runtime, JSON_THROW_ON_ERROR)))) {
                throw new ApiException('回滚已确认，但旧运行目录已变化');
            }
            $runtimeJournal = $stateRoot . '/' . $this->appName . '.runtime.json';
            $this->assertSafePath($runtimeJournal);
            $runtimeSnapshot = null;
            if (is_file($runtimeJournal)) {
                try {
                    $runtimeSnapshot = $this->hostPayloadRuntimeChangeFiles($info)->inspectSnapshot();
                } catch (Throwable $error) {
                    throw new ApiException('升级运行文件事务现场不可信：' . $error->getMessage());
                }
            } elseif ($record['phase'] === 'sql_committed_deploy_pending') {
                throw new ApiException('已提交升级缺少运行文件事务记录');
            }
            $dependencyJournal = $stateRoot . '/' . $this->appName . '.dependencies.json';
            $this->assertSafePath($dependencyJournal);
            $dependencySnapshot = null;
            if (is_file($dependencyJournal)) {
                try {
                    $dependencySnapshot = $this->hostPayloadDependencyChange($source)->inspectSnapshot();
                } catch (Throwable $error) {
                    throw new ApiException('升级依赖清单事务现场不可信：' . $error->getMessage());
                }
            } elseif ($record['phase'] === 'sql_committed_deploy_pending') {
                throw new ApiException('已提交升级缺少依赖清单事务记录');
            }
            $fingerprint = hash('sha256', json_encode(
                [$record, $backupHash, $candidateHash, $change['fingerprint'],
                    $runtimeSnapshot, $dependencySnapshot, $owned, $actual, $runtime],
                JSON_THROW_ON_ERROR,
            ));
            return [
                'app' => $this->appName,
                'operation' => 'upgrade',
                'sql_phase' => $record['phase'],
                'fingerprint' => $fingerprint,
                'old_host_files' => count($old),
                'new_host_files' => count($next),
                'file_phase' => $change['phase'],
                'runtime_phase' => $runtimeSnapshot['phase'] ?? null,
                'dependency_phase' => $dependencySnapshot['phase'] ?? null,
                'rollback_phase' => $rollback['phase'] ?? null,
                'restart_requested' => $record['restart_requested'],
                'actions' => match ($record['phase']) {
                    'sql_not_committed' => ['restore-old-candidate'],
                    'sql_committed_deploy_pending' => ['continue-upgrade'],
                    default => [],
                },
            ];
    }

    public function recoverHostUpgradeRollback(string $confirmation): array
    {
        $this->lock();
        try {
            $inspection = $this->inspectHostUpgradeRecoveryLocked();
            if ($inspection['sql_phase'] !== 'sql_not_committed'
                || !in_array('restore-old-candidate', $inspection['actions'], true)) {
                throw new ApiException('仅已确认 SQL 回滚的升级可以恢复旧候选');
            }
            if ($confirmation !== 'RESTORE ' . $this->appName . ' ' . $inspection['fingerprint']) {
                throw new ApiException('旧候选恢复确认内容与当前现场不符');
            }
            $stateRoot = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload';
            $pending = HostPayloadCandidateRollback::pendingState(HostPayloadPlan::directoryPath($this->installDir), $this->appName);
            $source = $pending['source'] ?? HostPayloadPlan::directoryPath($this->appDir);
            $info = $this->readPackageInfo($source);
            $next = HostPayloadManifest::inspectDirectory($source, $this->appName);
            $change = new HostPayloadChangeFiles(
                base_path(), $stateRoot, $source, $this->appName, $next,
            );
            $change->restore();
            $runtimeJournal = $stateRoot . '/' . $this->appName . '.runtime.json';
            $this->assertSafePath($runtimeJournal);
            if (is_file($runtimeJournal)) {
                $runtimeChange = $this->hostPayloadRuntimeChangeFiles($info);
                $runtimeChange->markRolledBack();
                $runtimeChange->archiveFinished();
            }
            $dependencyJournal = $stateRoot . '/' . $this->appName . '.dependencies.json';
            $this->assertSafePath($dependencyJournal);
            if (is_file($dependencyJournal)) {
                $dependencyChange = $this->hostPayloadDependencyChange($source);
                $dependencyChange->markRolledBack();
                $dependencyChange->archiveFinished();
            }
            $rollback = new HostPayloadCandidateRollback(
                HostPayloadPlan::directoryPath($this->installDir), $this->appName,
                (string) $info['upgrade_backup_id'],
                (string) $info['upgrade_backup_tree_sha256'],
                (string) $info['candidate_tree_sha256'],
            );
            $result = $rollback->restore();
            $restored = $this->getInfo();
            if (($restored['version'] ?? null) !== ($info['upgrade_from_version'] ?? null)
                || (int) ($restored['state'] ?? -1) !== self::INSTALLED
                || !empty($restored['operation_pending'])
                || !$this->hostPayloadDeployed()) {
                throw new ApiException('旧候选、运行目录或宿主文件尚未完整恢复');
            }
            $journal = $this->hostPayloadLifecycleJournal('upgrade', $info);
            $journal->completeRollback();
            return [
                'app' => $this->appName,
                'action' => 'restore-old-candidate',
                'sql_replayed' => false,
                'failed_candidate' => $result['failed_candidate'],
                'state' => $this->getInstallState(),
            ];
        } finally {
            $this->unlock();
        }
    }

    public function continueHostUpgrade(string $confirmation, bool $restart = false): array
    {
        $this->lock();
        try {
            $inspection = $this->inspectHostUpgradeRecoveryLocked();
            if ($inspection['sql_phase'] !== 'sql_committed_deploy_pending'
                || !in_array('continue-upgrade', $inspection['actions'], true)) {
                throw new ApiException('仅数据库已确认提交的升级可以继续部署');
            }
            if ($confirmation !== 'CONTINUE ' . $this->appName . ' ' . $inspection['fingerprint']) {
                throw new ApiException('升级继续确认内容与当前现场不符');
            }
            if ($inspection['restart_requested'] && !$restart) {
                throw new ApiException('原升级要求服务重载；继续操作须显式指定重载');
            }
            $info = $this->getInfo();
            foreach (['dependency_command_nonce', 'process_recovery_required', 'failed_upgrade', 'registration_candidate'] as $key) {
                if (!empty($info[$key])) throw new ApiException('存在其他未完成操作，不能继续升级');
            }
            $dependency = $this->hostPayloadDependencyChange();
            $flags = $dependency->apply();
            $state = $flags['composer'] || $flags['npm']
                ? self::DEPENDENT_WAIT_INSTALL : self::INSTALLED;
            $changes = ['state' => $state, 'operation_pending' => 1];
            if ($flags['composer']) $changes['composer_dependent_wait_install'] = 1;
            if ($flags['npm']) $changes['npm_dependent_wait_install'] = 1;
            $this->setInfo($changes);
            $runtime = $this->hostPayloadRuntimeChangeFiles($info);
            $runtime->apply();
            $host = new HostPayloadChangeFiles(
                base_path(), HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
                HostPayloadPlan::directoryPath($this->appDir), $this->appName,
                HostPayloadManifest::inspectDirectory($this->appDir, $this->appName),
            );
            $host->apply();
            if (!$this->hostPayloadDeployed()) {
                throw new ApiException('升级宿主文件尚未完整部署');
            }
            UserMenuCache::clearMenuCache();
            if ($restart && Server::restart() !== true) throw new ApiException('服务重载未完成');
            $result = $this->getInfo();
            unset($result['operation_pending']);
            $this->setInfo([], $result);
            $this->hostPayloadLifecycleJournal('upgrade', $info)->complete();
            return [
                'app' => $this->appName,
                'action' => 'continue-upgrade',
                'sql_replayed' => false,
                'state' => $this->getInstallState(),
                'dependency_wait' => ['composer' => $flags['composer'], 'npm' => $flags['npm']],
            ];
        } finally {
            $this->unlock();
        }
    }

    public function inspectHostUninstallRecovery(): array
    {
        $this->lock();
        try {
            return $this->inspectHostUninstallRecoveryLocked();
        } finally {
            $this->unlock();
        }
    }

    private function inspectHostUninstallRecoveryLocked(): array
    {
            $stateRoot = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload';
            $record = HostPayloadLifecycleJournal::inspect($stateRoot, $this->appName);
            if ($record === null || $record['operation'] !== 'uninstall') {
                throw new ApiException('没有可检查的受控宿主文件卸载记录');
            }
            $expectedDatabase = in_array($record['phase'], ['sql_not_started', 'sql_not_committed'], true)
                ? $record['database_before'] : $record['database_after'];
            if ($expectedDatabase !== null
                && PostgresHostCatalogFingerprint::capture($this->recoveryConnection(), $this->appName) !== $expectedDatabase) {
                throw new ApiException('卸载失败现场的 PostgreSQL 身份或插件表结构已变化');
            }
            $finalization = $this->hostPayloadUninstallFinalization();
            if ($finalization->hasJournal()) {
                if ($record['phase'] !== 'sql_committed_deploy_pending') {
                    throw new ApiException('卸载收尾记录与数据库阶段不符');
                }
                try {
                    $snapshot = $finalization->inspectSnapshot();
                } catch (Throwable $error) {
                    throw new ApiException('卸载审计收尾现场不可信：' . $error->getMessage());
                }
                return [
                    'app' => $this->appName,
                    'operation' => 'uninstall',
                    'sql_phase' => $record['phase'],
                    'candidate_present' => false,
                    'owned_host_files' => 0,
                    'file_phase' => 'complete',
                    'removal_phase' => 'complete',
                    'finalization_phase' => $snapshot['phase'],
                    'restart_requested' => $record['restart_requested'],
                    'fingerprint' => hash('sha256', json_encode(
                        [$record, $snapshot['fingerprint']], JSON_THROW_ON_ERROR,
                    )),
                    'actions' => ['continue-uninstall'],
                ];
            }
            $candidate = FreshInstallRecovery::tree(HostPayloadPlan::directoryPath($this->appDir));
            $candidateInfo = $candidate !== null && is_file($this->appDir . 'info.ini')
                ? $this->getInfo() : [];
            if ($candidateInfo !== []
                && ($candidateInfo['package_sha256'] ?? null) !== $record['package_sha256']) {
                throw new ApiException('卸载登记与原安装包身份不符');
            }
            $removalJournal = $stateRoot . '/' . $this->appName . '.removal.json';
            $this->assertSafePath($removalJournal);
            $removalSnapshot = null;
            if (is_file($removalJournal)) {
                $removal = new HostPayloadRemovalFiles(
                    $stateRoot, $this->appName,
                    array_values($this->getAllowedPath()), HostPayloadPlan::directoryPath($this->appDir),
                );
                try {
                    $removalSnapshot = $removal->inspectSnapshot();
                } catch (Throwable $error) {
                    throw new ApiException('卸载运行文件现场不可信：' . $error->getMessage());
                }
            }
            try {
                $change = (new HostPayloadChangeFiles(
                    base_path(), $stateRoot, HostPayloadPlan::directoryPath($this->appDir), $this->appName, [],
                ))->inspectSnapshot();
            } catch (Throwable $error) {
                throw new ApiException('卸载文件事务现场不可信：' . $error->getMessage());
            }
            if ($candidate !== null && is_file($this->appDir . 'host-payload.json')
                && $change['old'] !== HostPayloadManifest::inspectDirectory($this->appDir, $this->appName)) {
                throw new ApiException('卸载文件事务与原安装包不符');
            }
            $owned = HostPayloadOwnership::read($stateRoot, $this->appName);
            $actual = [];
            foreach ($owned ?? [] as $entry) {
                $target = base_path() . '/' . $entry['path'];
                HostPayloadPlan::assertSafePath($target);
                if (file_exists($target) && !is_file($target)) {
                    throw new ApiException('卸载宿主目标类型已变化：' . $entry['path']);
                }
                $actual[$entry['path']] = is_file($target) ? hash_file('sha256', $target) : null;
            }
            $runtime = [];
            if ($removalSnapshot === null) {
                foreach ($this->getAllowedPath() as $target) {
                    $runtime[$target] = FreshInstallRecovery::tree($target);
                }
            }
            $hostUnchanged = $owned === $change['old'];
            if ($hostUnchanged) {
                foreach ($change['old'] as $entry) {
                    if (($actual[$entry['path']] ?? null) !== $entry['sha256']) {
                        $hostUnchanged = false;
                        break;
                    }
                }
            }
            $rollbackSafe = $removalSnapshot !== null
                && $removalSnapshot['unchanged']
                && $hostUnchanged
                && in_array($change['phase'], ['pending', 'rolled_back'], true)
                && ($candidateInfo['package_sha256'] ?? null) === $record['package_sha256']
                && (int) ($candidateInfo['state'] ?? -1) === self::INSTALLED
                && empty($candidateInfo['operation_pending']);
            return [
                'app' => $this->appName,
                'operation' => 'uninstall',
                'sql_phase' => $record['phase'],
                'candidate_present' => $candidate !== null,
                'owned_host_files' => count($owned ?? []),
                'file_phase' => $change['phase'],
                'removal_phase' => $removalSnapshot['phase'] ?? null,
                'restart_requested' => $record['restart_requested'],
                'fingerprint' => hash('sha256', json_encode(
                    [$record, $candidate, $removalSnapshot['fingerprint'] ?? null,
                        $change['fingerprint'], $owned, $actual, $runtime],
                    JSON_THROW_ON_ERROR,
                )),
                'actions' => match (true) {
                    $record['phase'] === 'sql_committed_deploy_pending'
                        && $removalSnapshot !== null => ['continue-uninstall'],
                    in_array($record['phase'], ['sql_not_started', 'sql_not_committed', 'rolled_back'], true)
                        && $rollbackSafe => ['restore-uninstall'],
                    default => [],
                },
            ];
    }

    public function recoverHostUninstallRollback(string $confirmation): array
    {
        $this->lock();
        try {
            $inspection = $this->inspectHostUninstallRecoveryLocked();
            if (!in_array($inspection['sql_phase'], ['sql_not_started', 'sql_not_committed', 'rolled_back'], true)
                || !in_array('restore-uninstall', $inspection['actions'], true)) {
                throw new ApiException('仅数据库已确认回滚且文件未变的卸载可以恢复');
            }
            if ($confirmation !== 'RESTORE-UNINSTALL ' . $this->appName . ' ' . $inspection['fingerprint']) {
                throw new ApiException('卸载回滚确认内容与当前现场不符');
            }
            $stateRoot = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload';
            $record = HostPayloadLifecycleJournal::inspect($stateRoot, $this->appName);
            $host = new HostPayloadChangeFiles(
                base_path(), $stateRoot, HostPayloadPlan::directoryPath($this->appDir), $this->appName, [],
            );
            $removal = new HostPayloadRemovalFiles(
                $stateRoot, $this->appName,
                array_values($this->getAllowedPath()), HostPayloadPlan::directoryPath($this->appDir),
            );
            $host->restore();
            $removal->cancel();
            if (in_array($record['phase'], ['sql_not_started', 'sql_not_committed'], true)) {
                (new HostPayloadLifecycleJournal(
                    $stateRoot, $this->appName, 'uninstall', $record['package_sha256'],
                    $this->recoveryConnection(), $record['restart_requested'],
                ))->completeRollback();
            }
            if ($this->getInstallState() !== self::INSTALLED || !$this->hostPayloadDeployed()) {
                throw new ApiException('已回滚卸载的原插件文件尚未完整恢复');
            }
            return [
                'app' => $this->appName,
                'action' => 'restore-uninstall',
                'sql_replayed' => false,
                'state' => self::INSTALLED,
            ];
        } finally {
            $this->unlock();
        }
    }

    public function continueHostUninstall(string $confirmation, bool $restart = false): array
    {
        $this->lock();
        try {
            $inspection = $this->inspectHostUninstallRecoveryLocked();
            if ($inspection['sql_phase'] !== 'sql_committed_deploy_pending'
                || !in_array('continue-uninstall', $inspection['actions'], true)) {
                throw new ApiException('仅数据库已确认提交的卸载可以继续清理文件');
            }
            if ($confirmation !== 'CONTINUE-UNINSTALL ' . $this->appName . ' ' . $inspection['fingerprint']) {
                throw new ApiException('卸载继续确认内容与当前现场不符');
            }
            if ($inspection['restart_requested'] && !$restart) {
                throw new ApiException('原卸载要求服务重载；继续操作须显式指定重载');
            }
            $stateRoot = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload';
            $record = HostPayloadLifecycleJournal::inspect($stateRoot, $this->appName);
            $finalization = $this->hostPayloadUninstallFinalization();
            if (!$finalization->hasJournal()) {
                $removal = new HostPayloadRemovalFiles(
                    $stateRoot, $this->appName,
                    array_values($this->getAllowedPath()), HostPayloadPlan::directoryPath($this->appDir),
                );
                $host = new HostPayloadChangeFiles(
                    base_path(), $stateRoot, HostPayloadPlan::directoryPath($this->appDir), $this->appName, [],
                );
                $removal->applyRuntime();
                $host->apply();
                $removal->applyCandidate();
                UserMenuCache::clearMenuCache();
                if ($restart && Server::restart() !== true) throw new ApiException('服务重载未完成');
                $removal->complete();
                $finalization->begin();
            }
            $finalization->finish();
            (new HostPayloadLifecycleJournal(
                $stateRoot, $this->appName, 'uninstall', $record['package_sha256'],
                $this->recoveryConnection(), $record['restart_requested'],
            ))->complete();
            return [
                'app' => $this->appName,
                'action' => 'continue-uninstall',
                'sql_replayed' => false,
                'state' => $this->getInstallState(),
            ];
        } finally {
            $this->unlock();
        }
    }

    public function recoverFreshInstall(string $action, string $confirmation, ?array $plan = null, bool $restart = false): array
    {
        $this->lock();
        try {
            $hostPayload = $this->hostPayloadFiles();
            $dependencyChange = $hostPayload !== null ? $this->hostPayloadDependencyChange() : null;
            $recovery = $this->freshRecovery($hostPayload, $dependencyChange);
            return $recovery->recover($action, $confirmation, $plan, function () use ($restart, $hostPayload, $dependencyChange, $recovery): void {
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
                    if ($dependencyChange !== null && !$dependencyChange->hasJournal()) {
                        $dependencyChange->begin();
                        $recovery->checkpoint();
                    }
                    $this->deployFreshOrUpgrade(
                        $paths, $restart, $hostPayload, null, null, $dependencyChange,
                    );
                    $hostPayload?->verifyInstalled();
                    $recovery->checkpoint();
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

    private function deployFreshOrUpgrade(
        array $paths,
        bool $restart,
        ?HostPayloadFreshFiles $hostPayload = null,
        ?HostPayloadChangeFiles $hostChange = null,
        ?HostPayloadRuntimeChangeFiles $runtimeChange = null,
        ?HostPayloadDependencyChange $dependencyChange = null,
    ): void
    {
        if ($dependencyChange !== null) {
            $flags = $dependencyChange->apply();
            $changes = ['state' => self::INSTALLED];
            if ($flags['composer'] || $flags['npm']) {
                $changes['state'] = self::DEPENDENT_WAIT_INSTALL;
                if ($flags['composer']) $changes['composer_dependent_wait_install'] = 1;
                if ($flags['npm']) $changes['npm_dependent_wait_install'] = 1;
            }
            $this->setInfo($changes);
        } else {
            $this->dependConflictHandle();
        }
        echo '安装文件' . PHP_EOL;
        set_error_handler(static function (int $severity, string $message): never { throw new \RuntimeException($message); });
        try {
            if ($runtimeChange !== null) $runtimeChange->apply();
            else Server::installByRelation($paths);
        }
        finally { restore_error_handler(); }
        if ($hostPayload !== null) {
            if (!$hostPayload->hasJournal()) $hostPayload->begin();
            $hostPayload->apply();
        }
        $hostChange?->apply();
        if ($dependencyChange === null) $this->dependUpdateHandle();
        UserMenuCache::clearMenuCache();
        if ($restart && Server::restart() !== true) throw new ApiException('服务重载未完成');
    }

    private function hostPayloadFiles(): ?HostPayloadFreshFiles
    {
        $candidate = HostPayloadPlan::directoryPath($this->appDir);
        if (!is_dir($candidate)) {
            $journal = HostPayloadPlan::directoryPrefix($this->installDir) . 'fresh-recovery/' . $this->appName . '.json';
            $this->assertSafePath($journal);
            $record = is_file($journal) ? json_decode((string) file_get_contents($journal), true, 32, JSON_THROW_ON_ERROR) : null;
            $operation = is_array($record) ? ($record['operation'] ?? null) : null;
            if (!is_string($operation) || preg_match('/^[a-f0-9]{32}$/D', $operation) !== 1
                || !isset($record['cleanup_stage'])) return null;
            $candidate = HostPayloadPlan::directoryPrefix($this->installDir) . 'fresh-recovery/' . $this->appName . '-' . $operation;
            if (($record['archive'] ?? null) !== $candidate || !is_dir($candidate)) return null;
        }
        $files = HostPayloadManifest::inspectDirectory($candidate, $this->appName);
        if ($files === []) return null;
        return new HostPayloadFreshFiles(
            base_path(),
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            $candidate,
            $this->appName,
            $files,
        );
    }

    private function hostPayloadChangeFiles(bool $removal = false): ?HostPayloadChangeFiles
    {
        $next = $removal ? [] : HostPayloadManifest::inspectDirectory($this->appDir, $this->appName);
        $owned = HostPayloadOwnership::read(HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload', $this->appName);
        if ($next === [] && $owned === null) return null;
        return new HostPayloadChangeFiles(
            base_path(),
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            HostPayloadPlan::directoryPath($this->appDir),
            $this->appName,
            $next,
        );
    }

    private function hostPayloadRuntimeChangeFiles(array $info): HostPayloadRuntimeChangeFiles
    {
        $prior = $info['upgrade_runtime_tree_sha256'] ?? null;
        if (!is_string($prior) || preg_match('/^[a-f0-9]{64}$/D', $prior) !== 1) {
            throw new ApiException('受控宿主文件升级缺少原运行目录摘要');
        }
        return new HostPayloadRuntimeChangeFiles(
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            $this->appName,
            $this->getAllowedPath(),
            $prior,
        );
    }

    private function existingSchemaAttachRuntimeChange(): HostPayloadRuntimeChangeFiles
    {
        $paths = $this->getAllowedPath();
        $before = [];
        foreach ($paths as $target) {
            $this->assertSafePath($target);
            if (FreshInstallRecovery::tree($target) !== null) {
                throw new ApiException('已有插件运行文件，现有 schema 重接不能覆盖');
            }
            $before[$target] = null;
        }
        return $this->existingSchemaAttachRuntimeChangeFromJournal($before);
    }

    /** @param array<string,null>|null $before */
    private function existingSchemaAttachRuntimeChangeFromJournal(?array $before = null): HostPayloadRuntimeChangeFiles
    {
        $paths = $this->getAllowedPath();
        if ($before === null) {
            $before = [];
            foreach ($paths as $target) $before[$target] = null;
        }
        return new HostPayloadRuntimeChangeFiles(
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            $this->appName,
            $paths,
            hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
        );
    }

    private function hostPayloadDependencyChange(string $candidate = ''): HostPayloadDependencyChange
    {
        return new HostPayloadDependencyChange(
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            $this->appName,
            $candidate !== '' ? $candidate : HostPayloadPlan::directoryPath($this->appDir),
            base_path() . '/composer.json',
            dirname(base_path()) . '/' . env('FRONTEND_DIR', 'sandadmin-artd') . '/package.json',
        );
    }

    private function retireRuntimeChangeIfFinished(): void
    {
        $journal = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload/' . $this->appName . '.runtime.json';
        $this->assertSafePath($journal);
        if (!is_file($journal)) return;
        $info = $this->getInfo();
        $runtime = !empty($info['existing_schema_attached'])
            && empty($info['upgrade_runtime_tree_sha256'])
            ? $this->existingSchemaAttachRuntimeChangeFromJournal()
            : $this->hostPayloadRuntimeChangeFiles($info);
        $snapshot = $runtime->inspectSnapshot();
        if (!in_array($snapshot['phase'], ['complete', 'rolled_back'], true)) {
            throw new ApiException('运行文件变更尚未结束');
        }
        $runtime->archiveFinished();
    }

    private function retireDependencyChangeIfFinished(): void
    {
        $journal = HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload/' . $this->appName . '.dependencies.json';
        $this->assertSafePath($journal);
        if (!is_file($journal)) return;
        $change = $this->hostPayloadDependencyChange();
        $snapshot = $change->inspectSnapshot();
        if (!in_array($snapshot['phase'], ['complete', 'rolled_back'], true)) {
            throw new ApiException('依赖清单变更尚未结束');
        }
        $change->archiveFinished();
    }

    private function hostPayloadLifecycleJournal(
        string $operation, array $info, bool $restart = false,
    ): HostPayloadLifecycleJournal
    {
        $sha256 = $info['package_sha256'] ?? null;
        if (!is_string($sha256)) throw new ApiException('受控宿主文件缺少安装包摘要');
        return new HostPayloadLifecycleJournal(
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            $this->appName,
            $operation,
            $sha256,
            $this->recoveryConnection(),
            $restart,
        );
    }

    private function hostPayloadUninstallFinalization(): HostPayloadUninstallFinalization
    {
        return new HostPayloadUninstallFinalization(
            HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
            base_path(),
            $this->appName,
            array_values($this->getAllowedPath()),
            HostPayloadPlan::directoryPath($this->appDir),
        );
    }

    private function hostPayloadDeployed(): bool
    {
        try {
            $files = HostPayloadManifest::inspectDirectory($this->appDir, $this->appName);
            $owned = HostPayloadOwnership::read(HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload', $this->appName);
            if ($files === []) return $owned === null;
            if ($owned !== $files) return false;
            foreach ($files as $entry) {
                $target = base_path() . '/' . $entry['path'];
                HostPayloadPlan::assertSafePath($target);
                if (!is_file($target) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $target))) {
                    return false;
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
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
            if (!empty($this->getInfo()['existing_schema_attached'])) {
                throw new ApiException('插件接管的是既有数据库结构，普通卸载会删除原有数据；此入口禁止卸载');
            }
            $this->hostPayloadUninstallFinalization()->retireCompleted();
            $this->retireRuntimeChangeIfFinished();
            $this->retireDependencyChangeIfFinished();
            $pathRelation = $this->checkedPaths(false);
            $hostRemoval = $this->hostPayloadChangeFiles(true);
            $hostJournal = $hostRemoval !== null
                ? $this->hostPayloadLifecycleJournal('uninstall', $this->getInfo(), $restart) : null;
            $removal = $hostRemoval !== null ? new HostPayloadRemovalFiles(
                HostPayloadPlan::directoryPrefix($this->installDir) . 'host-payload',
                $this->appName,
                array_values($this->getAllowedPath()),
                HostPayloadPlan::directoryPath($this->appDir),
            ) : null;
            if ($hostRemoval === null) $this->setInfo(['operation_pending' => 1]);
            try {
                $hostJournal?->begin();
                $hostRemoval?->begin();
                $removal?->begin();

                echo '开始卸载[' . $this->appName . ']' . PHP_EOL;

                echo '卸载数据库' . PHP_EOL;
                $sql = $this->appDir . 'uninstall.sql';
                (new PostgresLifecycleSqlExecutor())->executeFile(
                    $sql,
                    $hostJournal !== null ? $this->recoveryConnection() : null,
                    $hostJournal !== null ? $hostJournal->observe(...) : null,
                );

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
                if ($removal !== null) {
                    $removal->applyRuntime();
                } else {
                    foreach ($pathRelation as $key => $value) {
                        if (is_dir($value)) {
                            Filesystem::delDir($value);
                            if (is_dir($value)) throw new ApiException('插件文件未能完全移除');
                        }
                    }
                }
                $hostRemoval?->apply();

                // 删除临时目录
                if ($removal !== null) $removal->applyCandidate();
                else Filesystem::delDir($this->appDir);
                if (is_dir($this->appDir)) throw new ApiException('插件登记目录未能移除');

                // 清理菜单缓存
                UserMenuCache::clearMenuCache();

                // 重启后端
                if ($restart && Server::restart() !== true) throw new ApiException('数据库卸载已执行，但服务重载未完成');
                $removal?->complete();
                if ($removal !== null) {
                    $finalization = $this->hostPayloadUninstallFinalization();
                    $finalization->begin();
                    $finalization->finish();
                }
                $hostJournal?->complete();
            } catch (Throwable $error) {
                if ($hostRemoval === null && is_dir($this->appDir)) $this->setInfo(['state' => self::FAILED]);
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
        return AbnormalPluginCleanup::pending(HostPayloadPlan::directoryPath($this->installDir), $this->appName);
    }

    private function abnormalCleanup(): AbnormalPluginCleanup
    {
        return new AbnormalPluginCleanup($this->appName, HostPayloadPlan::directoryPath($this->appDir),
            $this->getAllowedPath(), HostPayloadPlan::directoryPath($this->installDir), $this->recoveryConnection());
    }

    private function assertCleanupState(): void
    {
        if (!empty($this->getInfo()['existing_schema_attached'])) {
            throw new ApiException('插件接管的是既有数据库结构，自动清理不得删除原有数据');
        }
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

    /** @param array<string,mixed> $info */
    private function assertHostCompatibility(array $info): void
    {
        if (!HostVersionCompatibility::matches($info['support'] ?? null, config('plugin.sandadmin.app.version'))) {
            throw new ApiException('插件兼容范围无效或与当前宿主版本不兼容；未执行插件依赖或数据库脚本', 400);
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
        if (isset($info['candidate_tree_sha256'])) {
            $tree = FreshInstallRecovery::tree(HostPayloadPlan::directoryPath($this->appDir), ['info.ini']);
            $digest = is_array($tree) ? hash('sha256', json_encode($tree, JSON_THROW_ON_ERROR)) : '';
            if (!hash_equals((string) $info['candidate_tree_sha256'], $digest)) {
                throw new ApiException('候选安装目录与上传时摘要不一致');
            }
        }
        try {
            HostPayloadManifest::inspectDirectory($this->appDir, $this->appName);
        } catch (Throwable $error) {
            throw new ApiException('受控宿主载荷候选已变化：' . $error->getMessage(), 400);
        }
        return true;
    }

    private function assertPluginDependencies(): void
    {
        $path = $this->appDir . 'config.json';
        $this->assertSafePath($path);
        if (!is_file($path) || is_link($path) || filesize($path) > 16384) {
            throw new ApiException('插件依赖配置不可读取');
        }
        $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new ApiException('插件依赖配置格式错误');
        try {
            $requirements = PluginDependencyPolicy::requirements($config, $this->appName);
        } catch (\InvalidArgumentException $error) {
            throw new ApiException($error->getMessage(), 400);
        }
        foreach ($requirements as $name => $version) {
            $dependency = new self($name);
            if ($dependency->getInstallState() !== self::INSTALLED) {
                throw new ApiException("请先安装插件依赖 {$name}@{$version}；未执行当前插件数据库脚本", 400);
            }
            $info = $dependency->getInfo();
            if (($info['lifecycle_driver'] ?? null) !== self::DRIVER
                || !hash_equals($version, (string) ($info['version'] ?? ''))
                || !is_file(base_path() . '/plugin/' . $name . '/app/functions.php')
                || is_link(base_path() . '/plugin/' . $name . '/app/functions.php')) {
                throw new ApiException("插件依赖 {$name}@{$version} 不兼容；未执行当前插件数据库脚本", 400);
            }
        }
    }

    /** @return array{0:object,1:array{service:array{code:string,name:string},actions:array<string,string>}}|null */
    private function preflightPluginServiceCatalog(): ?array
    {
        $path = $this->appDir . 'config.json';
        $this->assertSafePath($path);
        if (!is_file($path) || is_link($path) || filesize($path) > 16384) {
            throw new ApiException('插件服务目录配置不可读取');
        }
        $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new ApiException('插件服务目录配置格式错误');
        try {
            $declaration = PluginServiceCatalogPolicy::declaration($config, $this->appName);
        } catch (\InvalidArgumentException $error) {
            throw new ApiException($error->getMessage(), 400);
        }
        if ($declaration === null) return null;
        $class = \plugin\SandIam\app\runtime\ServiceCatalog::class;
        if (!class_exists($class)) {
            throw new ApiException('SandIAM 服务目录端口不可用；未执行当前插件数据库脚本', 400);
        }
        $port = new $class();
        if (!method_exists($port, 'inspectServiceActions') || !method_exists($port, 'registerServiceActions')) {
            throw new ApiException('SandIAM 服务目录端口不兼容；未执行当前插件数据库脚本', 400);
        }
        $inspection = $port->inspectServiceActions($declaration['service'], $declaration['actions']);
        if (!is_array($inspection) || ($inspection['service_code'] ?? null) !== $declaration['service']['code']
            || ($inspection['can_register'] ?? null) !== true) {
            throw new ApiException('SandIAM 服务或动作已停用或目录预检失败；未执行当前插件数据库脚本', 400);
        }
        return [$port, $declaration];
    }

    /**
     * Prepare fixed bundled dependencies before acquiring the non-reentrant host lock.
     * An existing schema must use the separate inspected attachment flow.
     */
    private function preparePluginDependencies(): void
    {
        if ($this->getInstallState() !== self::WAIT_INSTALL) return;
        $this->checkPackage();
        $path = $this->appDir . 'config.json';
        $this->assertSafePath($path);
        if (!is_file($path) || is_link($path) || filesize($path) > 16384) {
            throw new ApiException('插件依赖配置不可读取');
        }
        $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new ApiException('插件依赖配置格式错误');
        try {
            $requirements = PluginDependencyPolicy::requirements($config, $this->appName);
            PluginDependencyPolicy::verifyBundles(HostPayloadPlan::directoryPath($this->appDir), $config, $this->appName);
        } catch (\InvalidArgumentException $error) {
            throw new ApiException($error->getMessage(), 400);
        }
        foreach ($requirements as $name => $version) {
            $dependency = new self($name);
            $state = $dependency->getInstallState();
            if ($state === self::INSTALLED) continue;
            if ($state === self::UNINSTALLED) {
                echo "上传插件依赖 {$name}@{$version}" . PHP_EOL;
                $dependency->uploadFromPath($this->appDir . 'dependencies/' . $name . '-' . $version . '.zip');
                $state = $dependency->getInstallState();
            }
            if ($state !== self::WAIT_INSTALL) {
                throw new ApiException("插件依赖 {$name}@{$version} 状态异常；请先恢复依赖插件", 400);
            }
            $candidate = $dependency->getInfo();
            if (!hash_equals($version, (string) ($candidate['version'] ?? ''))
                || !hash_equals($config['plugin_dependencies'][$name]['sha256'], (string) ($candidate['package_sha256'] ?? ''))) {
                throw new ApiException("插件依赖 {$name}@{$version} 候选包与声明不一致", 400);
            }
            if (ExistingSchemaManifest::hasExistingTables($dependency->recoveryConnection(), $name)) {
                throw new ApiException("数据库已有 {$name} 表；依赖候选已上传，请先完成现有 schema 重接，再安装 {$this->appName}", 400);
            }
            echo "安装插件依赖 {$name}@{$version}" . PHP_EOL;
            $dependency->install(false);
        }
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
        return $this->readPackageInfo(HostPayloadPlan::directoryPath($this->appDir));
    }

    private function readPackageInfo(string $directory): array
    {
        $file = HostPayloadPlan::directoryPrefix($directory) . 'info.ini';
        $this->assertSafePath($file);
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
                self::WAIT_INSTALL => '已有待安装候选，请从插件仓库继续',
                self::CONFLICT_PENDING => '存在依赖冲突候选，请从已安装插件管理页继续',
                self::DEPENDENT_WAIT_INSTALL => '存在待执行依赖任务，请从已安装插件管理页继续',
                default => '当前安装状态需要检查，请从已安装插件管理页处理',
            };
            if ($state === self::WAIT_INSTALL) {
                return [
                    'state' => $state, 'version' => $version, 'installed_version' => null,
                    'blocked' => false, 'reason' => $reason,
                    'candidate_sha256' => is_string($info['package_sha256'] ?? null) ? $info['package_sha256'] : null,
                    'candidate_update' => !empty($info['update']),
                ];
            }
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
            || in_array($app, ['sandadmin', 'sandpackage', 'saiadmin', 'saipackage', 'locks', 'backups', 'fresh-recovery', 'cleanup', 'host-payload'], true)) {
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
        if ($deployment && !isset($paths[$this->appDir . 'plugin' . DIRECTORY_SEPARATOR . $this->appName])) throw new ApiException('插件后端目录缺失');
        return $paths;
    }

    private function assertSafePath(string $path, ?bool $windows = null): void
    {
        $windows ??= DIRECTORY_SEPARATOR === '\\';
        if (str_contains($path, "\0")) throw new ApiException('插件路径不安全');
        $current = '';
        if ($windows) {
            $path = str_replace('\\', '/', $path);
            if (preg_match('~^[a-z]:/~i', $path)) {
                $current = substr($path, 0, 2);
                $path = substr($path, 3);
            } elseif (preg_match('~^//([^/]+)/([^/]+)(?:/|$)~', $path, $match)
                && !in_array($match[1], ['.', '..', '?'], true)
                && !in_array($match[2], ['.', '..'], true)) {
                $current = '//' . $match[1] . '/' . $match[2];
                $path = substr($path, strlen($match[0]));
            } else {
                throw new ApiException('插件路径必须为绝对路径');
            }
        } elseif (str_starts_with($path, '/')) {
            $path = ltrim($path, '/');
        } else {
            throw new ApiException('插件路径必须为绝对路径');
        }
        if ($path === '') throw new ApiException('插件路径不安全');
        foreach (explode('/', rtrim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new ApiException('插件路径不安全');
            $current .= '/' . $part;
            clearstatcache(true, $current);
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
