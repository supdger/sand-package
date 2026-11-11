<?php

namespace plugin\sandpackage\app\controller;

use plugin\sandadmin\app\cache\UserMenuCache;
use plugin\sandadmin\app\middleware\SystemLog;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use plugin\sandpackage\app\logic\InstallLogic;
use plugin\sandpackage\app\logic\LegacyInstallLogic;
use plugin\sandpackage\app\service\PluginStorage;
use plugin\sandpackage\app\service\PackageArchivePolicy;
use Saithink\Saipackage\service\Server;
use Saithink\Saipackage\service\Version;
use support\annotation\Middleware;
use support\Request;
use support\Response;
use Throwable;

#[Middleware(CheckLogin::class, CheckAuth::class, SystemLog::class)]
class InstallController extends BaseController
{
    /**
     * 构造
     */
    public function __construct()
    {
        parent::__construct();
        if ($this->adminId !== 1) {
            throw new ApiException('仅超级管理员能够操作');
        }
    }

    /**
     * 环境检查状态
     */
    static string $ok = 'ok';
    static string $fail = 'fail';
    static string $warn = 'warn';

    static array $needDependentVersion = [
        'php' => '8.2.0',
        'sandadmin' => '0.1.0',
        'sandpackage' => '0.1.0',
    ];

    /**
     * 应用列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $storage = new PluginStorage();
        $records = $storage->managedRecords();
        $runtime = $storage->runtimePlugins();
        $data = array_values($records + $runtime);
        $data = array_map(static function (array $item): array {
            $local = isset($item['app']) && is_string($item['app'])
                ? (new InstallLogic($item['app']))->ordinaryStatus()
                : ['state' => 99, 'blocked' => true, 'reason' => '插件标识缺失，无法确认安装状态'];
            if (isset($item['_error'])) $local = ['state' => 99, 'blocked' => true, 'reason' => $item['_error']];
            unset($item['_path'], $item['_error']);
            $item['title'] ??= $item['app'];
            $actual = array_merge($item, ['state' => $local['state']]);
            $presented = ($item['lifecycle_driver'] ?? '') === 'saipackage-pg-v1'
                ? InstallLogic::presentInfo($actual)
                : array_merge(LegacyInstallLogic::presentInfo($actual), InstallLogic::presentInfo($actual));
            $cleanupPending = (new InstallLogic($item['app']))->cleanupPending();
            return array_merge($item, $presented, [
                'state' => $local['state'],
                'ordinary_actions_blocked' => $local['blocked'],
                'cleanup_pending' => $cleanupPending,
                'state_text' => $cleanupPending ? '清理未完成' : $presented['state_text'],
                'recovery_reason' => $cleanupPending ? '上次清理尚未完成，请继续清理；系统会核对已完成步骤' : $local['reason'],
            ]);
        }, $data);

        $phpVersion = phpversion();
        $phpVersionCompare = Version::compare(self::$needDependentVersion['php'], $phpVersion);
        $phpVersionNotes = '正常';
        if (!$phpVersionCompare) {
            $phpVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['php'];
        }

        $sandadminVersion = config('plugin.sandadmin.app.version');
        $sandadminVersionCompare = Version::compare(self::$needDependentVersion['sandadmin'], $sandadminVersion);
        $sandadminVersionNotes = '正常';
        if (!$sandadminVersionCompare) {
            $sandadminVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['sandadmin'];
        }

        $saithinkVersion = config('plugin.sandpackage.app.version');
        $saithinkVersionCompare = Version::compare(self::$needDependentVersion['sandpackage'], $saithinkVersion);
        $saithinkVersionNotes = '正常';
        if (!$saithinkVersionCompare) {
            $saithinkVersionNotes = '需要版本' . ' >= ' . self::$needDependentVersion['sandpackage'];
        }


        return $this->success([
            // 容量配置损坏不得让整个插件列表不可用；自检项会单独报 fail。
            'limits' => self::safePackageLimits(),
            'version' => [
                'package_size' => self::packageSizeCheck(),
                'php_version' => [
                    'describe' => $phpVersion,
                    'state' => $phpVersionCompare ? self::$ok : self::$fail,
                    'notes' => $phpVersionNotes,
                ],
                'sandadmin_version' => [
                    'describe' => $sandadminVersion,
                    'state' => $sandadminVersionCompare ? self::$ok : self::$fail,
                    'notes' => $sandadminVersionNotes,
                ],
                'sandpackage_version' => [
                    'describe' => $saithinkVersion,
                    'state' => $saithinkVersionCompare ? self::$ok : self::$fail,
                    'notes' => $saithinkVersionNotes,
                ],
            ],
            'data' => $data
        ]);
    }

    /**
     * Multipart framing, boundary and field headers travel with the ZIP body, so
     * the HTTP layer must accept more than the archive limit itself.
     */
    private const MULTIPART_OVERHEAD_BYTES = 65536;

    /**
     * Single source of truth for every size gate, shared with the browser so the
     * frontend never hardcodes its own limit.
     *
     * @return array<string,mixed>
     */
    private static function packageLimits(): array
    {
        $effective = PackageArchivePolicy::effectiveLimit();
        $httpRaw = config('server.max_package_size');
        $http = is_int($httpRaw) ? $httpRaw : (is_string($httpRaw) && preg_match('/^[1-9][0-9]{0,12}$/D', $httpRaw) === 1 ? (int) $httpRaw : null);
        return [
            'effective_limit' => $effective,
            'ceiling' => PackageArchivePolicy::MAX_BYTES,
            'configured_key' => 'plugin.sandpackage.upload.size',
            'multipart_overhead' => self::MULTIPART_OVERHEAD_BYTES,
            'http_max_package_size' => $http,
            'http_required' => $effective + self::MULTIPART_OVERHEAD_BYTES,
            'layers' => [
                'frontend' => '前端校验（install-box.vue / failed-upgrade-recovery.ts）',
                'http' => 'HTTP 层 server.max_package_size',
                'archive' => '后端归档校验 PackageArchivePolicy',
            ],
        ];
    }

    /** @return array<string,mixed>|null Null when the size configuration itself is invalid. */
    private static function safePackageLimits(): ?array
    {
        try { return self::packageLimits(); }
        catch (Throwable) { return null; }
    }

    /**
     * Self-check: the HTTP layer must leave room for the archive limit plus the
     * multipart envelope, otherwise an in-limit upload is cut before any PHP
     * validation can explain it.
     *
     * @return array{describe:string,state:string,notes:string}
     */
    private static function packageSizeCheck(): array
    {
        try {
            $limits = self::packageLimits();
        } catch (Throwable $error) {
            return ['describe' => '未知', 'state' => self::$fail,
                'notes' => $error instanceof ApiException ? $error->getMessage() : '插件包容量配置不可读取'];
        }
        $required = $limits['http_required'];
        $http = $limits['http_max_package_size'];
        $describe = '归档上限 ' . $limits['effective_limit'] . ' bytes / HTTP '
            . ($http === null ? '未配置' : $http . ' bytes');
        if ($http === null) {
            return ['describe' => $describe, 'state' => self::$warn,
                'notes' => 'server.max_package_size 未配置或不是正整数；请在 config/server.php 设为 >= '
                    . $required . ' bytes（归档上限 ' . $limits['effective_limit'] . ' + multipart 余量 '
                    . $limits['multipart_overhead'] . '）'];
        }
        if ($http < $required) {
            return ['describe' => $describe, 'state' => self::$fail,
                'notes' => 'HTTP 层上限不足：actual=' . $http . ' bytes，need>=' . $required
                    . ' bytes；请把 config/server.php 的 max_package_size 调到 ' . $required
                    . ' 以上，或把 plugin.sandpackage.upload.size 降到 ' . ($http - $limits['multipart_overhead'])
                    . ' 以下后重载服务'];
        }
        return ['describe' => $describe, 'state' => self::$ok, 'notes' => '正常'];
    }

    /** Expose the one authoritative size limit to the browser. */
    public function limits(Request $request): Response
    {
        return $this->success(array_merge(self::packageLimits(), ['self_check' => self::packageSizeCheck()]));
    }

    /**
     * 上传插件
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function upload(Request $request): Response
    {
        $spl_file = $request->file('file');
        if (!is_object($spl_file)
            || !method_exists($spl_file, 'isValid')
            || !method_exists($spl_file, 'getUploadExtension')
            || !method_exists($spl_file, 'getSize')) {
            throw new ApiException('请选择有效的插件包文件后再上传', 400);
        }
        if (!$spl_file->isValid()) {
            throw new ApiException('上传文件未通过校验，请重新选择完整的 ZIP 插件包', 400);
        }
        $config = config('plugin.sandpackage.upload', [
            'size' => PackageArchivePolicy::MAX_BYTES,
            'type' => ['zip']
        ]);
        if (!in_array($spl_file->getUploadExtension(), $config['type'])) {
            return $this->fail('文件格式上传失败,请选择zip格式文件上传');
        }
        PackageArchivePolicy::assertSize(
            $spl_file->getSize(), PackageArchivePolicy::effectiveLimit(), 'HTTP 上传入口 install/upload',
        );
        $install = new InstallLogic();
        $info = $install->upload($spl_file);
        return $this->success($info);
    }

    /**
     * 安装插件
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function install(Request $request): Response
    {
        $appName = $request->post("appName", '');
        if (empty($appName)) {
            return $this->fail('参数错误');
        }
        $install = new InstallLogic($appName);
        $confirmation = (string) $request->post('confirmation', '');
        // Optional restart flag: the page-side orchestrator suppresses the
        // per-plugin reload while upgrading dependencies and lets the chain
        // tail restart once. Missing values keep the historical reload.
        $restart = InstallLogic::restartRequested($request->post('restart', null));
        $info = $install->install($restart, $confirmation);
        UserMenuCache::clearMenuCache();
        return $this->success($info);
    }

    /**
     * Read-only dependency plan for a staged candidate: declared requirements
     * mapped to local state and the smallest satisfying repository version.
     * Drives the page-side upgrade orchestration; install() re-validates.
     */
    public function inspectDependencies(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('依赖检查仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够检查插件依赖', 403);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        $logic = new InstallLogic($appName);
        $repository = $this->repositoryLogic();
        return $this->repositoryResponse($request, function (callable $complete) use ($logic, $repository): void {
            $repository->catalog(function (?array $catalog, ?Throwable $error) use ($logic, $complete): void {
                try {
                    $plugins = $error === null && is_array($catalog) ? ($catalog['plugins'] ?? null) : null;
                    $catalogError = $error instanceof ApiException ? $error->getMessage() : null;
                    $complete($logic->inspectDependencies($plugins, $catalogError), null);
                } catch (Throwable $planError) {
                    $complete(null, $planError);
                }
            });
        });
    }

    /** Inspect an uploaded candidate against the existing sandai schema. */
    public function inspectExistingSchemaAttach(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('重接预检仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够预检现有 schema', 400);
        $appName = trim((string) $request->post('appName', ''));
        $database = trim((string) $request->post('database', ''));
        if ($appName === '' || $database === '') throw new ApiException('请填写插件标识和数据库名', 400);
        return $this->success((new InstallLogic($appName))->inspectExistingSchemaAttach($database), '现有 schema 重接预检完成');
    }

    /** Install package files and register the plugin without replaying install.sql. */
    public function attachExistingSchema(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('现有 schema 重接仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够重接现有 schema', 400);
        $appName = trim((string) $request->post('appName', ''));
        $database = trim((string) $request->post('database', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $database === '' || $confirmation === '') throw new ApiException('请填写插件标识、数据库名和完整重接确认内容', 400);
        $info = (new InstallLogic($appName))->install(false, $confirmation, true, $database);
        UserMenuCache::clearMenuCache();
        return $this->success($info, '插件已重接现有 schema');
    }

    /** Read-only inspection of a failed existing-schema attachment. */
    public function inspectExistingSchemaAttachRecovery(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('重接恢复检查仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够检查重接恢复', 400);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new InstallLogic($appName))->inspectExistingSchemaAttachRecovery(), '重接恢复现场已核对');
    }

    /** Resume only checksum-verified file deployment; existing database data is untouched. */
    public function continueExistingSchemaAttach(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('重接恢复仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够继续重接恢复', 400);
        $appName = trim((string) $request->post('appName', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $confirmation === '') throw new ApiException('请填写插件标识和完整恢复确认内容', 400);
        return $this->success(
            (new InstallLogic($appName))->continueExistingSchemaAttach($confirmation),
            '现有 schema 重接文件已恢复',
        );
    }

    /**
     * Formally register a plugin that is already deployed on this host.
     * This endpoint never imports SQL or deploys package files.
     *
     * @throws Throwable
     */
    public function registerExisting(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            return $this->fail('登记操作仅支持 POST 请求');
        }
        $appName = trim((string) $request->post('appName', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $confirmation === '') {
            return $this->fail('请填写插件标识和完整登记确认内容');
        }
        $install = new LegacyInstallLogic($appName);
        $info = $install->registerExisting($confirmation);
        return $this->success(array_merge($info, LegacyInstallLogic::presentInfo($info)), '插件登记完成');
    }

    public function inspectDependencyRecovery(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('依赖任务检查仅支持 POST', 400);
        $app = trim((string) $request->post('appName', ''));
        if ($app === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new InstallLogic($app))->inspectDependencyRecovery());
    }

    public function recoverDependency(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('依赖任务恢复仅支持 POST', 400);
        $app = trim((string) $request->post('appName', ''));
        if ($app === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new InstallLogic($app))->recoverDependencyCommand(
            (string) $request->post('confirmation', ''), $request->post('restart', false) === true || $request->post('restart', '') === '1'));
    }

    public function inspectCandidate(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('候选检查仅支持 POST 请求', 400);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new InstallLogic($appName))->inspectCandidateWithdrawal());
    }

    /**
     * Revert only a ready, unexecuted upgrade candidate. SystemLog records the
     * operation; the logic never invokes SQL, file deployment or service reload.
     *
     * @throws Throwable
     */
    public function discardCandidate(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            return $this->fail('撤回候选仅支持 POST 请求');
        }
        $appName = trim((string) $request->post('appName', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $confirmation === '') {
            return $this->fail('请填写插件标识和完整撤回确认内容');
        }
        $normal = new InstallLogic($appName);
        if ($normal->usesCandidateWithdrawal()) {
            $info = $normal->discardCandidate($confirmation);
            return $this->success(array_merge($info, InstallLogic::presentInfo($info)), '升级候选已撤回，旧安装登记已恢复');
        }
        $install = new LegacyInstallLogic($appName);
        $info = $install->discardCandidate($confirmation);
        return $this->success(array_merge($info, LegacyInstallLogic::presentInfo($info)), '升级候选已撤回，数据库未执行无需回滚');
    }

    /** Read-only inspection of a never-started first-install candidate. */
    #[Permission(title: '检查首次安装候选撤回', slug: 'sandpackage:install:inspectFreshCandidate')]
    public function inspectFreshCandidate(Request $request): Response
    {
        return $this->success(
            $this->postgresLogic($request)->inspectFreshCandidateWithdrawal(),
            '首次安装候选撤回现场已核对',
        );
    }

    /**
     * Delete a never-started first-install candidate so the application name is
     * free again. No SQL, runtime file or dependency state is involved.
     *
     * @throws Throwable
     */
    #[Permission(title: '撤回首次安装候选', slug: 'sandpackage:install:discardFreshCandidate')]
    public function discardFreshCandidate(Request $request): Response
    {
        [$logic, $confirmation] = $this->postgresWrite($request);
        return $this->success(
            $logic->discardFreshCandidate($confirmation),
            '首次安装候选已撤回，应用名已释放，可以重新上传',
        );
    }

    #[Permission(title: '撤回等待依赖的安装', slug: 'sandpackage:install:withdrawWaitingInstall')]
    public function withdrawWaitingInstall(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            throw new ApiException('撤回安装仅支持 POST 请求', 400);
        }
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') {
            throw new ApiException('请填写插件标识', 400);
        }
        return $this->success(
            (new InstallLogic($appName))->withdrawWaitingInstall(),
            '安装已撤回',
        );
    }

    #[Permission(title: '重新安装失败的插件', slug: 'sandpackage:install:retryFailedInstall')]
    public function retryFailedInstall(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            throw new ApiException('重新安装仅支持 POST 请求', 400);
        }
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') {
            throw new ApiException('请填写插件标识', 400);
        }
        return $this->success(
            (new InstallLogic($appName))->retryFailedInstall(),
            '可以重新安装',
        );
    }

    /** Read-only inspection of host-level guard records that block every plugin. */
    #[Permission(title: '检查宿主守卫记录', slug: 'sandpackage:install:inspectGlobalGuards')]
    public function inspectGlobalGuards(Request $request): Response
    {
        return $this->success(
            (new InstallLogic($this->recoveryApp($request)))->inspectGlobalGuards(),
            '宿主守卫记录已核对',
        );
    }

    /** Remove only orphan guard records and abandoned upload staging. */
    #[Permission(title: '清理孤儿宿主守卫', slug: 'sandpackage:install:cleanupGlobalGuards')]
    public function cleanupGlobalGuards(Request $request): Response
    {
        $appName = $this->recoveryApp($request);
        $confirmation = (string) $request->post('confirmation', '');
        if ($confirmation === '') throw new ApiException('请填写检查结果给出的完整确认内容', 400);
        return $this->success(
            (new InstallLogic($appName))->cleanupGlobalGuards($confirmation),
            '孤儿守卫记录与残留上传暂存已清理',
        );
    }

    /** Read-only recovery inspection; no directory, journal or runtime mutation. */
    public function inspectFailedUpgradeRecovery(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('恢复检查仅支持 POST 请求', 400);
        $appName = $this->recoveryApp($request);
        // The upstream PostgreSQL driver rejects every legacy recovery entry, so
        // this shared entry reports that driver's own recovery surface instead.
        $logic = new InstallLogic($appName);
        if ($logic->usesPostgresLifecycle()) {
            return $this->success($this->postgresRecoveryOverview($logic, $appName), '恢复状态检查完成');
        }
        return $this->success((new LegacyInstallLogic($appName))->inspectFailedUpgradeRecovery($this->adminId), '恢复状态检查完成');
    }

    // ========== PostgreSQL 生命周期驱动恢复入口 ==========
    // 以下端点与 `php webman sandpackage:recover` 的同名动作共用 InstallLogic，
    // 不复制恢复判定；CLI 语义保持不变。

    /** Read-only inspection of a failed controlled host-file upgrade. */
    #[Permission(title: '检查宿主升级恢复', slug: 'sandpackage:install:inspectHostUpgradeRecovery')]
    public function inspectHostUpgradeRecovery(Request $request): Response
    {
        return $this->success(
            $this->postgresLogic($request)->inspectHostUpgradeRecovery(),
            '宿主升级恢复现场已核对',
        );
    }

    /** Restore the exact pre-upgrade candidate; bound to the recorded failure identity. */
    #[Permission(title: '回滚宿主升级', slug: 'sandpackage:install:recoverHostUpgradeRollback')]
    public function recoverHostUpgradeRollback(Request $request): Response
    {
        [$logic, $confirmation] = $this->postgresWrite($request);
        return $this->success($logic->recoverHostUpgradeRollback($confirmation), '宿主升级已回滚到原候选');
    }

    /** Resume a verified host upgrade from its recorded checkpoint. */
    #[Permission(title: '继续宿主升级', slug: 'sandpackage:install:continueHostUpgrade')]
    public function continueHostUpgrade(Request $request): Response
    {
        [$logic, $confirmation] = $this->postgresWrite($request);
        return $this->success(
            $logic->continueHostUpgrade($confirmation, $this->recoveryRestart($request)),
            '宿主升级已继续',
        );
    }

    /** Read-only inspection of a failed controlled host-file uninstall. */
    #[Permission(title: '检查宿主卸载恢复', slug: 'sandpackage:install:inspectHostUninstallRecovery')]
    public function inspectHostUninstallRecovery(Request $request): Response
    {
        return $this->success(
            $this->postgresLogic($request)->inspectHostUninstallRecovery(),
            '宿主卸载恢复现场已核对',
        );
    }

    /** Roll back a failed controlled host-file uninstall. */
    #[Permission(title: '回滚宿主卸载', slug: 'sandpackage:install:recoverHostUninstallRollback')]
    public function recoverHostUninstallRollback(Request $request): Response
    {
        [$logic, $confirmation] = $this->postgresWrite($request);
        return $this->success($logic->recoverHostUninstallRollback($confirmation), '宿主卸载已回滚');
    }

    /** Resume a verified host uninstall from its recorded checkpoint. */
    #[Permission(title: '继续宿主卸载', slug: 'sandpackage:install:continueHostUninstall')]
    public function continueHostUninstall(Request $request): Response
    {
        [$logic, $confirmation] = $this->postgresWrite($request);
        return $this->success(
            $logic->continueHostUninstall($confirmation, $this->recoveryRestart($request)),
            '宿主卸载已继续',
        );
    }

    /** Read-only inspection of an interrupted first install. */
    #[Permission(title: '检查首次安装恢复', slug: 'sandpackage:install:inspectFreshInstallRecovery')]
    public function inspectFreshInstallRecovery(Request $request): Response
    {
        return $this->success(
            $this->postgresLogic($request)->inspectFreshInstallRecovery($this->recoveryPlan($request)),
            '首次安装恢复现场已核对',
        );
    }

    /**
     * Execute one confirmed first-install recovery action. The permitted action
     * set is decided by the inspection record inside FreshInstallRecovery.
     */
    #[Permission(title: '执行首次安装恢复', slug: 'sandpackage:install:recoverFreshInstall')]
    public function recoverFreshInstall(Request $request): Response
    {
        [$logic, $confirmation] = $this->postgresWrite($request);
        $action = trim((string) $request->post('action', ''));
        if (!in_array($action, self::FRESH_RECOVERY_ACTIONS, true)) {
            throw new ApiException('请选择检查结果允许的首次安装恢复动作', 400);
        }
        return $this->success(
            $logic->recoverFreshInstall(
                $action, $confirmation, $this->recoveryPlan($request), $this->recoveryRestart($request),
            ),
            '首次安装恢复动作已执行',
        );
    }

    /** Mirrors the CLI's `*-fresh` actions; the logic re-validates against the journal. */
    private const FRESH_RECOVERY_ACTIONS = [
        'cleanup-fresh', 'manual-cleanup-fresh', 'finish-cleanup-fresh', 'continue-fresh',
    ];

    /**
     * Probe each PostgreSQL-driver recovery surface read-only and report which
     * one applies, so the management side has a single usable recovery entry.
     *
     * @return array<string,mixed>
     */
    private function postgresRecoveryOverview(InstallLogic $logic, string $appName): array
    {
        $surfaces = [
            'host_upgrade' => ['inspect' => fn(): array => $logic->inspectHostUpgradeRecovery(),
                'endpoint' => '/app/sandpackage/install/inspectHostUpgradeRecovery'],
            'host_uninstall' => ['inspect' => fn(): array => $logic->inspectHostUninstallRecovery(),
                'endpoint' => '/app/sandpackage/install/inspectHostUninstallRecovery'],
            'fresh_install' => ['inspect' => fn(): array => $logic->inspectFreshInstallRecovery(),
                'endpoint' => '/app/sandpackage/install/inspectFreshInstallRecovery'],
            'candidate_withdrawal' => ['inspect' => fn(): array => $logic->inspectCandidateWithdrawal(),
                'endpoint' => '/app/sandpackage/install/inspectCandidate'],
            'fresh_candidate_withdrawal' => ['inspect' => fn(): array => $logic->inspectFreshCandidateWithdrawal(),
                'endpoint' => '/app/sandpackage/install/inspectFreshCandidate'],
            'dependency' => ['inspect' => fn(): array => $logic->inspectDependencyRecovery(),
                'endpoint' => '/app/sandpackage/install/inspectDependencyRecovery'],
            'global_guards' => ['inspect' => fn(): array => $logic->inspectGlobalGuards(),
                'endpoint' => '/app/sandpackage/install/inspectGlobalGuards'],
        ];
        $available = [];
        $unavailable = [];
        foreach ($surfaces as $kind => $surface) {
            try {
                $available[] = ['recovery_kind' => $kind, 'endpoint' => $surface['endpoint'],
                    'inspection' => ($surface['inspect'])()];
            } catch (ApiException $error) {
                $unavailable[] = ['recovery_kind' => $kind, 'reason' => $error->getMessage()];
            } catch (Throwable) {
                // An unreadable surface must not hide the ones that do apply.
                $unavailable[] = ['recovery_kind' => $kind, 'reason' => '该恢复现场无法读取，请检查服务日志'];
            }
        }
        $info = $logic->getInfo();
        return [
            'app' => $appName,
            'lifecycle_driver' => 'saipackage-pg-v1',
            'recovery_mode' => 'postgres_lifecycle',
            'state' => $info['state'] ?? null,
            'diagnostic_id' => $info['diagnostic_id'] ?? null,
            'last_error_code' => $info['last_error_code'] ?? null,
            'available_recoveries' => $available,
            'unavailable_recoveries' => $unavailable,
            'cli_equivalent' => 'php webman sandpackage:recover <action> ' . $appName,
        ];
    }

    private function recoveryApp(Request $request): string
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('恢复操作仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够操作恢复', 403);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        return $appName;
    }

    /** Resolve the PostgreSQL-driver logic, refusing plugins on the legacy driver. */
    private function postgresLogic(Request $request): InstallLogic
    {
        $logic = new InstallLogic($this->recoveryApp($request));
        if (!$logic->usesPostgresLifecycle()) {
            throw new ApiException('此插件不使用上游 PostgreSQL 安装流程，请使用对应的恢复入口', 400);
        }
        return $logic;
    }

    /** @return array{InstallLogic,string} */
    private function postgresWrite(Request $request): array
    {
        $logic = $this->postgresLogic($request);
        $confirmation = (string) $request->post('confirmation', '');
        if ($confirmation === '') throw new ApiException('请填写检查结果给出的完整确认内容', 400);
        return [$logic, $confirmation];
    }

    /** Reload is a separate operation authorisation, exactly as in the CLI. */
    private function recoveryRestart(Request $request): bool
    {
        return $request->post('restart', false) === true || $request->post('restart', '') === '1';
    }

    /** @return array<string,mixed>|null The reviewed manual cleanup plan, if supplied. */
    private function recoveryPlan(Request $request): ?array
    {
        $plan = $request->post('plan');
        if ($plan === null || $plan === '') return null;
        if (is_string($plan)) {
            try { $plan = json_decode($plan, true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable) { throw new ApiException('人工清理计划不是有效的 JSON', 400); }
        }
        if (!is_array($plan)) throw new ApiException('人工清理计划格式错误', 400);
        return $plan;
    }

    /** Restore only runtime files from the identity-bound pre-upgrade backup. */
    public function restoreRuntimeFromBackup(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('运行文件恢复仅支持 POST 请求', 400);
        $appName = trim((string) $request->post('appName', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $confirmation === '') throw new ApiException('请填写插件标识和完整运行文件恢复确认内容', 400);
        $result = (new LegacyInstallLogic($appName))->restoreRuntimeFromBackup($confirmation, $this->adminId);
        return $this->success([
            'result' => $result,
            'presentation' => [
                'recovery_mode' => 'verification_required',
                'allowed_actions' => ['prepare_failed_upgrade_replacement'],
                'message' => $result['message'],
            ],
        ], '运行文件恢复完成');
    }

    public function inspectInterruptedPreUpgradeBackup(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('升级前备份恢复检查仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够检查升级前备份恢复', 403);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new LegacyInstallLogic($appName))->inspectInterruptedPreUpgradeBackup(), '升级前备份恢复检查完成');
    }

    public function restoreInterruptedPreUpgradeBackup(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('升级前备份恢复仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够恢复升级前备份', 403);
        $appName = trim((string) $request->post('appName', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $confirmation === '') throw new ApiException('请填写插件标识和完整恢复确认内容', 400);
        return $this->success(
            (new LegacyInstallLogic($appName))->restoreInterruptedPreUpgradeBackup($confirmation),
            '升级前备份已恢复；未执行数据库脚本、文件部署或服务登记'
        );
    }

    /** Revalidates one prepared replacement; no confirmation is issued. */
    public function verifyFailedUpgradeRecovery(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') {
            throw new ApiException('恢复核验仅支持 POST 请求', 400);
        }
        if ($this->adminId !== 1) {
            throw new ApiException('仅超级管理员能够执行恢复核验', 400);
        }
        $appName = trim((string) $request->post('appName', ''));
        $replacementId = trim((string) $request->post('replacementId', ''));
        if ($appName === '' || $replacementId === '') throw new ApiException('请填写插件标识和替换候选标识', 400);
        $result = (new LegacyInstallLogic($appName))->verifyPreparedFailedUpgradeReplacement($replacementId, $this->adminId);
        return $this->success($result, '恢复条件核验完成');
    }

    /** Receive and preflight a private failed-upgrade replacement archive. */
    public function prepareFailedUpgradeReplacement(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('替换候选预检仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够预检替换候选', 400);
        $appName = trim((string) $request->post('appName', ''));
        $file = $request->file('file');
        if ($appName === '' || !is_object($file)) throw new ApiException('请填写插件标识并选择替换 ZIP 包', 400);
        $result = (new LegacyInstallLogic($appName))->prepareFailedUpgradeReplacement($file, $this->adminId);
        return $this->success($result, '替换候选预检完成');
    }

    /** Durably exchange the failed candidate for one prepared private package. */
    public function replaceFailedUpgradeCandidate(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('替换失败候选仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够替换失败候选', 400);
        $appName = trim((string) $request->post('appName', ''));
        $replacementId = trim((string) $request->post('replacementId', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $replacementId === '' || $confirmation === '') throw new ApiException('请填写插件标识、替换候选标识和完整替换确认内容', 400);
        $result = (new LegacyInstallLogic($appName))->replaceFailedUpgradeCandidate($replacementId, $confirmation, $this->adminId);
        return $this->success($result, '失败候选替换完成');
    }

    /** Execute the already verified replacement's update.sql, never install.sql. */
    public function retryFailedUpgrade(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('失败升级重试仅支持 POST 请求', 400);
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够重试失败升级', 400);
        $appName = trim((string) $request->post('appName', ''));
        $confirmation = (string) $request->post('confirmation', '');
        if ($appName === '' || $confirmation === '') throw new ApiException('请填写插件标识和完整重试确认内容', 400);
        $result = (new LegacyInstallLogic($appName))->retryFailedUpgrade($confirmation, $this->adminId);
        return $this->success($result, '失败升级重试完成');
    }

    /**
     * Read-only uninstall preview: installed plugins that still declare this
     * app as a package dependency. The dialog warns with the list; uninstall()
     * refuses under the write lock while dependents remain.
     */
    public function inspectUninstall(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('卸载检查仅支持 POST 请求', 400);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new InstallLogic($appName))->inspectUninstall(), '卸载依赖检查完成');
    }

    /**
     * 卸载插件
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function uninstall(Request $request): Response
    {
        $appName = $request->post("appName", '');
        if (empty($appName)) {
            return $this->fail('参数错误');
        }
        $install = new InstallLogic($appName);
        $install->uninstall();
        UserMenuCache::clearMenuCache();
        return $this->success('卸载插件成功');
    }

    public function inspectCleanup(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('清理检查仅支持 POST 请求', 400);
        $app = trim((string) $request->post('appName', ''));
        if ($app === '') throw new ApiException('请指定要检查的插件', 400);
        return $this->success((new InstallLogic($app))->inspectCleanup());
    }

    public function cleanup(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('清理仅支持 POST 请求', 400);
        $app = trim((string) $request->post('appName', ''));
        $fingerprint = (string) $request->post('fingerprint', '');
        $confirmApp = (string) $request->post('confirmApp', '');
        if ($app === '' || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || $confirmApp !== $app) {
            throw new ApiException('请先检查清理范围，并输入插件标识确认', 400);
        }
        return $this->success((new InstallLogic($app))->cleanup($fingerprint, $confirmApp), '插件残留已清理，可以重新安装');
    }

    /**
     * HTTP worker 平滑重载（SIGUSR1）。不会重读 process.php，也不能注册新的自定义进程。
     * 需要 master 重读 process.php 时请走安装/恢复的 full_restart / restart-only。
     */
    public function reload(Request $request): Response
    {
        Server::reload();

        return $this->success('重载成功（SIGUSR1；不等于完整重启）');
    }

    // ========== 商店代理接口 ==========

    /** 仓库清单由服务端配置，不接受客户端仓库或下载地址。 */
    public function repositoryCatalog(Request $request): Response
    {
        $logic = $this->repositoryLogic();
        return $this->repositoryResponse($request, fn(callable $complete) => $logic->catalog($complete));
    }

    /** 下载只准备候选，数据库生命周期由现有安装入口执行。 */
    public function repositoryDownload(Request $request): Response
    {
        [$app, $version, $sha256] = $this->repositorySelection($request, false);
        $logic = $this->repositoryLogic();
        return $this->repositoryResponse($request, fn(callable $complete) => $logic->download($app, $version, $sha256, $complete));
    }

    public function repositoryCleanupPackage(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('补充清理包仅支持 POST 请求', 400);
        [$app, $version, $sha256] = $this->repositorySelection($request, false);
        $logic = $this->repositoryLogic();
        return $this->repositoryResponse($request, fn(callable $complete) => $logic->cleanupPackage($app, $version, $sha256, $complete));
    }

    /** 文档读取只校验清单与 ZIP，不读取或修复本地安装状态。 */
    public function repositoryDocument(Request $request): Response
    {
        [$app, $version, $sha256] = $this->repositorySelection($request, true);
        $logic = $this->repositoryLogic();
        return $this->repositoryResponse($request, fn(callable $complete) => $logic->document($app, $version, $sha256, $complete, (string) $request->get('type', 'readme')));
    }

    /** @return array{string,string,string} */
    private function repositorySelection(Request $request, bool $query): array
    {
        $app = $query ? $request->get('app') : $request->post('app');
        $version = $query ? $request->get('version') : $request->post('version');
        $sha256 = $query ? $request->get('sha256') : $request->post('sha256');
        if (!is_string($app) || !preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)
            || !is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $version)
            || !is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new ApiException('请选择有效的插件版本');
        }
        return [$app, $version, $sha256];
    }

    private function repositoryLogic(): \plugin\sandpackage\app\logic\RepositoryLogic
    {
        return new \plugin\sandpackage\app\logic\RepositoryLogic(
            \plugin\sandpackage\app\service\GithubRepositoryClient::shared(),
            (string) config('plugin.sandpackage.repository.repository', 'supdger/sandadmin'),
            (string) config('plugin.sandpackage.repository.ref', 'main'),
            (string) config('plugin.sandadmin.app.version')
        );
    }

    /** Send headers through middleware first; JSON arrives when asynchronous I/O finishes. */
    private function repositoryResponse(Request $request, callable $operation): Response
    {
        if ($request->protocolVersion() !== '1.1') {
            return $this->fail('插件仓库请求需要 HTTP/1.1 连接')->withStatus(505);
        }
        $connection = $request->connection;
        $closeAfterResponse = strcasecmp((string) $request->header('connection', ''), 'close') === 0;
        \Workerman\Timer::add(0.001, function () use ($connection, $operation, $closeAfterResponse): void {
            if ($connection->getStatus() !== \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED) return;
            $done = false;
            $complete = function (?array $result, ?Throwable $error) use ($connection, &$done, $closeAfterResponse): void {
                if ($done) return;
                $done = true;
                if ($connection->getStatus() !== \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED) return;
                $response = $error === null ? $this->success($result ?? []) : $this->repositoryError($error);
                $connection->send(new \Workerman\Protocols\Http\Chunk($response->rawBody()));
                $end = new \Workerman\Protocols\Http\Chunk('');
                if ($closeAfterResponse) $connection->close($end);
                else $connection->send($end);
            };
            try { $operation($complete); }
            catch (Throwable $error) { $complete(null, $error); }
        }, [], false);
        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Transfer-Encoding' => 'chunked',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => $closeAfterResponse ? 'close' : 'keep-alive',
        ]);
    }

    private function repositoryError(Throwable $error): Response
    {
        if ($error instanceof ApiException) return $this->fail($error->getMessage());
        \support\Log::error('Repository package operation failed: ' . get_class($error));
        return $this->fail('插件仓库操作失败，请检查服务日志');
    }
}
