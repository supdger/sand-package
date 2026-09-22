<?php

namespace plugin\sandpackage\app\controller;

use plugin\sandadmin\app\cache\UserMenuCache;
use plugin\sandadmin\app\middleware\SystemLog;
use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandpackage\app\logic\InstallLogic;
use plugin\sandpackage\app\logic\LegacyInstallLogic;
use plugin\sandpackage\app\service\PluginStorage;
use Saithink\Saipackage\service\Server;
use Saithink\Saipackage\service\Version;
use support\annotation\Middleware;
use support\Request;
use support\Response;
use Throwable;

#[Middleware(CheckLogin::class, SystemLog::class)]
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
        'php' => '8.1.0',
        'sandadmin' => '6.0.0',
        'sandpackage' => '6.0.0',
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
            'version' => [
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
            'size' => 1024 * 1024 * 5,
            'type' => ['zip']
        ]);
        if (!in_array($spl_file->getUploadExtension(), $config['type'])) {
            return $this->fail('文件格式上传失败,请选择zip格式文件上传');
        }
        if ($spl_file->getSize() > $config['size']) {
            return $this->fail('文件大小不能超过5M');
        }
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
        $info = $install->install(true, $confirmation);
        UserMenuCache::clearMenuCache();
        return $this->success($info);
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
        $install = new LegacyInstallLogic($appName);
        $info = $install->discardCandidate($confirmation);
        return $this->success(array_merge($info, LegacyInstallLogic::presentInfo($info)), '升级候选已撤回，数据库未执行无需回滚');
    }

    /** Read-only recovery inspection; no directory, journal or runtime mutation. */
    public function inspectFailedUpgradeRecovery(Request $request): Response
    {
        if (strtoupper($request->method()) !== 'POST') throw new ApiException('恢复检查仅支持 POST 请求', 400);
        $appName = trim((string) $request->post('appName', ''));
        if ($appName === '') throw new ApiException('请填写插件标识', 400);
        return $this->success((new LegacyInstallLogic($appName))->inspectFailedUpgradeRecovery($this->adminId), '恢复状态检查完成');
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
     * 重启
     * @param Request $request
     * @return Response
     */
    public function reload(Request $request): Response
    {
        Server::restart();

        return $this->success('重载成功');
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
        return $this->repositoryResponse($request, fn(callable $complete) => $logic->document($app, $version, $sha256, $complete));
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
