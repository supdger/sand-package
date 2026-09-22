<?php

namespace plugin\sandpackage\app\logic;

use Throwable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;
use Saithink\Saipackage\service\Server;
use Saithink\Saipackage\service\Version;
use Saithink\Saipackage\service\Filesystem;
use Saithink\Saipackage\service\Depends;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\app\cache\UserMenuCache;
use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;
use support\Log;
use think\facade\Db;
use plugin\sandpackage\app\service\PluginStorage;

/** Compatibility only: pre-upstream recovery records. Not used for new installs. */
class LegacyInstallLogic
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

    private const UPLOAD_MAX_ENTRIES = 2048;
    private const UPLOAD_MAX_UNCOMPRESSED_BYTES = 67108864;
    private const DEPENDENCY_COMMAND_LEASE_SECONDS = 1800;

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
    protected string $appName;

    /**
     * @var string 插件根目录
     */
    protected string $appDir;

    /** @var array<string,string|null> */
    private array $dependencySnapshots = [];

    /** @var array<int,array{target:string,backup:?string,touched:bool}> */
    private array $deploymentRecovery = [];

    /** @var resource|null */
    private $dependencyLock = null;

    /** @var resource|null Held by TerminalRunner from process start through callback finalization. */
    private $dependencyExecutionLock = null;

    private ?string $dependencyExecutionType = null;

    private ?string $dependencyExecutionNonce = null;

    private ?string $pendingCandidateRegistrationManifest = null;
    private PluginStorage $storage;

    /** @var resource|null */
    private $operationLock = null;

    private int $operationLockDepth = 0;

    /** @var resource|null A shared, verification-only view of the app lifecycle lock. */
    private $readOnlyVerificationLock = null;

    /** @var resource|null */
    private $uploadPreflightLock = null;

    public function __construct(string $appName = '')
    {
        $this->storage = new PluginStorage();
        $this->installDir = rtrim($this->storage->root(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->backupsDir = $this->installDir . 'backups' . DIRECTORY_SEPARATOR;
        if ($appName) {
            $this->assertAppName($appName);
            $this->appName = $appName;
            $this->appDir = $this->installDir . $appName . DIRECTORY_SEPARATOR;
            if (($this->getInfo()['lifecycle_driver'] ?? '') === 'saipackage-pg-v1') {
                throw new ApiException('此插件使用上游 PostgreSQL 安装流程，不能调用旧恢复入口');
            }
        }
    }

    public function getInstallState()
    {
        if (!is_dir($this->appDir)) {
            return $this->hasRuntimeDeployment() ? self::RUNTIME_UNREGISTERED : self::UNINSTALLED;
        }
        $info = $this->getInfo();
        if ($info && isset($info['state'])) {
            if ((int) $info['state'] === self::INSTALLED && !$this->hasRuntimeDeployment()) {
                return self::DEPLOYMENT_MISSING;
            }
            return $info['state'];
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
        $this->acquireUploadPreflightLock();
        try {
            $source = $this->uploadedFilePath($file);
            $candidate = $this->copyUploadArchiveToPrivate($source);
            $info = $this->readUploadArchiveMetadata($candidate);
            if (!@chmod($candidate, 0400)) {
                throw new ApiException('无法封存受控插件安装包');
            }
            $this->useAppName((string) $info['app']);
            $this->acquireOperationLock();
            try {
                $this->assertNoDependencyOperation();
                $this->assertNotFailedUpgradeRecovery();
                $this->assertUploadArchiveFingerprint($candidate, $info);
                return $this->stageUploadArchive($candidate, $info, true);
            } finally {
                $this->releaseOperationLock();
            }
        } catch (Throwable $e) {
            if (isset($candidate) && is_string($candidate) && is_file($candidate)) {
                @unlink($candidate);
            }
            throw $e;
        } finally {
            $this->releaseUploadPreflightLock();
        }
    }

    /**
     * 从本地 zip 文件路径安装（用于在线下载后安装）
     * @param string $zipPath zip 文件完整路径
     * @return array 模块的基本信息
     * @throws Throwable
     */
    public function uploadFromPath(string $zipPath): array
    {
        $this->acquireUploadPreflightLock();
        try {
            if (!is_file($zipPath)) {
                throw new ApiException('文件不存在');
            }
            $candidate = $this->copyUploadArchiveToPrivate($zipPath);
            $info = $this->readUploadArchiveMetadata($candidate);
            if (!@chmod($candidate, 0400)) {
                throw new ApiException('无法封存受控插件安装包');
            }
            $this->useAppName((string) $info['app']);
            $this->acquireOperationLock();
            try {
                $this->assertNoDependencyOperation();
                $this->assertNotFailedUpgradeRecovery();
                $this->assertUploadArchiveFingerprint($candidate, $info);
                return $this->stageUploadArchive($candidate, $info, true);
            } finally {
                $this->releaseOperationLock();
            }
        } catch (Throwable $e) {
            if (isset($candidate) && is_string($candidate) && is_file($candidate)) {
                @unlink($candidate);
            }
            throw $e;
        } finally {
            $this->releaseUploadPreflightLock();
        }
    }


    /**
     * 安装或更新
     * @return array
     * @throws Throwable
     */
    public function install(bool $restart = true, ?string $confirmation = null): array
    {
        $this->acquireOperationLock();
        try {
            $this->assertNoDependencyOperation();
            $this->assertNotFailedUpgradeRecovery();
            $state = $this->getInstallState();
            if ($state == self::INSTALLED || $state == self::DIRECTORY_OCCUPIED
                || $state == self::RUNTIME_UNREGISTERED || $state == self::DEPLOYMENT_MISSING) {
                throw new ApiException('插件已经存在');
            }

            if ($state == self::DEPENDENT_WAIT_INSTALL) {
                throw new ApiException('等待依赖安装');
            }

            $info = $this->getInfo();
            if ($this->isUpgradeCandidateStage($info)) {
                if ($this->isLegacyHistoryCandidate($info)) {
                    throw new ApiException('这是较早版本上传的升级候选，请先撤回后重新上传，不能直接升级');
                }
                if (!$this->isReadyUpgradeCandidate($info)) {
                    throw new ApiException('升级候选不完整，不能安装，请联系管理员或按恢复记录撤回');
                }
                $backup = $this->readVerifiedCandidateBackup((string) $info['package_backup_id'], (string) $info['registration_manifest']);
                $this->assertRuntimeManifest($backup['runtime_manifest']);
                if (!hash_equals($backup['runtime_manifest_hash'], (string) $info['runtime_manifest'])) {
                    throw new ApiException('升级候选运行时清单绑定不匹配，不能升级');
                }
                $this->assertUpgradeLineage($info, $backup['version']);
                $this->assertHostSupportsUpgrade($info);
                $expected = 'UPGRADE ' . $this->appName . '@' . $backup['version'] . '->' . (string) $info['version'];
                if (!is_string($confirmation) || !hash_equals($expected, $confirmation)) {
                    throw new ApiException('升级确认内容不匹配，未执行任何变更');
                }
            }
            if (($info['registration_candidate'] ?? 0) == 1) {
                throw new ApiException('检测到已有部署，请先使用插件登记流程核验，不能覆盖现有文件');
            }

            try {
                $this->recordStage('lifecycle_validation', '正在核验插件生命周期脚本');
                $lifecycleFiles = $this->requireLifecycleFiles();
                $this->recordStage('dependency_check', '正在检查插件依赖');
                $this->installPlatformDependencies();
                $this->dependConflictHandle();

            // An upgrade has a different lifecycle from a first installation:
            // do not rerun install.sql against an already installed schema.
            $info = $this->getInfo();
            $isUpdate = ($info['update'] ?? 0) == 1;
            $this->recordStage($isUpdate ? 'database_update' : 'database_install', $isUpdate ? '正在更新插件数据库' : '正在安装插件数据库');
            $sqlFile = $lifecycleFiles[$isUpdate ? 'update.sql' : 'install.sql'];
            $this->executeLifecycleSql($sqlFile, $isUpdate ? '插件升级脚本不可用或未能执行' : '插件安装脚本不可用或未能执行');

            $this->recordStage('file_deploy', '正在部署插件文件');
            $backupId = $this->deployFilesWithRecovery();
            $info = $this->getInfo();
            if (($info['npm_dependent_wait_install'] ?? 0) == 1 || ($info['composer_dependent_wait_install'] ?? 0) == 1) {
                $this->recordStage('dependency_install', '文件已部署，等待依赖安装完成', ['deployment_backup_id' => $backupId]);
                return $this->getInfo();
            }

            $this->recordStage('service_registration', '正在登记插件服务能力', ['deployment_backup_id' => $backupId]);
            $this->registerServiceCatalog();
            $info = $this->getInfo();
            $info['service_catalog_registered'] = 1;
            $this->setInfo([], $info);
            if ($restart) {
                if (Server::restart() !== true) {
                    throw new ApiException('插件已部署，但服务重载未完成，已尝试恢复之前状态');
                }
            }
            $this->markInstalled();
            $this->clearMenuCacheBestEffort();
                return $this->getInfo();
            } catch (Throwable $e) {
                $this->compensateInstallationFailure();
                $stage = $this->getCurrentStage();
                $diagnosticId = $this->recordFailure($stage, $e);
                $publicMessage = self::publicFailureMessage($stage, $diagnosticId);
                if ($publicMessage !== null) {
                    throw new ApiException($publicMessage);
                }
                throw $e instanceof ApiException ? $e : new ApiException('插件安装未完成，请查看安装状态后处理');
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function uninstall(): void
    {
        $this->acquireOperationLock();
        $state = null;
        $destructionStarted = false;
        $safeDestructivePath = false;
        $preserveOperationState = false;
        try {
            $this->assertNotFailedUpgradeRecovery();
            $state = $this->getInstallState();
            $registryExists = is_dir($this->appDir);
            if (!$registryExists) {
                if (!$this->hasRuntimeDeployment()) {
                    return;
                }
                throw new ApiException('插件登记信息缺失但仍有部署文件，需要恢复后再卸载');
            }
            $info = $this->getInfo();
            $this->assertNoDependencyOperation();
            if ($this->isUpgradeCandidateStage($info)) {
                throw new ApiException('升级候选尚未执行，只能撤回候选');
            }
            if ($this->isOperationInProgressState($state, $info)) {
                $preserveOperationState = true;
                throw new ApiException('插件操作正在执行，不能卸载');
            }
            if (!$this->isSafeUninstallState($state, $info)) {
                $this->markUninstallRecoveryRequired($info, '插件登记状态不完整或存在残留，需要恢复后再卸载');
            }
            // A registry predating the lifecycle state field is accepted only
            // after this complete package has been revalidated. Preserve that
            // proven lineage if its first canonical uninstall attempt fails.
            if (!array_key_exists('state', $info)) {
                $info['last_stable_state'] = self::INSTALLED;
                $info['failed_stage'] = 'database_uninstall';
                if ($this->setInfo([], $info) !== true) {
                    throw new ApiException('无法保存历史插件卸载恢复状态');
                }
            }
            $this->recordStage('lifecycle_validation', '正在核验插件生命周期脚本');
            $lifecycleFiles = $this->requireLifecycleFiles();
            $safeDestructivePath = true;

            echo '开始卸载[' . $this->appName . ']' . PHP_EOL;

            $this->recordStage('database_uninstall', '正在卸载插件数据库');
            echo '卸载数据库' . PHP_EOL;
            $this->executeLifecycleSql($lifecycleFiles['uninstall.sql'], '插件卸载脚本不可用或未能执行');
            $destructionStarted = true;

            echo '备份文件' . PHP_EOL;
            $backFiles = [];
            $pathRelation = $this->getAllowedPath();
            $index = 1;
            foreach ($pathRelation as $key => $value) {
                if (is_dir($value)) {
                    $backFiles[$this->appName . '-' . $index] = $value;
                    $index++;
                }
            }
            $backupsZip = $this->backupsDir . $this->appName . '-uninstall-' . date('YmdHis') . '.zip';
            Filesystem::zipDir($backFiles, $backupsZip);

            echo '卸载文件' . PHP_EOL;
            $pathRelation = $this->getAllowedPath();
            foreach ($pathRelation as $key => $value) {
                if (is_dir($value)) {
                    Filesystem::delDir($value);
                }
            }

            // 删除临时目录
            Filesystem::delDir($this->appDir);

            // 清理菜单缓存
            UserMenuCache::clearMenuCache();

            // 重启后端
            Server::restart();
        } catch (Throwable $e) {
            // A ready upgrade candidate is intentionally immutable until it is
            // explicitly confirmed for update or discarded. Do not turn this
            // authorization rejection into a failed/uninstall state.
            if (!$destructionStarted && is_dir($this->appDir) && (
                $this->isUpgradeCandidateStage($this->getInfo())
                || self::hasFailedUpgradeDatabaseUpdateMarkers($this->getInfo())
            )) {
                throw $e instanceof ApiException ? $e : new ApiException('升级候选尚未执行，只能撤回候选');
            }
            if (is_string(($this->getInfo()['dependency_command_nonce'] ?? null))) {
                throw $e instanceof ApiException ? $e : new ApiException('依赖安装任务正在执行，不能卸载');
            }
            if (!$safeDestructivePath && is_dir($this->appDir) && ($preserveOperationState
                || (($this->getInfo()['uninstall_recovery_required'] ?? 0) == 1))) {
                throw $e instanceof ApiException ? $e : new ApiException('插件卸载需要恢复后再执行');
            }
            if (is_dir($this->appDir)) {
                $this->recordFailure($this->getCurrentStage(), $e, !$destructionStarted && $state === self::INSTALLED ? self::INSTALLED : self::FAILED);
            }
            throw $e instanceof ApiException ? $e : new ApiException('插件卸载未完成，未删除插件文件或登记信息');
        } finally {
            $this->releaseOperationLock();
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
        return true;
    }

    /**
     * 依赖安装完成标记
     * @throws Throwable
     */
    public function beginDependencyCommand(string $type): string
    {
            $this->acquireOperationLock();
        try {
            $this->assertDependencyType($type);
            foreach (['npm', 'composer'] as $hostType) {
                if ($hostType !== $type) {
                    $this->recoverHostDependencyJournal($hostType);
                }
            }
            if (is_resource($this->dependencyLock)) {
                throw new ApiException('该依赖安装任务正在执行，请勿重复提交');
            }
            $this->dependencyLock = $this->acquireDependencyLock($type);
            try {
                $this->recoverDependencyProcessJournal($type);
                $this->recoverHostDependencyJournal($type);
                $info = $this->getInfo();
                if (is_string($info['dependency_command_nonce'] ?? null) && $info['dependency_command_nonce'] !== '') {
                    $this->recoverExpiredDependencyCommand($info);
                    $info = $this->getInfo();
                }
                $flag = $this->dependencyWaitFlag($type);
                if (($info['state'] ?? null) != self::DEPENDENT_WAIT_INSTALL || ($info[$flag] ?? 0) != 1
                    || !empty($info['dependency_command_nonce'])) {
                    throw new ApiException('当前没有可执行的依赖安装任务');
                }
                $hostLease = $this->readHostDependencyLease($type);
                if (is_array($hostLease) && (int) $hostLease['expires_at'] <= time()) {
                    (new self($hostLease['app']))->recoverExpiredHostLease($type, $hostLease);
                }
                $nonce = bin2hex(random_bytes(16));
                $previous = $info;
                $this->writeHostDependencyJournal($type, $nonce, 'PREPARED', $previous);
                $info['dependency_command_type'] = $type;
                $info['dependency_command_nonce'] = $nonce;
                $info['dependency_command_lease_until'] = time() + self::DEPENDENCY_COMMAND_LEASE_SECONDS;
                $info['dependency_stable_state'] = self::DEPENDENT_WAIT_INSTALL;
                $info['dependency_host_lease_state'] = 'prepared';
                $info['stage'] = 'dependency_command';
                $info['stage_label'] = $type === 'composer' ? '正在安装后端依赖' : '正在安装前端依赖';
                $info['last_error'] = '';
                $this->clearFailureDiagnostic($info);
                if ($this->setInfo([], $info) !== true) {
                    $this->rollbackDependencyCommandBegin($type, $nonce, $previous);
                    throw new ApiException('无法保存依赖安装任务状态');
                }
                $this->writeHostDependencyJournal($type, $nonce, 'APP_PREPARED', $previous);
                try {
                    $this->acquireHostDependencyLease($type, $nonce);
                    $this->writeHostDependencyJournal($type, $nonce, 'HOST_WRITTEN', $previous);
                    $info['dependency_host_lease_state'] = 'active';
                    if ($this->setInfo([], $info) !== true) {
                        throw new ApiException('无法提交依赖安装任务状态');
                    }
                    $this->writeHostDependencyJournal($type, $nonce, 'APP_ACTIVE', $previous);
                    $this->clearHostDependencyJournal($type, $nonce);
                } catch (Throwable $e) {
                    $this->rollbackDependencyCommandBegin($type, $nonce, $previous);
                    throw $e;
                }
                return $nonce;
            } catch (Throwable $e) {
                throw $e;
            } finally {
                // The persistent nonce/lease is the command owner. This flock
                // only serializes begin, so a crashed SSE request cannot hold
                // the host lock forever and prevent lease recovery.
                $this->releaseDependencyCommand();
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    public function releaseDependencyCommand(): void
    {
        if (is_resource($this->dependencyLock)) {
            @flock($this->dependencyLock, LOCK_UN);
            @fclose($this->dependencyLock);
        }
        $this->dependencyLock = null;
    }

    /**
     * TerminalRunner acquires this immediately before proc_open and retains it
     * until the matching lifecycle callback has persisted its final state.
     */
    public function acquireDependencyExecutionLock(string $type, string $nonce): void
    {
        $this->assertDependencyType($type);
        $this->acquireOperationLock();
        try {
            $this->assertDependencyCommand($type, $nonce);
            if (($this->getInfo()['dependency_host_lease_state'] ?? null) !== 'active') {
                throw new ApiException('宿主依赖命令事务尚未提交');
            }
            $lease = $this->readHostDependencyLease($type);
            if (($lease['app'] ?? null) !== $this->appName || ($lease['nonce'] ?? null) !== $nonce) {
                throw new ApiException('宿主依赖命令租约不匹配');
            }
            $lock = $this->openDependencyExecutionLock($type);
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                throw new ApiException('该宿主依赖命令正在执行，请勿重复提交');
            }
            $this->dependencyExecutionLock = $lock;
            $this->dependencyExecutionType = $type;
            $this->dependencyExecutionNonce = $nonce;
        } finally {
            $this->releaseOperationLock();
        }
    }

    public function releaseDependencyExecutionLock(): void
    {
        if (is_resource($this->dependencyExecutionLock)) {
            @flock($this->dependencyExecutionLock, LOCK_UN);
            @fclose($this->dependencyExecutionLock);
        }
        $this->dependencyExecutionLock = null;
        $this->dependencyExecutionType = null;
        $this->dependencyExecutionNonce = null;
    }

    /**
     * Persist the process ownership proof before the launcher is allowed to
     * fork the configured package manager. The host journal is deliberately
     * written first: if the app info write is interrupted, later recovery
     * still has a durable PGID to fail closed on.
     *
     * @param list<int> $descendantPids
     */
    public function recordDependencyProcessStarted(string $type, string $nonce, int $launcherPid, int $processGroupId, array $descendantPids, int $startTime): void
    {
        $this->acquireOperationLock();
        try {
            $this->assertDependencyExecutionOwner($type, $nonce);
            if ($launcherPid < 1 || $processGroupId < 1 || $launcherPid !== $processGroupId) {
                throw new ApiException('宿主依赖命令启动记录不完整');
            }
            $journal = $this->dependencyProcessJournalPayload($type, $nonce, $launcherPid, $processGroupId, $descendantPids, $startTime, 'LAUNCHER_READY');
            $this->writeJsonAtomically($this->hostDependencyProcessJournalPath($type), $journal);
            $info = $this->getInfo();
            $info['process_recovery_required'] = 1;
            $info['dependency_process_journal'] = $this->appDependencyProcessJournal($journal);
            if ($this->setInfo([], $info) !== true) {
                throw new ApiException('无法保存依赖命令进程恢复记录');
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /** @param list<int> $descendantPids */
    public function updateDependencyProcessJournal(string $type, string $nonce, array $descendantPids, string $phase, ?int $failureTime = null): void
    {
        $this->acquireOperationLock();
        try {
            $this->assertDependencyExecutionOwner($type, $nonce);
            $journal = $this->readDependencyProcessJournal($type);
            if ($journal === null || ($journal['app'] ?? null) !== $this->appName || ($journal['nonce'] ?? null) !== $nonce) {
                throw new ApiException('宿主依赖命令进程恢复记录不匹配');
            }
            $journal['descendant_pids'] = $this->normalizeProcessIds($descendantPids);
            $journal['phase'] = $phase;
            $journal['failure_time'] = $failureTime;
            $journal['updated_at'] = time();
            $this->writeJsonAtomically($this->hostDependencyProcessJournalPath($type), $journal);
            $info = $this->getInfo();
            $info['process_recovery_required'] = 1;
            $info['dependency_process_journal'] = $this->appDependencyProcessJournal($journal);
            if ($this->setInfo([], $info) !== true) {
                throw new ApiException('无法更新依赖命令进程恢复记录');
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /**
     * The caller may invoke a lifecycle callback only after this method has
     * proved the entire recorded process group and every reported descendant
     * have disappeared. App state is cleared before the host record; an
     * unlink failure leaves a host journal which is harmlessly replayable.
     */
    public function confirmDependencyProcessReaped(string $type, string $nonce): void
    {
        $this->acquireOperationLock();
        try {
            $this->assertDependencyExecutionOwner($type, $nonce);
            $journal = $this->readDependencyProcessJournal($type);
            if ($journal === null || ($journal['app'] ?? null) !== $this->appName || ($journal['nonce'] ?? null) !== $nonce
                || !$this->recordedProcessesAreDead($journal)) {
                throw new ApiException('宿主依赖命令进程尚未确认退出，需要恢复');
            }
            $info = $this->getInfo();
            unset($info['process_recovery_required'], $info['dependency_process_journal']);
            if ($this->setInfo([], $info) !== true) {
                throw new ApiException('无法清理依赖命令进程恢复记录');
            }
            if (!@unlink($this->hostDependencyProcessJournalPath($type))) {
                throw new ApiException('无法清理宿主依赖命令进程恢复记录');
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /**
     * @return array{advanced:bool,completed:bool}
     * @throws Throwable
     */
    public function dependentInstallComplete(string $type, ?string $nonce = null, bool $restart = false): array
    {
        $this->acquireOperationLock();
        try {
            if ($type === 'all') {
                throw new ApiException('依赖安装任务类型错误');
            }
            $this->assertDependencyExecutionOwner($type, $nonce);
            $this->assertDependencyCommand($type, $nonce, true);
            $info = $this->getInfo();
            unset($info[$this->dependencyWaitFlag($type)]);
            if (isset($info['npm_dependent_wait_install']) || isset($info['composer_dependent_wait_install'])) {
                $this->beginHostLeaseSettlement($type, $nonce, $info);
                $this->releaseDependencyOperation($info);
                $info['stage'] = 'dependency_install';
                $info['stage_label'] = '部分依赖已完成，仍在等待其他依赖';
                $this->setInfo([], $info);
                $this->releaseHostDependencyLease($type, $nonce);
                $this->completeHostLeaseSettlement($type, $nonce);
                return ['advanced' => true, 'completed' => false];
            }

            $this->setInfo([], $info);
            $this->recordStage('service_registration', '正在登记插件服务能力');
            try {
                $this->registerServiceCatalog();
                $info = $this->getInfo();
                $info['service_catalog_registered'] = 1;
                $this->setInfo([], $info);
                if (($info['dependency_restart_required'] ?? 0) == 1) {
                    if (Server::restart() !== true) {
                        throw new ApiException('依赖已安装，但服务重载未完成，已尝试恢复之前状态');
                    }
                }
                $info = $this->getInfo();
                $this->beginHostLeaseSettlement($type, $nonce, $info);
                $this->markInstalled($info);
                $this->releaseHostDependencyLease($type, $nonce);
                $this->completeHostLeaseSettlement($type, $nonce);
                $this->clearMenuCacheBestEffort();
                return ['advanced' => true, 'completed' => true];
            } catch (Throwable $e) {
                try {
                    $this->restoreDependencyRecovery($this->getInfo());
                    $recovered = $this->getInfo();
                    $this->releaseDependencyOperation($recovered);
                    unset($recovered['service_catalog_registered']);
                    $recovered['dependency_recovery_state'] = 'restored';
                } catch (Throwable) {
                    $recovered = $this->getInfo();
                    $this->releaseDependencyOperation($recovered);
                    $recovered['dependency_recovery_state'] = 'incomplete';
                }
                $this->beginHostLeaseSettlement($type, $nonce, $recovered);
                $this->recordFailure($this->getCurrentStage(), $e, null, $recovered);
                $this->releaseHostDependencyLease($type, $nonce);
                $this->completeHostLeaseSettlement($type, $nonce);
                throw $e instanceof ApiException ? $e : new ApiException('插件登记未完成，请查看安装状态后处理');
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /**
     * 依赖冲突检查
     * @return bool
     * @throws Throwable
     */
    public function dependConflictHandle(): bool
    {
        $this->acquireOperationLock();
        try {
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
            $backupId = $this->appName . '-dependency-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
            $backupsZip = $this->backupsDir . $backupId . '.zip';
            Filesystem::zip($coverFiles, $backupsZip);
            foreach ($coverFiles as $file) {
                $this->dependencySnapshots[$file] = is_file($file) ? (string) file_get_contents($file) : null;
            }
            $this->persistDependencySnapshots($backupId);
            $this->recordStage('dependency_check', '正在检查插件依赖', ['dependency_backup_id' => $backupId]);
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
                $info['dependency_restart_required'] = 1;
                $info['state'] = self::DEPENDENT_WAIT_INSTALL;
            }
            $this->setInfo([], $info);
        }
            return true;
        } finally {
            $this->releaseOperationLock();
        }
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
                throw new ApiException('依赖安装必须通过受控终端任务完成');
            }
        }
    }

    public function dependencyCommandFailed(string $type, ?string $nonce = null): bool
    {
        $this->acquireOperationLock();
        try {
            $info = $this->getInfo();
            if (($info['state'] ?? null) === self::FAILED && ($info['dependency_recovery_state'] ?? '') === 'restored') {
                return false;
            }
            $this->assertDependencyExecutionOwner($type, $nonce);
            $this->assertDependencyFailureCommand($type, $nonce, true);
            try {
                $this->restoreDependencyRecovery($info);
            } catch (Throwable $e) {
                $this->releaseDependencyOperation($info);
                $info['dependency_recovery_state'] = 'incomplete';
                $this->beginHostLeaseSettlement($type, $nonce, $info);
                $this->recordFailure('dependency_install', $e, null, $info);
                $this->releaseHostDependencyLease($type, $nonce);
                $this->completeHostLeaseSettlement($type, $nonce);
                throw new ApiException('依赖安装未完成，自动恢复证据不足，请按备份记录人工处理');
            }
            $info = $this->getInfo();
            $this->releaseDependencyOperation($info);
            $info['dependency_recovery_state'] = 'restored';
            $this->beginHostLeaseSettlement($type, $nonce, $info);
            $this->recordFailure('dependency_install', new ApiException($type === 'composer' ? '后端依赖命令未完成' : '前端依赖命令未完成'), null, $info);
            $this->releaseHostDependencyLease($type, $nonce);
            $this->completeHostLeaseSettlement($type, $nonce);
            return true;
        } finally {
            $this->releaseOperationLock();
        }
    }

    /**
     * Installs package-declared Sand platform dependencies from a checksum
     * verified release bundle. Existing compatible plugins are left untouched.
     *
     * @throws Throwable
     */
    private function installPlatformDependencies(): void
    {
        $platform = Server::getConfig($this->appDir, 'sand_platform');
        $dependencies = $platform['required_plugins'] ?? [];
        if ($dependencies === []) {
            return;
        }
        if (!is_array($dependencies)) {
            throw new ApiException('插件平台依赖声明格式错误');
        }

        foreach ($dependencies as $appName => $requirement) {
            if (!is_string($appName) || $appName === '' || !is_array($requirement)) {
                throw new ApiException('插件平台依赖声明格式错误');
            }
            $dependency = new self($appName);
            $state = $dependency->getInstallState();
            if ($state === self::UNINSTALLED) {
                $this->installBundledDependency($dependency, $appName, $requirement);
                $state = $dependency->getInstallState();
            }
            if ($state === self::WAIT_INSTALL) {
                $dependency->install(false);
                $state = $dependency->getInstallState();
            }
            if ($state !== self::INSTALLED) {
                throw new ApiException("插件依赖未完成安装：{$appName}");
            }
            $this->assertInstalledDependency($dependency, $appName, $requirement);
        }
    }

    /** @param array<string,mixed> $requirement */
    private function installBundledDependency(self $dependency, string $appName, array $requirement): void
    {
        $bundle = trim((string) ($requirement['bundle'] ?? ''));
        $checksum = strtolower(trim((string) ($requirement['sha256'] ?? '')));
        if ($bundle === '' || str_starts_with($bundle, '/') || str_contains($bundle, '..')
            || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new ApiException("插件依赖缺少可信发布工件：{$appName}");
        }
        $bundlePath = $this->appDir . str_replace('/', DIRECTORY_SEPARATOR, $bundle);
        if (!is_file($bundlePath) || !hash_equals($checksum, hash_file('sha256', $bundlePath))) {
            throw new ApiException("插件依赖发布工件校验失败：{$appName}");
        }

        $temporaryBundle = $this->installDir . 'dependency-' . bin2hex(random_bytes(12)) . '.zip';
        if (!copy($bundlePath, $temporaryBundle)) {
            throw new ApiException("插件依赖发布工件无法准备：{$appName}");
        }
        try {
            $uploaded = new self();
            $uploaded->uploadFromPath($temporaryBundle);
            $dependency->install(false);
        } finally {
            if (is_file($temporaryBundle)) {
                @unlink($temporaryBundle);
            }
        }
    }

    /** @param array<string,mixed> $requirement */
    private function assertInstalledDependency(self $dependency, string $appName, array $requirement): void
    {
        $info = $dependency->getInfo();
        $minimumVersion = trim((string) ($requirement['min_version'] ?? ''));
        if (($info['app'] ?? null) !== $appName || $minimumVersion === ''
            || !Version::compare($minimumVersion, (string) ($info['version'] ?? ''))) {
            throw new ApiException("插件依赖版本不兼容：{$appName}");
        }
        $functions = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $appName
            . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'functions.php';
        if (!is_file($functions)) {
            throw new ApiException("插件依赖运行入口不可用：{$appName}");
        }
        require_once $functions;
    }

    /**
     * Lets a dependency own registration of the service capabilities declared
     * by this package. The host never writes dependency-domain tables itself.
     *
     * @throws Throwable
     */
    private function registerServiceCatalog(): void
    {
        $platform = Server::getConfig($this->appDir, 'sand_platform');
        $catalog = $platform['service_catalog'] ?? null;
        if ($catalog === null) {
            return;
        }
        if (!is_array($catalog) || !is_string($catalog['dependency'] ?? null)
            || !is_string($catalog['class'] ?? null) || !is_string($catalog['method'] ?? null)
            || !is_string($catalog['rollback_method'] ?? null)
            || !is_array($catalog['service'] ?? null) || !is_array($catalog['actions'] ?? null)) {
            throw new ApiException('插件服务目录缺少可恢复登记声明');
        }
        $dependency = new self($catalog['dependency']);
        if ($dependency->getInstallState() !== self::INSTALLED) {
            throw new ApiException("插件服务目录依赖不可用：{$catalog['dependency']}");
        }
        $class = $catalog['class'];
        $method = $catalog['method'];
        $rollbackMethod = $catalog['rollback_method'];
        if (!class_exists($class) || !method_exists($class, $method) || !method_exists($class, $rollbackMethod)) {
            throw new ApiException("插件服务目录端口不可用：{$catalog['dependency']}");
        }
        $provider = new $class();
        try {
            $provider->{$method}($catalog['service'], $catalog['actions']);
        } catch (Throwable $e) {
            try {
                $provider->{$rollbackMethod}($catalog['service'], $catalog['actions']);
            } catch (Throwable) {
                throw new ApiException('插件服务登记失败且未能自动恢复');
            }
            throw $e;
        }
    }

    /** @throws Throwable */
    private function rollbackServiceCatalog(): void
    {
        $platform = Server::getConfig($this->appDir, 'sand_platform');
        $catalog = $platform['service_catalog'] ?? null;
        if ($catalog === null) {
            return;
        }
        if (!is_array($catalog) || !is_string($catalog['class'] ?? null)
            || !is_string($catalog['rollback_method'] ?? null)
            || !is_array($catalog['service'] ?? null) || !is_array($catalog['actions'] ?? null)
            || !class_exists($catalog['class']) || !method_exists($catalog['class'], $catalog['rollback_method'])) {
            throw new ApiException('插件服务登记恢复条件不足');
        }
        $provider = new $catalog['class']();
        $provider->{$catalog['rollback_method']}($catalog['service'], $catalog['actions']);
    }

    /**
     * Register an already deployed plugin without copying files, importing SQL,
     * or touching plugin business data. A package must first have been uploaded
     * as a registration candidate and must exactly match the live deployment.
     *
     * @throws Throwable
     */
    public function registerExisting(string $confirmation): array
    {
        $this->acquireOperationLock();
        try {
            $this->assertNoDependencyOperation();
            $this->assertNotFailedUpgradeRecovery();
            $info = $this->getInfo();
            $stateBeforeConfirmation = (int) ($info['state'] ?? self::UNINSTALLED);
            $wasInstalled = $stateBeforeConfirmation === self::INSTALLED;
            $this->recordStage('registration_confirmation', '正在核验插件登记确认');
            $alreadyRegistered = $this->assertRegistrationConfirmation($info, $confirmation);

            if ($alreadyRegistered) {
                $this->recordStage('lifecycle_validation', '正在核验插件生命周期脚本');
                $this->requireLifecycleFiles();
                $this->recordStage('registration_revalidation', '正在复核已登记插件的运行文件');
                $this->assertRegistrationMetadata($info);
                $manifest = $this->verifyDeploymentMatchesPackage();
                if (!hash_equals((string) $info['registration_manifest'], $manifest)) {
                    throw new ApiException('已登记插件的运行文件已变化，不能重复确认');
                }
                $info = $this->getInfo();
                $info['stage'] = 'registered';
                $info['stage_label'] = '已复核已登记插件，未执行文件部署、数据库脚本或插件代码';
                $info['last_error'] = '';
                $this->clearFailureDiagnostic($info);
                $this->setInfo([], $info);
                return $info;
            }

            $this->recordStage('lifecycle_validation', '正在核验插件生命周期脚本');
            $this->requireLifecycleFiles();
            $this->recordStage('registration_check', '正在核验已部署插件');
            $this->assertRegistrationMetadata($info);
            $manifest = $this->verifyDeploymentMatchesPackage();

            $info = $this->getInfo();
            unset($info['update'], $info['registration_candidate'], $info['last_error'], $info['last_error_code'], $info['diagnostic_id']);
            $info['state'] = self::INSTALLED;
            $info['stage'] = 'registered';
            $info['stage_label'] = '已登记现有插件，未执行文件部署、数据库脚本或插件代码';
            $info['registration_manifest'] = $manifest;
            $this->setInfo([], $info);
            $this->clearMenuCacheBestEffort();
            return $this->getInfo();
        } catch (Throwable $e) {
            if (is_dir($this->appDir) && self::hasFailedUpgradeDatabaseUpdateMarkers($this->getInfo())) {
                throw $e instanceof ApiException ? $e : new ApiException('数据库升级未完成，后续文件部署已停止。为避免覆盖可能的部分变更，请先核验恢复条件，不能执行常规安装、卸载、登记、撤回候选或上传操作', 400);
            }
            if (is_string(($this->getInfo()['dependency_command_nonce'] ?? null))) {
                throw $e instanceof ApiException ? $e : new ApiException('依赖安装任务正在执行，不能登记');
            }
            $stage = $this->getCurrentStage();
            $this->recordFailure($stage, $e, $stage === 'registration_confirmation' ? ($stateBeforeConfirmation ?? null) : ($wasInstalled ?? false ? self::INSTALLED : null));
            throw $e instanceof ApiException ? $e : new ApiException('插件登记未完成，请查看登记状态后处理');
        } finally {
            $this->releaseOperationLock();
        }
    }

    /** @param array<string,mixed> $info */
    public static function presentInfo(array $info): array
    {
        $state = (int) ($info['state'] ?? self::UNINSTALLED);
        $stateText = [
            self::UNINSTALLED => '未安装',
            self::INSTALLED => '已安装',
            self::WAIT_INSTALL => '等待安装',
            self::CONFLICT_PENDING => '等待处理依赖冲突',
            self::DEPENDENT_WAIT_INSTALL => '等待依赖安装',
            self::DIRECTORY_OCCUPIED => '安装目录被占用',
            self::RUNTIME_UNREGISTERED => '发现未登记的已部署插件',
            self::DEPLOYMENT_MISSING => '登记信息与部署文件不一致',
            self::FAILED => '操作未完成',
        ][$state] ?? '状态未知';

        $presented = [
            'state_text' => $stateText,
            'stage_label' => (string) ($info['stage_label'] ?? ''),
            'last_error' => (string) ($info['last_error'] ?? ''),
            'backup_id' => (string) ($info['deployment_backup_id'] ?? $info['package_backup_id'] ?? ''),
        ];
        $modernReplacementReady = self::isExactModernReplacementReadyShape($info)
            && is_string($info['app'] ?? null)
            && (new self($info['app']))->hasExactModernReplacementReadyEvidence($info);
        if ($modernReplacementReady) {
            $presented['recovery_mode'] = 'retry_safe';
            $presented['allowed_actions'] = ['retry_after_replacement'];
            $presented['recovery_app'] = (string) $info['app'];
            $presented['recovery_from_version'] = (string) $info['upgrade_from_version'];
            $presented['recovery_to_version'] = (string) $info['version'];
        } elseif (self::isFailedUpgradeRecoveryShape($info)
            && (self::hasCompleteModernFailedMarkers($info) || self::hasNoModernFailedMarkers($info))) {
            $presented['recovery_mode'] = 'verification_required';
            $presented['allowed_actions'] = ['prepare_failed_upgrade_replacement'];
            $presented['recovery_reason'] = '数据库升级未完成，后续文件部署已停止。为避免覆盖可能的部分变更，请先核验恢复条件。';
            $presented['ordinary_actions_blocked'] = true;
        }
        if (self::isLegacyHistoryCandidateShapeForPresentation($info)) {
            try {
                $evidence = self::legacyHistoryEvidence($info);
                $presented['legacy_recoverable'] = true;
                $presented['derived_upgrade_from_version'] = $evidence['from_version'];
                $presented['legacy_recovery_reason'] = '这是较早版本上传的候选，请先撤回后重新上传';
            } catch (Throwable) {
                $presented['legacy_recoverable'] = false;
                $presented['legacy_recovery_reason'] = '较早版本上传的候选未通过安全核验，不能操作';
            }
        }
        if (self::isModernReadyCandidateShapeForPresentation($info)) {
            try {
                self::assertCandidatePackageIdentity($info);
                $presented['upgrade_candidate_verified'] = true;
            } catch (Throwable) {
                $presented['upgrade_candidate_verified'] = false;
            }
        }
        return $presented;
    }

    /** @param array<string,mixed> $info */
    private static function isFailedUpgradeRecoveryShape(array $info): bool
    {
        return self::isFailedUpgradeDatabaseUpdateState($info)
            && is_string($info['app'] ?? null) && is_string($info['version'] ?? null)
            && self::legacyStrictSemver($info['version'])
            && is_string($info['upgrade_from_version'] ?? null)
            && self::legacyStrictSemver($info['upgrade_from_version'])
            && self::compareSemver($info['version'], $info['upgrade_from_version']) > 0;
    }

    /** @param array<string,mixed> $info */
    private static function isFailedUpgradeDatabaseUpdateState(array $info): bool
    {
        return self::isCanonicalRegistryInteger($info['state'] ?? null, self::FAILED)
            && ($info['stage'] ?? null) === 'failed'
            && ($info['failed_stage'] ?? null) === 'database_update'
            && self::isCanonicalRegistryInteger($info['update'] ?? null, 1);
    }

    private static function isCanonicalRegistryInteger(mixed $value, int $expected): bool
    {
        return $value === $expected || $value === (string) $expected;
    }

    /** @param array<string,mixed> $info */
    private static function hasFailedUpgradeDatabaseUpdateMarkers(array $info): bool
    {
        return ($info['stage'] ?? null) === 'failed'
            && ($info['failed_stage'] ?? null) === 'database_update';
    }

    /** @param array<string,mixed> $info */
    private static function isExactModernReplacementReadyShape(array $info): bool
    {
        return self::isFailedUpgradeRecoveryShape($info)
            && self::hasCompleteModernFailedMarkers($info)
            && is_string($info['failed_upgrade_replacement_id'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/D', $info['failed_upgrade_replacement_id']) === 1
            && ($info['replacement_candidate_state'] ?? null) === 'ready';
    }

    /** @param array<string,mixed> $info */
    private static function hasCompleteModernFailedMarkers(array $info): bool
    {
        foreach (['candidate_archive_sha256','candidate_payload_manifest_sha256','recovery_descriptor_sha256','update_sql_sha256'] as $field) if (!self::isSha256Value($info[$field] ?? null)) return false;
        return true;
    }

    /** @param array<string,mixed> $info */
    private static function hasNoModernFailedMarkers(array $info): bool
    {
        foreach (['candidate_archive_sha256','candidate_payload_manifest_sha256','recovery_descriptor_sha256','update_sql_sha256','failed_upgrade_replacement_id','replacement_candidate_state'] as $field) {
            if (array_key_exists($field, $info)) return false;
        }
        return true;
    }

    /** Any modern marker means the list may only offer a new verification, never retry. */
    private static function hasAnyModernFailedMarker(array $info): bool
    {
        foreach (['candidate_archive_sha256','candidate_payload_manifest_sha256','recovery_descriptor_sha256','update_sql_sha256','failed_upgrade_replacement_id','replacement_candidate_state'] as $field) {
            if (array_key_exists($field, $info)) return true;
        }
        return false;
    }

    private static function isSha256Value(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function assertNotFailedUpgradeRecovery(): void
    {
        if (isset($this->appDir) && is_dir($this->appDir) && self::hasFailedUpgradeDatabaseUpdateMarkers($this->getInfo())) {
            throw new ApiException('数据库升级未完成，后续文件部署已停止。为避免覆盖可能的部分变更，请先核验恢复条件，不能执行常规安装、卸载、登记、撤回候选或上传操作', 400);
        }
    }

    /**
     * This is a read-only preflight. It deliberately never authorizes a retry:
     * replace and retry must recalculate the same binding under their own lock.
     *
     * @return array{app:string,from_version:string,to_version:string,verdict:string,recovery_state:string,evidence_fingerprint:?string,allowed_actions:list<string>,assertions_total:int,assertions_passed:int,failed_assertion_ids:list<string>,audit_written:false,message:string}
     */
    public function verifyFailedUpgradeRecovery(int|array $actor): array
    {
        $this->acquireReadOnlyVerificationLock();
        try {
            $info = $this->requireExactFailedUpgradeRecoveryInfo();
            $this->failedUpgradeRecoveryCoordinator()->prepare($info, fn (): array => $this->runtimeRestoreDiagnostic($info));
            $identity = $this->failedUpgradeIdentityBinding($info);
            [$connection, $pdo] = $this->openPostgresRecoveryConnection();

            $verifier = new FailedUpgradeRecoveryVerifier();
            $result = $verifier->verify($identity['descriptor_raw'], $pdo, $identity['binding']);
            if (($result['connection_reusable'] ?? false) !== true) {
                // Think ORM exposes close() as the public connection disposal API.
                // Do not return a verdict from a connection whose rollback failed.
                $connection->close();
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复核验连接未能安全回滚，未授权任何后续操作', 400);
            }

            if (($result['status'] ?? null) !== 'retry_safe') {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：' . (string) ($result['message'] ?? '恢复核验未通过'), 400);
            }
            $assertions = $verifier->internalAssertionLog();

            return [
                'app' => $this->appName,
                'from_version' => (string) $info['upgrade_from_version'],
                'to_version' => (string) $info['version'],
                'verdict' => 'retry_safe',
                'recovery_state' => (string) $result['state'],
                'evidence_fingerprint' => $result['evidence_fingerprint'],
                'allowed_actions' => !$this->hasExactModernReplacementReadyEvidence($info) ? ['prepare_failed_upgrade_replacement'] : ['replace_failed_upgrade_candidate'],
                'assertions_total' => count($assertions),
                'assertions_passed' => count(array_filter($assertions, static fn (array $assertion): bool => $assertion['passed'])),
                'failed_assertion_ids' => array_values(array_column(array_filter($assertions, static fn (array $assertion): bool => !$assertion['passed']), 'id')),
                'audit_written' => false,
                'message' => (string) $result['message'],
            ];
        } finally {
            $this->releaseReadOnlyVerificationLock();
        }
    }

    /**
     * Inspect exposes only a failed candidate's tuple and currently permitted
     * read/write next step. It deliberately does not mint confirmations.
     *
     * @return array{app:string,from_version:string,to_version:string,recovery_mode:string,allowed_actions:list<string>,message:string}
     */
    public function inspectFailedUpgradeRecovery(int $actorId): array
    {
        $info = $this->getInfo();
        return $this->failedUpgradeRecoveryCoordinator()->inspect(
            $info,
            fn (): array => $this->runtimeRestoreDiagnostic($info),
        );
    }

    /**
     * Rechecks both the prepared replacement evidence and the catalog under a
     * fresh lock. A successful response is the sole authorization to replace.
     *
     * @return array{app:string,from_version:string,to_version:string,replacement_id:string,profile_hash:string,verdict:string,recovery_state:string,evidence_fingerprint:string,allowed_actions:list<string>,assertions_total:int,assertions_passed:int,failed_assertion_ids:list<string>,audit_written:false,message:string}
     */
    public function verifyPreparedFailedUpgradeReplacement(string $replacementId, int|array $actor): array
    {
        $this->acquireReadOnlyVerificationLock();
        try {
            $failed = $this->requireFailedUpgradeRecoveryBootstrapInfo();
            $this->failedUpgradeRecoveryCoordinator()->verify($failed, $replacementId, fn (): array => $this->runtimeRestoreDiagnostic($failed));
            $this->assertReplacementRecordId($replacementId);
            $record = $this->readExistingReplacementRecord($replacementId);
            $this->assertPreparedReplacement($record, $failed);
            $check = $this->revalidatePreparedFailedUpgradeRecovery($failed, $record);
            if (!is_string($record['profile_hash'] ?? null) || !$this->isSha256($record['profile_hash'])) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选 profile 摘要不完整', 400);
            }
            return [
                'app' => $this->appName,
                'from_version' => (string) $failed['upgrade_from_version'],
                'to_version' => (string) $failed['version'],
                'replacement_id' => $replacementId,
                'profile_hash' => $record['profile_hash'],
                'verdict' => 'retry_safe',
                'recovery_state' => $check['state'],
                'evidence_fingerprint' => $check['evidence_fingerprint'],
                'allowed_actions' => ['replace_failed_upgrade_candidate'],
                'assertions_total' => $check['assertions_total'],
                'assertions_passed' => $check['assertions_passed'],
                'failed_assertion_ids' => $check['failed_assertion_ids'],
                'audit_written' => false,
                'message' => '替换候选与当前恢复条件均已重新核验。',
            ];
        } finally {
            $this->releaseReadOnlyVerificationLock();
        }
    }

    /**
     * Explicitly restore the two runtime targets from the verified pre-upgrade
     * backup. This never changes the package candidate, backup, registry, DB,
     * or modern replacement markers.
     *
     * @return array{app:string,from_version:string,to_version:string,state:string,status:string,restore_id:string,runtime_manifest_hash:string,message:string}
     */
    public function restoreRuntimeFromBackup(string $confirmation, int|array $actor): array
    {
        $this->acquireOperationLock();
        try {
            $info = $this->requireFailedUpgradeRecoveryBootstrapInfo();
            return $this->failedUpgradeRecoveryCoordinator()->restore(
                $info,
                $confirmation,
                is_int($actor) ? FailedUpgradeRecoveryAudit::webActor($actor) : FailedUpgradeRecoveryAudit::normalizeActor($actor),
                fn (): array => $this->runtimeRestoreDiagnostic($info),
                function (array $runtime): array {
                    $backup = $runtime['backup'];
                    $sources = [];
                    foreach (array_keys($backup['runtime_manifest']) as $index) {
                        $sources[] = $this->backupRuntimeSource($backup['backup_directory'], $index);
                    }
                    return [
                        'sources' => $sources,
                        'targets' => array_values($this->getAllowedPath()),
                        'manifests' => array_column($backup['runtime_manifest'], 'manifest'),
                    ];
                },
                fn (string $point) => $this->candidateFault($point),
            );
        } finally {
            $this->releaseOperationLock();
        }
    }

    private function failedUpgradeRecoveryCoordinator(): FailedUpgradeRecoveryCoordinator
    {
        return new FailedUpgradeRecoveryCoordinator(
            $this->appName,
            new FailedUpgradeRecoveryInspector(),
            new FailedUpgradeRecoveryFileTransaction(rtrim($this->installDir, DIRECTORY_SEPARATOR)),
            new FailedUpgradeRecoveryAudit(),
        );
    }

    /**
     * Accept a replacement package into the private SandPackage store. This
     * deliberately uses no lifecycle lock: it never recovers, renames, or
     * changes the active candidate, backup, registry, or runtime tree.
     *
     * @return array{app:string,from_version:string,to_version:string,replacement_id:string,profile:string,message:string}
     */
    public function prepareFailedUpgradeReplacement(mixed $file, int|array $actor): array
    {
        $this->acquireUploadPreflightLock();
        $this->acquireReadOnlyVerificationLock();
        try {
            $failed = $this->requireFailedUpgradeRecoveryBootstrapInfo();
            $this->failedUpgradeRecoveryCoordinator()->prepare($failed, fn (): array => $this->runtimeRestoreDiagnostic($failed));
            $source = $this->uploadedFilePath($file);
            if (filesize($source) === false || filesize($source) > 5 * 1024 * 1024) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换安装包不能超过 5MB', 400);
            }
            $archive = $this->copyUploadArchiveToPrivate($source);
            try {
                $metadata = $this->readUploadArchiveMetadata($archive);
                if (($metadata['app'] ?? null) !== $this->appName || (int) $metadata['size'] > 5 * 1024 * 1024 || !@chmod($archive, 0400)) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换安装包预检不通过', 400);
                }
                $root = $this->replacementRoot();
                $id = bin2hex(random_bytes(16));
                $stage = $root . DIRECTORY_SEPARATOR . '.' . $id . '.stage';
                Filesystem::unzip($archive, $stage);
                $this->assertExtractedArchiveManifest($stage, $metadata['entries']);
                $this->assertSafePackageDirectory($stage);
                $identity = $this->replacementPackageIdentity($stage, (string) $metadata['sha256'], $failed);
                $this->captureFailedUpgradeCandidateIdentity($stage, $archive, (string) $metadata['sha256']);
                $package = $root . DIRECTORY_SEPARATOR . $id . '.package';
                if (!$this->renameCandidatePath($stage, $package, 'replacement.prepare.package.rename')) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法保存替换候选包', 400);
                }
                $record = [
                    'schema' => 'sandpackage-failed-upgrade-replacement/v1',
                    'id' => $id,
                    'app' => $this->appName,
                    'from_version' => (string) $failed['upgrade_from_version'],
                    'to_version' => (string) $failed['version'],
                    'backup_id' => (string) $failed['package_backup_id'],
                    'profile_hash' => $identity['profile_hash'],
                    'archive_sha256' => $identity['archive_sha256'],
                    'payload_sha256' => $identity['payload_sha256'],
                    'descriptor_sha256' => $identity['descriptor_sha256'],
                    'update_sql_sha256' => $identity['update_sql_sha256'],
                    'package_manifest' => $identity['package_manifest'],
                    'package_manifest_sha256' => $identity['package_manifest_sha256'],
                    'status' => 'prepared',
                    'prepared_by' => (int) $this->normalizeRecoveryActor($actor)['actor_id'],
                    'created_at' => time(),
                ];
                $this->writeCandidateJournal($this->replacementRecordPath($id), $record, 'replacement.prepare.record.write');
                (new FailedUpgradeRecoveryAudit())->write([
                    'action' => 'prepare_failed_upgrade_replacement', 'app' => $this->appName,
                    'from_version' => $record['from_version'], 'to_version' => $record['to_version'],
                    ...$this->normalizeRecoveryActor($actor),
                    'failed_stage' => 'database_update', 'profile_hash' => $record['profile_hash'], 'replacement_id' => $id,
                    'verdict' => 'prepared', 'confirmation_result' => 'not_required',
                    'candidate_archive_sha256' => $record['archive_sha256'], 'candidate_payload_manifest_sha256' => $record['payload_sha256'],
                    'descriptor_sha256' => $record['descriptor_sha256'], 'update_sql_sha256' => $record['update_sql_sha256'],
                    'backup_id' => $record['backup_id'],
                ]);
                return ['app' => $this->appName, 'from_version' => $record['from_version'], 'to_version' => $record['to_version'], 'replacement_id' => $id, 'profile_hash' => $record['profile_hash'], 'message' => '替换候选包已完成私有预检，尚未替换失败候选。'];
            } catch (Throwable $e) {
                if (isset($stage) && is_dir($stage)) Filesystem::delDir($stage);
                throw $e;
            } finally {
                if (isset($archive) && is_file($archive)) @unlink($archive);
            }
        } finally {
            $this->releaseReadOnlyVerificationLock();
            $this->releaseUploadPreflightLock();
        }
    }

    /** @return array{app:string,from_version:string,to_version:string,replacement_id:string,state:string,message:string} */
    public function replaceFailedUpgradeCandidate(string $replacementId, string $confirmation, int|array $actor): array
    {
        $this->acquireOperationLock();
        try {
            $failed = $this->requireFailedUpgradeRecoveryBootstrapInfo();
            $this->failedUpgradeRecoveryCoordinator()->replace($failed, $replacementId, $confirmation, fn (): array => $this->runtimeRestoreDiagnostic($failed));
            $expected = 'REPLACE ' . $this->appName . '@' . (string) $failed['version'];
            if (!hash_equals($expected, $confirmation)) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换确认内容不匹配', 400);
            $record = $this->readReplacementRecord($replacementId);
            $this->assertPreparedReplacement($record, $failed);
            $check = $this->revalidatePreparedFailedUpgradeRecovery($failed, $record);
            $oldManifest = $this->preparedPackageManifest($this->appDir, true);
            $quarantineRoot = $this->installDir . 'quarantine' . DIRECTORY_SEPARATOR . $this->appName;
            $this->prepareManagedDirectory($quarantineRoot, 0700);
            $quarantine = $quarantineRoot . DIRECTORY_SEPARATOR . 'failed-' . $replacementId;
            if (file_exists($quarantine)) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换隔离位置已存在', 400);
            $journal = $this->replacementTransactionPath();
            $payload = ['schema' => 'sandpackage-failed-upgrade-replacement-transaction/v1', 'app' => $this->appName, 'replacement_id' => $replacementId, 'phase' => 'prepared', 'old_manifest' => $oldManifest, 'old_manifest_sha256' => $this->preparedPackageManifestDigest($oldManifest), 'quarantine' => $quarantine, 'created_at' => time()];
            $this->writeCandidateJournal($journal, $payload, 'replacement.transaction.write');
            if (!$this->renameCandidatePath($this->appDir, $quarantine, 'replacement.old.quarantine.rename')) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法隔离失败候选', 400);
            if ($this->preparedPackageManifest($quarantine, true) !== $oldManifest) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：失败候选隔离清单不匹配', 400);
            $payload['phase'] = 'old_quarantined'; $this->writeCandidateJournal($journal, $payload, 'replacement.transaction.old_quarantined');
            $package = $this->replacementPackagePath($replacementId);
            if (!$this->renameCandidatePath($package, $this->appDir, 'replacement.new.move.rename')) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法移动替换候选', 400);
            $record['status'] = 'replaced';
            $this->assertReplacementPackageAtActiveRoot($record, $failed);
            $newInfo = $this->replacementCandidateInfo($failed, $record, $replacementId);
            $this->candidateFault('replacement.candidate.info.write');
            if (Server::setIni($this->appDir, $newInfo) !== true) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法保存替换候选状态', 400);
            $this->fsyncTree($this->appDir);
            $this->candidateFault('replacement.candidate.check');
            $payload['phase'] = 'replacement_ready'; $this->writeCandidateJournal($journal, $payload, 'replacement.transaction.ready');
            $record['replaced_at'] = time(); $record['replaced_by'] = (int) $this->normalizeRecoveryActor($actor)['actor_id']; $record['quarantine_id'] = basename($quarantine); $record['evidence_fingerprint'] = $check['evidence_fingerprint'];
            $this->writeCandidateJournal($this->replacementRecordPath($replacementId), $record, 'replacement.record.replaced');
            $this->candidateFault('replacement.transaction.unlink');
            $this->removeTransactionJournal($journal, 'FAILED_UPGRADE_RECOVERY_BLOCKED：替换事务记录无法完成');
            (new FailedUpgradeRecoveryAudit())->write($this->replacementAuditPayload('replace_failed_upgrade_candidate', $failed, $record, $actor, $check, 'confirmed', [
                'replacement_id' => $replacementId,
                'old_quarantine_id' => basename($quarantine),
                'old_quarantine_manifest_sha256' => $this->preparedPackageManifestDigest($oldManifest),
                'new_candidate_digest' => (string) $record['payload_sha256'],
            ]));
            return ['app' => $this->appName, 'from_version' => (string) $failed['upgrade_from_version'], 'to_version' => (string) $failed['version'], 'replacement_id' => $replacementId, 'state' => 'ready', 'message' => '已替换为已核验候选，尚未执行升级脚本。'];
        } finally { $this->releaseOperationLock(); }
    }

    /** @return array{app:string,from_version:string,to_version:string,state:string,message:string} */
    public function retryFailedUpgrade(string $confirmation, int|array $actor): array
    {
        $this->acquireOperationLock();
        try {
            $info = $this->requireExactReplacementReadyInfo();
            $this->failedUpgradeRecoveryCoordinator()->retry($info, $confirmation, fn (): array => $this->runtimeRestoreDiagnostic($info));
            $replacementId = $info['failed_upgrade_replacement_id'];
            $record = $this->readReplacementRecord($replacementId);
            $from = $info['upgrade_from_version'] ?? null;
            if (!is_string($from) || !is_string($info['version'] ?? null) || !hash_equals('RETRY ' . $this->appName . '@' . $from . '->' . $info['version'], $confirmation)) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：重试确认内容不匹配', 400);
            $this->assertPreparedReplacement($record, $info, true);
            $check = $this->revalidateFailedUpgradeRecovery($info);
            $files = $this->requireLifecycleFiles();
            if (!hash_equals((string) $record['update_sql_sha256'], $this->regularFileSha256($files['update.sql'], '候选包升级脚本'))) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换升级脚本摘要不匹配', 400);
            $this->candidateFault('replacement.retry.preflight.complete');
            $this->recordStage('database_update', '正在重新执行已核验的插件升级脚本');
            try {
                $this->executeLifecycleSql($files['update.sql'], '插件升级脚本不可用或未能执行');
                $this->candidateFault('replacement.retry.database_update.committed');
            } catch (Throwable $e) {
                $this->recordFailure('database_update', $e);
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：数据库升级再次失败，未覆盖运行文件', 400);
            }
            try {
                $this->recordStage('file_deploy', '正在部署已核验的插件文件');
                $deploymentBackup = $this->deployFilesWithRecovery();
                $this->recordStage('service_registration', '正在登记插件服务能力', ['deployment_backup_id' => $deploymentBackup]);
                $this->registerServiceCatalog();
                $ready = $this->getInfo(); $ready['service_catalog_registered'] = 1; $this->setInfo([], $ready);
                $this->markInstalled();
                $this->clearMenuCacheBestEffort();
            } catch (Throwable $e) {
                $this->compensateInstallationFailure(); $this->recordFailure($this->getCurrentStage(), $e); throw $e instanceof ApiException ? $e : new ApiException('插件升级未完成，请查看升级状态后处理');
            }
            (new FailedUpgradeRecoveryAudit())->write($this->replacementAuditPayload('retry_failed_upgrade', $info, $record, $actor, $check, 'confirmed'));
            return ['app' => $this->appName, 'from_version' => $from, 'to_version' => (string) $info['version'], 'state' => 'installed', 'message' => '已重新执行已核验升级脚本，未自动重载服务。'];
        } finally { $this->releaseOperationLock(); }
    }

    /** @return array<string,mixed> */
    private function requireExactReplacementReadyInfo(): array
    {
        $info = $this->getInfo();
        if (($info['app'] ?? null) !== $this->appName || !$this->hasExactModernReplacementReadyEvidence($info)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：当前候选不是已核验替换包', 400);
        }
        return $info;
    }

    /** Uses the same complete retained-package evidence for list and retry. */
    private function hasExactModernReplacementReadyEvidence(array $info): bool
    {
        if (!self::isExactModernReplacementReadyShape($info)) return false;
        try {
            $record = $this->readExistingReplacementRecord((string) $info['failed_upgrade_replacement_id']);
            $this->assertPreparedReplacement($record, $info, true);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function replacementRoot(): string
    {
        $root = $this->installDir . 'replacements' . DIRECTORY_SEPARATOR . $this->appName;
        $this->prepareManagedDirectory($root, 0700);
        return $root;
    }

    private function replacementRecordPath(string $id): string
    {
        $this->assertReplacementRecordId($id);
        return $this->replacementRoot() . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function existingReplacementRecordPath(string $id): string
    {
        $this->assertReplacementRecordId($id);
        return $this->installDir . 'replacements' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function assertReplacementRecordId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选标识不合法', 400);
        }
    }

    private function replacementPackagePath(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选标识不合法', 400);
        }
        return $this->replacementRoot() . DIRECTORY_SEPARATOR . $id . '.package';
    }

    private function replacementTransactionPath(): string
    {
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . $this->appName . '-failed-upgrade-replacement.transaction.json';
    }

    /** @return array<string,mixed> */
    private function readReplacementRecord(string $id): array
    {
        return $this->readReplacementRecordFile($this->replacementRecordPath($id), $id);
    }

    /** Read-only variant for presentation and retry eligibility checks. @return array<string,mixed> */
    private function readExistingReplacementRecord(string $id): array
    {
        return $this->readReplacementRecordFile($this->existingReplacementRecordPath($id), $id);
    }

    /** @return array<string,mixed> */
    private function readReplacementRecordFile(string $file, string $id): array
    {
        if (!$this->isSafeRegularFile($file, 0600)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选记录不可用', 400);
        }
        try { $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选记录不可读', 400); }
        if (!is_array($record) || ($record['schema'] ?? null) !== 'sandpackage-failed-upgrade-replacement/v1'
            || ($record['id'] ?? null) !== $id || ($record['app'] ?? null) !== $this->appName) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选记录不完整', 400);
        }
        return $record;
    }

    /** @param array<string,mixed> $failed @return array<string,mixed> */
    private function replacementPackageIdentity(string $package, string $archiveSha256, array $failed, ?string $referenceCandidate = null, bool $active = false): array
    {
        $this->assertSafePackageDirectory($package);
        $descriptorRaw = $this->readFailedUpgradeDescriptor($package);
        try { $descriptor = (new FailedUpgradeRecoveryVerifier())->parseDescriptor($descriptorRaw); }
        catch (Throwable) { throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换包恢复描述不符合冻结契约', 400); }
        $current = null;
        if (self::hasCompleteModernFailedMarkers($failed) || $referenceCandidate !== null) {
            $reference = $referenceCandidate ?? $this->appDir;
            $currentRaw = $this->readFailedUpgradeDescriptor($reference);
            try { $current = (new FailedUpgradeRecoveryVerifier())->parseDescriptor($currentRaw); }
            catch (Throwable) { throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：当前失败候选恢复描述不符合冻结契约', 400); }
        }
        $packageInfo = self::readPackageInfo($package);
        if (($descriptor['app'] ?? null) !== $this->appName || ($descriptor['from_version'] ?? null) !== ($failed['upgrade_from_version'] ?? null)
            || ($descriptor['to_version'] ?? null) !== ($failed['version'] ?? null)
            || ($current !== null && ($descriptor['profile'] ?? null) !== ($current['profile'] ?? null))
            || ($packageInfo['app'] ?? null) !== $this->appName || ($packageInfo['version'] ?? null) !== ($failed['version'] ?? null)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换包应用、版本或恢复配置不匹配', 400);
        }
        $payload = $this->failedUpgradePayloadManifest($package);
        $payloadSha = $active
            ? (string) $descriptor['candidate_payload']['digest']
            : (new FailedUpgradePackageIdentity())->descriptorPayloadDigest(
                $package,
                (string) $descriptor['candidate_payload']['algorithm'],
                (string) $descriptor['app'],
            );
        $descriptorSha = hash('sha256', $descriptorRaw);
        $updateSha = $this->regularFileSha256($package . DIRECTORY_SEPARATOR . 'update.sql', '替换候选升级脚本');
        if (!hash_equals((string) $descriptor['candidate_payload']['digest'], $payloadSha)
            || !hash_equals((string) $descriptor['update_lifecycle']['sha256'], $updateSha)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换包描述摘要不匹配', 400);
        }
        return ['profile_hash' => hash('sha256', FailedUpgradeRecoveryVerifier::canonicalJson($descriptor['profile'])), 'archive_sha256' => $archiveSha256, 'payload_manifest' => $payload,
            'payload_sha256' => $payloadSha, 'descriptor_sha256' => $descriptorSha, 'update_sql_sha256' => $updateSha,
            'package_manifest' => $payload, 'package_manifest_sha256' => $this->preparedPackageManifestDigest($payload)];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $failed */
    private function assertPreparedReplacement(array $record, array $failed, bool $active = false, ?string $referenceCandidate = null): void
    {
        if (!in_array($record['status'] ?? null, $active ? ['replaced'] : ['prepared'], true)
            || ($record['from_version'] ?? null) !== ($failed['upgrade_from_version'] ?? null)
            || ($record['to_version'] ?? null) !== ($failed['version'] ?? null)
            || ($record['backup_id'] ?? null) !== ($failed['package_backup_id'] ?? null)
            || !$this->isSha256($record['profile_hash'] ?? null) || !$this->isSha256($record['archive_sha256'] ?? null) || !$this->isSha256($record['payload_sha256'] ?? null)
            || !$this->isSha256($record['descriptor_sha256'] ?? null) || !$this->isSha256($record['update_sql_sha256'] ?? null)
            || !is_array($record['package_manifest'] ?? null) || !is_string($record['package_manifest_sha256'] ?? null)
            || !hash_equals($record['package_manifest_sha256'], $this->preparedPackageManifestDigest($record['package_manifest']))) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选证据不完整', 400);
        }
        $this->assertRetainedReplacementArchive($record);
        $package = $active ? $this->appDir : $this->replacementPackagePath((string) $record['id']);
        if (!is_dir($package)) throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选包不可用', 400);
        $identity = $this->replacementPackageIdentity($package, (string) $record['archive_sha256'], $failed, $referenceCandidate, $active);
        foreach (['profile_hash','archive_sha256','payload_sha256','descriptor_sha256','update_sql_sha256','package_manifest_sha256'] as $field) {
            if (!hash_equals((string) $record[$field], (string) $identity[$field])) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选摘要不匹配', 400);
            }
        }
        if ($identity['package_manifest'] !== $record['package_manifest']) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选载荷清单不匹配', 400);
        }
    }

    /** @param array<string,mixed> $record */
    private function assertRetainedReplacementArchive(array $record): void
    {
        $digest = (string) $record['archive_sha256'];
        $archive = $this->installDir . 'archives' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR . $digest . '.zip';
        if (!$this->isSafeRegularFile($archive, 0400) || !hash_equals($digest, $this->regularFileSha256($archive, '替换候选安装包归档'))) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选安装包归档摘要不匹配', 400);
        }
    }

    /** @param array<string,mixed> $failed @param array<string,mixed> $record @param array{evidence_fingerprint:string,state:string} $check @param array<string,string> $extra @return array<string,string> */
    private function replacementAuditPayload(string $action, array $failed, array $record, int|array $actor, array $check, string $confirmationResult, array $extra = []): array
    {
        return array_merge([
            'action' => $action, 'app' => $this->appName,
            'from_version' => (string) $failed['upgrade_from_version'], 'to_version' => (string) $failed['version'],
            ...$this->normalizeRecoveryActor($actor),
            'failed_stage' => 'database_update', 'profile_hash' => (string) $record['profile_hash'],
            'verdict' => 'retry_safe', 'evidence_fingerprint' => $check['evidence_fingerprint'],
            'candidate_archive_sha256' => (string) $record['archive_sha256'],
            'candidate_payload_manifest_sha256' => (string) $record['payload_sha256'],
            'descriptor_sha256' => (string) $record['descriptor_sha256'],
            'update_sql_sha256' => (string) $record['update_sql_sha256'],
            'backup_id' => (string) $failed['package_backup_id'], 'confirmation_result' => $confirmationResult,
        ], $extra);
    }

    /** @param array<string,mixed> $record */
    private function assertReplacementPackageAtActiveRoot(array $record, ?array $failed = null): void
    {
        $failed ??= $this->getInfo();
        $failed['state'] = self::FAILED; $failed['stage'] = 'failed'; $failed['failed_stage'] = 'database_update'; $failed['update'] = 1;
        $this->assertPreparedReplacement($record, $failed, true);
    }

    /** @param array<string,mixed> $failed @param array<string,mixed> $record @return array<string,mixed> */
    private function replacementCandidateInfo(array $failed, array $record, string $id): array
    {
        $info = self::readPackageInfo($this->appDir);
        foreach (['package_backup_id','registration_manifest','runtime_manifest','upgrade_from_version'] as $field) $info[$field] = $failed[$field];
        $info['app'] = $this->appName; $info['version'] = $failed['version']; $info['state'] = self::FAILED;
        $info['stage'] = 'failed'; $info['failed_stage'] = 'database_update'; $info['update'] = 1;
        $info['candidate_archive_sha256'] = $record['archive_sha256']; $info['candidate_payload_manifest_sha256'] = $record['payload_sha256'];
        $info['recovery_descriptor_sha256'] = $record['descriptor_sha256']; $info['update_sql_sha256'] = $record['update_sql_sha256'];
        $info['failed_upgrade_replacement_id'] = $id; $info['replacement_candidate_state'] = 'ready';
        unset($info['last_error_code'], $info['diagnostic_id']);
        return $info;
    }

    /** @param array<string,mixed> $failed @return array{evidence_fingerprint:string,state:string} */
    private function revalidateFailedUpgradeRecovery(array $failed): array
    {
        $identity = $this->failedUpgradeIdentityBinding($failed);
        [$connection, $pdo] = $this->openPostgresRecoveryConnection();
        $result = (new FailedUpgradeRecoveryVerifier())->verify($identity['descriptor_raw'], $pdo, $identity['binding']);
        if (($result['connection_reusable'] ?? false) !== true) { $connection->close(); throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复核验连接未能安全回滚', 400); }
        if (($result['status'] ?? null) !== 'retry_safe' || !is_string($result['evidence_fingerprint'] ?? null)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：' . (string) ($result['message'] ?? '恢复核验未通过'), 400);
        }
        return ['evidence_fingerprint' => $result['evidence_fingerprint'], 'state' => (string) $result['state']];
    }

    /**
     * Bind Gate A to the immutable prepared replacement and the verified
     * pre-upgrade backup. This path performs only file reads and a PostgreSQL
     * READ ONLY transaction; it deliberately does not write the audit log.
     *
     * @param array<string,mixed> $failed
     * @param array<string,mixed> $record
     * @return array{evidence_fingerprint:string,state:string,assertions_total:int,assertions_passed:int,failed_assertion_ids:list<string>}
     */
    private function revalidatePreparedFailedUpgradeRecovery(array $failed, array $record): array
    {
        $this->assertPreparedReplacement($record, $failed);
        $backup = $this->readVerifiedCandidateBackup((string) $failed['package_backup_id'], (string) $failed['registration_manifest']);
        $this->assertRuntimeManifest($backup['runtime_manifest']);
        if (!hash_equals($backup['runtime_manifest_hash'], (string) $failed['runtime_manifest'])) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选未绑定已验证的升级前运行时清单', 400);
        }
        $this->assertUpgradeLineage($failed, $backup['version']);
        $this->assertHostSupportsUpgrade($failed);
        $package = $this->replacementPackagePath((string) $record['id']);
        $descriptorRaw = $this->readFailedUpgradeDescriptor($package);
        $binding = new FailedUpgradeIdentityBinding(
            (string) $record['archive_sha256'],
            (string) $failed['package_backup_id'],
            $backup['package_manifest_sha256'],
            (string) $record['payload_sha256'],
            $backup['deployment_manifest_sha256'],
            (string) $record['descriptor_sha256'],
            $backup['previous_registration_manifest_sha256'],
            $backup['runtime_manifest_hash'],
            (string) $record['update_sql_sha256'],
        );
        [$connection, $pdo] = $this->openPostgresRecoveryConnection();
        $verifier = new FailedUpgradeRecoveryVerifier();
        $result = $verifier->verify($descriptorRaw, $pdo, $binding);
        if (($result['connection_reusable'] ?? false) !== true) {
            $connection->close();
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复核验连接未能安全回滚', 400);
        }
        $assertions = $verifier->internalAssertionLog();
        if (($result['status'] ?? null) !== 'retry_safe' || !is_string($result['evidence_fingerprint'] ?? null)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：' . (string) ($result['message'] ?? '恢复核验未通过'), 400);
        }
        $failedAssertions = array_values(array_filter($assertions, static fn (array $assertion): bool => !$assertion['passed']));
        return [
            'evidence_fingerprint' => $result['evidence_fingerprint'],
            'state' => (string) $result['state'],
            'assertions_total' => count($assertions),
            'assertions_passed' => count($assertions) - count($failedAssertions),
            'failed_assertion_ids' => array_values(array_column($failedAssertions, 'id')),
        ];
    }

    /** @return array{0:object,1:object} */
    private function openPostgresRecoveryConnection(): array
    {
        try {
            $connection = Db::connect('pgsql');
            $pdo = $connection->connect();
        } catch (Throwable) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法建立只读恢复核验连接', 400);
        }
        if (!is_object($connection) || !is_object($pdo)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：无法建立只读恢复核验连接', 400);
        }
        return [$connection, $pdo];
    }

    /** @return array<string,mixed> */
    private function requireExactFailedUpgradeRecoveryInfo(): array
    {
        $info = $this->getInfo();
        if (!self::isFailedUpgradeRecoveryShape($info) || ($info['app'] ?? null) !== $this->appName
            || (!self::isExactModernReplacementReadyShape($info) && !self::hasCompleteModernFailedMarkers($info))) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：该插件不是可核验的数据库升级失败状态', 400);
        }
        return $info;
    }

    /**
     * Accept a historical database_update failure only when its v2 candidate
     * identity is wholly absent. Complete modern identity remains accepted;
     * partial identity is fail-closed and cannot be repaired in place.
     *
     * @return array<string,mixed>
     */
    private function requireFailedUpgradeRecoveryBootstrapInfo(): array
    {
        $info = $this->getInfo();
        if (!self::isFailedUpgradeRecoveryShape($info) || ($info['app'] ?? null) !== $this->appName
            || (!self::hasCompleteModernFailedMarkers($info) && !self::hasNoModernFailedMarkers($info))
            || !is_string($info['package_backup_id'] ?? null) || !$this->isBackupId($info['package_backup_id'])
            || !self::isSha256Value($info['registration_manifest'] ?? null)
            || !self::isSha256Value($info['runtime_manifest'] ?? null)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：数据库升级失败状态或身份链不完整', 400);
        }
        self::assertCandidatePackageIdentity($info);
        return $info;
    }

    /**
     * Recomputes every mutable-on-disk identity that is available to a failed
     * candidate. The original ZIP is retained privately by digest at upload
     * preflight so its immutable archive hash can also be recomputed here.
     *
     * @param array<string,mixed> $info
     * @return array{descriptor_raw:string,binding:FailedUpgradeIdentityBinding,audit:array<string,string>}
     */
    private function failedUpgradeIdentityBinding(array $info): array
    {
        self::assertCandidatePackageIdentity($info);
        $backup = $this->readVerifiedCandidateBackup((string) $info['package_backup_id'], (string) $info['registration_manifest']);
        $this->assertRuntimeManifest($backup['runtime_manifest']);
        if (!hash_equals($backup['runtime_manifest_hash'], (string) $info['runtime_manifest'])) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件与失败升级前备份不一致', 400);
        }
        $this->assertUpgradeLineage($info, $backup['version']);
        $this->assertHostSupportsUpgrade($info);

        $candidate = rtrim($this->appDir, DIRECTORY_SEPARATOR);
        $descriptorRaw = $this->readFailedUpgradeDescriptor($candidate);
        $descriptor = (new FailedUpgradeRecoveryVerifier())->parseDescriptor($descriptorRaw);
        if ($descriptor['app'] !== $this->appName
            || $descriptor['from_version'] !== $backup['version']
            || $descriptor['to_version'] !== $info['version']) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包恢复描述与升级谱系不一致', 400);
        }

        $archiveSha = $this->recomputeFailedUpgradeArchiveSha256($info);
        if (self::isExactModernReplacementReadyShape($info)) {
            $record = $this->readExistingReplacementRecord((string) $info['failed_upgrade_replacement_id']);
            $this->assertPreparedReplacement($record, $info, true);
            $payloadSha = (string) $record['payload_sha256'];
        } else {
            $payloadSha = $this->failedUpgradePayloadManifestDigest($candidate);
        }
        $descriptorSha = hash('sha256', $descriptorRaw);
        $updateSha = $this->regularFileSha256($candidate . DIRECTORY_SEPARATOR . 'update.sql', '候选包升级脚本');
        foreach ([
            'candidate_payload_manifest_sha256' => $payloadSha,
            'recovery_descriptor_sha256' => $descriptorSha,
            'update_sql_sha256' => $updateSha,
        ] as $field => $actual) {
            if (!is_string($info[$field] ?? null) || !hash_equals($actual, $info[$field])) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包不可变身份校验失败', 400);
            }
        }

        $binding = new FailedUpgradeIdentityBinding(
            $archiveSha,
            (string) $info['package_backup_id'],
            $backup['package_manifest_sha256'],
            $payloadSha,
            $backup['deployment_manifest_sha256'],
            $descriptorSha,
            $backup['previous_registration_manifest_sha256'],
            $backup['runtime_manifest_hash'],
            $updateSha,
        );
        return [
            'descriptor_raw' => $descriptorRaw,
            'binding' => $binding,
            'audit' => [
                'candidate_archive_sha256' => $archiveSha,
                'candidate_payload_manifest_sha256' => $payloadSha,
                'descriptor_sha256' => $descriptorSha,
                'update_sql_sha256' => $updateSha,
                'backup_id' => (string) $info['package_backup_id'],
                'profile_hash' => hash('sha256', FailedUpgradeRecoveryVerifier::canonicalJson($descriptor['profile'])),
            ],
        ];
    }

    /** @return array{actor_type:string,actor_id:string,actor_name:string} */
    private function normalizeRecoveryActor(int|array $actor): array
    {
        return is_int($actor)
            ? FailedUpgradeRecoveryAudit::webActor($actor)
            : FailedUpgradeRecoveryAudit::normalizeActor($actor);
    }

    /**
     * Upload preflight records this only when a package opts in by carrying the
     * v2 descriptor. Existing packages keep their ordinary lifecycle intact.
     *
     * @return array<string,string>
     */
    private function captureFailedUpgradeCandidateIdentity(string $candidate, string $archive, string $archiveSha256): array
    {
        $descriptor = $candidate . DIRECTORY_SEPARATOR . 'recovery' . DIRECTORY_SEPARATOR . 'failed-upgrade.v2.json';
        if (!file_exists($descriptor)) {
            return [];
        }
        $descriptorRaw = $this->readFailedUpgradeDescriptor($candidate);
        (new FailedUpgradeRecoveryVerifier())->parseDescriptor($descriptorRaw);
        if (!$this->isSha256($archiveSha256)) {
            throw new ApiException('候选安装包归档摘要不合法');
        }
        $this->retainFailedUpgradeArchive($archive, $archiveSha256);
        return [
            'candidate_archive_sha256' => $archiveSha256,
            'candidate_payload_manifest_sha256' => $this->failedUpgradePayloadManifestDigest($candidate),
            'recovery_descriptor_sha256' => hash('sha256', $descriptorRaw),
            'update_sql_sha256' => $this->regularFileSha256($candidate . DIRECTORY_SEPARATOR . 'update.sql', '候选包升级脚本'),
        ];
    }

    private function readFailedUpgradeDescriptor(string $candidate): string
    {
        return (new FailedUpgradePackageIdentity())->readDescriptor($candidate);
    }

    private function retainFailedUpgradeArchive(string $archive, string $archiveSha256): void
    {
        $directory = $this->installDir . 'archives' . DIRECTORY_SEPARATOR . $this->appName;
        (new FailedUpgradePackageIdentity())->retainArchive($archive, $archiveSha256, $directory);
    }

    /** @param array<string,mixed> $info */
    private function recomputeFailedUpgradeArchiveSha256(array $info): string
    {
        $expected = $info['candidate_archive_sha256'] ?? null;
        if (!$this->isSha256($expected)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选安装包归档身份缺失', 400);
        }
        $archive = $this->installDir . 'archives' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR . $expected . '.zip';
        if (!$this->isSafeRegularFile($archive, 0400)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选安装包归档不可用', 400);
        }
        $actual = $this->regularFileSha256($archive, '候选安装包归档');
        if (!hash_equals($expected, $actual)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选安装包归档摘要不匹配', 400);
        }
        return $actual;
    }

    private function regularFileSha256(string $file, string $label): string
    {
        return (new FailedUpgradePackageIdentity())->fileSha256($file, $label);
    }

    private function failedUpgradePayloadManifestDigest(string $directory): string
    {
        $descriptorRaw = $this->readFailedUpgradeDescriptor($directory);
        $descriptor = (new FailedUpgradeRecoveryVerifier())->parseDescriptor($descriptorRaw);
        return (new FailedUpgradePackageIdentity())->descriptorPayloadDigest(
            $directory,
            (string) $descriptor['candidate_payload']['algorithm'],
            (string) $descriptor['app'],
        );
    }

    /** @return array<string,array{size:int,sha256:string}> */
    private function failedUpgradePayloadManifest(string $directory): array
    {
        return (new FailedUpgradePackageIdentity())->payloadManifest(
            $directory,
            fn (string $root): string => $this->normalizedFailedUpgradePayloadInfo($root),
        );
    }

    private function normalizedFailedUpgradePayloadInfo(string $directory): string
    {
        $info = self::readPackageInfo($directory);
        foreach (['state', 'stage', 'stage_label', 'last_error', 'last_error_code', 'diagnostic_id', 'failed_stage', 'update', 'package_backup_id', 'registration_manifest', 'runtime_manifest', 'upgrade_from_version', 'deployment_backup_id', 'registration_candidate', 'service_catalog_registered', 'dependency_recovery_state', 'composer_dependent_wait_install', 'npm_dependent_wait_install', 'candidate_archive_sha256', 'candidate_payload_manifest_sha256', 'recovery_descriptor_sha256', 'update_sql_sha256', 'failed_upgrade_replacement_id', 'replacement_candidate_state'] as $field) {
            unset($info[$field]);
        }
        ksort($info, SORT_STRING);
        return json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function readPackageInfo(string $directory): array
    {
        return Server::getIni(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $info */
    private static function isModernReadyCandidateShapeForPresentation(array $info): bool
    {
        $app = $info['app'] ?? null;
        $from = $info['upgrade_from_version'] ?? null;
        $to = $info['version'] ?? null;
        return is_string($app) && preg_match('/^[a-z][a-z0-9-]{1,63}$/', $app) === 1
            && (int) ($info['state'] ?? -1) === self::WAIT_INSTALL && ($info['stage'] ?? null) === 'ready' && (int) ($info['update'] ?? 0) === 1
            && is_string($to) && self::legacyStrictSemver($to) && is_string($from) && self::legacyStrictSemver($from) && self::compareSemver($to, $from) > 0
            && is_string($info['package_backup_id'] ?? null) && preg_match('/^' . preg_quote($app, '/') . '-package-[0-9]{14}-[a-f0-9]{12}$/', $info['package_backup_id']) === 1
            && self::legacySha256($info['registration_manifest'] ?? null) && self::legacySha256($info['runtime_manifest'] ?? null);
    }

    /** @param array<string,mixed> $info */
    private static function isLegacyHistoryCandidateShapeForPresentation(array $info): bool
    {
        $app = $info['app'] ?? null;
        if (!is_string($app) || preg_match('/^[a-z][a-z0-9-]{1,63}$/', $app) !== 1) {
            return false;
        }
        foreach (['registration_manifest', 'runtime_manifest', 'support', 'upgrade_from_version'] as $field) {
            if (array_key_exists($field, $info)) {
                return false;
            }
        }
        return (int) ($info['state'] ?? -1) === self::WAIT_INSTALL
            && ($info['stage'] ?? null) === 'ready'
            && (int) ($info['update'] ?? 0) === 1
            && is_string($info['version'] ?? null) && self::legacyStrictSemver($info['version'])
            && is_string($info['package_backup_id'] ?? null)
            && preg_match('/^' . preg_quote($app, '/') . '-package-[0-9]{14}-[a-f0-9]{12}$/', $info['package_backup_id']) === 1;
    }

    /** @param array<string,mixed> $info */
    private static function assertCandidatePackageIdentity(array $info): void
    {
        $app = $info['app'] ?? null;
        $version = $info['version'] ?? null;
        if (!is_string($app) || preg_match('/^[a-z][a-z0-9-]{1,63}$/', $app) !== 1
            || !is_string($version) || !self::legacyStrictSemver($version)) {
            throw new ApiException('候选安装包标识或版本非法');
        }
        $installRoot = (new PluginStorage())->root();
        $candidate = $installRoot . DIRECTORY_SEPARATOR . $app;
        self::legacySafeDirectoryNode($installRoot);
        self::legacyCanonicalChild($installRoot, $candidate);
        $stored = self::readPackageInfo($candidate);
        if (($stored['app'] ?? null) !== $app || ($stored['version'] ?? null) !== $version) {
            throw new ApiException('候选安装包根信息不匹配');
        }
        $plugin = $candidate . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $frontendRoot = env('FRONTEND_DIR', 'sandadmin-artd');
        $frontend = $candidate . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        self::legacySafeDirectory($plugin);
        self::legacySafeDirectory($frontend);
        $pluginInfo = self::readPackageInfo($plugin);
        if (($pluginInfo['app'] ?? null) !== $app || ($pluginInfo['version'] ?? null) !== $version) {
            throw new ApiException('候选插件目录身份不匹配');
        }
    }

    /**
     * Read-only compatibility bridge for pre-manifest candidates. These historic
     * uploads have no modern registration/runtime manifest, support, or lineage
     * fields, so they can never be upgraded. The old info.ini registration digest
     * cannot be recomputed as a modern manifest; it is retained only alongside
     * complete backup/package/runtime cross-checks before a withdraw is allowed.
     *
     * @param array<string,mixed> $info
     * @return array{from_version:string,registration_digest:string,backup_package_digest:string,runtime_digest:string,candidate_package_digest:string}
     */
    private static function legacyHistoryEvidence(array $info): array
    {
        if (!self::isLegacyHistoryCandidateShapeForPresentation($info)) {
            throw new ApiException('较早版本候选形态不匹配');
        }
        self::assertCandidatePackageIdentity($info);
        $app = (string) $info['app'];
        $installRoot = (new PluginStorage())->root();
        self::legacySafeDirectoryNode($installRoot);
        $candidate = $installRoot . DIRECTORY_SEPARATOR . $app;
        $backups = $installRoot . DIRECTORY_SEPARATOR . 'backups';
        self::legacyCanonicalChild($installRoot, $candidate);
        self::legacyCanonicalChildNode($installRoot, $backups);
        $backup = $backups . DIRECTORY_SEPARATOR . (string) $info['package_backup_id'];
        self::legacyCanonicalChild($backups, $backup);
        if (file_exists($backup . DIRECTORY_SEPARATOR . 'registration_manifest.json')) {
            throw new ApiException('较早版本备份不应混用现代清单');
        }
        self::requireLegacyBackupShape($backup, $app);

        $backupInfo = self::readPackageInfo($backup);
        $from = $backupInfo['version'] ?? null;
        $registration = $backupInfo['registration_manifest'] ?? null;
        if (($backupInfo['app'] ?? null) !== $app || !is_string($from) || !self::legacyStrictSemver($from)
            || (int) ($backupInfo['state'] ?? -1) !== self::INSTALLED || ($backupInfo['stage'] ?? null) !== 'registered'
            || !self::legacySha256($registration) || self::compareSemver((string) $info['version'], $from) <= 0) {
            throw new ApiException('较早版本备份谱系或登记摘要不匹配');
        }

        $candidatePlugin = $candidate . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $backupPlugin = $backup . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $frontendRoot = env('FRONTEND_DIR', 'sandadmin-artd');
        $candidateFrontend = $candidate . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $backupFrontend = $backup . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        self::legacySafeDirectory($candidatePlugin);
        self::legacySafeDirectory($candidateFrontend);
        self::legacySafeDirectory($backupPlugin);
        self::legacySafeDirectory($backupFrontend);
        $candidatePluginInfo = self::readPackageInfo($candidatePlugin);
        if (($candidatePluginInfo['app'] ?? null) !== $app || ($candidatePluginInfo['version'] ?? null) !== $info['version']) {
            throw new ApiException('较早版本候选包身份不匹配');
        }

        $runtimePlugin = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $runtimeFrontend = dirname(rtrim(base_path(), DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        self::legacySafeDirectory($runtimePlugin);
        self::legacySafeDirectory($runtimeFrontend);
        $backupBackendManifest = self::legacyDirectoryManifest($backupPlugin);
        $backupFrontendManifest = self::legacyDirectoryManifest($backupFrontend);
        if ($backupBackendManifest !== self::legacyDirectoryManifest($runtimePlugin)
            || $backupFrontendManifest !== self::legacyDirectoryManifest($runtimeFrontend)) {
            throw new ApiException('已部署运行时文件与较早版本备份不一致');
        }
        $legacyRegistration = hash('sha256', json_encode([$backupBackendManifest, $backupFrontendManifest], JSON_THROW_ON_ERROR));
        if (!hash_equals($registration, $legacyRegistration)) {
            throw new ApiException('较早版本备份登记摘要不匹配');
        }
        return [
            'from_version' => $from,
            'registration_digest' => $registration,
            'backup_package_digest' => self::legacyManifestDigest(self::legacyDirectoryManifest($backup)),
            'runtime_digest' => self::legacyManifestDigest(['backend' => $backupBackendManifest, 'frontend' => $backupFrontendManifest]),
            'candidate_package_digest' => self::legacyManifestDigest(self::legacyDirectoryManifest($candidate)),
        ];
    }

    /** @param array<string,mixed> $info @param array<string,mixed> $payload */
    private static function assertLegacyHistoryRestored(string $app, string $backupId, array $info, array $payload): void
    {
        $from = $payload['derived_from_version'] ?? null;
        $registration = $payload['derived_registration_digest'] ?? null;
        $packageDigest = $payload['derived_backup_package_digest'] ?? null;
        $runtimeDigest = $payload['derived_runtime_digest'] ?? null;
        if (!is_string($from) || !self::legacyStrictSemver($from) || !self::legacySha256($registration)
            || !self::legacySha256($packageDigest) || !self::legacySha256($runtimeDigest)
            || ($info['app'] ?? null) !== $app || ($info['version'] ?? null) !== $from
            || (int) ($info['state'] ?? -1) !== self::INSTALLED || ($info['stage'] ?? null) !== 'registered'
            || !hash_equals($registration, (string) ($info['registration_manifest'] ?? ''))) {
            throw new ApiException('较早版本候选恢复目录身份不匹配，需要人工处理');
        }
        $installRoot = (new PluginStorage())->root();
        $backups = $installRoot . DIRECTORY_SEPARATOR . 'backups';
        $restored = $installRoot . DIRECTORY_SEPARATOR . $app;
        $backup = $backups . DIRECTORY_SEPARATOR . $backupId;
        self::legacyCanonicalChild($installRoot, $restored);
        self::legacyCanonicalChildNode($installRoot, $backups);
        self::legacyCanonicalChild($backups, $backup);
        if (file_exists($backup . DIRECTORY_SEPARATOR . 'registration_manifest.json')) {
            throw new ApiException('较早版本备份不应混用现代清单');
        }
        self::requireLegacyBackupShape($backup, $app);
        $backupInfo = self::readPackageInfo($backup);
        if (($backupInfo['app'] ?? null) !== $app || ($backupInfo['version'] ?? null) !== $from
            || (int) ($backupInfo['state'] ?? -1) !== self::INSTALLED || ($backupInfo['stage'] ?? null) !== 'registered'
            || !hash_equals($registration, (string) ($backupInfo['registration_manifest'] ?? ''))
            || !hash_equals($packageDigest, self::legacyManifestDigest(self::legacyDirectoryManifest($backup)))
            || self::legacyDirectoryManifest($restored) !== self::legacyDirectoryManifest($backup)) {
            throw new ApiException('较早版本候选恢复备份不匹配，需要人工处理');
        }
        $frontendRoot = env('FRONTEND_DIR', 'sandadmin-artd');
        $backupPlugin = $backup . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $backupFrontend = $backup . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $runtimePlugin = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $runtimeFrontend = dirname(rtrim(base_path(), DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $runtime = ['backend' => self::legacyDirectoryManifest($backupPlugin), 'frontend' => self::legacyDirectoryManifest($backupFrontend)];
        $legacyRegistration = hash('sha256', json_encode([$runtime['backend'], $runtime['frontend']], JSON_THROW_ON_ERROR));
        if (!hash_equals($registration, $legacyRegistration)
            || !hash_equals($runtimeDigest, self::legacyManifestDigest($runtime))
            || $runtime['backend'] !== self::legacyDirectoryManifest($runtimePlugin)
            || $runtime['frontend'] !== self::legacyDirectoryManifest($runtimeFrontend)) {
            throw new ApiException('较早版本候选恢复运行时不匹配，需要人工处理');
        }
    }

    private static function legacyStrictSemver(string $version): bool
    {
        return self::semverParts($version) !== null;
    }

    /** @return array{major:string,minor:string,patch:string,pre:list<string>}|null */
    private static function semverParts(string $version): ?array
    {
        if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/', $version, $match)) {
            return null;
        }
        $pre = ($match[4] ?? '') === '' ? [] : explode('.', $match[4]);
        foreach ($pre as $part) {
            if (preg_match('/^\d+$/', $part) === 1 && preg_match('/^(0|[1-9]\d*)$/', $part) !== 1) {
                return null;
            }
        }
        return ['major' => $match[1], 'minor' => $match[2], 'patch' => $match[3], 'pre' => $pre];
    }

    private static function compareSemver(string $left, string $right): int
    {
        $leftParts = self::semverParts($left);
        $rightParts = self::semverParts($right);
        if ($leftParts === null || $rightParts === null) {
            throw new ApiException('版本格式非法');
        }
        foreach (['major', 'minor', 'patch'] as $part) {
            $result = self::compareNumericSemverPart($leftParts[$part], $rightParts[$part]);
            if ($result !== 0) {
                return $result;
            }
        }
        if ($leftParts['pre'] === [] || $rightParts['pre'] === []) {
            return $leftParts['pre'] === [] ? ($rightParts['pre'] === [] ? 0 : 1) : -1;
        }
        $limit = min(count($leftParts['pre']), count($rightParts['pre']));
        for ($index = 0; $index < $limit; $index++) {
            $leftPart = $leftParts['pre'][$index];
            $rightPart = $rightParts['pre'][$index];
            if ($leftPart === $rightPart) {
                continue;
            }
            $leftNumeric = preg_match('/^\d+$/', $leftPart) === 1;
            $rightNumeric = preg_match('/^\d+$/', $rightPart) === 1;
            if ($leftNumeric && $rightNumeric) {
                return self::compareNumericSemverPart($leftPart, $rightPart);
            }
            if ($leftNumeric !== $rightNumeric) {
                return $leftNumeric ? -1 : 1;
            }
            return $leftPart <=> $rightPart;
        }
        return count($leftParts['pre']) <=> count($rightParts['pre']);
    }

    private static function compareNumericSemverPart(string $left, string $right): int
    {
        return strlen($left) === strlen($right) ? ($left <=> $right) : (strlen($left) <=> strlen($right));
    }

    private static function legacySha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private static function requireLegacyBackupShape(string $backup, string $app): void
    {
        self::legacySafeDirectory($backup);
        foreach (['info.ini', 'config.json', 'install.sql', 'update.sql', 'uninstall.sql'] as $file) {
            $path = $backup . DIRECTORY_SEPARATOR . $file;
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || (($stat['mode'] & 0170000) !== 0100000)
                || (($stat['mode'] & 0002) !== 0) || (int) @filesize($path) <= 0) {
                throw new ApiException('较早版本备份生命周期文件不完整');
            }
        }
        $frontendRoot = env('FRONTEND_DIR', 'sandadmin-artd');
        self::legacySafeDirectory($backup . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app);
        self::legacySafeDirectory($backup . DIRECTORY_SEPARATOR . $frontendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app);
        foreach (new \DirectoryIterator($backup) as $entry) {
            if ($entry->isDot() || $entry->isDir()) {
                continue;
            }
            $name = $entry->getFilename();
            if ((str_ends_with($name, '.php') || str_ends_with($name, '.sql'))
                && !in_array($name, ['install.sql', 'update.sql', 'uninstall.sql'], true)) {
                throw new ApiException('较早版本备份包含未冻结的根生命周期文件');
            }
        }
    }

    private static function legacyCanonicalChild(string $parent, string $child): void
    {
        self::legacySafeDirectoryNode($parent);
        self::legacySafeDirectory($child);
        $parentReal = realpath($parent);
        $childParentReal = realpath(dirname($child));
        if ($parentReal === false || $childParentReal === false || !hash_equals($parentReal, $childParentReal)) {
            throw new ApiException('较早版本候选路径不受控');
        }
    }

    private static function legacyCanonicalChildNode(string $parent, string $child): void
    {
        self::legacySafeDirectoryNode($parent);
        self::legacySafeDirectoryNode($child);
        $parentReal = realpath($parent);
        $childParentReal = realpath(dirname($child));
        if ($parentReal === false || $childParentReal === false || !hash_equals($parentReal, $childParentReal)) {
            throw new ApiException('较早版本候选路径不受控');
        }
    }

    private static function legacySafeDirectory(string $directory): void
    {
        self::legacySafeDirectoryNode($directory);
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $stat = @lstat($item->getPathname());
            if (!is_array($stat) || $item->isLink() || (($stat['mode'] & 0170000) !== 0040000 && ($stat['mode'] & 0170000) !== 0100000)
                || (($stat['mode'] & 0002) !== 0) || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
                throw new ApiException('较早版本候选目录包含不安全文件');
            }
        }
    }

    private static function legacySafeDirectoryNode(string $directory): void
    {
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $stat = @lstat($directory);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000) || is_link($directory)
            || (($stat['mode'] & 0002) !== 0) || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
            throw new ApiException('较早版本候选目录安全属性不符合要求');
        }
    }

    /** @return array<string,string> */
    private static function legacyDirectoryManifest(string $directory): array
    {
        self::legacySafeDirectory($directory);
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                throw new ApiException('较早版本候选包含非普通文件');
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('较早版本候选文件摘要读取失败');
            }
            $manifest[str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName())] = $hash;
        }
        ksort($manifest, SORT_STRING);
        return $manifest;
    }

    /** @param array<mixed> $manifest */
    private static function legacyManifestDigest(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /** @throws Throwable */
    private function deployFilesWithRecovery(): string
    {
        $backupId = $this->appName . '-deploy-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
        $backupDir = $this->backupsDir . $backupId;
        if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new ApiException('无法准备插件文件备份');
        }
        $this->recordStage('file_deploy', '正在部署插件文件', ['deployment_backup_id' => $backupId]);

        $entries = [];
        try {
            foreach ($this->getAllowedPath() as $source => $target) {
                if (!is_dir($source)) {
                    throw new ApiException('插件发布包缺少必要文件');
                }
                if (file_exists($target) && !is_dir($target)) {
                    throw new ApiException('插件部署位置不可用');
                }
                if (is_link($target)) {
                    throw new ApiException('插件部署位置不可用');
                }
                $entry = ['target' => $target, 'backup' => null, 'touched' => false];
                if (is_dir($target)) {
                    $backupTarget = $backupDir . DIRECTORY_SEPARATOR . 'target-' . (count($entries) + 1);
                    if (!rename($target, $backupTarget)) {
                        throw new ApiException('无法备份当前插件文件');
                    }
                    $entry['backup'] = $backupTarget;
                }
                $entry['touched'] = true;
                $entries[] = $entry;
                $this->copyDirectory($source, $target);
            }
        } catch (Throwable $e) {
            $this->restoreDeployment($entries);
            throw $e;
        }
        $this->deploymentRecovery = $entries;
        $this->persistDeploymentRecovery($backupId, $entries);
        return $backupId;
    }

    /** @param array<int,array{target:string,backup:?string,touched:bool}> $entries */
    private function restoreDeployment(array $entries): void
    {
        foreach (array_reverse($entries) as $entry) {
            if (!$entry['touched']) {
                continue;
            }
            $target = $entry['target'];
            if (is_link($target)) {
                throw new ApiException('文件部署恢复记录不完整');
            }
            if ($entry['backup'] === null) {
                if (is_dir($target)) {
                    Filesystem::delDir($target);
                }
                continue;
            }
            if (is_dir($entry['backup'])) {
                if (is_dir($target)) {
                    Filesystem::delDir($target);
                }
                if (!rename($entry['backup'], $target)) {
                    throw new ApiException('文件部署失败，之前的插件文件未能自动恢复');
                }
                if (is_link($target) || !is_dir($target)) {
                    throw new ApiException('文件部署恢复记录不完整');
                }
                continue;
            }
            if (!is_dir($target)) {
                throw new ApiException('文件部署恢复记录不完整');
            }
        }
        $this->deploymentRecovery = [];
    }

    private function restoreLastDeployment(): void
    {
        if ($this->deploymentRecovery !== []) {
            $this->restoreDeployment($this->deploymentRecovery);
        }
    }

    /** @param array<string,mixed> $info */
    private function restoreDependencyRecovery(array $info): void
    {
        $errors = [];
        try {
            $this->restorePersistedDependencySnapshots((string) ($info['dependency_backup_id'] ?? ''));
        } catch (Throwable $e) {
            $errors[] = $e;
        }
        try {
            $this->restorePersistedDeployment((string) ($info['deployment_backup_id'] ?? ''));
        } catch (Throwable $e) {
            $errors[] = $e;
        }
        if (($info['service_catalog_registered'] ?? 0) == 1) {
            try {
                $this->rollbackServiceCatalog();
            } catch (Throwable $e) {
                $errors[] = $e;
            }
        }
        if ($errors !== []) {
            throw new ApiException('依赖恢复未能完整完成');
        }
    }

    private function compensateInstallationFailure(): void
    {
        $errors = [];
        try {
            $this->restoreDependencySnapshots();
        } catch (Throwable $e) {
            $errors[] = $e;
        }
        try {
            $this->restoreLastDeployment();
        } catch (Throwable $e) {
            $errors[] = $e;
        }
        try {
            $info = $this->getInfo();
            if (($info['service_catalog_registered'] ?? 0) == 1) {
                $this->rollbackServiceCatalog();
            }
        } catch (Throwable $e) {
            $errors[] = $e;
        }
        $info = $this->getInfo();
        $info['dependency_recovery_state'] = $errors === [] ? 'restored' : 'incomplete';
        $this->setInfo([], $info);
    }

    /** @param array<int,array{target:string,backup:?string,touched:bool}> $entries */
    private function persistDeploymentRecovery(string $backupId, array $entries): void
    {
        $payload = ['state' => 'pending', 'entries' => $entries];
        if (file_put_contents($this->backupsDir . $backupId . '.deployment.json', json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new ApiException('无法保存插件文件恢复记录');
        }
    }

    private function restorePersistedDeployment(string $backupId): void
    {
        if ($backupId === '') {
            return;
        }
        if (!preg_match('/^[a-z0-9-]+$/', $backupId)) {
            throw new ApiException('插件文件恢复记录不完整');
        }
        $file = $this->backupsDir . $backupId . '.deployment.json';
        $payload = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($payload) || !is_array($payload['entries'] ?? null)) {
            throw new ApiException('插件文件恢复记录不完整');
        }
        if (($payload['state'] ?? null) === 'restored') {
            return;
        }
        $entries = $payload['entries'];
        $allowedTargets = array_values($this->getAllowedPath());
        $backupDirectory = $this->backupsDir . $backupId;
        $backupRoot = realpath($backupDirectory);
        if ($backupRoot === false || is_link($backupDirectory) || count($entries) !== count($allowedTargets)) {
            throw new ApiException('插件文件恢复记录不完整');
        }
        $seenTargets = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry) || !is_string($entry['target'] ?? null) || !is_bool($entry['touched'] ?? null)
                || !array_key_exists($index, $allowedTargets) || $entry['target'] !== $allowedTargets[$index]
                || isset($seenTargets[$entry['target']]) || is_link($entry['target'])) {
                throw new ApiException('插件文件恢复记录不完整');
            }
            $seenTargets[$entry['target']] = true;
            if (($entry['backup'] ?? null) !== null) {
                if (!is_string($entry['backup']) || is_link($entry['backup']) || !is_dir($entry['backup'])
                    || !preg_match('/^target-[1-9][0-9]*$/', basename($entry['backup']))
                    || basename($entry['backup']) !== 'target-' . ($index + 1)
                    || realpath(dirname($entry['backup'])) !== $backupRoot
                    || dirname(realpath($entry['backup'])) !== $backupRoot) {
                    throw new ApiException('插件文件恢复记录不完整');
                }
            }
        }
        if (count($seenTargets) !== count($allowedTargets)) {
            throw new ApiException('插件文件恢复记录不完整');
        }
        $this->restoreDeployment($entries);
        $payload['state'] = 'restored';
        if (file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new ApiException('无法更新插件文件恢复记录');
        }
    }

    /** @throws Throwable */
    private function copyDirectory(string $source, string $target): void
    {
        if (is_link($source)) {
            throw new ApiException('插件发布包包含不受支持的链接文件');
        }
        if (!mkdir($target, 0755, true) && !is_dir($target)) {
            throw new ApiException('无法创建插件部署目录');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new ApiException('插件发布包包含不受支持的链接文件');
            }
            $relative = $iterator->getSubPathName();
            $destination = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
                    throw new ApiException('无法创建插件部署目录');
                }
            } elseif (!$item->isFile() || !copy($item->getPathname(), $destination)) {
                throw new ApiException('插件文件部署失败');
            }
        }
    }

    /** @throws Throwable */
    private function verifyDeploymentMatchesPackage(): string
    {
        $entries = [];
        foreach ($this->getAllowedPath() as $source => $target) {
            $sourceManifest = $this->directoryManifest($source);
            $targetManifest = $this->directoryManifest($target);
            if ($sourceManifest !== $targetManifest) {
                throw new ApiException('已部署文件与上传安装包不一致，不能登记');
            }
            $entries[] = $sourceManifest;
        }
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    private function directoryManifest(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new ApiException('插件文件核验条件不足，不能登记');
        }
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                throw new ApiException('插件文件核验条件不足，不能登记');
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('插件文件核验失败，不能登记');
            }
            $manifest[str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName())] = $hash;
        }
        ksort($manifest, SORT_STRING);
        return $manifest;
    }

    /** @param array<string,mixed> $info */
    private function assertRegistrationConfirmation(array $info, string $confirmation): bool
    {
        $version = trim((string) ($info['version'] ?? ''));
        if ($version === '' || $confirmation !== 'REGISTER ' . $this->appName . '@' . $version) {
            throw new ApiException('登记确认内容不匹配，未执行任何变更');
        }
        if (($info['state'] ?? self::UNINSTALLED) === self::INSTALLED && isset($info['registration_manifest'])) {
            return true;
        }
        if (($info['registration_candidate'] ?? 0) != 1) {
            throw new ApiException('未找到可核验的已上传安装包，不能登记');
        }
        return false;
    }

    /** @param array<string,mixed> $candidate */
    private function assertRegistrationMetadata(array $candidate): void
    {
        $runtime = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR;
        $packageRuntime = $this->appDir . 'plugin' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR;
        $candidateVersion = trim((string) ($candidate['version'] ?? ''));
        $packageInfo = Server::getIni($packageRuntime);
        $runtimeInfo = Server::getIni($runtime);
        $packageInfoVersion = trim((string) ($packageInfo['version'] ?? ''));
        $runtimeInfoVersion = trim((string) ($runtimeInfo['version'] ?? ''));
        $packageVersion = $this->readStaticAppVersion($packageRuntime . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        $runtimeVersion = $this->readStaticAppVersion($runtime . 'config' . DIRECTORY_SEPARATOR . 'app.php');
        if ($candidateVersion === '' || ($candidate['app'] ?? null) !== $this->appName
            || ($packageInfo['app'] ?? null) !== $this->appName || ($runtimeInfo['app'] ?? null) !== $this->appName
            || $packageInfoVersion === '' || $runtimeInfoVersion === '' || $packageVersion === null || $runtimeVersion === null
            || !hash_equals($candidateVersion, $packageInfoVersion) || !hash_equals($candidateVersion, $runtimeInfoVersion)
            || !hash_equals($candidateVersion, $packageVersion) || !hash_equals($candidateVersion, $runtimeVersion)) {
            throw new ApiException('安装包与运行插件的标识或版本不一致，不能登记');
        }
    }

    private function readStaticAppVersion(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if (!is_string($content) || preg_match("/['\"]version['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $content, $matches) !== 1) {
            return null;
        }
        return trim($matches[1]) !== '' ? trim($matches[1]) : null;
    }

    /**
     * The package lifecycle is a single contract. Validate all three root SQL
     * files before any install, update, registration, or uninstall path can
     * mutate the host or the staged package registry.
     *
     * @return array<string,string>
     * @throws ApiException
     */
    private function requireLifecycleFiles(): array
    {
        $files = [];
        foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $name) {
            $path = $this->appDir . $name;
            if (!is_file($path) || is_link($path) || !is_readable($path) || filesize($path) === 0) {
                throw new ApiException("插件生命周期脚本不可用：{$name}");
            }
            $files[$name] = $path;
        }
        return $files;
    }

    /**
     * SandPackage owns the PostgreSQL transaction boundary for every lifecycle
     * script. A package SQL file cannot select a different importer or retain
     * a partially applied prefix when one statement fails.
     */
    private function executeLifecycleSql(string $file, string $failureMessage): void
    {
        try {
            (new PostgresLifecycleSqlExecutor())->executeFile($file);
        } catch (Throwable $error) {
            throw new ApiException($failureMessage, 400, $error);
        }
    }

    /** @param array<string,mixed>|null $info */
    private function markInstalled(?array $info = null): void
    {
        $info ??= $this->getInfo();
        $info['registration_manifest'] = $this->verifyDeploymentMatchesPackage();
        $this->releaseDependencyOperation($info);
        unset($info['update'], $info['registration_candidate'], $info['npm_dependent_wait_install'], $info['composer_dependent_wait_install'], $info['service_catalog_registered'], $info['dependency_restart_required'], $info['last_dependency_command_type'], $info['last_dependency_command_nonce']);
        $info['state'] = self::INSTALLED;
        $info['stage'] = 'completed';
        $info['stage_label'] = '插件安装完成';
        $info['last_error'] = '';
        $this->clearFailureDiagnostic($info);
        $info['last_stable_state'] = self::INSTALLED;
        unset($info['failed_stage'], $info['uninstall_recovery_required']);
        if ($this->setInfo([], $info) !== true) {
            throw new ApiException('无法保存插件安装完成状态');
        }
    }

    private function clearMenuCacheBestEffort(): void
    {
        try {
            UserMenuCache::clearMenuCache();
        } catch (Throwable) {
            $info = $this->getInfo();
            $info['last_warning'] = '菜单缓存暂未刷新，请刷新管理端后继续操作。';
            $this->setInfo([], $info);
        }
    }

    /** @param array<string,mixed> $extra */
    private function recordStage(string $stage, string $label, array $extra = []): void
    {
        $info = $this->getInfo();
        $info['stage'] = $stage;
        $info['stage_label'] = $label;
        $info['last_error'] = '';
        $this->clearFailureDiagnostic($info);
        foreach ($extra as $key => $value) {
            $info[$key] = $value;
        }
        if ($this->setInfo([], $info) !== true) {
            throw new ApiException('无法保存插件失败状态');
        }
    }

    private function getCurrentStage(): string
    {
        return (string) ($this->getInfo()['stage'] ?? 'unknown');
    }

    /** @param array<string,mixed>|null $info */
    private function recordFailure(string $stage, Throwable $error, ?int $preserveState = null, ?array $info = null): string
    {
        $diagnosticId = self::newDiagnosticId();
        $info ??= $this->getInfo();
        $info['state'] = $preserveState ?? self::FAILED;
        $info['failed_stage'] = $stage;
        $info['stage'] = 'failed';
        $info['stage_label'] = $stage === 'database_update'
            ? '数据库升级未完成，后续文件部署已停止，需要先核验恢复条件'
            : '操作未完成，需要处理后重新执行';
        $info['last_error'] = match ($stage) {
            'lifecycle_validation' => '插件生命周期脚本不完整、不可读、为空或包含不支持的链接，未执行插件操作。',
            'database_install' => '数据库安装没有完成，数据库变更可能已部分执行，请按插件发布说明核对后再继续。',
            'database_update' => '数据库升级没有完成，数据库变更可能已部分执行，请按插件发布说明核对后再继续。',
            'database_uninstall' => '插件卸载脚本不可用或未能执行，未删除插件文件、登记信息、菜单缓存或重载服务。',
            'file_deploy' => '文件部署失败，系统已尝试恢复之前的插件文件。',
            'service_registration', 'registration_confirmation', 'registration_check', 'registration_revalidation' => '插件登记核验未完成，未将该插件标记为已安装。',
            'dependency_install' => '依赖安装未完成，系统已恢复依赖配置和之前的插件文件。',
            default => '插件操作未完成，请检查安装包、依赖和操作状态后重试。',
        };
        $info['last_error_code'] = self::failureCode($stage);
        $info['diagnostic_id'] = $diagnosticId;
        $info['last_error'] .= ' 诊断编号 ' . $diagnosticId . '。';
        $this->logFailure($diagnosticId, $stage, $error, (string) $info['last_error_code']);
        if ($this->setInfo([], $info) !== true) {
            throw new ApiException('无法保存插件失败状态');
        }
        return $diagnosticId;
    }

    /** @param array<string,mixed> $info */
    private function clearFailureDiagnostic(array &$info): void
    {
        unset($info['last_error_code'], $info['diagnostic_id']);
    }

    private static function newDiagnosticId(): string
    {
        return 'SP-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    }

    private static function failureCode(string $stage): string
    {
        return match ($stage) {
            'database_update' => 'SANDPACKAGE_DATABASE_UPDATE_FAILED',
            'database_install' => 'SANDPACKAGE_DATABASE_INSTALL_FAILED',
            'database_uninstall' => 'SANDPACKAGE_DATABASE_UNINSTALL_FAILED',
            default => 'SANDPACKAGE_OPERATION_FAILED',
        };
    }

    private static function publicFailureMessage(string $stage, string $diagnosticId): ?string
    {
        return match ($stage) {
            'database_update' => '数据库升级没有完成，诊断编号 ' . $diagnosticId . '。请根据诊断编号查看服务端日志后处理',
            'database_install' => '数据库安装没有完成，诊断编号 ' . $diagnosticId . '。请根据诊断编号查看服务端日志后处理',
            default => null,
        };
    }

    private function logFailure(string $diagnosticId, string $stage, Throwable $error, string $failureCode): void
    {
        try {
            $code = (string) $error->getCode();
            $sqlState = preg_match('/^[0-9A-Z]{5}$/', strtoupper($code)) === 1 ? strtoupper($code) : null;
            Log::error('SandPackage operation failed', [
                'diagnostic_id' => $diagnosticId,
                'app' => $this->appName,
                'stage' => $stage,
                'failure_code' => $failureCode,
                'exception_class' => $error::class,
                'exception_code' => $code,
                'sqlstate' => $sqlState,
                'exception_message' => $error->getMessage(),
                'exception_file' => $error->getFile(),
                'exception_line' => $error->getLine(),
                'exception_trace' => $error->getTraceAsString(),
            ]);
        } catch (Throwable) {
            // A logging outage must not replace the original installation failure.
        }
    }

    private function backupPackage(): string
    {
        $this->assertManagedDirectory($this->backupsDir);
        $backupId = $this->appName . '-package-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
        $target = $this->backupsDir . $backupId;
        $info = $this->getInfo();
        $deploymentManifest = $this->verifyDeploymentMatchesPackage();
        if (($info['app'] ?? null) !== $this->appName || !is_string($info['version'] ?? null) || $info['version'] === ''
            || (int) ($info['state'] ?? -1) !== self::INSTALLED || !$this->isSha256($info['registration_manifest'] ?? null)
            || !hash_equals((string) $info['registration_manifest'], $deploymentManifest)) {
            throw new ApiException('升级前插件注册信息与当前部署不一致，未移动任何文件');
        }
        if (!is_dir($this->backupsDir) && !mkdir($this->backupsDir, 0755, true) && !is_dir($this->backupsDir)) {
            throw new ApiException('无法创建插件安装包备份目录');
        }
        $this->assertManagedDirectory($this->backupsDir);
        $preparedPackageManifest = $this->preparedPackageManifest($this->appDir);
        $preparedPackageDigest = $this->preparedPackageManifestDigest($preparedPackageManifest);
        $transaction = $this->candidateTransactionPath();
        $payload = [
            'app' => $this->appName,
            'backup_id' => $backupId,
            'phase' => 'prepared',
            'deployment_manifest' => $deploymentManifest,
            'from_version' => $info['version'] ?? null,
            'previous_registration_manifest' => $info['registration_manifest'] ?? null,
            'prepared_package_manifest' => $preparedPackageManifest,
            'prepared_package_manifest_digest' => $preparedPackageDigest,
            'created_at' => time(),
        ];
        $this->writeCandidateJournal($transaction, $payload, 'backup.journal.write');
        try {
            if (!$this->renameCandidatePath($this->appDir, $target, 'backup.rename')) {
                throw new ApiException('无法备份当前插件安装包');
            }
            $payload['phase'] = 'backed_up';
            $this->writeCandidateJournal($transaction, $payload, 'backup.journal.fsync');
            $backupInfo = self::readPackageInfo($target);
            if (($backupInfo['app'] ?? null) !== $this->appName || !is_string($backupInfo['version'] ?? null) || $backupInfo['version'] === ''
                || (int) ($backupInfo['state'] ?? -1) !== self::INSTALLED || !$this->isSha256($backupInfo['registration_manifest'] ?? null)
                || !hash_equals((string) $backupInfo['registration_manifest'], $deploymentManifest)
                || !is_string($payload['from_version'] ?? null) || !hash_equals($payload['from_version'], $backupInfo['version'])
                || !is_string($payload['previous_registration_manifest'] ?? null)
                || !hash_equals($payload['previous_registration_manifest'], $backupInfo['registration_manifest'])) {
                throw new ApiException('升级前插件注册信息不完整');
            }
            $packageManifest = $this->packageManifest($target);
            $runtimeManifest = $this->runtimeManifest();
            $registrationManifest = $this->registrationManifestHash($backupId, $backupInfo, $packageManifest, $runtimeManifest, $deploymentManifest);
            $this->pendingCandidateRegistrationManifest = $registrationManifest;
            $manifest = [
                'app' => $this->appName,
                'backup_id' => $backupId,
                'version' => $backupInfo['version'],
                'registration_manifest' => $registrationManifest,
                'previous_registration_manifest' => $backupInfo['registration_manifest'],
                'deployment_manifest' => $deploymentManifest,
                'package_manifest' => $packageManifest,
                'runtime_manifest' => $runtimeManifest,
                'runtime_manifest_hash' => $this->runtimeManifestHash($runtimeManifest),
                'created_at' => time(),
            ];
            $this->writeCandidateJournal($target . DIRECTORY_SEPARATOR . 'registration_manifest.json', $manifest, 'backup.manifest.write');
            $payload['phase'] = 'manifest_written';
            $payload['registration_manifest'] = $registrationManifest;
            $payload['from_version'] = $backupInfo['version'];
            $payload['previous_registration_manifest'] = $backupInfo['registration_manifest'];
            $payload['package_manifest'] = $packageManifest;
            $payload['runtime_manifest'] = $runtimeManifest;
            $payload['runtime_manifest_hash'] = $this->runtimeManifestHash($runtimeManifest);
            $this->writeCandidateJournal($transaction, $payload, 'backup.manifest.fsync');
        } catch (Throwable $e) {
            if (is_dir($target)) {
                if (is_dir($this->appDir)) {
                    throw new ApiException('升级前插件备份与原目录同时存在，已保留恢复记录等待处理');
                }
                $this->assertPreUpgradePackageIdentity($target, $payload);
                if (!$this->renameCandidatePath($target, $this->appDir, 'backup.rollback.rename')) {
                    throw new ApiException('升级前插件备份恢复未完成，已保留恢复记录等待处理');
                }
                $this->assertPreUpgradePackageIdentity($this->appDir, $payload);
                $this->updateCandidateTransaction($backupId, 'rollback_restored');
            } elseif (!is_dir($this->appDir)) {
                throw new ApiException('升级前插件与备份均不可用，已保留恢复记录等待处理');
            }
            $this->assertPreUpgradePackageIdentity($this->appDir, $payload);
            $this->removeTransactionJournal($transaction, '升级前插件恢复完成记录无法清理');
            $this->pendingCandidateRegistrationManifest = null;
            throw $e;
        }
        return $backupId;
    }

    /** @param array<string,mixed> $newInfo */
    private function stageUploadedPackage(string $source, array $newInfo): void
    {
        $backupId = (string) ($newInfo['package_backup_id'] ?? '');
        $storedInfo = self::readPackageInfo($source);
        foreach ($newInfo as $key => $value) {
            $storedInfo[$key] = $value;
        }
        $this->clearFailureDiagnostic($storedInfo);
        if (Server::setIni($source, $storedInfo) !== true) {
            throw new ApiException('无法准备已校验安装包的登记信息');
        }
        $this->fsyncTree($source);
        if ($backupId !== '') {
            $this->updateCandidateTransaction($backupId, 'candidate_ready_to_move');
        }
        if (!$this->renameCandidatePath($source, $this->appDir, 'candidate.app.rename')) {
            if ($backupId !== '') {
                $this->restorePackageBackup($backupId);
            }
            throw new ApiException('无法准备插件安装包');
        }
        $candidateCommitted = false;
        try {
            // Persist the backup identifier before integrity/state validation so
            // an interrupted upgrade remains recoverable and diagnosable.
            if ($backupId !== '') {
                $this->updateCandidateTransaction($backupId, 'candidate_moved');
            }
            $this->candidateFault('candidate.info.write');
            $this->setInfo([], $storedInfo);
            if ($backupId !== '') {
                $this->updateCandidateTransaction($backupId, 'candidate_info_written');
            }
            $this->candidateFault('candidate.check');
            $this->checkPackage();
            if ($backupId !== '') {
                $this->updateCandidateTransaction($backupId, 'candidate_checked');
                $this->completeCandidateTransaction($backupId);
                // Unlinking the journal is the commit boundary. Any fault
                // after it must not enter the compensation branch below and
                // destroy the now-committed ready candidate.
                $candidateCommitted = true;
                $this->fsyncDirectory(dirname($this->candidateTransactionPath()));
                $this->candidateFault('candidate.complete.parent_sync');
            }
        } catch (Throwable $e) {
            if ($candidateCommitted) {
                throw $e;
            }
            $this->recordFailure('package_validation', $e);
            Filesystem::delDir($this->appDir);
            if ($backupId !== '') {
                $this->restorePackageBackup($backupId);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function stageUploadArchive(string $archive, array $metadata, bool $deleteArchive): array
    {
        $copyToDir = $this->installDir . 'upload-' . bin2hex(random_bytes(12));
        try {
            $this->assertUploadArchiveFingerprint($archive, $metadata);
            $candidateLock = @fopen($archive, 'rb');
            if (!is_resource($candidateLock) || !flock($candidateLock, LOCK_EX | LOCK_NB)) {
                if (is_resource($candidateLock)) {
                    fclose($candidateLock);
                }
                throw new ApiException('受控插件安装包正在被修改');
            }
            Filesystem::unzip($archive, $copyToDir);
            flock($candidateLock, LOCK_UN);
            fclose($candidateLock);
            $candidateLock = null;
            $this->assertUploadArchiveFingerprint($archive, $metadata);
            $this->assertExtractedArchiveManifest($copyToDir, $metadata['entries']);
            $copyToDir .= DIRECTORY_SEPARATOR;
            $info = Server::getIni($copyToDir);
            if (($info['app'] ?? null) !== $metadata['app']) {
                throw new ApiException('插件的基础配置信息错误');
            }
            $this->assertAppName((string) $info['app']);
            $this->clearFailureDiagnostic($info);
            $recoveryIdentity = $this->captureFailedUpgradeCandidateIdentity($copyToDir, $archive, (string) $metadata['sha256']);
            if (Server::setIni($copyToDir, $info) !== true) {
                throw new ApiException('无法清理安装包中的诊断信息');
            }
            $this->fsyncTree($copyToDir);
            $this->stageUploadedDirectory($copyToDir, $info, $recoveryIdentity);
            $copyToDir = '';
            if ($deleteArchive && is_file($archive) && !@unlink($archive)) {
                throw new ApiException('无法清理已校验的临时安装包');
            }
            return $info;
        } catch (Throwable $e) {
            if (isset($candidateLock) && is_resource($candidateLock)) {
                flock($candidateLock, LOCK_UN);
                fclose($candidateLock);
            }
            if ($copyToDir !== '' && is_dir($copyToDir)) {
                Filesystem::delDir($copyToDir);
            }
            throw $e;
        }
    }

    /** @param array<string,array{type:string,size:int,crc:int,sha256:string}> $expected */
    private function assertExtractedArchiveManifest(string $directory, array $expected): void
    {
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || (!$item->isDir() && !$item->isFile())) {
                throw new ApiException('插件安装包解压结果包含不安全文件');
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            if ($item->isDir()) {
                $actual[$path] = ['type' => 'dir', 'size' => 0, 'sha256' => hash('sha256', '')];
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('插件安装包解压结果无法核验');
            }
            $actual[$path] = ['type' => 'file', 'size' => $item->getSize(), 'sha256' => $hash];
        }
        foreach ($expected as $path => $entry) {
            if (($actual[$path]['type'] ?? null) !== $entry['type'] || ($entry['type'] === 'file' && ((int) ($actual[$path]['size'] ?? -1) !== $entry['size'] || !hash_equals($entry['sha256'], (string) ($actual[$path]['sha256'] ?? ''))))) {
                throw new ApiException('插件安装包解压结果与预检清单不一致');
            }
            unset($actual[$path]);
        }
        foreach ($actual as $path => $entry) {
            if ($entry['type'] !== 'dir' || !array_filter(array_keys($expected), static fn (string $candidate): bool => str_starts_with($candidate, $path . '/'))) {
                throw new ApiException('插件安装包解压结果包含预检外文件');
            }
        }
    }

    /** @param array<string,mixed> $info */
    private function stageUploadedDirectory(string $copyToDir, array $info, array $recoveryIdentity = []): void
    {
        $upgrade = false;
        if (is_dir($this->appDir)) {
            $oldInfo = $this->getInfo();
            if ($oldInfo && !empty($oldInfo['app'])) {
                $versions = explode('.', (string) ($oldInfo['version'] ?? ''));
                if (isset($versions[2])) {
                    $versions[2]++;
                }
                $nextVersion = implode('.', $versions);
                $upgrade = Version::compare($nextVersion, (string) ($info['version'] ?? ''));
                if (!$upgrade) {
                    throw new ApiException('插件已经存在');
                }
            }
            if (Filesystem::dirIsEmpty($this->appDir) || (!Filesystem::dirIsEmpty($this->appDir) && !$upgrade)) {
                throw new ApiException('该插件的安装目录已经被占用');
            }
        }

        $newInfo = [
            'state' => self::WAIT_INSTALL,
            'stage' => 'ready',
            'stage_label' => '安装包已校验，等待执行安装',
            'last_error' => '',
        ];
        if ($upgrade) {
            $newInfo['update'] = 1;
            $newInfo['package_backup_id'] = $this->backupPackage();
            $newInfo['registration_manifest'] = $this->pendingCandidateRegistrationManifest;
            $backup = $this->readVerifiedCandidateBackup((string) $newInfo['package_backup_id'], (string) $newInfo['registration_manifest']);
            $newInfo['runtime_manifest'] = $backup['runtime_manifest_hash'];
            $newInfo['upgrade_from_version'] = $backup['version'];
        } elseif ($this->hasRuntimeDeployment()) {
            $newInfo['registration_candidate'] = 1;
            $newInfo['stage'] = 'registration_ready';
            $newInfo['stage_label'] = '检测到未登记的已部署插件，请使用登记流程确认';
        }
        foreach ($recoveryIdentity as $key => $value) {
            $newInfo[$key] = $value;
        }
        $candidateInfo = self::readPackageInfo($copyToDir);
        foreach ($newInfo as $key => $value) {
            $candidateInfo[$key] = $value;
        }
        $this->clearFailureDiagnostic($candidateInfo);
        if (Server::setIni($copyToDir, $candidateInfo) !== true) {
            throw new ApiException('无法冻结已校验安装包的登记信息');
        }
        $this->fsyncTree($copyToDir);
        if ($upgrade) {
            $this->bindCandidateTransaction((string) $newInfo['package_backup_id'], $candidateInfo, $copyToDir);
        }
        $this->stageUploadedPackage($copyToDir, $newInfo);
    }

    /** @return array{app:string,sha256:string,size:int,device:int,inode:int,entries:array<string,array{type:string,size:int,crc:int,sha256:string}>} */
    private function readUploadArchiveMetadata(string $archive): array
    {
        if (!is_file($archive) || is_link($archive)) {
            throw new ApiException('插件安装包不可用');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new ApiException('插件安装包无法读取');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::UPLOAD_MAX_ENTRIES) {
                throw new ApiException('插件安装包文件数量超出限制');
            }
            $seen = [];
            $entries = [];
            $infoIndex = null;
            $totalBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new ApiException('插件安装包目录记录无效');
                }
                $name = $this->canonicalArchiveEntryName($stat['name']);
                if (isset($seen[$name])) {
                    throw new ApiException('插件安装包包含重复文件');
                }
                $seen[$name] = true;
                $opsys = 0;
                $attributes = 0;
                if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    throw new ApiException('插件安装包文件属性无法读取');
                }
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new ApiException('插件安装包包含不支持的链接文件');
                }
                $isDirectory = str_ends_with((string) $stat['name'], '/');
                if ($mode !== 0 && !$isDirectory && $mode !== 0100000) {
                    throw new ApiException('插件安装包包含不支持的文件类型');
                }
                $size = (int) ($stat['size'] ?? -1);
                if ($size < 0 || $size > self::UPLOAD_MAX_UNCOMPRESSED_BYTES - $totalBytes) {
                    throw new ApiException('插件安装包解压后大小超出限制');
                }
                $totalBytes += $size;
                $contents = $isDirectory ? '' : $zip->getFromIndex($index);
                if (!is_string($contents)) {
                    throw new ApiException('插件安装包文件内容无法读取');
                }
                $entries[$name] = ['type' => $isDirectory ? 'dir' : 'file', 'size' => $size, 'crc' => (int) ($stat['crc'] ?? -1), 'sha256' => hash('sha256', $contents)];
                if ($name === 'info.ini') {
                    $infoIndex = $index;
                }
            }
            if ($infoIndex === null) {
                throw new ApiException('插件安装包缺少基础配置信息');
            }
            $raw = $zip->getFromIndex($infoIndex);
            $info = is_string($raw) ? @parse_ini_string($raw, true, INI_SCANNER_TYPED) : false;
            if (!is_array($info) || !is_string($info['app'] ?? null)) {
                throw new ApiException('插件的基础配置信息错误');
            }
            $this->assertAppName($info['app']);
            $handle = @fopen($archive, 'rb');
            $stat = is_resource($handle) ? fstat($handle) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            $hash = hash_file('sha256', $archive);
            if (!is_array($stat) || !is_string($hash)) {
                throw new ApiException('插件安装包无法读取');
            }
            return [
                'app' => $info['app'],
                'sha256' => $hash,
                'size' => (int) $stat['size'],
                'device' => (int) $stat['dev'],
                'inode' => (int) $stat['ino'],
                'entries' => $entries,
            ];
        } finally {
            $zip->close();
        }
    }

    private function copyUploadArchiveToPrivate(string $source): string
    {
        $privateDir = $this->installDir . 'uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($privateDir) && !mkdir($privateDir, 0700, true) && !is_dir($privateDir)) {
            throw new ApiException('无法准备受控安装包目录');
        }
        $this->assertManagedDirectory($privateDir);
        if (!@chmod($privateDir, 0700)) {
            throw new ApiException('无法保护受控安装包目录');
        }
        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new ApiException('插件安装包在预检后不可读取');
        }
        $candidate = $privateDir . bin2hex(random_bytes(16)) . '.zip';
        $output = @fopen($candidate, 'x+b');
        if (!is_resource($output)) {
            fclose($input);
            throw new ApiException('无法准备受控安装包副本');
        }
        @chmod($candidate, 0600);
        try {
            $bytes = 0;
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) {
                    throw new ApiException('读取插件安装包失败');
                }
                $bytes += strlen($chunk);
                if ($bytes > self::UPLOAD_MAX_UNCOMPRESSED_BYTES || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new ApiException('插件安装包副本超出限制或写入失败');
                }
            }
            if ($bytes === 0) {
                throw new ApiException('插件安装包为空');
            }
            return $candidate;
        } catch (Throwable $e) {
            @unlink($candidate);
            throw $e;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** @param array{app:string,sha256:string,size:int,device:int,inode:int} $metadata */
    private function assertUploadArchiveFingerprint(string $candidate, array $metadata): void
    {
        $stat = @stat($candidate);
        $hash = hash_file('sha256', $candidate);
        if (!is_array($stat) || !is_string($hash) || (int) $stat['size'] !== $metadata['size']
            || (int) $stat['dev'] !== $metadata['device'] || (int) $stat['ino'] !== $metadata['inode']
            || !hash_equals($metadata['sha256'], $hash)) {
            throw new ApiException('受控插件安装包校验失败');
        }
    }

    private function canonicalArchiveEntryName(string $name): string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            throw new ApiException('插件安装包包含不安全路径');
        }
        $name = rtrim($name, '/');
        if ($name === '') {
            throw new ApiException('插件安装包包含不安全路径');
        }
        foreach (explode('/', $name) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new ApiException('插件安装包包含不安全路径');
            }
        }
        return $name;
    }

    private function uploadedFilePath(mixed $file): string
    {
        if (!is_object($file) || !method_exists($file, 'getPathname')) {
            throw new ApiException('上传文件不可用');
        }
        $path = $file->getPathname();
        if (!is_string($path) || !is_file($path) || is_link($path)) {
            throw new ApiException('上传文件不可用');
        }
        return $path;
    }

    private function useAppName(string $appName): void
    {
        $this->assertAppName($appName);
        $this->appName = $appName;
        $this->appDir = $this->installDir . $appName . DIRECTORY_SEPARATOR;
    }

    private function restorePackageBackup(string $backupId): void
    {
        $backup = $this->backupsDir . $backupId;
        $this->assertManagedDirectory($this->backupsDir);
        if (!$this->isBackupId($backupId) || !is_dir($backup) || is_dir($this->appDir)) {
            throw new ApiException('安装包校验失败，之前的安装包未能自动恢复');
        }
        $this->assertSafePackageDirectory($backup);
        $transaction = $this->readCandidateTransaction($backupId);
        $metadataFile = $backup . DIRECTORY_SEPARATOR . 'registration_manifest.json';
        $metadata = is_file($metadataFile) ? json_decode((string) file_get_contents($metadataFile), true) : null;
        if (!is_array($metadata) || ($metadata['backup_id'] ?? null) !== $backupId || !is_string($metadata['version'] ?? null)
            || ($transaction['from_version'] ?? null) !== $metadata['version']) {
            throw new ApiException('安装包恢复证据不完整');
        }
        $this->updateCandidateTransaction($backupId, 'rollback_restore_pending');
        if (!$this->renameCandidatePath($backup, $this->appDir, 'rollback.restore.rename')) {
            throw new ApiException('安装包校验失败，之前的安装包未能自动恢复');
        }
        $this->updateCandidateTransaction($backupId, 'rollback_restored');
        $this->assertRestoredBackupFromTransaction($transaction);
        $file = $this->candidateTransactionPath();
        $this->candidateFault('rollback.journal.unlink');
        $this->removeTransactionJournal($file, '安装包恢复完成记录无法清理');
        $this->candidateFault('rollback.journal.parent_sync');
    }

    /**
     * Revert a staged upgrade before any lifecycle SQL, dependency command,
     * package deployment, or service restart is allowed to run.
     *
     * @throws Throwable
     */
    public function discardCandidate(string $confirmation): array
    {
        $this->acquireOperationLock();
        try {
            $this->assertNoDependencyOperation();
            $this->assertNotFailedUpgradeRecovery();
            $info = $this->getInfo();
            $modern = $this->isReadyUpgradeCandidate($info);
            $legacyEvidence = null;
            if (!$modern && $this->isLegacyHistoryCandidateShape($info)) {
                $legacyEvidence = $this->assertLegacyHistoryCandidate($info);
            }
            if (!$modern && $legacyEvidence === null) {
                throw new ApiException('仅可撤回尚未执行的升级候选');
            }
            $version = (string) ($info['version'] ?? '');
            if (!hash_equals('DISCARD ' . $this->appName . '@' . $version, $confirmation)) {
                throw new ApiException('撤回确认内容不匹配，未执行任何变更');
            }
            $backupId = (string) $info['package_backup_id'];
            if ($legacyEvidence !== null) {
                // Historical candidates predate support and the modern manifests. They are
                // withdraw-only, so compatibility is deliberately not evaluated here.
                $backup = ['version' => $legacyEvidence['from_version']];
            } else {
                $backup = $this->readVerifiedCandidateBackup($backupId, (string) $info['registration_manifest']);
                $this->assertRuntimeManifest($backup['runtime_manifest']);
                if (!hash_equals($backup['runtime_manifest_hash'], (string) $info['runtime_manifest'])) {
                    throw new ApiException('升级候选运行时清单绑定不匹配，不能撤回');
                }
                $this->assertUpgradeLineage($info, $backup['version']);
                $this->assertSafePackageDirectory($this->appDir);
            }

            $quarantineRoot = $this->installDir . 'quarantine' . DIRECTORY_SEPARATOR . $this->appName;
            if (!is_dir($quarantineRoot) && !mkdir($quarantineRoot, 0700, true) && !is_dir($quarantineRoot)) {
                throw new ApiException('无法准备升级候选隔离目录');
            }
            $this->assertManagedDirectory($quarantineRoot);
            $this->assertSafePackageDirectory($quarantineRoot);
            $quarantine = $quarantineRoot . DIRECTORY_SEPARATOR . $version . '-' . bin2hex(random_bytes(8));
            $restore = $this->installDir . '.restore-' . bin2hex(random_bytes(8));
            $journal = $this->discardTransactionPath();
            $journalPayload = ['app' => $this->appName, 'backup_id' => $backupId, 'candidate' => $quarantine, 'phase' => 'prepared', 'created_at' => time()];
            if ($legacyEvidence !== null) {
                $journalPayload['legacy_history'] = true;
                $journalPayload['derived_from_version'] = $legacyEvidence['from_version'];
                $journalPayload['derived_registration_digest'] = $legacyEvidence['registration_digest'];
                $journalPayload['derived_backup_package_digest'] = $legacyEvidence['backup_package_digest'];
                $journalPayload['derived_runtime_digest'] = $legacyEvidence['runtime_digest'];
                $journalPayload['derived_candidate_package_digest'] = $legacyEvidence['candidate_package_digest'];
            } else {
                $nonce = bin2hex(random_bytes(16));
                $info['discard_journal_schema'] = 'sandpackage-discard-v2';
                $info['discard_journal_epoch'] = 2;
                $info['discard_journal_nonce'] = $nonce;
                if (Server::setIni($this->appDir, $info) !== true) {
                    throw new ApiException('无法准备撤回事务候选证据');
                }
                $this->fsyncTree($this->appDir);
                $backupEvidenceFile = $this->backupsDir . $backupId . DIRECTORY_SEPARATOR . 'registration_manifest.json';
                $backupEvidence = json_decode((string) file_get_contents($backupEvidenceFile), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($backupEvidence)) {
                    throw new ApiException('撤回恢复备份证据不完整，需要人工处理');
                }
                $backupEvidence['discard_journal_schema'] = 'sandpackage-discard-v2';
                $backupEvidence['discard_journal_epoch'] = 2;
                $backupEvidence['discard_journal_nonce'] = $nonce;
                $this->writeCandidateJournal($backupEvidenceFile, $backupEvidence, 'discard.backup.evidence.write');
                $candidateManifest = $this->preparedPackageManifest($this->appDir);
                $backupManifest = $this->preparedPackageManifest($this->backupsDir . $backupId, true);
                $journalPayload['schema'] = 'sandpackage-discard-v2';
                $journalPayload['epoch'] = 2;
                $journalPayload['nonce'] = $nonce;
                $journalPayload['candidate_package_manifest'] = $candidateManifest;
                $journalPayload['candidate_package_manifest_digest'] = $this->preparedPackageManifestDigest($candidateManifest);
                $journalPayload['backup_package_manifest'] = $backupManifest;
                $journalPayload['backup_package_manifest_digest'] = $this->preparedPackageManifestDigest($backupManifest);
            }
            $this->writeCandidateJournal($journal, $journalPayload, 'discard.journal.write');
            if (!$this->renameCandidatePath($this->appDir, $quarantine, 'discard.quarantine.rename')) {
                throw new ApiException('无法隔离升级候选');
            }
            if ($legacyEvidence === null) {
                $this->assertDiscardCandidatePackage($quarantine, $journalPayload);
            }
            try {
                $this->candidateFault('discard.backup.copy');
                $this->copyDirectory($this->backupsDir . $backupId, $restore);
                $this->chmodRestoreTree($restore);
                $this->candidateFault('discard.restore.temp.write');
                $this->fsyncTree($restore);
                $this->fsyncDirectory(dirname($restore));
                $this->candidateFault('discard.restore.temp.fsync');
                $this->candidateFault('discard.restore.chmod');
                if ($legacyEvidence === null && !@unlink($restore . DIRECTORY_SEPARATOR . 'registration_manifest.json')) {
                    throw new ApiException('无法准备旧注册包恢复');
                }
                $this->fsyncDirectory($restore);
                $this->candidateFault('discard.restore.manifest.fsync');
                if ($legacyEvidence === null) {
                    $this->assertDiscardBackupPackage($restore, $journalPayload);
                }
                $restoredInfo = self::readPackageInfo($restore);
                if (($restoredInfo['app'] ?? null) !== $this->appName || ($restoredInfo['version'] ?? null) !== $backup['version']) {
                    throw new ApiException('升级前注册包校验失败');
                }
                if (!$this->renameCandidatePath($restore, $this->appDir, 'discard.restore.rename')) {
                    throw new ApiException('无法恢复升级前注册包');
                }
                $this->assertDiscardJournalApp($journalPayload);
                $this->candidateFault('discard.journal.unlink');
                $this->removeTransactionJournal($journal, '撤回完成记录无法清理');
                $this->candidateFault('discard.journal.parent_sync');
                $this->candidateFault('discard.cleanup');
                return $this->getInfo();
            } catch (Throwable $e) {
                if (is_dir($restore)) {
                    Filesystem::delDir($restore);
                }
                if (is_dir($this->appDir)) {
                    if ($legacyEvidence === null) {
                        $this->assertDiscardBackupPackage($this->appDir, $journalPayload);
                    } else {
                        $this->assertSafePackageDirectory($this->appDir);
                    }
                    Filesystem::delDir($this->appDir);
                }
                if (!is_dir($quarantine)) {
                    throw new ApiException('撤回恢复失败且候选隔离包缺失，需要人工处理');
                }
                if ($legacyEvidence === null) {
                    $this->assertDiscardCandidatePackage($quarantine, $journalPayload);
                } else {
                    $this->assertSafePackageDirectory($quarantine);
                }
                if (!$this->renameCandidatePath($quarantine, $this->appDir, 'discard.rollback.rename')) {
                    throw new ApiException('撤回恢复失败且候选无法回滚，需要人工处理');
                }
                if ($legacyEvidence === null) {
                    $this->assertDiscardCandidatePackage($this->appDir, $journalPayload);
                }
                throw $e;
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /** @param array<string,mixed> $info */
    private function isReadyUpgradeCandidate(array $info): bool
    {
        $shape = $this->isUpgradeCandidateStage($info)
            && ($info['app'] ?? null) === $this->appName
            && is_string($info['version'] ?? null) && $this->isStrictSemver($info['version'])
            && is_string($info['package_backup_id'] ?? null) && $this->isBackupId($info['package_backup_id'])
            && $this->isSha256($info['registration_manifest'] ?? null)
            && $this->isSha256($info['runtime_manifest'] ?? null)
            && is_string($info['upgrade_from_version'] ?? null) && $this->isStrictSemver($info['upgrade_from_version'])
            && self::compareSemver((string) $info['version'], (string) $info['upgrade_from_version']) > 0;
        if (!$shape) {
            return false;
        }
        try {
            self::assertCandidatePackageIdentity($info);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $info */
    private function isLegacyHistoryCandidate(array $info): bool
    {
        if (!$this->isLegacyHistoryCandidateShape($info)) {
            return false;
        }
        try {
            self::legacyHistoryEvidence($info);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $info @return array{from_version:string,registration_digest:string,backup_package_digest:string,runtime_digest:string,candidate_package_digest:string} */
    private function assertLegacyHistoryCandidate(array $info): array
    {
        if (!$this->isLegacyHistoryCandidateShape($info)) {
            throw new ApiException('较早版本候选信息不完整或异常，不能撤回');
        }
        try {
            return self::legacyHistoryEvidence($info);
        } catch (Throwable $e) {
            throw new ApiException('较早版本候选无法完成历史证据校验，不能撤回', previous: $e);
        }
    }

    /** @param array<string,mixed> $info */
    private function isLegacyHistoryCandidateShape(array $info): bool
    {
        foreach (['registration_manifest', 'runtime_manifest', 'support', 'upgrade_from_version'] as $field) {
            if (array_key_exists($field, $info)) {
                return false;
            }
        }
        return $this->isUpgradeCandidateStage($info)
            && ($info['app'] ?? null) === $this->appName
            && is_string($info['version'] ?? null) && $this->isStrictSemver($info['version'])
            && is_string($info['package_backup_id'] ?? null) && $this->isBackupId($info['package_backup_id']);
    }

    /** @param array<string,mixed> $info */
    private function isUpgradeCandidateStage(array $info): bool
    {
        return (int) ($info['state'] ?? -1) === self::WAIT_INSTALL
            && ($info['stage'] ?? null) === 'ready'
            && (int) ($info['update'] ?? 0) === 1;
    }

    /** @param array<string,mixed> $info */
    private function assertHostSupportsUpgrade(array $info): void
    {
        $support = $info['support'] ?? null;
        $host = config('plugin.sandadmin.app.version');
        if (!is_string($support) || !is_string($host)
            || !preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/', $host, $match)) {
            throw new ApiException('升级候选兼容信息不完整或宿主版本非法，不能升级');
        }
        if (($match[4] ?? '') !== '' && array_filter(explode('.', $match[4]), static fn (string $part): bool => preg_match('/^\d+$/', $part) === 1 && preg_match('/^(0|[1-9]\d*)$/', $part) !== 1)) {
            throw new ApiException('宿主版本非法，不能升级');
        }
        $tokens = explode('|', $support);
        if ($tokens === [] || array_filter($tokens, static fn (string $token): bool => preg_match('/^\d+\.x$/', $token) !== 1)
            || !in_array($match[1] . '.x', $tokens, true)) {
            throw new ApiException('升级候选与当前宿主版本不兼容，不能升级');
        }
    }

    /** @param array<string,mixed> $info */
    private function assertUpgradeLineage(array $info, string $fromVersion): void
    {
        $toVersion = $info['version'] ?? null;
        if (($info['upgrade_from_version'] ?? null) !== $fromVersion || !is_string($toVersion)
            || !$this->isStrictSemver($fromVersion) || !$this->isStrictSemver($toVersion)
            || self::compareSemver($toVersion, $fromVersion) <= 0) {
            throw new ApiException('升级候选版本谱系校验失败，不能执行或撤回');
        }
    }

    private function isStrictSemver(string $version): bool
    {
        return self::semverParts($version) !== null;
    }

    /** @return array{version:string,runtime_manifest:array<int,array{path:string,manifest:array<string,string>}>,runtime_manifest_hash:string,package_manifest_sha256:string,previous_registration_manifest_sha256:string,deployment_manifest_sha256:string} */
    private function readVerifiedCandidateBackup(string $backupId, string $registrationManifest): array
    {
        if (!$this->isBackupId($backupId) || !$this->isSha256($registrationManifest)) {
            throw new ApiException('升级候选备份标识非法');
        }
        $this->assertManagedDirectory($this->backupsDir);
        $backup = $this->backupsDir . $backupId;
        $this->assertSafePackageDirectory($backup);
        $backupRoot = realpath($this->backupsDir);
        if ($backupRoot === false || realpath(dirname($backup)) !== $backupRoot) {
            throw new ApiException('升级候选备份路径非法');
        }
        $file = $backup . DIRECTORY_SEPARATOR . 'registration_manifest.json';
        if (!is_file($file) || is_link($file) || !$this->isSafeRegularFile($file, 0600)) {
            throw new ApiException('升级候选备份清单缺失');
        }
        $metadata = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $packageManifest = $this->packageManifest($backup);
        $runtimeManifest = $metadata['runtime_manifest'] ?? null;
        $backupInfo = self::readPackageInfo($backup);
        if (!is_array($metadata) || ($metadata['app'] ?? null) !== $this->appName || ($metadata['backup_id'] ?? null) !== $backupId
            || !is_string($metadata['version'] ?? null) || $metadata['version'] === ''
            || !is_string($metadata['registration_manifest'] ?? null) || !$this->isSha256($metadata['registration_manifest']) || !hash_equals($registrationManifest, $metadata['registration_manifest'])
            || !is_string($metadata['previous_registration_manifest'] ?? null) || !$this->isSha256($metadata['previous_registration_manifest'])
            || !is_string($metadata['deployment_manifest'] ?? null) || !$this->isSha256($metadata['deployment_manifest'])
            || !is_array($metadata['package_manifest'] ?? null) || $metadata['package_manifest'] !== $packageManifest
            || !is_array($runtimeManifest) || !is_string($metadata['runtime_manifest_hash'] ?? null)
            || !$this->isSha256($metadata['runtime_manifest_hash']) || !hash_equals($metadata['runtime_manifest_hash'], $this->runtimeManifestHash($runtimeManifest))
            || $runtimeManifest !== $this->backupRuntimeManifest($backup, $runtimeManifest)
            || ($backupInfo['app'] ?? null) !== $this->appName || ($backupInfo['version'] ?? null) !== $metadata['version']
            || ($backupInfo['registration_manifest'] ?? null) !== $metadata['previous_registration_manifest']
            || !hash_equals((string) $metadata['previous_registration_manifest'], (string) $metadata['deployment_manifest'])
            || !hash_equals((string) $metadata['deployment_manifest'], $this->deploymentManifestForPackage($backup, $runtimeManifest))
            || !hash_equals((string) $metadata['registration_manifest'], $this->registrationManifestHash($backupId, $backupInfo, $packageManifest, $runtimeManifest, (string) $metadata['deployment_manifest']))) {
            throw new ApiException('升级候选备份清单校验失败');
        }
        return [
            'version' => $metadata['version'],
            'backup_directory' => $backup,
            'backup_id' => $backupId,
            'runtime_manifest' => $runtimeManifest,
            'runtime_manifest_hash' => $metadata['runtime_manifest_hash'],
            'package_manifest_sha256' => hash('sha256', json_encode($packageManifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'previous_registration_manifest_sha256' => $metadata['previous_registration_manifest'],
            'deployment_manifest_sha256' => $metadata['deployment_manifest'],
        ];
    }

    /**
     * Prove the stored runtime manifest against the immutable backup package,
     * not the mutable deployed runtime. Keeping those two checks separate is
     * what makes a runtime-drift diagnosis safe and actionable.
     *
     * @param array<int,array{path:string,manifest:array<string,string>}> $stored
     * @return array<int,array{path:string,manifest:array<string,string>}>
     */
    private function backupRuntimeManifest(string $backup, array $stored): array
    {
        $targets = array_values($this->getAllowedPath());
        if (count($stored) !== count($targets) || count($targets) !== 2) {
            throw new ApiException('升级候选备份运行时清单不完整');
        }
        $verified = [];
        foreach ($targets as $index => $target) {
            $entry = $stored[$index] ?? null;
            if (!is_array($entry) || ($entry['path'] ?? null) !== $this->canonicalRuntimeTargetPath($target)
                || !is_array($entry['manifest'] ?? null)) {
                throw new ApiException('升级候选备份运行时清单非法');
            }
            $source = $this->backupRuntimeSource($backup, $index);
            $manifest = $this->directoryManifest($source);
            if ($manifest !== $entry['manifest']) {
                throw new ApiException('升级候选备份运行时清单校验失败');
            }
            $verified[] = ['path' => $entry['path'], 'manifest' => $manifest];
        }
        return $verified;
    }

    private function canonicalRuntimeTargetPath(string $target): string
    {
        $parent = realpath(dirname($target));
        if ($parent === false || is_link(dirname($target))) {
            throw new ApiException('升级候选备份运行时目标路径非法');
        }
        return rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($target);
    }

    private function backupRuntimeSource(string $backup, int $index): string
    {
        $sources = array_keys($this->getAllowedPath());
        if (!isset($sources[$index])) {
            throw new ApiException('升级候选备份运行时源不完整');
        }
        $relative = ltrim(substr($sources[$index], strlen(rtrim($this->appDir, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
        $source = rtrim($backup, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
        $this->assertSafePackageDirectory($source);
        return $source;
    }

    /** @param array<string,mixed> $info @return array{required:bool,backup:array<string,mixed>,backup_id:string,diff:list<array<string,mixed>>} */
    private function runtimeRestoreDiagnostic(array $info): array
    {
        $backup = $this->readVerifiedCandidateBackup((string) $info['package_backup_id'], (string) $info['registration_manifest']);
        if (!hash_equals($backup['runtime_manifest_hash'], (string) ($info['runtime_manifest'] ?? ''))) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：失败候选未绑定已验证的升级前运行时清单', 400);
        }
        $current = [];
        foreach (array_values($this->getAllowedPath()) as $target) {
            $current[] = [
                'path' => $this->canonicalRuntimeTargetPath($target),
                'manifest' => is_dir($target) && !is_link($target) ? $this->directoryManifest($target) : [],
            ];
        }
        $diff = $this->limitedRuntimeDiff($backup['runtime_manifest'], $current);
        return ['required' => $diff !== [], 'backup' => $backup, 'backup_id' => (string) $info['package_backup_id'], 'diff' => $diff];
    }

    /**
     * The response identifier is derived only from immutable evidence. It is
     * deliberately not a private filesystem path or the random quarantine
     * directory name, so a confirmed repeated POST has the same durable result.
     *
     * @param array<string,mixed> $info
     * @param array{required:bool,backup:array<string,mixed>,backup_id:string,diff:list<array<string,mixed>>} $runtime
     * @return array{app:string,from_version:string,to_version:string,state:string,status:string,restore_id:string,runtime_manifest_hash:string,message:string}
     */
    private function runtimeRestoreSuccess(array $info, array $runtime, string $message): array
    {
        $runtimeManifestHash = (string) $runtime['backup']['runtime_manifest_hash'];
        $restoreId = substr(hash('sha256', json_encode([
            'app' => $this->appName,
            'backup_id' => (string) $runtime['backup_id'],
            'runtime_manifest_hash' => $runtimeManifestHash,
        ], JSON_THROW_ON_ERROR)), 0, 32);
        return [
            'app' => $this->appName,
            'from_version' => (string) $info['upgrade_from_version'],
            'to_version' => (string) $info['version'],
            // Kept during the staging compatibility window; clients must use status.
            'state' => 'restored',
            'status' => 'restored',
            'restore_id' => $restoreId,
            'runtime_manifest_hash' => $runtimeManifestHash,
            'message' => $message,
        ];
    }

    /** @param array<int,array{path:string,manifest:array<string,string>}> $stored @param array<int,array{path:string,manifest:array<string,string>}> $current @return list<array<string,mixed>> */
    private function limitedRuntimeDiff(array $stored, array $current): array
    {
        $diff = [];
        foreach ($stored as $index => $entry) {
            $actual = $current[$index]['manifest'] ?? [];
            $expected = $entry['manifest'];
            $changes = [];
            foreach ($expected as $path => $hash) {
                $now = $actual[$path] ?? null;
                if ($now === null || !hash_equals($hash, $now)) {
                    if (count($changes) < 10) $changes[] = ['path' => $path, 'kind' => $now === null ? 'missing' : 'changed'];
                }
            }
            foreach ($actual as $path => $_hash) {
                if (!array_key_exists($path, $expected) && count($changes) < 10) $changes[] = ['path' => $path, 'kind' => 'extra'];
            }
            if ($changes !== []) $diff[] = ['target' => $index === 0 ? 'backend' : 'frontend', 'changes' => $changes];
        }
        return $diff;
    }

    private function runtimeRestoreTransactionPath(): string
    {
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . $this->appName . '-runtime-restore.transaction.json';
    }

    /** Resume only a journal whose target and staged paths are exact children of its private restore root. */
    private function resumeRuntimeRestore(array $info): bool
    {
        $journal = $this->runtimeRestoreTransactionPath();
        if (!file_exists($journal)) return false;
        if (is_link($journal) || !$this->isSafeRegularFile($journal, 0600)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不安全', 400);
        }
        $payload = json_decode((string) file_get_contents($journal), true, 512, JSON_THROW_ON_ERROR);
        $id = $payload['id'] ?? null;
        $backupId = $payload['backup_id'] ?? null;
        $entries = $payload['entries'] ?? null;
        if (!is_array($payload) || ($payload['schema'] ?? null) !== 'sandpackage-runtime-restore/v1'
            || ($payload['app'] ?? null) !== $this->appName || !is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || !is_string($backupId) || !is_array($entries) || count($entries) !== 2) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录不完整', 400);
        }
        $backup = $this->readVerifiedCandidateBackup($backupId, (string) $info['registration_manifest']);
        if (!hash_equals($backup['runtime_manifest_hash'], (string) ($payload['runtime_manifest_hash'] ?? ''))) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录与备份不一致', 400);
        }
        $root = $this->installDir . 'runtime-restores' . DIRECTORY_SEPARATOR . $this->appName . DIRECTORY_SEPARATOR . $id;
        $targets = array_values($this->getAllowedPath());
        foreach ($entries as $index => $entry) {
            if (!is_array($entry) || !isset($targets[$index]) || ($entry['target'] ?? null) !== $targets[$index]
                || ($entry['stage'] ?? null) !== $root . DIRECTORY_SEPARATOR . 'stage' . DIRECTORY_SEPARATOR . 'target-' . ($index + 1)
                || ($entry['quarantine'] ?? null) !== $root . DIRECTORY_SEPARATOR . 'quarantine' . DIRECTORY_SEPARATOR . 'target-' . ($index + 1)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录路径不匹配', 400);
            }
            $target = $entry['target']; $stage = $entry['stage']; $quarantine = $entry['quarantine'];
            if (is_dir($target) && !is_link($target) && $this->directoryManifest($target) === $backup['runtime_manifest'][$index]['manifest']) continue;
            if (file_exists($target)) {
                if (!is_dir($target) || is_link($target) || file_exists($quarantine) || !@rename($target, $quarantine)) {
                    throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：中断的运行文件恢复证据不足，已保留隔离目录等待处理', 400);
                }
            }
            if (!is_dir($stage)) {
                $this->copyDirectory($this->backupRuntimeSource($backup['backup_directory'], $index), $stage);
                $this->fsyncTree($stage);
            }
            if (is_link($stage) || !@rename($stage, $target)) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：中断的运行文件恢复证据不足，已保留隔离目录等待处理', 400);
            }
        }
        $this->assertRuntimeManifest($backup['runtime_manifest']);
        $this->removeTransactionJournal($journal, 'FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复记录无法完成');
        return true;
    }

    private function isSha256(mixed $value): bool
    {
        return self::isSha256Value($value);
    }

    private function isBackupId(string $backupId): bool
    {
        return preg_match('/^' . preg_quote($this->appName, '/') . '-package-[0-9]{14}-[a-f0-9]{12}$/', $backupId) === 1;
    }

    /** @param array<int,array{path:string,manifest:array<string,string>}> $runtimeManifest */
    private function runtimeManifestHash(array $runtimeManifest): string
    {
        return hash('sha256', json_encode($runtimeManifest, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $info @param array<string,string> $packageManifest @param array<int,array{path:string,manifest:array<string,string>}> $runtimeManifest */
    private function registrationManifestHash(string $backupId, array $info, array $packageManifest, array $runtimeManifest, string $deploymentManifest): string
    {
        return hash('sha256', json_encode([
            'app' => $this->appName,
            'backup_id' => $backupId,
            'version' => $info['version'] ?? null,
            'previous_registration_manifest' => $info['registration_manifest'] ?? null,
            'deployment_manifest' => $deploymentManifest,
            'package_manifest' => $packageManifest,
            'runtime_manifest' => $runtimeManifest,
            'runtime_manifest_hash' => $this->runtimeManifestHash($runtimeManifest),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<int,array{path:string,manifest:array<string,string>}> $runtimeManifest */
    private function deploymentManifestForPackage(string $package, array $runtimeManifest): string
    {
        $entries = [];
        $sources = array_keys($this->getAllowedPath());
        foreach ($sources as $source) {
            $relative = ltrim(substr($source, strlen(rtrim($this->appDir, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
            $entries[] = $this->directoryManifest(rtrim($package, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative);
        }
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    /** @return array<int,array{path:string,manifest:array<string,string>}> */
    private function runtimeManifest(): array
    {
        $targets = array_values($this->getAllowedPath());
        if (count($targets) !== 2) {
            throw new ApiException('插件部署目标不完整');
        }
        return array_map(fn (string $target): array => ['path' => $this->canonicalDirectory($target), 'manifest' => $this->directoryManifest($target)], $targets);
    }

    /** @param array<int,array{path:string,manifest:array<string,string>}> $manifest */
    private function assertRuntimeManifest(array $manifest): void
    {
        $expected = array_map(fn (string $target): string => $this->canonicalDirectory($target), array_values($this->getAllowedPath()));
        if (count($expected) !== 2 || count($manifest) !== 2) {
            throw new ApiException('升级候选运行时清单不完整');
        }
        $actual = [];
        foreach ($manifest as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_array($entry['manifest'] ?? null)
                || !in_array($entry['path'], $expected, true) || isset($actual[$entry['path']])) {
                throw new ApiException('升级候选运行时清单非法');
            }
            $actual[$entry['path']] = $entry['manifest'];
        }
        foreach ($expected as $target) {
            if (!isset($actual[$target]) || $actual[$target] !== $this->directoryManifest($target)) {
                throw new ApiException('已部署运行时文件与升级前清单不一致，拒绝撤回候选');
            }
        }
    }

    private function canonicalDirectory(string $directory): string
    {
        $this->assertSafePackageDirectory($directory);
        $canonical = realpath($directory);
        if ($canonical === false) {
            throw new ApiException('插件目录无法规范化');
        }
        return rtrim($canonical, DIRECTORY_SEPARATOR);
    }

    private function assertSafePackageDirectory(string $directory): void
    {
        $stat = @lstat($directory);
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000) || is_link($directory)
            || (($stat['mode'] & 0002) !== 0) || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
            throw new ApiException('插件目录安全属性不符合要求');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $entry = @lstat($item->getPathname());
            if (!is_array($entry) || $item->isLink() || (($entry['mode'] & 0170000) !== 0040000 && ($entry['mode'] & 0170000) !== 0100000)
                || (($entry['mode'] & 0002) !== 0) || ($expectedUid !== null && $entry['uid'] !== $expectedUid)) {
                throw new ApiException('插件目录包含不安全文件');
            }
        }
    }

    private function prepareManagedDirectory(string $directory, int $mode): void
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '' || !str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new ApiException('插件受控目录路径非法');
        }
        $missing = [];
        $current = $directory;
        while (@lstat($current) === false) {
            $missing[] = $current;
            $parent = dirname($current);
            if ($parent === $current) {
                throw new ApiException('插件受控目录路径非法');
            }
            $current = $parent;
        }
        $this->assertManagedDirectoryNode($current);
        $runtimeRoot = dirname(rtrim($this->installDir, DIRECTORY_SEPARATOR));
        for ($node = $current; $node !== $runtimeRoot && str_starts_with($node, $runtimeRoot . DIRECTORY_SEPARATOR); $node = dirname($node)) {
            $this->assertManagedDirectoryNode(dirname($node));
        }
        foreach (array_reverse($missing) as $path) {
            if (!@mkdir($path, $mode) && !is_dir($path)) {
                throw new ApiException('无法准备插件受控目录');
            }
            if (!@chmod($path, $mode)) {
                throw new ApiException('无法保护插件受控目录');
            }
            $this->assertManagedDirectoryNode($path);
        }
        $this->assertManagedDirectoryNode($directory);
    }

    private function assertManagedDirectoryNode(string $directory): void
    {
        $stat = @lstat($directory);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000) || is_link($directory)
            || (($stat['mode'] & 0002) !== 0) || ($uid !== null && $stat['uid'] !== $uid)) {
            throw new ApiException('插件受控目录父链不安全');
        }
    }

    private function assertManagedDirectory(string $directory): void
    {
        $install = rtrim($this->installDir, DIRECTORY_SEPARATOR);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        try {
            $this->assertManagedDirectoryNode($install);
        } catch (ApiException) {
            throw new ApiException('插件受控目录根不安全');
        }
        $relative = ltrim(substr(rtrim($directory, DIRECTORY_SEPARATOR), strlen($install)), DIRECTORY_SEPARATOR);
        if ($relative === '' || str_starts_with($relative, '..') || !str_starts_with(rtrim($directory, DIRECTORY_SEPARATOR), $install . DIRECTORY_SEPARATOR)) {
            throw new ApiException('插件受控目录路径非法');
        }
        $current = $install;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ApiException('插件受控目录路径非法');
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            $stat = @lstat($current);
            if ($stat === false) {
                break;
            }
            $this->assertManagedDirectoryNode($current);
        }
    }

    private function isSafeRegularFile(string $file, int $requiredMode = 0): bool
    {
        $stat = @lstat($file);
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        return is_array($stat) && !is_link($file) && (($stat['mode'] & 0170000) === 0100000)
            && (($stat['mode'] & 0002) === 0) && ($expectedUid === null || $stat['uid'] === $expectedUid)
            && ($requiredMode === 0 || (($stat['mode'] & 0777) === $requiredMode));
    }

    /** @return array<string,string> */
    private function packageManifest(string $directory): array
    {
        $manifest = $this->directoryManifest($directory);
        unset($manifest['registration_manifest.json']);
        return $manifest;
    }

    /** @return array<string,array{size:int,sha256:string}> */
    private function preparedPackageManifest(string $directory, bool $allowGeneratedRegistrationTemp = false): array
    {
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                throw new ApiException('升级前插件包包含不安全文件');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            // This file is generated only after the old package moves into
            // backups. Transaction journals and quarantine live outside the
            // package root and are never part of this package contract.
            if ($relative === 'registration_manifest.json') {
                continue;
            }
            if (preg_match('/^registration_manifest\.json\.[a-f0-9]{16}\.tmp$/', $relative) === 1) {
                if (!$allowGeneratedRegistrationTemp) {
                    throw new ApiException('升级前插件包包含未完成事务文件');
                }
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('升级前插件包文件无法校验');
            }
            $manifest[$relative] = ['size' => $item->getSize(), 'sha256' => $hash];
        }
        ksort($manifest, SORT_STRING);
        return $manifest;
    }

    /** @param array<string,array{size:int,sha256:string}> $manifest */
    private function preparedPackageManifestDigest(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function removeGeneratedRegistrationTemps(string $directory): void
    {
        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'registration_manifest.json.*.tmp') ?: [] as $file) {
            if (preg_match('/registration_manifest\.json\.[a-f0-9]{16}\.tmp$/', $file) !== 1 || !$this->isSafeRegularFile($file, 0600)) {
                throw new ApiException('升级前插件事务临时文件不安全，需要人工处理');
            }
            if (!@unlink($file)) {
                throw new ApiException('无法清理升级前插件事务临时文件');
            }
        }
        $this->fsyncDirectory($directory);
    }

    /** @param array<string,mixed> $payload */
    private function assertPreUpgradePackageIdentity(string $package, array $payload): void
    {
        $deployment = $payload['deployment_manifest'] ?? null;
        if (!is_string($deployment) || !$this->isSha256($deployment)) {
            throw new ApiException('升级前插件恢复摘要不完整，需要人工处理');
        }
        $this->assertSafePackageDirectory($package);
        $preparedManifest = $payload['prepared_package_manifest'] ?? null;
        $preparedDigest = $payload['prepared_package_manifest_digest'] ?? null;
        if (!is_array($preparedManifest) || !is_string($preparedDigest) || !$this->isSha256($preparedDigest)
            || !hash_equals($preparedDigest, $this->preparedPackageManifestDigest($preparedManifest))
            || $this->preparedPackageManifest($package, true) !== $preparedManifest) {
            throw new ApiException('升级前插件包逐文件清单不匹配，需要人工处理');
        }
        $info = self::readPackageInfo($package);
        if (($info['app'] ?? null) !== $this->appName || !is_string($info['version'] ?? null)
            || !$this->isStrictSemver($info['version']) || (int) ($info['state'] ?? -1) !== self::INSTALLED
            || !is_string($info['registration_manifest'] ?? null) || !$this->isSha256($info['registration_manifest'])
            || !hash_equals($deployment, $info['registration_manifest'])
            || !is_string($payload['from_version'] ?? null) || !hash_equals($payload['from_version'], $info['version'])
            || !is_string($payload['previous_registration_manifest'] ?? null) || !hash_equals($payload['previous_registration_manifest'], $info['registration_manifest'])) {
            throw new ApiException('升级前插件身份或登记摘要不匹配，需要人工处理');
        }
        $runtime = $this->runtimeManifest();
        $runtimeByPath = [];
        foreach ($runtime as $entry) {
            $runtimeByPath[$entry['path']] = $entry['manifest'];
        }
        $entries = [];
        foreach ($this->getAllowedPath() as $source => $target) {
            $relative = ltrim(substr($source, strlen(rtrim($this->appDir, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
            $packagePath = rtrim($package, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            $canonicalTarget = $this->canonicalDirectory($target);
            $packageFiles = $this->directoryManifest($packagePath);
            if (!isset($runtimeByPath[$canonicalTarget]) || $packageFiles !== $runtimeByPath[$canonicalTarget]) {
                throw new ApiException('升级前插件包与已部署文件不一致，需要人工处理');
            }
            $entries[] = $packageFiles;
        }
        if (!hash_equals($deployment, hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)))) {
            throw new ApiException('升级前插件部署摘要不匹配，需要人工处理');
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertLegacyPreUpgradePackageIdentity(string $package, array $payload): void
    {
        $deployment = $payload['deployment_manifest'] ?? null;
        $previousRegistration = $payload['previous_registration_manifest'] ?? null;
        if (!is_string($deployment) || !$this->isSha256($deployment)
            || !is_string($previousRegistration) || !$this->isSha256($previousRegistration)) {
            throw new ApiException('升级前历史恢复摘要不完整，需要人工处理');
        }
        $this->assertSafePackageDirectory($package);
        $preparedManifest = $payload['prepared_package_manifest'] ?? null;
        $preparedDigest = $payload['prepared_package_manifest_digest'] ?? null;
        if (!is_array($preparedManifest) || !is_string($preparedDigest) || !$this->isSha256($preparedDigest)
            || !hash_equals($preparedDigest, $this->preparedPackageManifestDigest($preparedManifest))
            || $this->preparedPackageManifest($package, true) !== $preparedManifest) {
            throw new ApiException('升级前历史插件包逐文件清单不匹配，需要人工处理');
        }
        $info = self::readPackageInfo($package);
        if (($info['app'] ?? null) !== $this->appName || !is_string($info['version'] ?? null)
            || !$this->isStrictSemver($info['version']) || (int) ($info['state'] ?? -1) !== self::INSTALLED
            || !is_string($info['registration_manifest'] ?? null) || !$this->isSha256($info['registration_manifest'])
            || !hash_equals($previousRegistration, $info['registration_manifest'])
            || !is_string($payload['from_version'] ?? null) || !hash_equals($payload['from_version'], $info['version'])) {
            throw new ApiException('升级前历史插件身份或登记摘要不匹配，需要人工处理');
        }
        $runtimeByPath = [];
        foreach ($this->runtimeManifest() as $entry) {
            $runtimeByPath[$entry['path']] = $entry['manifest'];
        }
        $entries = [];
        foreach ($this->getAllowedPath() as $source => $target) {
            $relative = ltrim(substr($source, strlen(rtrim($this->appDir, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
            $packageFiles = $this->directoryManifest(rtrim($package, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative);
            $canonicalTarget = $this->canonicalDirectory($target);
            if (!isset($runtimeByPath[$canonicalTarget]) || $packageFiles !== $runtimeByPath[$canonicalTarget]) {
                throw new ApiException('升级前历史插件包与已部署文件不一致，需要人工处理');
            }
            $entries[] = $packageFiles;
        }
        if (!hash_equals($deployment, hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)))) {
            throw new ApiException('升级前历史插件部署摘要不匹配，需要人工处理');
        }
    }

    /**
     * A narrow test seam for simulated I/O interruption. Production keeps it
     * as a no-op; contracts subclass the real logic rather than duplicating it.
     */
    protected function candidateFault(string $point): void
    {
    }

    /** @param array<string,mixed> $payload */
    private function writeCandidateJournal(string $file, array $payload, string $point): void
    {
        $this->assertManagedDirectory(dirname($file));
        $this->candidateFault($point);
        $this->writeJsonAtomically($file, $payload, $point);
        $this->fsyncDirectory(dirname($file));
        $this->candidateFault($point . '.committed');
    }

    private function renameCandidatePath(string $source, string $target, string $point): bool
    {
        $this->candidateFault($point);
        if (!rename($source, $target)) {
            return false;
        }
        $this->fsyncDirectory(dirname($source));
        if (dirname($source) !== dirname($target)) {
            $this->fsyncDirectory(dirname($target));
        }
        $this->candidateFault($point . '.committed');
        return true;
    }

    private function fsyncDirectory(string $directory): void
    {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) {
            throw new ApiException('无法持久化插件目录变更');
        }
        try {
            if (!fsync($handle)) {
                throw new ApiException('无法持久化插件目录变更');
            }
        } finally {
            fclose($handle);
        }
    }

    private function fsyncTree(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new ApiException('恢复目录包含不安全文件');
            }
            if ($entry->isDir()) {
                $this->fsyncDirectory($entry->getPathname());
                continue;
            }
            if (!function_exists('fsync')) {
                continue;
            }
            $handle = @fopen($entry->getPathname(), 'rb');
            if (!is_resource($handle)) {
                throw new ApiException('无法持久化恢复文件');
            }
            try {
                if (!fsync($handle)) {
                    throw new ApiException('无法持久化恢复文件');
                }
            } finally {
                fclose($handle);
            }
        }
        $this->fsyncDirectory($directory);
    }

    private function chmodRestoreTree(string $directory): void
    {
        if (!@chmod($directory, 0700)) {
            throw new ApiException('无法设置恢复目录权限');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $mode = $entry->isDir() ? 0700 : 0600;
            if (!$entry->isFile() && !$entry->isDir() || !@chmod($entry->getPathname(), $mode)) {
                throw new ApiException('无法设置恢复文件权限');
            }
        }
        $this->fsyncTree($directory);
    }

    public function recoverPendingCandidates(): void
    {
        $this->acquireOperationLock();
        try {
            $this->recoverCandidateTransactions();
            // A runtime-restore journal can exist only after the explicit
            // confirmed action wrote its first durable record. Resume it under
            // the same exclusive lock; malformed or ambiguous evidence throws
            // and remains visible for manual handling.
            if (is_file($this->runtimeRestoreTransactionPath())) {
                $info = $this->requireExactFailedUpgradeRecoveryInfo();
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：v2 不会自动恢复历史运行文件 journal', 400);
            }
        } finally {
            $this->releaseOperationLock();
        }
    }

    /** @return array<string,mixed> */
    public function inspectInterruptedPreUpgradeBackup(): array
    {
        $this->acquireReadOnlyVerificationLock();
        try {
            $payload = $this->readInterruptedPreUpgradeJournal();
            $backup = $this->backupsDir . $payload['backup_id'];
            if (is_dir($this->appDir) || !is_dir($backup)) {
                throw new ApiException('升级前备份恢复现场不完整，需要人工处理', 400);
            }
            $this->assertLegacyPreUpgradePackageIdentity($backup, $payload);
            return [
                'app' => $this->appName,
                'from_version' => $payload['from_version'],
                'backup_id' => $payload['backup_id'],
                'previous_registration_manifest' => $payload['previous_registration_manifest'],
                'deployment_manifest' => $payload['deployment_manifest'],
                'recovery_mode' => 'pre_upgrade_backup_restore_required',
                'allowed_actions' => ['restore_interrupted_pre_upgrade_backup'],
                'confirmation' => $this->interruptedPreUpgradeConfirmation($payload),
            ];
        } finally {
            $this->releaseReadOnlyVerificationLock();
        }
    }

    /** @return array<string,mixed> */
    public function restoreInterruptedPreUpgradeBackup(string $confirmation): array
    {
        $this->acquireOperationLock(false);
        try {
            $payload = $this->readInterruptedPreUpgradeJournal();
            if (!hash_equals($this->interruptedPreUpgradeConfirmation($payload), $confirmation)) {
                throw new ApiException('升级前备份恢复确认内容不匹配，未执行任何变更', 400);
            }
            $backup = $this->backupsDir . $payload['backup_id'];
            if ($payload['phase'] === 'backed_up') {
                if (is_dir($this->appDir) || !is_dir($backup)) {
                    throw new ApiException('升级前备份恢复现场不完整，需要人工处理', 400);
                }
                $this->assertLegacyPreUpgradePackageIdentity($backup, $payload);
                $payload['legacy_restore_original_info'] = self::readPackageInfo($backup);
                $payload['phase'] = 'legacy_restore_rename_pending';
                $payload['updated_at'] = time();
                $this->writeCandidateJournal($this->candidateTransactionPath(), $payload, 'candidate.legacy_restore_rename_pending');
            }
            if ($payload['phase'] === 'legacy_restore_rename_pending') {
                if (!is_dir($this->appDir) && is_dir($backup)) {
                    $this->assertLegacyPreUpgradePackageIdentity($backup, $payload);
                    if (!$this->renameCandidatePath($backup, $this->appDir, 'legacy.restore.rename')) {
                        throw new ApiException('升级前备份目录恢复未完成，保留记录等待重试', 400);
                    }
                } elseif (!is_dir($this->appDir) || is_dir($backup)) {
                    throw new ApiException('升级前备份恢复路径冲突，需要人工处理', 400);
                }
                $this->assertLegacyPreUpgradePackageIdentity($this->appDir, $payload);
                $this->updateCandidateTransaction($payload['backup_id'], 'legacy_restore_registration_pending');
                $payload['phase'] = 'legacy_restore_registration_pending';
            }
            if ($payload['phase'] === 'legacy_restore_registration_pending') {
                if (!is_dir($this->appDir) || is_dir($backup)) {
                    throw new ApiException('升级前登记摘要恢复现场不完整，需要人工处理', 400);
                }
                $info = $this->getInfo();
                if (($info['registration_manifest'] ?? null) === $payload['previous_registration_manifest']) {
                    $this->assertLegacyPreUpgradePackageIdentity($this->appDir, $payload);
                    $info['registration_manifest'] = $payload['deployment_manifest'];
                    $info['stage'] = 'completed';
                    $info['stage_label'] = '插件安装完成；升级前登记摘要已恢复';
                    $this->writeLegacyRestoreInfoAtomically($info);
                    $this->candidateFault('legacy.restore.registration.committed');
                }
                $this->assertNormalizedLegacyPreUpgradePackageIdentity($this->appDir, $payload);
                $this->fsyncTree($this->appDir);
                $payload = $this->readCandidateTransaction($payload['backup_id']);
                $payload['phase'] = 'legacy_restore_completed';
                $payload['normalized_package_manifest'] = $this->preparedPackageManifest($this->appDir, true);
                $payload['normalized_package_manifest_digest'] = $this->preparedPackageManifestDigest($payload['normalized_package_manifest']);
                $this->writeCandidateJournal($this->candidateTransactionPath(), $payload, 'candidate.legacy_restore_completed');
                $this->candidateFault('legacy.restore.completed.committed');
            }
            $payload = $this->readCandidateTransaction($payload['backup_id']);
            if (!is_dir($this->appDir) || is_dir($backup)) {
                throw new ApiException('升级前备份恢复完成路径冲突，需要人工处理', 400);
            }
            $this->assertNormalizedLegacyPreUpgradePackageIdentity($this->appDir, $payload);
            if (($payload['phase'] ?? null) !== 'legacy_restore_completed'
                || !is_array($payload['normalized_package_manifest'] ?? null)
                || !is_string($payload['normalized_package_manifest_digest'] ?? null)
                || !hash_equals($payload['normalized_package_manifest_digest'], $this->preparedPackageManifestDigest($payload['normalized_package_manifest']))
                || $this->preparedPackageManifest($this->appDir, true) !== $payload['normalized_package_manifest']) {
                throw new ApiException('升级前备份恢复完成证据不匹配，需要人工处理', 400);
            }
            $this->removeTransactionJournal($this->candidateTransactionPath(), '升级前备份恢复记录无法完成');
            return ['app' => $this->appName, 'version' => $payload['from_version'], 'state' => 'restored', 'sql_executed' => false];
        } finally {
            $this->releaseOperationLock();
        }
    }

    /** @return array<string,mixed> */
    private function readInterruptedPreUpgradeJournal(): array
    {
        $file = $this->candidateTransactionPath();
        if (!is_file($file) || is_link($file) || !$this->isSafeRegularFile($file, 0600)) {
            throw new ApiException('未找到可恢复的升级前备份记录', 400);
        }
        $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['app'] ?? null) !== $this->appName
            || !is_string($payload['backup_id'] ?? null) || !$this->isBackupId($payload['backup_id'])
            || !in_array($payload['phase'] ?? null, ['backed_up', 'legacy_restore_rename_pending', 'legacy_restore_registration_pending', 'legacy_restore_completed'], true)
            || !is_string($payload['from_version'] ?? null) || !is_string($payload['previous_registration_manifest'] ?? null)
            || !is_string($payload['deployment_manifest'] ?? null)) {
            throw new ApiException('升级前备份恢复记录不完整，需要人工处理', 400);
        }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function interruptedPreUpgradeConfirmation(array $payload): string
    {
        return 'RESTORE PRE-UPGRADE ' . $this->appName . '@' . $payload['from_version'] . ' ' . $payload['backup_id']
            . ' ' . $payload['previous_registration_manifest'] . ':' . $payload['deployment_manifest'];
    }

    /** @param array<string,mixed> $info */
    private function writeLegacyRestoreInfoAtomically(array $info): void
    {
        $target = $this->appDir . 'info.ini';
        $original = @file_get_contents($target);
        if (!is_string($original)) {
            throw new ApiException('升级前登记摘要原文件无法读取，保留记录等待重试', 400);
        }
        $targetMode = @fileperms($target);
        if (!is_int($targetMode) || (($targetMode & 0002) !== 0)) {
            throw new ApiException('升级前登记摘要原文件权限不安全，需要人工处理', 400);
        }
        $targetMode &= 0777;
        $lineEnding = str_contains($original, "\r\n") ? "\r\n" : (str_contains($original, "\r") ? "\r" : "\n");
        $sectionOffset = strlen($original);
        if (preg_match('/^[ \t]*\\[[^\\r\\n]+\\]/m', $original, $sectionMatch, PREG_OFFSET_CAPTURE) === 1) {
            $sectionOffset = (int) $sectionMatch[0][1];
        }
        $head = substr($original, 0, $sectionOffset);
        $tail = substr($original, $sectionOffset);
        foreach ([
            'registration_manifest' => (string) $info['registration_manifest'],
            'stage' => (string) $info['stage'],
            'stage_label' => (string) $info['stage_label'],
        ] as $key => $value) {
            $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[^\\r\\n]*(?:\\r\\n|\\r|\\n|$)/m';
            preg_match_all($pattern, $head, $matches);
            $count = count($matches[0] ?? []);
            if ($count > 1 || ($key === 'registration_manifest' && $count !== 1)) {
                throw new ApiException('升级前登记摘要原文件键不唯一，需要人工处理', 400);
            }
            $replacement = $key . ' = ' . $value . $lineEnding;
            if ($count === 1) {
                $head = preg_replace_callback($pattern, static fn (): string => $replacement, $head, 1) ?? '';
                continue;
            }
            if ($head !== '' && !str_ends_with($head, "\n") && !str_ends_with($head, "\r")) {
                $head .= $lineEnding;
            }
            $head .= $replacement;
        }
        $content = $head . $tail;
        $temp = $this->installDir . 'locks' . DIRECTORY_SEPARATOR . $this->appName . '-legacy-info.ini.tmp';
        if (file_exists($temp) || is_link($temp)) {
            if (is_link($temp) || !$this->isSafeRegularFile($temp) || !@unlink($temp)) {
                throw new ApiException('升级前登记摘要临时文件冲突，需要人工处理', 400);
            }
            $this->fsyncDirectory(dirname($temp));
        }
        $handle = @fopen($temp, 'x+b');
        if (!is_resource($handle)) {
            throw new ApiException('升级前登记摘要临时文件无法创建，保留记录等待重试', 400);
        }
        try {
            if (!@chmod($temp, $targetMode)) {
                throw new ApiException('升级前登记摘要临时文件权限无法设置，保留记录等待重试', 400);
            }
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new ApiException('升级前登记摘要临时文件无法完整写入，保留记录等待重试', 400);
                }
                $offset += $written;
            }
            $this->candidateFault('legacy.restore.registration.temp_written');
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ApiException('升级前登记摘要临时文件无法持久化，保留记录等待重试', 400);
            }
            $this->candidateFault('legacy.restore.registration.temp_fsynced');
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($temp);
            throw $error;
        }
        fclose($handle);
        $parsed = @parse_ini_file($temp, true, INI_SCANNER_TYPED);
        if (!is_array($parsed) || self::normalizedIniArray($parsed) !== self::normalizedIniArray($info)) {
            @unlink($temp);
            $this->fsyncDirectory(dirname($temp));
            throw new ApiException('升级前登记摘要临时文件无法保持原配置，需要人工处理', 400);
        }
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new ApiException('升级前登记摘要无法原子提交，保留记录等待重试', 400);
        }
        $this->candidateFault('legacy.restore.registration.renamed');
        $this->fsyncDirectory(dirname($target));
        $this->candidateFault('legacy.restore.registration.parent_synced');
    }

    /** @param array<string,mixed> $payload */
    private function assertNormalizedLegacyPreUpgradePackageIdentity(string $package, array $payload): void
    {
        $originalInfo = $payload['legacy_restore_original_info'] ?? null;
        $deployment = $payload['deployment_manifest'] ?? null;
        $originalManifest = $payload['prepared_package_manifest'] ?? null;
        if (!is_array($originalInfo) || !is_array($originalManifest)
            || !is_string($deployment) || !$this->isSha256($deployment)) {
            throw new ApiException('升级前登记摘要恢复证据不完整，需要人工处理', 400);
        }
        $expectedInfo = $originalInfo;
        $expectedInfo['registration_manifest'] = $deployment;
        $expectedInfo['stage'] = 'completed';
        $expectedInfo['stage_label'] = '插件安装完成；升级前登记摘要已恢复';
        if (self::normalizedIniArray(self::readPackageInfo($package)) !== self::normalizedIniArray($expectedInfo)) {
            throw new ApiException('升级前登记摘要恢复身份不匹配，需要人工处理', 400);
        }
        $currentManifest = $this->preparedPackageManifest($package, true);
        unset($originalManifest['info.ini'], $currentManifest['info.ini']);
        if ($currentManifest !== $originalManifest
            || !hash_equals($deployment, $this->verifyDeploymentMatchesPackage())) {
            throw new ApiException('升级前登记摘要恢复文件不匹配，需要人工处理', 400);
        }
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function normalizedIniArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalizedIniArray($item);
            }
        }
        ksort($value, SORT_STRING);
        return $value;
    }

    private function candidateTransactionPath(): string
    {
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . $this->appName . '-candidate.transaction.json';
    }

    private function discardTransactionPath(): string
    {
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . $this->appName . '-discard.transaction.json';
    }

    private function completeCandidateTransaction(string $backupId): void
    {
        $file = $this->candidateTransactionPath();
        $payload = $this->readCandidateTransaction($backupId);
        if (($payload['app'] ?? null) !== $this->appName || ($payload['backup_id'] ?? null) !== $backupId
            || ($payload['phase'] ?? null) !== 'candidate_checked') {
            throw new ApiException('升级候选事务记录不完整');
        }
        $this->candidateFault('candidate.complete.unlink');
        $this->unlinkTransactionJournal($file, '无法完成升级候选事务');
        $this->pendingCandidateRegistrationManifest = null;
    }

    private function removeTransactionJournal(string $file, string $message): void
    {
        $this->unlinkTransactionJournal($file, $message);
        $this->fsyncDirectory(dirname($file));
    }

    private function unlinkTransactionJournal(string $file, string $message): void
    {
        if (!is_file($file) || is_link($file) || !$this->isSafeRegularFile($file, 0600)) {
            throw new ApiException($message);
        }
        if (!@unlink($file)) {
            throw new ApiException($message);
        }
    }

    /** @param array<string,mixed> $candidate */
    private function bindCandidateTransaction(string $backupId, array $candidate, string $candidateDirectory): void
    {
        $file = $this->candidateTransactionPath();
        $payload = $this->readCandidateTransaction($backupId);
        $toVersion = $candidate['version'] ?? null;
        if (!is_string($toVersion) || !$this->isStrictSemver($toVersion)
            || !is_string($candidate['upgrade_from_version'] ?? null)
            || !is_string($candidate['registration_manifest'] ?? null)
            || !is_string($candidate['runtime_manifest'] ?? null)) {
            throw new ApiException('升级候选事务绑定不完整');
        }
        $payload['to_version'] = $toVersion;
        $payload['upgrade_from_version'] = $candidate['upgrade_from_version'];
        $payload['candidate_registration_manifest'] = $candidate['registration_manifest'];
        $payload['candidate_runtime_manifest'] = $candidate['runtime_manifest'];
        $candidateManifest = $this->preparedPackageManifest($candidateDirectory);
        $payload['candidate_package_manifest'] = $candidateManifest;
        $payload['candidate_package_manifest_digest'] = $this->preparedPackageManifestDigest($candidateManifest);
        $this->writeCandidateJournal($file, $payload, 'candidate.binding.write');
    }

    /** @return array<string,mixed> */
    private function readCandidateTransaction(string $backupId): array
    {
        $file = $this->candidateTransactionPath();
        if (!is_file($file) || is_link($file) || !$this->isSafeRegularFile($file, 0600)) {
            throw new ApiException('升级候选事务记录不完整');
        }
        $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['app'] ?? null) !== $this->appName || ($payload['backup_id'] ?? null) !== $backupId) {
            throw new ApiException('升级候选事务记录不完整');
        }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function assertRestoredBackupFromTransaction(array $payload): void
    {
        $from = $payload['from_version'] ?? null;
        $registration = $payload['previous_registration_manifest'] ?? null;
        $deployment = $payload['deployment_manifest'] ?? null;
        $packageManifest = $payload['package_manifest'] ?? null;
        $runtimeManifest = $payload['runtime_manifest'] ?? null;
        $runtimeHash = $payload['runtime_manifest_hash'] ?? null;
        if (!is_string($from) || !$this->isStrictSemver($from) || !is_string($registration) || !$this->isSha256($registration)
            || !is_string($deployment) || !$this->isSha256($deployment) || !is_array($packageManifest)
            || !is_array($runtimeManifest) || !is_string($runtimeHash) || !$this->isSha256($runtimeHash)
            || !hash_equals($registration, $deployment) || !hash_equals($runtimeHash, $this->runtimeManifestHash($runtimeManifest))) {
            throw new ApiException('安装包恢复事务证据不完整，保留恢复记录等待处理');
        }
        $this->assertSafePackageDirectory($this->appDir);
        $restored = $this->getInfo();
        if (($restored['app'] ?? null) !== $this->appName || ($restored['version'] ?? null) !== $from
            || (int) ($restored['state'] ?? -1) !== self::INSTALLED
            || !is_string($restored['registration_manifest'] ?? null)
            || !hash_equals($registration, $restored['registration_manifest'])
            || $this->packageManifest($this->appDir) !== $packageManifest
            || !hash_equals($deployment, $this->deploymentManifestForPackage($this->appDir, $runtimeManifest))) {
            throw new ApiException('安装包恢复身份校验失败，保留恢复记录等待处理');
        }
        $this->assertRuntimeManifest($runtimeManifest);
    }

    /** @param array<string,mixed> $info @param array<string,mixed> $payload */
    private function assertReadyCandidateFromTransaction(array $info, array $payload): void
    {
        if (!$this->isReadyUpgradeCandidate($info) || ($info['package_backup_id'] ?? null) !== ($payload['backup_id'] ?? null)
            || ($info['version'] ?? null) !== ($payload['to_version'] ?? null)
            || ($info['upgrade_from_version'] ?? null) !== ($payload['upgrade_from_version'] ?? null)
            || ($info['registration_manifest'] ?? null) !== ($payload['candidate_registration_manifest'] ?? null)
            || ($info['runtime_manifest'] ?? null) !== ($payload['candidate_runtime_manifest'] ?? null)
            || !is_array($payload['candidate_package_manifest'] ?? null)
            || !is_string($payload['candidate_package_manifest_digest'] ?? null)
            || !$this->isSha256($payload['candidate_package_manifest_digest'])
            || !hash_equals($payload['candidate_package_manifest_digest'], $this->preparedPackageManifestDigest($payload['candidate_package_manifest']) )
            || $this->preparedPackageManifest($this->appDir, true) !== $payload['candidate_package_manifest']) {
            throw new ApiException('升级候选恢复身份不完整');
        }
        $backup = $this->readVerifiedCandidateBackup((string) $payload['backup_id'], (string) $info['registration_manifest']);
        $this->assertUpgradeLineage($info, $backup['version']);
        $this->assertRuntimeManifest($backup['runtime_manifest']);
        $this->assertSafePackageDirectory($this->appDir);
        $this->checkPackage();
    }

    /** @param array<string,mixed> $payload */
    private function assertCandidatePackageFromTransaction(string $package, array $payload): void
    {
        $manifest = $payload['candidate_package_manifest'] ?? null;
        $digest = $payload['candidate_package_manifest_digest'] ?? null;
        if (!is_array($manifest) || !is_string($digest) || !$this->isSha256($digest)
            || !hash_equals($digest, $this->preparedPackageManifestDigest($manifest))) {
            throw new ApiException('升级候选逐文件清单不完整，保留恢复记录等待处理');
        }
        $this->assertSafePackageDirectory($package);
        if ($this->preparedPackageManifest($package, true) !== $manifest) {
            throw new ApiException('升级候选逐文件清单不匹配，保留恢复记录等待处理');
        }
    }

    /** @param array<string,mixed> $payload */
    private function restoreVerifiedCandidateBackup(string $backup, array $payload): void
    {
        $this->assertPreUpgradePackageIdentity($backup, $payload);
        $this->removeGeneratedRegistrationTemps($backup);
        $this->assertPreUpgradePackageIdentity($backup, $payload);
        if (!$this->renameCandidatePath($backup, $this->appDir, 'recover.candidate.restore')) {
            throw new ApiException('升级候选恢复失败，保留恢复记录等待人工处理');
        }
        $this->assertPreUpgradePackageIdentity($this->appDir, $payload);
    }

    /** @param array<string,mixed> $payload */
    private function assertDiscardJournalApp(array $payload): void
    {
        $backupId = $payload['backup_id'] ?? null;
        if (!is_string($backupId) || !$this->isBackupId($backupId)) {
            throw new ApiException('撤回恢复记录不完整，需要人工处理');
        }
        if (($payload['legacy_history'] ?? false) === true) {
            foreach (['derived_from_version', 'derived_registration_digest', 'derived_backup_package_digest', 'derived_runtime_digest', 'derived_candidate_package_digest'] as $field) {
                if (!is_string($payload[$field] ?? null)) {
                    throw new ApiException('较早版本候选撤回恢复记录不完整，需要人工处理');
                }
            }
            $quarantinedCandidate = $this->assertQuarantineJournalPath((string) ($payload['candidate'] ?? ''));
            $candidateDigest = (string) $payload['derived_candidate_package_digest'];
            if (is_dir($quarantinedCandidate)) {
                $this->assertSafePackageDirectory($quarantinedCandidate);
                if (!hash_equals($candidateDigest, self::legacyManifestDigest(self::legacyDirectoryManifest($quarantinedCandidate)))) {
                    throw new ApiException('较早版本候选隔离包摘要不匹配，需要人工处理');
                }
            }
            $info = $this->getInfo();
            if ($this->isLegacyHistoryCandidateShape($info)) {
                $evidence = $this->assertLegacyHistoryCandidate($info);
                foreach (['from_version' => 'derived_from_version', 'registration_digest' => 'derived_registration_digest', 'backup_package_digest' => 'derived_backup_package_digest', 'runtime_digest' => 'derived_runtime_digest', 'candidate_package_digest' => 'derived_candidate_package_digest'] as $key => $field) {
                    if (!hash_equals((string) $payload[$field], $evidence[$key])) {
                        throw new ApiException('较早版本候选撤回身份不匹配，需要人工处理');
                    }
                }
                return;
            }
            if (!is_dir($quarantinedCandidate)) {
                throw new ApiException('较早版本候选隔离包缺失，保留恢复记录等待人工处理');
            }
            self::assertLegacyHistoryRestored($this->appName, $backupId, $info, $payload);
            return;
        }
        $metadataFile = $this->backupsDir . $backupId . DIRECTORY_SEPARATOR . 'registration_manifest.json';
        $metadata = is_file($metadataFile) ? json_decode((string) file_get_contents($metadataFile), true) : null;
        $candidatePath = $this->assertQuarantineJournalPath((string) ($payload['candidate'] ?? ''));
        $modern = $this->assertDiscardJournalMode($payload, $candidatePath, $metadata);
        if (!$modern) {
            // Journals written before the complete-tree binding was added can
            // only use the previous verified metadata contract. New journals
            // always take the stricter branch below.
            if (!is_array($metadata) || !is_string($metadata['registration_manifest'] ?? null)) {
                throw new ApiException('撤回恢复备份证据不完整，需要人工处理');
            }
            $info = $this->getInfo();
            if ($this->isReadyUpgradeCandidate($info) && ($info['package_backup_id'] ?? null) === $backupId) {
                $verified = $this->readVerifiedCandidateBackup($backupId, (string) $info['registration_manifest']);
                if (!hash_equals($verified['runtime_manifest_hash'], (string) $info['runtime_manifest'])) {
                    throw new ApiException('撤回候选运行时清单绑定不匹配，需要人工处理');
                }
                $this->assertUpgradeLineage($info, $verified['version']);
                $this->assertRuntimeManifest($verified['runtime_manifest']);
                $this->checkPackage();
                return;
            }
            $verified = $this->readVerifiedCandidateBackup($backupId, $metadata['registration_manifest']);
            if (($info['app'] ?? null) !== $this->appName || ($info['version'] ?? null) !== $verified['version']
                || (int) ($info['state'] ?? -1) !== self::INSTALLED
                || !is_string($info['registration_manifest'] ?? null)
                || !is_string($metadata['previous_registration_manifest'] ?? null)
                || !hash_equals($metadata['previous_registration_manifest'], $info['registration_manifest'])
                || !is_array($metadata['package_manifest'] ?? null)
                || $this->packageManifest($this->appDir) !== $metadata['package_manifest']
                || !is_string($metadata['deployment_manifest'] ?? null)
                || !hash_equals($metadata['deployment_manifest'], $this->deploymentManifestForPackage($this->appDir, $verified['runtime_manifest']))) {
                throw new ApiException('撤回恢复目录身份不匹配，需要人工处理');
            }
            $this->assertRuntimeManifest($verified['runtime_manifest']);
            return;
        }
        $candidate = $this->assertQuarantineJournalPath((string) ($payload['candidate'] ?? ''));
        if (!is_array($metadata) || !is_string($metadata['registration_manifest'] ?? null)
            || !is_array($payload['candidate_package_manifest'] ?? null) || !is_array($payload['backup_package_manifest'] ?? null)) {
            throw new ApiException('撤回恢复备份证据不完整，需要人工处理');
        }
        $info = $this->getInfo();
        if ($this->isReadyUpgradeCandidate($info) && ($info['package_backup_id'] ?? null) === $backupId) {
            if (is_dir($candidate)) {
                throw new ApiException('撤回候选逐文件清单不匹配，需要人工处理');
            }
            if (($info['discard_journal_schema'] ?? null) !== 'sandpackage-discard-v2'
                || ($info['discard_journal_epoch'] ?? null) !== 2
                || !is_string($info['discard_journal_nonce'] ?? null) || !hash_equals($payload['nonce'], $info['discard_journal_nonce'])) {
                throw new ApiException('撤回事务候选证据不完整，需要人工处理');
            }
            $this->assertDiscardCandidatePackage($this->appDir, $payload);
            $candidateRegistration = $info['registration_manifest'] ?? null;
            $candidateRuntime = $info['runtime_manifest'] ?? null;
            if (!is_string($candidateRegistration) || !is_string($candidateRuntime)) {
                throw new ApiException('撤回候选身份不完整，需要人工处理');
            }
            $verified = $this->readVerifiedCandidateBackup($backupId, $candidateRegistration);
            if (!hash_equals($verified['runtime_manifest_hash'], $candidateRuntime)) {
                throw new ApiException('撤回候选运行时清单绑定不匹配，需要人工处理');
            }
            $this->assertUpgradeLineage($info, $verified['version']);
            $this->assertRuntimeManifest($verified['runtime_manifest']);
            $this->checkPackage();
            return;
        }
        if (!is_dir($candidate)) {
            throw new ApiException('撤回候选隔离包缺失，保留恢复记录等待处理');
        }
        $this->assertDiscardCandidatePackage($candidate, $payload);
        $this->assertDiscardBackupPackage($this->appDir, $payload);
        $verified = $this->readVerifiedCandidateBackup($backupId, $metadata['registration_manifest']);
        if (($info['app'] ?? null) !== $this->appName || ($info['version'] ?? null) !== $verified['version']
            || (int) ($info['state'] ?? -1) !== self::INSTALLED
            || !is_string($info['registration_manifest'] ?? null)
            || !is_string($metadata['previous_registration_manifest'] ?? null)
            || !hash_equals($metadata['previous_registration_manifest'], $info['registration_manifest'])
            || !is_array($metadata['package_manifest'] ?? null)
            || $this->packageManifest($this->appDir) !== $metadata['package_manifest']
            || !is_string($metadata['deployment_manifest'] ?? null)
            || !hash_equals($metadata['deployment_manifest'], $this->deploymentManifestForPackage($this->appDir, $verified['runtime_manifest']))) {
            throw new ApiException('撤回恢复目录身份不匹配，需要人工处理');
        }
        $this->assertRuntimeManifest($verified['runtime_manifest']);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $backupEvidence
     */
    private function assertDiscardJournalMode(array $payload, string $candidate, ?array $backupEvidence): bool
    {
        $candidateInfo = is_dir($candidate)
            ? self::readPackageInfo($candidate)
            : (is_dir($this->appDir) ? $this->getInfo() : []);
        $journalModern = array_key_exists('schema', $payload) || array_key_exists('epoch', $payload) || array_key_exists('nonce', $payload);
        $candidateModern = is_array($candidateInfo) && (array_key_exists('discard_journal_schema', $candidateInfo)
            || array_key_exists('discard_journal_epoch', $candidateInfo) || array_key_exists('discard_journal_nonce', $candidateInfo));
        $backupModern = is_array($backupEvidence) && (array_key_exists('discard_journal_schema', $backupEvidence)
            || array_key_exists('discard_journal_epoch', $backupEvidence) || array_key_exists('discard_journal_nonce', $backupEvidence));
        if (!$journalModern && !$candidateModern && !$backupModern) {
            return false;
        }
        if (($payload['schema'] ?? null) !== 'sandpackage-discard-v2' || ($payload['epoch'] ?? null) !== 2
            || !is_string($payload['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/', $payload['nonce']) !== 1
            || !is_array($candidateInfo) || ($candidateInfo['discard_journal_schema'] ?? null) !== 'sandpackage-discard-v2'
            || ($candidateInfo['discard_journal_epoch'] ?? null) !== 2
            || !is_string($candidateInfo['discard_journal_nonce'] ?? null) || !hash_equals($payload['nonce'], $candidateInfo['discard_journal_nonce'])
            || !is_array($backupEvidence) || ($backupEvidence['discard_journal_schema'] ?? null) !== 'sandpackage-discard-v2'
            || ($backupEvidence['discard_journal_epoch'] ?? null) !== 2
            || !is_string($backupEvidence['discard_journal_nonce'] ?? null) || !hash_equals($payload['nonce'], $backupEvidence['discard_journal_nonce'])
            || !is_array($payload['candidate_package_manifest'] ?? null) || !is_string($payload['candidate_package_manifest_digest'] ?? null)
            || !is_array($payload['backup_package_manifest'] ?? null) || !is_string($payload['backup_package_manifest_digest'] ?? null)) {
            throw new ApiException('撤回事务记录字段不完整，保留恢复记录等待人工处理');
        }
        return true;
    }

    /** @param array<string,mixed> $payload */
    private function assertDiscardCandidatePackage(string $directory, array $payload): void
    {
        $manifest = $payload['candidate_package_manifest'] ?? null;
        $digest = $payload['candidate_package_manifest_digest'] ?? null;
        if (!is_array($manifest) || !is_string($digest) || !$this->isSha256($digest)
            || !hash_equals($digest, $this->preparedPackageManifestDigest($manifest))) {
            throw new ApiException('撤回候选逐文件清单不完整，需要人工处理');
        }
        $this->assertSafePackageDirectory($directory);
        if ($this->preparedPackageManifest($directory, true) !== $manifest) {
            throw new ApiException('撤回候选逐文件清单不匹配，需要人工处理');
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertDiscardBackupPackage(string $directory, array $payload): void
    {
        $manifest = $payload['backup_package_manifest'] ?? null;
        $digest = $payload['backup_package_manifest_digest'] ?? null;
        if (!is_array($manifest) || !is_string($digest) || !$this->isSha256($digest)
            || !hash_equals($digest, $this->preparedPackageManifestDigest($manifest))) {
            throw new ApiException('撤回恢复包逐文件清单不完整，需要人工处理');
        }
        $this->assertSafePackageDirectory($directory);
        if ($this->preparedPackageManifest($directory, true) !== $manifest) {
            throw new ApiException('撤回恢复包逐文件清单不匹配，需要人工处理');
        }
    }

    private function updateCandidateTransaction(string $backupId, string $phase): void
    {
        $file = $this->candidateTransactionPath();
        $payload = $this->readCandidateTransaction($backupId);
        $payload['phase'] = $phase;
        $payload['updated_at'] = time();
        $this->writeCandidateJournal($file, $payload, 'candidate.' . $phase);
    }

    private function recoverCandidateTransactions(): void
    {
        $this->assertManagedDirectory($this->installDir . 'locks' . DIRECTORY_SEPARATOR);
        $this->assertManagedDirectory($this->backupsDir);
        $this->recoverFailedUpgradeReplacementTransaction();
        foreach ([[$this->candidateTransactionPath(), 'candidate'], [$this->discardTransactionPath(), 'discard']] as [$file, $type]) {
            if (@lstat($file) === false) {
                continue;
            }
            if (is_link($file) || !$this->isSafeRegularFile($file, 0600)) {
                throw new ApiException('插件撤回恢复记录安全属性不完整，需要人工处理');
            }
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || ($payload['app'] ?? null) !== $this->appName || !is_string($payload['backup_id'] ?? null)
                || !$this->isBackupId($payload['backup_id']) || !is_string($payload['phase'] ?? null)) {
                throw new ApiException('插件撤回恢复记录不完整，需要人工处理');
            }
            $backup = $this->backupsDir . $payload['backup_id'];
            $backupExists = is_dir($backup);
            if ($backupExists) {
                $this->assertSafePackageDirectory($backup);
                if (realpath(dirname($backup)) !== realpath($this->backupsDir)) {
                    throw new ApiException('插件撤回恢复备份路径非法，需要人工处理');
                }
            }
            if ($type === 'candidate') {
                $phase = $payload['phase'];
                if (!in_array($phase, ['prepared', 'backed_up', 'manifest_written', 'candidate_ready_to_move', 'candidate_moved', 'candidate_info_written', 'candidate_checked', 'rollback_restore_pending', 'rollback_restored'], true)) {
                    throw new ApiException('升级候选恢复阶段非法，需要人工处理');
                }
                $appExists = is_dir($this->appDir);
                if (in_array($phase, ['prepared', 'backed_up'], true)) {
                    if ($appExists) {
                        if ($backupExists) {
                            throw new ApiException('升级前插件与备份同时存在，保留恢复记录等待人工处理');
                        }
                        $this->assertPreUpgradePackageIdentity($this->appDir, $payload);
                    } elseif ($backupExists) {
                        $this->restoreVerifiedCandidateBackup($backup, $payload);
                    } else {
                        throw new ApiException('升级候选和旧注册包均缺失，保留恢复记录等待人工处理');
                    }
                } elseif (in_array($phase, ['rollback_restore_pending', 'rollback_restored'], true)) {
                    if ($appExists) {
                        if ($backupExists) {
                            throw new ApiException('恢复后的插件与备份同时存在，保留恢复记录等待人工处理');
                        }
                        $this->assertRestoredBackupFromTransaction($payload);
                    } elseif ($backupExists) {
                        $this->restoreVerifiedCandidateBackup($backup, $payload);
                        $this->assertRestoredBackupFromTransaction($payload);
                    } else {
                        throw new ApiException('恢复后的插件与备份均缺失，保留恢复记录等待人工处理');
                    }
                } elseif (in_array($phase, ['manifest_written', 'candidate_ready_to_move'], true)) {
                    if ($appExists) {
                        if (!$backupExists) {
                            throw new ApiException('升级候选已移动但旧注册包缺失，保留恢复记录等待人工处理');
                        }
                        // Do not quarantine a candidate until the only old
                        // registered package has passed its full pre-move
                        // identity check. A damaged backup must remain where
                        // it is with the transaction journal intact.
                        $this->assertPreUpgradePackageIdentity($backup, $payload);
                        $this->assertCandidatePackageFromTransaction($this->appDir, $payload);
                        $quarantine = $this->recoveryQuarantinePath();
                        if (!$this->renameCandidatePath($this->appDir, $quarantine, 'recover.candidate.quarantine')) {
                            throw new ApiException('升级候选隔离失败，保留恢复记录等待人工处理');
                        }
                        $this->assertCandidatePackageFromTransaction($quarantine, $payload);
                        $this->restoreVerifiedCandidateBackup($backup, $payload);
                    } elseif ($backupExists) {
                        $this->restoreVerifiedCandidateBackup($backup, $payload);
                    } else {
                        throw new ApiException('升级候选和旧注册包均缺失，保留恢复记录等待人工处理');
                    }
                } else {
                    // From candidate_moved onward the only accepted recovery
                    // shape is a complete ready candidate plus its intact old
                    // backup. Missing or conflicting evidence remains in place.
                    if (!$appExists || !$backupExists) {
                        throw new ApiException('升级候选恢复证据不完整，保留恢复记录等待人工处理');
                    }
                    $this->assertPreUpgradePackageIdentity($backup, $payload);
                    $this->assertCandidatePackageFromTransaction($this->appDir, $payload);
                    $this->assertReadyCandidateFromTransaction($this->getInfo(), $payload);
                }
            } else {
                if ($payload['phase'] !== 'prepared' || !is_string($payload['candidate'] ?? null)) {
                    throw new ApiException('撤回候选恢复阶段非法，需要人工处理');
                }
                $candidate = $this->assertQuarantineCandidatePath($payload['candidate']);
                $discardMetadataFile = $this->backupsDir . $payload['backup_id'] . DIRECTORY_SEPARATOR . 'registration_manifest.json';
                $discardMetadata = is_file($discardMetadataFile)
                    ? json_decode((string) file_get_contents($discardMetadataFile), true, 512, JSON_THROW_ON_ERROR)
                    : null;
                $modernDiscard = $this->assertDiscardJournalMode($payload, $candidate, is_array($discardMetadata) ? $discardMetadata : null);
                if (!is_dir($this->appDir) && is_dir($candidate)) {
                    $this->assertSafePackageDirectory($candidate);
                    if ($modernDiscard) {
                        $this->assertDiscardBackupPackage($backup, $payload);
                        $this->assertDiscardCandidatePackage($candidate, $payload);
                    }
                    if (!$this->renameCandidatePath($candidate, $this->appDir, 'recover.discard.restore')) {
                        throw new ApiException('撤回中断后无法恢复候选包');
                    }
                    if ($modernDiscard) {
                        $this->assertDiscardCandidatePackage($this->appDir, $payload);
                    }
                    $this->assertDiscardJournalApp($payload);
                } elseif (!is_dir($this->appDir) && !is_dir($candidate)) {
                    throw new ApiException('撤回候选和注册包均缺失，保留恢复记录等待人工处理');
                } elseif (is_dir($this->appDir)) {
                    $this->assertSafePackageDirectory($this->appDir);
                    $this->assertDiscardJournalApp($payload);
                }
            }
            $this->removeTransactionJournal($file, '无法完成插件撤回恢复');
        }
    }

    /** Complete only an already-evidenced replacement move; never invent a candidate. */
    private function recoverFailedUpgradeReplacementTransaction(): void
    {
        $journalFile = $this->replacementTransactionPath();
        if (@lstat($journalFile) === false) return;
        if (!$this->isSafeRegularFile($journalFile, 0600)) throw new ApiException('替换候选恢复记录安全属性不完整，需要人工处理');
        try { $journal = json_decode((string) file_get_contents($journalFile), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new ApiException('替换候选恢复记录不可读，需要人工处理'); }
        if (!is_array($journal) || ($journal['schema'] ?? null) !== 'sandpackage-failed-upgrade-replacement-transaction/v1'
            || ($journal['app'] ?? null) !== $this->appName || !is_string($journal['replacement_id'] ?? null)
            || !in_array($journal['phase'] ?? null, ['prepared','old_quarantined','replacement_ready'], true)
            || !is_array($journal['old_manifest'] ?? null) || !is_string($journal['old_manifest_sha256'] ?? null)
            || !hash_equals($journal['old_manifest_sha256'], $this->preparedPackageManifestDigest($journal['old_manifest']))
            || !is_string($journal['quarantine'] ?? null)) throw new ApiException('替换候选恢复记录不完整，需要人工处理');
        $record = $this->readReplacementRecord($journal['replacement_id']);
        $quarantine = $this->assertQuarantineJournalPath($journal['quarantine']);
        $package = $this->replacementPackagePath($journal['replacement_id']);
        $appExists = is_dir($this->appDir); $oldExists = is_dir($quarantine); $packageExists = is_dir($package);
        if ($oldExists && $this->preparedPackageManifest($quarantine, true) !== $journal['old_manifest']) {
            throw new ApiException('替换候选隔离包清单不匹配，需要人工处理');
        }
        if ($journal['phase'] === 'prepared' && $appExists && $packageExists && !$oldExists) {
            if ($this->preparedPackageManifest($this->appDir, true) !== $journal['old_manifest']) throw new ApiException('替换前候选清单不匹配，需要人工处理');
            $this->removeTransactionJournal($journalFile, '无法完成替换候选恢复');
            return;
        }
        if (in_array($journal['phase'], ['prepared','old_quarantined'], true) && !$appExists && $oldExists && $packageExists) {
            $oldInfo = self::readPackageInfo($quarantine);
            if (!self::isFailedUpgradeRecoveryShape($oldInfo)) throw new ApiException('替换前失败候选身份不完整，需要人工处理');
            $this->assertPreparedReplacement($record, $oldInfo, false, $quarantine);
            if (!$this->renameCandidatePath($package, $this->appDir, 'replacement.recover.new.move.rename')) throw new ApiException('替换候选恢复移动失败');
            $newInfo = $this->replacementCandidateInfo($oldInfo, $record, $journal['replacement_id']);
            if (Server::setIni($this->appDir, $newInfo) !== true) throw new ApiException('替换候选恢复状态保存失败');
            $this->fsyncTree($this->appDir);
            $journal['phase'] = 'replacement_ready'; $this->writeCandidateJournal($journalFile, $journal, 'replacement.recover.ready');
            $record['status'] = 'replaced'; $record['recovered_at'] = time(); $this->writeCandidateJournal($this->replacementRecordPath($journal['replacement_id']), $record, 'replacement.recover.record');
            $this->removeTransactionJournal($journalFile, '无法完成替换候选恢复');
            return;
        }
        if (in_array($journal['phase'], ['prepared','old_quarantined'], true) && $appExists && $oldExists && !$packageExists) {
            $oldInfo = self::readPackageInfo($quarantine);
            if (!self::isFailedUpgradeRecoveryShape($oldInfo)) throw new ApiException('替换前失败候选身份不完整，需要人工处理');
            $activeRecord = $record; $activeRecord['status'] = 'replaced';
            $this->assertPreparedReplacement($activeRecord, $oldInfo, true, $quarantine);
            $newInfo = $this->replacementCandidateInfo($oldInfo, $activeRecord, $journal['replacement_id']);
            if (Server::setIni($this->appDir, $newInfo) !== true) throw new ApiException('替换候选恢复状态保存失败');
            $this->fsyncTree($this->appDir);
            $journal['phase'] = 'replacement_ready'; $this->writeCandidateJournal($journalFile, $journal, 'replacement.recover.ready');
            $activeRecord['recovered_at'] = time(); $this->writeCandidateJournal($this->replacementRecordPath($journal['replacement_id']), $activeRecord, 'replacement.recover.record');
            $this->removeTransactionJournal($journalFile, '无法完成替换候选恢复');
            return;
        }
        if ($journal['phase'] === 'replacement_ready' && $appExists && $oldExists && !$packageExists) {
            $info = $this->getInfo();
            if (($info['failed_upgrade_replacement_id'] ?? null) !== $journal['replacement_id']) throw new ApiException('替换候选恢复状态不匹配，需要人工处理');
            $failed = $info; $failed['state'] = self::FAILED; $failed['stage'] = 'failed'; $failed['failed_stage'] = 'database_update'; $failed['update'] = 1;
            $record['status'] = 'replaced'; $this->assertPreparedReplacement($record, $failed, true);
            $this->writeCandidateJournal($this->replacementRecordPath($journal['replacement_id']), $record, 'replacement.recover.record');
            $this->removeTransactionJournal($journalFile, '无法完成替换候选恢复');
            return;
        }
        throw new ApiException('替换候选恢复状态不明确，需要人工处理');
    }

    private function recoveryQuarantinePath(): string
    {
        $root = $this->installDir . 'quarantine' . DIRECTORY_SEPARATOR . $this->appName;
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new ApiException('无法准备候选隔离目录');
        }
        $this->assertManagedDirectory($root);
        $this->assertSafePackageDirectory($root);
        return $root . DIRECTORY_SEPARATOR . 'recovery-' . bin2hex(random_bytes(8));
    }

    private function assertQuarantineCandidatePath(string $candidate): string
    {
        $root = $this->installDir . 'quarantine' . DIRECTORY_SEPARATOR . $this->appName;
        if (!is_dir($root) || is_link($root)) {
            throw new ApiException('撤回候选隔离目录非法，需要人工处理');
        }
        $this->assertManagedDirectory($root);
        $this->assertSafePackageDirectory($root);
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || realpath(dirname($candidate)) !== $canonicalRoot || !str_starts_with($candidate, $canonicalRoot . DIRECTORY_SEPARATOR)) {
            throw new ApiException('撤回候选恢复路径越界，需要人工处理');
        }
        return $candidate;
    }

    private function assertQuarantineJournalPath(string $candidate): string
    {
        $root = $this->installDir . 'quarantine' . DIRECTORY_SEPARATOR . $this->appName;
        if ($candidate === '' || !is_dir($root) || is_link($root)) {
            throw new ApiException('撤回候选隔离目录非法，需要人工处理');
        }
        $this->assertManagedDirectory($root);
        $this->assertSafePackageDirectory($root);
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || realpath(dirname($candidate)) !== $canonicalRoot
            || !str_starts_with($candidate, $canonicalRoot . DIRECTORY_SEPARATOR)
            || basename($candidate) === '' || basename($candidate) === '.' || basename($candidate) === '..') {
            throw new ApiException('撤回候选恢复路径越界，需要人工处理');
        }
        return $candidate;
    }

    private function hasRuntimeDeployment(): bool
    {
        foreach ($this->getAllowedPath() as $target) {
            if (is_dir($target)) {
                return true;
            }
        }
        return false;
    }

    private function restoreDependencySnapshots(): void
    {
        $backupId = (string) ($this->getInfo()['dependency_backup_id'] ?? '');
        if ($backupId !== '') {
            $this->restorePersistedDependencySnapshots($backupId);
            return;
        }
        foreach ($this->dependencySnapshots as $file => $content) {
            if ($content === null) {
                if (is_file($file) && !unlink($file)) {
                    throw new ApiException('依赖配置恢复失败');
                }
                continue;
            }
            if (file_put_contents($file, $content, LOCK_EX) === false) {
                throw new ApiException('依赖配置恢复失败');
            }
        }
    }

    private function persistDependencySnapshots(string $backupId): void
    {
        $payload = [];
        foreach ($this->dependencySnapshots as $file => $content) {
            $payload[$file] = $content === null ? null : base64_encode($content);
        }
        $files = json_encode($payload, JSON_THROW_ON_ERROR);
        $record = ['files' => $payload, 'sha256' => hash('sha256', $files)];
        if (file_put_contents($this->backupsDir . $backupId . '.snapshot.json', json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new ApiException('无法保存依赖配置恢复记录');
        }
    }

    private function restorePersistedDependencySnapshots(string $backupId): void
    {
        if ($backupId === '') {
            return;
        }
        if (!preg_match('/^[a-z0-9-]+$/', $backupId)) {
            throw new ApiException('依赖配置恢复记录不完整');
        }
        $file = $this->backupsDir . $backupId . '.snapshot.json';
        $record = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($record) || !is_array($record['files'] ?? null) || !is_string($record['sha256'] ?? null)) {
            throw new ApiException('依赖配置恢复记录不完整');
        }
        $payload = $record['files'];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (!hash_equals($record['sha256'], hash('sha256', $encoded))) {
            throw new ApiException('依赖配置恢复记录校验失败');
        }
        $allowedFiles = [
            base_path() . DIRECTORY_SEPARATOR . 'composer.json',
            dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd') . DIRECTORY_SEPARATOR . 'package.json',
        ];
        foreach ($payload as $target => $content) {
            if (!is_string($target) || !in_array($target, $allowedFiles, true) || ($content !== null && !is_string($content))) {
                throw new ApiException('依赖配置恢复记录不完整');
            }
            if ($content !== null && base64_decode($content, true) === false) {
                throw new ApiException('依赖配置恢复记录校验失败');
            }
        }
        foreach ($payload as $target => $content) {
            if ($content === null) {
                if (is_file($target) && !unlink($target)) {
                    throw new ApiException('依赖配置恢复失败');
                }
                continue;
            }
            $decoded = base64_decode($content, true);
            if ($decoded === false || file_put_contents($target, $decoded, LOCK_EX) === false) {
                throw new ApiException('依赖配置恢复失败');
            }
        }
    }

    private function assertDependencyType(string $type): void
    {
        if ($type !== 'npm' && $type !== 'composer') {
            throw new ApiException('依赖安装任务类型错误');
        }
    }

    private function dependencyWaitFlag(string $type): string
    {
        $this->assertDependencyType($type);
        return $type === 'composer' ? 'composer_dependent_wait_install' : 'npm_dependent_wait_install';
    }

    private function assertDependencyCommand(string $type, ?string $nonce, bool $allowExpiredOwner = false): void
    {
        $this->assertDependencyType($type);
        $info = $this->getInfo();
        if (($info['state'] ?? null) != self::DEPENDENT_WAIT_INSTALL
            || !is_string($nonce) || $nonce === '' || !is_string($info['dependency_command_nonce'] ?? null)
            || !hash_equals($info['dependency_command_nonce'], $nonce)
            || ($info['dependency_command_type'] ?? null) !== $type
            || (!$allowExpiredOwner && !$this->hasLiveDependencyLease($info))) {
            throw new ApiException('当前依赖安装任务已失效');
        }
    }

    private function assertDependencyFailureCommand(string $type, ?string $nonce, bool $allowExpiredOwner = false): void
    {
        $this->assertDependencyType($type);
        $info = $this->getInfo();
        if (!is_string($nonce) || $nonce === '') {
            throw new ApiException('当前依赖安装任务已失效');
        }
        $active = ($info['dependency_command_type'] ?? null) === $type
            && is_string($info['dependency_command_nonce'] ?? null)
            && hash_equals($info['dependency_command_nonce'], $nonce)
            && ($allowExpiredOwner || $this->hasLiveDependencyLease($info));
        $completed = ($info['state'] ?? null) === self::INSTALLED
            && ($info['last_dependency_command_type'] ?? null) === $type
            && is_string($info['last_dependency_command_nonce'] ?? null)
            && hash_equals($info['last_dependency_command_nonce'], $nonce);
        if (!$active && !$completed) {
            throw new ApiException('当前依赖安装任务已失效');
        }
    }

    /** @param array<string,mixed> $info */
    private function hasLiveDependencyLease(array $info): bool
    {
        return is_int($info['dependency_command_lease_until'] ?? null)
            && $info['dependency_command_lease_until'] > time();
    }

    private function assertNoDependencyOperation(): void
    {
        $info = $this->getInfo();
        if (!is_string($info['dependency_command_nonce'] ?? null) || $info['dependency_command_nonce'] === '') {
            $this->assertNoActiveHostDependencyLease();
            return;
        }
        if ($this->hasLiveDependencyLease($info)) {
            throw new ApiException('依赖安装任务正在执行，不能执行当前插件操作');
        }
        throw new ApiException('依赖安装任务租约已过期，需要恢复后再执行插件操作');
    }

    private function assertNoActiveHostDependencyLease(): void
    {
        foreach (['npm', 'composer'] as $type) {
            $this->recoverDependencyProcessJournal($type);
            $this->recoverHostDependencyJournal($type);
            $lease = $this->readHostDependencyLease($type);
            if (is_array($lease) && (int) $lease['expires_at'] <= time()) {
                (new self($lease['app']))->recoverExpiredHostLease($type, $lease);
                $lease = $this->readHostDependencyLease($type);
            }
            if ($lease !== null) {
                throw new ApiException('宿主依赖命令正在执行，不能执行当前插件操作');
            }
        }
    }

    /** @param array<string,mixed> $lease */
    private function recoverExpiredHostLease(string $type, array $lease): void
    {
        if (!$this->canRecoverExpiredDependencyExecution($type)) {
            throw new ApiException('过期宿主依赖命令仍可能执行，不能恢复或替换任务');
        }
        $nonce = (string) $lease['nonce'];
        $previous = $this->getInfo();
        $this->writeHostDependencyJournal($type, $nonce, 'PREPARED', $previous);
        if (($previous['dependency_command_nonce'] ?? null) !== $nonce) {
            $previous['uninstall_recovery_required'] = 1;
            $previous['stage'] = 'dependency_recovery_required';
            $previous['last_error'] = '发现无回调的过期宿主依赖租约，需要恢复确认。';
        } else {
            $this->releaseDependencyOperation($previous);
            $previous['state'] = (int) ($previous['dependency_stable_state'] ?? self::FAILED);
            $previous['dependency_recovery_state'] = 'lease_expired';
            $previous['stage'] = 'dependency_recovery';
        }
        if ($this->setInfo([], $previous) !== true) {
            throw new ApiException('无法保存过期宿主依赖租约恢复状态');
        }
        if (!@unlink($this->hostDependencyLeasePath($type))) {
            throw new ApiException('无法清理过期宿主依赖租约');
        }
        $this->clearHostDependencyJournal($type, $nonce);
    }

    /** @param array<string,mixed> $info */
    private function recoverExpiredDependencyCommand(array $info): void
    {
        if ($this->hasLiveDependencyLease($info)) {
            throw new ApiException('该依赖安装任务正在执行，请勿重复提交');
        }
        $stableState = (int) ($info['dependency_stable_state'] ?? $info['state'] ?? self::FAILED);
        if ($stableState !== self::DEPENDENT_WAIT_INSTALL
            || !is_string($info['dependency_command_type'] ?? null)
            || !isset($info[$this->dependencyWaitFlag($info['dependency_command_type'])])) {
            throw new ApiException('依赖安装任务租约已过期，需要恢复后再重新提交');
        }
        $type = $info['dependency_command_type'];
        if (!$this->canRecoverExpiredDependencyExecution($type)) {
            throw new ApiException('依赖安装任务租约已过期，但宿主命令仍可能执行，需要恢复后再重新提交');
        }
        $nonce = $info['dependency_command_nonce'];
        $this->releaseHostDependencyLease($type, $nonce);
        $this->releaseDependencyOperation($info);
        $info['state'] = self::DEPENDENT_WAIT_INSTALL;
        $info['dependency_recovery_state'] = 'lease_expired';
        $info['stage'] = 'dependency_recovery';
        $info['stage_label'] = '依赖安装任务租约已过期，可重新提交受控依赖命令';
        $info['last_error'] = '上一依赖安装任务租约已过期，旧回调不会再改变当前状态。';
        $this->setInfo([], $info);
    }

    private function assertDependencyExecutionOwner(string $type, ?string $nonce): void
    {
        if (!is_string($nonce) || $nonce === '' || !is_resource($this->dependencyExecutionLock)
            || $this->dependencyExecutionType !== $type || $this->dependencyExecutionNonce !== $nonce) {
            throw new ApiException('宿主依赖命令执行锁不匹配');
        }
    }

    /** @return resource|false */
    private function openDependencyExecutionLock(string $type)
    {
        $lockDir = $this->installDir . 'locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new ApiException('无法锁定宿主依赖命令');
        }
        return fopen($lockDir . 'host-' . $type . '.execution.lock', 'c');
    }

    /** @return array<string,mixed>|null */
    private function readHostDependencyLease(string $type): ?array
    {
        $file = $this->hostDependencyLeasePath($type);
        if (!is_file($file)) {
            return null;
        }
        $lease = json_decode((string) file_get_contents($file), true);
        if (!is_array($lease) || !is_string($lease['app'] ?? null) || !is_string($lease['nonce'] ?? null)
            || !is_int($lease['created_at'] ?? null) || !is_int($lease['expires_at'] ?? null)
            || ($lease['type'] ?? null) !== $type) {
            throw new ApiException('宿主依赖命令租约记录不完整，需要恢复后再操作');
        }
        return $lease;
    }

    /** @param array<string,mixed> $previous */
    private function writeHostDependencyJournal(string $type, string $nonce, string $phase, array $previous): void
    {
        $payload = [
            'app' => $this->appName,
            'nonce' => $nonce,
            'type' => $type,
            'phase' => $phase,
            'previous' => $previous,
            'previous_hash' => hash('sha256', json_encode($previous, JSON_THROW_ON_ERROR)),
            'created_at' => time(),
            'expires_at' => time() + self::DEPENDENCY_COMMAND_LEASE_SECONDS,
            'journal_hash' => hash('sha256', $this->appName . "\0" . $nonce . "\0" . $type . "\0" . $phase),
        ];
        $this->writeJsonAtomically($this->hostDependencyJournalPath($type), $payload);
    }

    private function clearHostDependencyJournal(string $type, string $nonce): void
    {
        $file = $this->hostDependencyJournalPath($type);
        if (!is_file($file)) {
            return;
        }
        $journal = json_decode((string) file_get_contents($file), true);
        if (!is_array($journal) || ($journal['app'] ?? null) !== $this->appName || ($journal['nonce'] ?? null) !== $nonce) {
            throw new ApiException('宿主依赖命令事务记录不匹配');
        }
        if (!@unlink($file)) {
            throw new ApiException('无法清理宿主依赖命令事务记录');
        }
    }

    private function recoverHostDependencyJournal(string $type): void
    {
        $file = $this->hostDependencyJournalPath($type);
        if (!is_file($file)) {
            return;
        }
        $journal = json_decode((string) file_get_contents($file), true);
        if (!is_array($journal) || !is_string($journal['app'] ?? null) || !is_string($journal['nonce'] ?? null)
            || !is_array($journal['previous'] ?? null) || ($journal['type'] ?? null) !== $type
            || !is_int($journal['created_at'] ?? null) || !is_int($journal['expires_at'] ?? null)
            || !hash_equals((string) ($journal['previous_hash'] ?? ''), hash('sha256', json_encode($journal['previous'], JSON_THROW_ON_ERROR)))
            || !hash_equals((string) ($journal['journal_hash'] ?? ''), hash('sha256', $journal['app'] . "\0" . $journal['nonce'] . "\0" . $type . "\0" . (string) ($journal['phase'] ?? '')))) {
            throw new ApiException('宿主依赖命令事务记录不完整，需要恢复后再操作');
        }
        if ($journal['app'] !== $this->appName) {
            (new self($journal['app']))->recoverHostDependencyJournal($type);
            return;
        }
        $phase = (string) ($journal['phase'] ?? '');
        if ($phase === 'FINALIZING') {
            if (!$this->canRecoverExpiredDependencyExecution($type)) {
                throw new ApiException('宿主依赖命令事务正在收尾，不能执行当前操作');
            }
            $lease = $this->readHostDependencyLease($type);
            if (is_array($lease) && ($lease['app'] ?? null) === $this->appName && ($lease['nonce'] ?? null) === $journal['nonce']) {
                if (!@unlink($this->hostDependencyLeasePath($type))) {
                    throw new ApiException('无法恢复宿主依赖命令租约');
                }
            }
            $info = $this->getInfo();
            if (($info['dependency_host_lease_settlement'] ?? null) === $journal['nonce']) {
                unset($info['dependency_host_lease_settlement']);
                if ($this->setInfo([], $info) !== true) {
                    throw new ApiException('无法完成宿主依赖命令事务恢复');
                }
            }
            $this->clearHostDependencyJournal($type, $journal['nonce']);
            return;
        }
        if (!in_array($phase, ['PREPARED', 'APP_PREPARED', 'HOST_WRITTEN', 'APP_ACTIVE'], true)) {
            throw new ApiException('宿主依赖命令事务阶段无效，需要恢复后再操作');
        }
        // The execution flock is held for the complete proc_open lifetime. A
        // failed non-blocking acquisition means an old child may still mutate
        // shared npm/composer state, so recovery must fail closed.
        if (!$this->canRecoverExpiredDependencyExecution($type)) {
            throw new ApiException('宿主依赖命令可能仍在执行，不能恢复或替换任务');
        }
        $lease = $this->readHostDependencyLease($type);
        if (is_array($lease) && (($lease['app'] ?? null) !== $this->appName || ($lease['nonce'] ?? null) !== $journal['nonce'])) {
            throw new ApiException('宿主依赖命令租约所有者不匹配，需要恢复后再操作');
        }
        if (is_array($lease) && !@unlink($this->hostDependencyLeasePath($type))) {
            throw new ApiException('无法回滚宿主依赖命令租约');
        }
        if ($this->setInfo([], $journal['previous']) !== true) {
            throw new ApiException('无法恢复依赖安装任务状态');
        }
        $this->clearHostDependencyJournal($type, $journal['nonce']);
    }

    /** @param array<string,mixed> $info */
    private function beginHostLeaseSettlement(string $type, ?string $nonce, array &$info): void
    {
        if (!is_string($nonce) || $nonce === '') {
            throw new ApiException('宿主依赖命令租约不匹配');
        }
        $this->writeHostDependencyJournal($type, $nonce, 'FINALIZING', $info);
        $info['dependency_host_lease_settlement'] = $nonce;
    }

    private function completeHostLeaseSettlement(string $type, ?string $nonce): void
    {
        if (!is_string($nonce) || $nonce === '') {
            throw new ApiException('宿主依赖命令租约不匹配');
        }
        $info = $this->getInfo();
        if (($info['dependency_host_lease_settlement'] ?? null) !== $nonce) {
            throw new ApiException('宿主依赖命令事务不匹配');
        }
        unset($info['dependency_host_lease_settlement']);
        if ($this->setInfo([], $info) !== true) {
            throw new ApiException('无法完成宿主依赖命令事务');
        }
        $this->clearHostDependencyJournal($type, $nonce);
    }

    /** @param array<string,mixed> $previous */
    private function rollbackDependencyCommandBegin(string $type, string $nonce, array $previous): void
    {
        $lease = $this->readHostDependencyLease($type);
        if (is_array($lease) && ($lease['app'] ?? null) === $this->appName && ($lease['nonce'] ?? null) === $nonce) {
            if (!@unlink($this->hostDependencyLeasePath($type))) {
                throw new ApiException('依赖安装任务租约回滚失败，需要恢复后再操作');
            }
        }
        if ($this->setInfo([], $previous) !== true) {
            throw new ApiException('依赖安装任务回滚失败，需要恢复后再操作');
        }
        $this->clearHostDependencyJournal($type, $nonce);
    }

    /** @param array<string,mixed> $payload */
    private function writeJsonAtomically(string $file, array $payload, string $faultPrefix = ''): void
    {
        $temp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $handle = @fopen($temp, 'x+b');
        if (!is_resource($handle)) {
            throw new ApiException('无法创建宿主依赖命令事务临时记录');
        }
        try {
            if (!@chmod($temp, 0600)) {
                throw new ApiException('无法设置宿主依赖命令事务临时记录权限');
            }
            if ($faultPrefix !== '') {
                $this->candidateFault($faultPrefix . '.temp.chmod');
            }
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new ApiException('无法完整写入宿主依赖命令事务记录');
                }
                $offset += $written;
            }
            if ($faultPrefix !== '') {
                $this->candidateFault($faultPrefix . '.temp.write');
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ApiException('无法持久化宿主依赖命令事务记录');
            }
            if ($faultPrefix !== '') {
                $this->candidateFault($faultPrefix . '.temp.fsync');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($temp);
            throw $e;
        }
        fclose($handle);
        if (!rename($temp, $file)) {
            @unlink($temp);
            throw new ApiException('无法提交宿主依赖命令事务记录');
        }
        if ($faultPrefix !== '') {
            $this->candidateFault($faultPrefix . '.rename');
        }
        $this->fsyncDirectory(dirname($file));
        if ($faultPrefix !== '') {
            $this->candidateFault($faultPrefix . '.parent_sync');
        }
    }

    private function acquireHostDependencyLease(string $type, string $nonce): void
    {
        if ($this->readHostDependencyLease($type) !== null) {
            throw new ApiException('该宿主依赖命令正在执行，请勿重复提交');
        }
        $payload = [
            'app' => $this->appName,
            'nonce' => $nonce,
            'type' => $type,
            'created_at' => time(),
            'expires_at' => time() + self::DEPENDENCY_COMMAND_LEASE_SECONDS,
            'journal_phase' => 'HOST_WRITTEN',
        ];
        $this->writeJsonAtomically($this->hostDependencyLeasePath($type), $payload);
    }

    private function releaseHostDependencyLease(string $type, ?string $nonce): void
    {
        if (!is_string($nonce) || $nonce === '') {
            throw new ApiException('宿主依赖命令租约不匹配');
        }
        $this->assertDependencyType($type);
        $ownsLock = !is_resource($this->dependencyLock);
        if ($ownsLock) {
            $this->dependencyLock = $this->acquireDependencyLock($type);
        }
        try {
            $lease = $this->readHostDependencyLease($type);
            if ($lease === null || ($lease['app'] ?? null) !== $this->appName || ($lease['nonce'] ?? null) !== $nonce) {
                throw new ApiException('宿主依赖命令租约不匹配');
            }
            if (!@unlink($this->hostDependencyLeasePath($type))) {
                throw new ApiException('无法释放宿主依赖命令租约');
            }
        } finally {
            if ($ownsLock) {
                $this->releaseDependencyCommand();
            }
        }
    }

    private function canRecoverExpiredDependencyExecution(string $type): bool
    {
        $lock = $this->openDependencyExecutionLock($type);
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return false;
        }
        @flock($lock, LOCK_UN);
        @fclose($lock);
        return true;
    }

    private function recoverDependencyProcessJournal(string $type): void
    {
        $journal = $this->readDependencyProcessJournal($type);
        if ($journal === null) {
            return;
        }
        $lock = $this->openDependencyExecutionLock($type);
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ApiException('宿主依赖命令进程可能仍在执行，不能恢复或替换任务');
        }
        try {
            if (($journal['app'] ?? null) !== $this->appName) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
                $lock = null;
                (new self((string) $journal['app']))->recoverDependencyProcessJournal($type);
                return;
            }
            if (!$this->recordedProcessesAreDead($journal)) {
                throw new ApiException('宿主依赖命令进程仍在执行，不能恢复或替换任务');
            }
            $info = $this->getInfo();
            $appJournal = $info['dependency_process_journal'] ?? null;
            $appJournal = is_string($appJournal) ? json_decode($appJournal, true) : null;
            if (($info['dependency_process_journal'] ?? null) !== null && (!is_array($appJournal) || ($appJournal['nonce'] ?? null) !== $journal['nonce'])) {
                throw new ApiException('依赖命令进程恢复记录不匹配，需要人工处理');
            }
            unset($info['process_recovery_required'], $info['dependency_process_journal']);
            if ($this->setInfo([], $info) !== true) {
                throw new ApiException('无法恢复依赖命令进程状态');
            }
            if (!@unlink($this->hostDependencyProcessJournalPath($type))) {
                throw new ApiException('无法清理宿主依赖命令进程恢复记录');
            }
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    private function hostDependencyLeasePath(string $type): string
    {
        $this->assertDependencyType($type);
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . 'host-' . $type . '.lease.json';
    }

    private function hostDependencyJournalPath(string $type): string
    {
        $this->assertDependencyType($type);
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . 'host-' . $type . '.journal.json';
    }

    private function hostDependencyProcessJournalPath(string $type): string
    {
        $this->assertDependencyType($type);
        return $this->installDir . 'locks' . DIRECTORY_SEPARATOR . 'host-' . $type . '.process.journal.json';
    }

    /** @return array<string,mixed>|null */
    private function readDependencyProcessJournal(string $type): ?array
    {
        $file = $this->hostDependencyProcessJournalPath($type);
        if (!is_file($file)) {
            return null;
        }
        $journal = json_decode((string) file_get_contents($file), true);
        if (!is_array($journal) || !is_string($journal['app'] ?? null) || !is_string($journal['nonce'] ?? null)
            || ($journal['type'] ?? null) !== $type || !is_int($journal['launcher_pid'] ?? null)
            || !is_int($journal['pgid'] ?? null) || !is_array($journal['descendant_pids'] ?? null)
            || !is_int($journal['start_time'] ?? null) || !is_string($journal['phase'] ?? null)) {
            throw new ApiException('宿主依赖命令进程恢复记录不完整，需要人工处理');
        }
        return $journal;
    }

    /** @param list<int> $descendantPids @return array<string,mixed> */
    private function dependencyProcessJournalPayload(string $type, string $nonce, int $launcherPid, int $processGroupId, array $descendantPids, int $startTime, string $phase): array
    {
        return ['app' => $this->appName, 'nonce' => $nonce, 'type' => $type, 'launcher_pid' => $launcherPid,
            'pgid' => $processGroupId, 'descendant_pids' => $this->normalizeProcessIds($descendantPids),
            'start_time' => $startTime, 'failure_time' => null, 'phase' => $phase, 'created_at' => time(), 'updated_at' => time()];
    }

    /** @param array<string,mixed> $journal */
    private function appDependencyProcessJournal(array $journal): string
    {
        return json_encode(['nonce' => $journal['nonce'], 'type' => $journal['type'], 'launcher_pid' => $journal['launcher_pid'],
            'pgid' => $journal['pgid'], 'descendant_pids' => $journal['descendant_pids'], 'start_time' => $journal['start_time'],
            'failure_time' => $journal['failure_time'], 'phase' => $journal['phase']], JSON_THROW_ON_ERROR);
    }

    /** @param list<mixed> $processIds @return list<int> */
    private function normalizeProcessIds(array $processIds): array
    {
        $normalized = [];
        foreach ($processIds as $pid) {
            if (is_int($pid) && $pid > 0) {
                $normalized[$pid] = $pid;
            }
        }
        return array_values($normalized);
    }

    /** @param array<string,mixed> $journal */
    private function recordedProcessesAreDead(array $journal): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }
        $pgid = (int) ($journal['pgid'] ?? 0);
        if ($pgid < 1 || @posix_kill(-$pgid, 0)) {
            return false;
        }
        foreach ($journal['descendant_pids'] ?? [] as $pid) {
            if (!is_int($pid) || $pid < 1 || @posix_kill($pid, 0)) {
                return false;
            }
        }
        $launcherPid = (int) ($journal['launcher_pid'] ?? 0);
        return $launcherPid > 0 && !@posix_kill($launcherPid, 0);
    }

    /** @param array<string,mixed> $info */
    private function releaseDependencyOperation(array &$info): void
    {
        unset($info['dependency_command_nonce'], $info['dependency_command_type'], $info['dependency_command_lease_until'], $info['dependency_stable_state'], $info['dependency_host_lease_state']);
    }

    /** @param array<string,mixed> $info */
    private function isOperationInProgressState(int $state, array $info): bool
    {
        return in_array($state, [self::WAIT_INSTALL, self::CONFLICT_PENDING, self::DEPENDENT_WAIT_INSTALL], true)
            || is_string($info['dependency_command_nonce'] ?? null);
    }

    /** @param array<string,mixed> $info */
    private function isSafeUninstallState(int $state, array $info): bool
    {
        if (($info['uninstall_recovery_required'] ?? 0) == 1) {
            return false;
        }
        if ($state === self::INSTALLED) {
            return true;
        }
        if ($state === self::FAILED) {
            // A new failure is not proof that a package was ever installed.
            // Only the recorded installed lineage and a known post-install
            // failure stage make the canonical uninstall transaction safe.
            return ($info['last_stable_state'] ?? null) === self::INSTALLED
                && in_array((string) ($info['failed_stage'] ?? ''), [
                    'database_update', 'database_uninstall', 'file_deploy', 'service_registration',
                    'registration_confirmation', 'registration_check',
                    'registration_revalidation', 'dependency_install',
                ], true)
                && $this->hasTrustedRegistrationManifest($info);
        }
        // Legacy registries may predate the state field but still contain a
        // complete package. Treat them as recovery candidates, never as a
        // deletion-only uninstall path.
        return !array_key_exists('state', $info) && !empty($info['app'])
            && $this->hasTrustedRegistrationManifest($info);
    }

    /** @param array<string,mixed> $info */
    private function hasTrustedRegistrationManifest(array $info): bool
    {
        if (!is_string($info['registration_manifest'] ?? null) || $info['registration_manifest'] === '') {
            return false;
        }
        try {
            $this->requireLifecycleFiles();
            $this->assertRegistrationMetadata($info);
            return hash_equals($info['registration_manifest'], $this->verifyDeploymentMatchesPackage());
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $info */
    private function markUninstallRecoveryRequired(array $info, string $message): never
    {
        $info['uninstall_recovery_required'] = 1;
        $info['stage'] = 'uninstall_recovery_required';
        $info['stage_label'] = '插件卸载需要恢复确认，未执行数据库脚本或文件删除';
        $info['last_error'] = $message;
        if ($this->setInfo([], $info) !== true) {
            throw new ApiException('无法保存插件卸载恢复状态');
        }
        throw new ApiException($message);
    }

    /** @return resource */
    private function acquireDependencyLock(string $type)
    {
        $lockDir = $this->installDir . 'locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new ApiException('无法锁定依赖安装任务');
        }
        $lock = fopen($lockDir . 'host-' . $type . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ApiException('该依赖安装任务正在执行，请勿重复提交');
        }
        return $lock;
    }

    /**
     * Serializes every state or runtime-tree mutation for one plugin. Nested
     * lifecycle helpers reuse the same handle so recovery can safely call
     * deployment restoration without deadlocking itself.
     *
     * @return resource
     */
    private function acquireOperationLock(bool $recoverTransactions = true)
    {
        if (rtrim($this->installDir, DIRECTORY_SEPARATOR) !== $this->storage->root()) throw new ApiException('插件存储根已变化；请重新执行操作');
        if (is_resource($this->operationLock)) {
            $this->operationLockDepth++;
            return $this->operationLock;
        }
        $lockDir = $this->installDir . 'locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new ApiException('无法锁定插件操作');
        }
        $this->assertManagedDirectory($lockDir);
        $lock = fopen($lockDir . $this->appName . '-operation.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ApiException('该插件操作正在执行，请勿重复提交');
        }
        $this->operationLock = $lock;
        $this->operationLockDepth = 1;
        try {
            if ($recoverTransactions) {
                $this->recoverCandidateTransactions();
            }
        } catch (Throwable $e) {
            $this->releaseOperationLock();
            throw $e;
        }
        return $lock;
    }

    private function releaseOperationLock(): void
    {
        if (!is_resource($this->operationLock)) {
            $this->operationLock = null;
            $this->operationLockDepth = 0;
            return;
        }
        $this->operationLockDepth--;
        if ($this->operationLockDepth > 0) {
            return;
        }
        @flock($this->operationLock, LOCK_UN);
        @fclose($this->operationLock);
        $this->operationLock = null;
        $this->operationLockDepth = 0;
    }

    /**
     * Acquires only a shared view of an already-established lifecycle lock.
     * Verification must not create lock state, recover an interrupted candidate,
     * or invoke any lifecycle write path. Ordinary operations retain the
     * exclusive lock above and its recovery behavior.
     *
     * @return resource
     */
    private function acquireReadOnlyVerificationLock()
    {
        if (is_resource($this->readOnlyVerificationLock)) {
            return $this->readOnlyVerificationLock;
        }
        $lock = @fopen($this->installDir . 'locks' . DIRECTORY_SEPARATOR . $this->appName . '-operation.lock', 'r');
        if ($lock === false || !flock($lock, LOCK_SH | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ApiException('恢复核验锁不可用，未执行任何恢复或写入操作', 400);
        }
        $this->readOnlyVerificationLock = $lock;
        return $lock;
    }

    private function releaseReadOnlyVerificationLock(): void
    {
        if (!is_resource($this->readOnlyVerificationLock)) {
            $this->readOnlyVerificationLock = null;
            return;
        }
        @flock($this->readOnlyVerificationLock, LOCK_UN);
        @fclose($this->readOnlyVerificationLock);
        $this->readOnlyVerificationLock = null;
    }

    /**
     * Uploads do not reveal their app name until the package has been unpacked.
     * Serialize that bounded preflight so move/unzip/cleanup cannot race, then
     * hold the per-app operation lock for every app-specific registry or tree
     * mutation that follows.
     *
     * @return resource
     */
    private function acquireUploadPreflightLock()
    {
        if (is_resource($this->uploadPreflightLock)) {
            return $this->uploadPreflightLock;
        }
        $lockDir = $this->installDir . 'locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new ApiException('无法锁定插件上传预检');
        }
        $this->assertManagedDirectory($lockDir);
        $lock = fopen($lockDir . 'upload-preflight.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ApiException('插件上传预检正在执行，请勿重复提交');
        }
        $this->uploadPreflightLock = $lock;
        return $lock;
    }

    private function releaseUploadPreflightLock(): void
    {
        if (is_resource($this->uploadPreflightLock)) {
            @flock($this->uploadPreflightLock, LOCK_UN);
            @fclose($this->uploadPreflightLock);
        }
        $this->uploadPreflightLock = null;
    }

    private function assertAppName(string $appName): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $appName)) {
            throw new ApiException('插件标识格式错误');
        }
    }

    /**
     * 获取模块基本信息
     */
    public function getInfo(): array
    {
        return Server::getIni($this->appDir);
    }

    /**
     * 设置模块基本信息
     * @throws Throwable
     */
    public function setInfo(array $kv = [], array $arr = []): bool
    {
        $this->acquireOperationLock();
        try {
            if ($kv) {
                $info = $this->getInfo();
                foreach ($kv as $k => $v) {
                    $info[$k] = $v;
                }
                return Server::setIni($this->appDir, $info);
            } elseif ($arr) {
                return Server::setIni($this->appDir, $arr);
            }
            throw new ApiException('参数错误');
        } finally {
            $this->releaseOperationLock();
        }
    }
}
