<?php
declare(strict_types=1);
namespace plugin\sandpackage\app\service;

use plugin\sandadmin\exception\ApiException;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2) . '/tools/system-update-worker.php';
require_once __DIR__ . '/SystemUpdateEnvironment.php';

/** Super-admin API coordinator. Commands and paths are trusted host configuration only. */
final class SystemUpdate
{
    /** Exact confirmations for the manual exits; neither ever overwrites a host file. */
    public const FINALIZE_CONFIRMATION = 'accept-current-files';
    public const ABANDON_CONFIRMATION = 'abandon-task';

    private array $settings;
    private string $root;
    private string $server;

    public function __construct()
    {
        $this->server = \SandSystemUpdateRuntime::normalizePath(base_path());
        $this->root = $this->server . '/runtime/system-update';
        $this->settings = config('plugin.sandpackage.system_update', []);
    }

    public function status(): array
    {
        $checks = $this->environmentChecks();
        $installed = $this->installed();
        $versions = array_column($installed, 'version', 'package');
        $releases = [];
        try {
            $releases = array_values(array_filter($this->releases(), static fn (array $release): bool =>
                isset($versions[$release['package']]) && version_compare($release['version'], $versions[$release['package']], '>')));
        }
        catch (Throwable $error) { $checks[] = $this->check('repository', '官方发行', false, $error->getMessage()); }
        return [
            'installed' => $installed, 'releases' => $releases,
            'active_task' => $this->latest(), 'checks' => $checks,
            'capabilities' => ['supported' => !$this->failed($checks), 'reason' => implode('；', array_column(array_filter($checks, static fn (array $check): bool => $check['state'] === 'fail'), 'message'))],
        ];
    }

    public function inspect(mixed $requested): array
    {
        $this->prepare();
        if (!is_array($requested) || !$requested || count($requested) > 2) throw new ApiException('请选择核心或插件管理器的固定版本', 400);
        $checks = $this->environmentChecks(); $targets = []; $seen = [];
        $available = $this->releases(true);
        $installed = array_column($this->installed(), 'version', 'package');
        foreach ($requested as $target) {
            if (!is_array($target) || !isset(\SandSystemUpdateRuntime::PACKAGES[$target['package'] ?? '']) || isset($seen[$target['package']]) || !is_string($target['version'] ?? null)) throw new ApiException('更新对象无效或重复', 400);
            $seen[$target['package']] = true; $match = null;
            foreach ($available as $release) if ($release['package'] === $target['package'] && $release['version'] === $target['version']) $match = $release;
            if ($match === null || !version_compare($target['version'], $installed[$target['package']] ?? '', '>')) throw new ApiException('版本未在官方受信更新发行中，或没有高于当前版本', 400);
            $targets[] = $match;
        }
        try { $plan = $this->plan($targets); }
        catch (Throwable $error) {
            // A silent can_start=false leaves the user without a reason to act on.
            $checks[] = $this->check('plan', '系统更新计划', false, \SandSystemUpdateRuntime::redact($error->getMessage()));
            return ['confirmation' => '', 'targets' => $this->publicTargets($targets), 'checks' => $checks, 'can_start' => false, 'expires_at' => time()];
        }
        $candidateHost = $installed['supdger/sand-core'] ?? '';
        foreach ($targets as $target) if ($target['package'] === 'supdger/sand-core') $candidateHost = $target['version'];
        foreach ($targets as $target) {
            $range = '>=' . $target['host_min'] . (isset($target['host_max']) && $target['host_max'] !== '' ? ' <=' . $target['host_max'] : '');
            $checks[] = $this->check('release-' . basename($target['package']), $target['name'] . ' 发行兼容', HostVersionCompatibility::matches($range, $candidateHost), '目标系统版本 ' . $candidateHost . '，发行要求 ' . $range);
        }
        foreach ((new PluginStorage())->runtimePlugins() as $app => $plugin) {
            if (in_array($app, ['sandadmin', 'sandpackage', 'saiadmin', 'saipackage'], true)) continue;
            $checks[] = $this->check('plugin-' . $app, ($plugin['title'] ?? $app) . ' 系统兼容', HostVersionCompatibility::matches($plugin['support'] ?? null, $candidateHost), '插件 ' . $app . ' 声明 ' . ($plugin['support'] ?? '缺失') . '，目标系统版本 ' . $candidateHost);
        }
        $handles = [];
        if (!$this->failed($checks)) {
            try {
                $handles = \SandSystemUpdateRuntime::locks($plan);
                $this->assertNoPending();
                \SandSystemUpdateRuntime::assertManagedSources($plan);
                SystemUpdateEnvironment::prepareStatic($plan);
                \SandSystemUpdateRuntime::assertManaged($plan);
                $checks[] = $this->check('managed', '本地文件与发布基线', true, '已管理文件无修改，静态目录无未知碰撞');
                \SandSystemUpdateRuntime::command(\SandSystemUpdateRuntime::composerArguments($plan, true), $this->server, static function (string $line): void {}, 120);
                $checks[] = $this->check('solver', '依赖兼容', true, '精确版本 Composer 求解通过，不执行安装钩子');
                $plan['fingerprint'] = \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($plan));
            } catch (Throwable $error) { $checks[] = $this->check('preflight', '系统升级预检', false, \SandSystemUpdateRuntime::redact($error->getMessage())); }
            finally { \SandSystemUpdateRuntime::unlock($handles); }
        }
        $confirmation = bin2hex(random_bytes(32));
        $expires = time() + 600;
        if (!$this->failed($checks)) \SandSystemUpdateRuntime::writeJson($this->root . '/plans/' . hash('sha256', $confirmation) . '.json', ['expires_at' => $expires, 'plan' => $plan]);
        return ['confirmation' => $confirmation, 'targets' => $this->publicTargets($targets), 'checks' => $checks, 'can_start' => !$this->failed($checks), 'expires_at' => $expires];
    }

    public function start(mixed $confirmation): array
    {
        if (!is_string($confirmation) || !preg_match('/^[a-f0-9]{64}$/D', $confirmation)) throw new ApiException('预检确认信息无效', 400);
        $this->prepare();
        $file = $this->root . '/plans/' . hash('sha256', $confirmation) . '.json';
        if (!is_file($file)) throw new ApiException('确认信息不存在或已使用，请重新检查', 400);
        $record = \SandSystemUpdateRuntime::readJson($file);
        $handles = \SandSystemUpdateRuntime::locks($record['plan']);
        try {
            $this->assertNoPending();
            $record = \SandSystemUpdateRuntime::readJson($file);
            if ($record['expires_at'] < time()) throw new ApiException('预检已过期，请重新检查', 400);
            // One scan of the managed scopes answers both the gate and the frozen plan.
            $fingerprint = \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($record['plan']));
            if (!hash_equals($record['plan']['fingerprint'], $fingerprint)) throw new ApiException('预检后系统文件已变化，请重新检查', 400);
            // Local deployment commands must not change between inspection and execution.
            $current = $this->plan($record['plan']['targets']);
            unset($record['plan']['fingerprint']);
            if ($current !== $record['plan']) throw new ApiException('系统更新配置已变化，请重新检查', 400);
            $record['plan']['fingerprint'] = $fingerprint;
            $id = bin2hex(random_bytes(16));
            $directory = $this->root . '/jobs/' . $id;
            if (!mkdir($directory, 0700)) throw new RuntimeException('无法创建系统更新任务');
            if (!copy(dirname(__DIR__, 2) . '/tools/system-update-worker.php', $directory . '/worker.php')) throw new RuntimeException('无法冻结独立执行器');
            chmod($directory . '/worker.php', 0600);
            $task = ['id' => $id, 'state' => 'queued', 'stage' => 'queued', 'targets' => $this->publicTargets($record['plan']['targets']), 'created_at' => time(), 'updated_at' => time(), 'logs' => [], 'error' => '', 'recovery_available' => false, 'mutated' => false];
            \SandSystemUpdateRuntime::writeJson($directory . '/plan.json', $record['plan']);
            \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $task);
            // Consume atomically under the same global lock before dispatch.
            if (!rename($file, $directory . '/confirmation.used.json')) throw new RuntimeException('无法消费预检确认信息');
            $this->launch($directory, 'run');
            return $this->publicTask($task);
        } finally { \SandSystemUpdateRuntime::unlock($handles); }
    }

    /** task.json plus its terminal-state sidecar; a failed record write must not read as running. */
    private function readTask(string $directory): array
    {
        $task = \SandSystemUpdateRuntime::readJson($directory . '/task.json');
        if (!is_file($directory . '/failed.json') || !in_array($task['state'] ?? '', ['queued', 'running', 'recovering'], true)) return $task;
        try { $sidecar = \SandSystemUpdateRuntime::readJson($directory . '/failed.json'); }
        catch (Throwable) { return $task; }
        foreach (['state', 'error', 'recovery_available', 'failure_fingerprint', 'updated_at'] as $key) if (isset($sidecar[$key])) $task[$key] = $sidecar[$key];
        return $task;
    }

    public function task(mixed $id): array
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new ApiException('系统更新任务编号无效', 400);
        $directory = $this->root . '/jobs/' . $id;
        if (!is_file($directory . '/task.json')) throw new ApiException('系统更新任务不存在', 400);
        $task = $this->readTask($directory);
        if (in_array($task['state'], ['queued', 'running', 'recovering'], true) && time() - $task['updated_at'] > 30) {
            $lock = @fopen($this->root . '/update.lock', 'r');
            if (is_resource($lock)) {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    try {
                        $task = $this->readTask($directory);
                        if (!in_array($task['state'], ['queued', 'running', 'recovering'], true)) return $this->publicTask($task);
                        $task['recovery_available'] = false;
                        if (!($task['mutated'] ?? false)) {
                            // Nothing was changed, so nothing needs recovering: a plain failure
                            // keeps the user one click away from checking updates again.
                            $task['state'] = 'failed';
                            $task['error'] = '独立升级进程在改动任何系统文件之前已中断，系统仍是原版本，可重新检查更新。' . \SandSystemUpdateRuntime::manualHint($directory);
                        } else {
                            $task['state'] = 'recovery_required';
                            $task['error'] = '独立升级进程已中断，请检查任务日志和备份后恢复。' . \SandSystemUpdateRuntime::manualHint($directory);
                            // No fingerprint of an unobserved crash is fabricated. Recovery refuses unexplained external changes.
                            if (isset($task['failure_fingerprint'])) $task['recovery_available'] = is_file($directory . '/backup.json');
                        }
                        \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $task);
                    } finally { flock($lock, LOCK_UN); }
                }
                fclose($lock);
            }
        }
        return $this->publicTask($task);
    }

    public function recover(mixed $id): array
    {
        $task = $this->task($id);
        $directory = $this->root . '/jobs/' . $id;
        if (!$task['recovery_available'] || !in_array($task['state'], ['failed', 'recovery_required'], true)) throw new ApiException('此任务没有可安全自动恢复的文件现场。' . $this->exitHint($directory), 400);
        $plan = \SandSystemUpdateRuntime::readJson($directory . '/plan.json');
        $handles = \SandSystemUpdateRuntime::locks($plan);
        try {
            foreach ($this->tasks() as $other) if ($other['id'] !== $id && in_array($other['state'], ['queued', 'running', 'recovering', 'recovery_required'], true)) throw new ApiException('另一个系统任务尚未收尾', 400);
            $raw = $this->readTask($directory);
            if (!in_array($raw['state'], ['failed', 'recovery_required'], true) || !($raw['recovery_available'] ?? false)) throw new ApiException('该任务已在恢复或已完成，请刷新状态', 400);
            if (!isset($raw['failure_fingerprint']) || !hash_equals($raw['failure_fingerprint'], \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($plan)))) {
                throw new ApiException('失败后受管理文件已被外部修改，自动恢复拒绝覆盖，请核对备份。' . $this->exitHint($directory), 400);
            }
            $raw['state'] = 'recovering'; $raw['updated_at'] = time();
            \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $raw);
            $this->launch($directory, 'recover');
            return $this->publicTask($raw);
        } finally { \SandSystemUpdateRuntime::unlock($handles); }
    }

    /**
     * Confirmed exit for a task automatic recovery refuses. It never overwrites a file: with
     * FINALIZE it only runs the finishing steps, with ABANDON it only closes the record, so a
     * fingerprint mismatch or a broken toolchain can no longer lock the host out of updating.
     */
    public function finalize(mixed $id, mixed $confirmation): array
    {
        $task = $this->task($id);
        $directory = $this->root . '/jobs/' . $id;
        if (!is_string($confirmation) || !in_array($confirmation, [self::FINALIZE_CONFIRMATION, self::ABANDON_CONFIRMATION], true)) {
            throw new ApiException('请输入精确确认文本：' . self::FINALIZE_CONFIRMATION . '（接受当前文件现场并只执行收尾）或 ' . self::ABANDON_CONFIRMATION . '（仅关闭任务记录，不执行任何命令）', 400);
        }
        if (!in_array($task['state'], ['failed', 'recovery_required'], true)) throw new ApiException('仅升级失败或需要恢复的任务可以人工收尾', 400);
        $plan = \SandSystemUpdateRuntime::readJson($directory . '/plan.json');
        $handles = \SandSystemUpdateRuntime::locks($plan);
        try {
            foreach ($this->tasks() as $other) if ($other['id'] !== $id && in_array($other['state'], ['queued', 'running', 'recovering'], true)) throw new ApiException('另一个系统任务尚未收尾', 400);
            $raw = $this->readTask($directory);
            if (!in_array($raw['state'], ['failed', 'recovery_required'], true)) throw new ApiException('该任务已在恢复或已完成，请刷新状态', 400);
            $previous = (string) ($raw['error'] ?? '');
            $raw['manual_finalize'] = ['confirmation' => $confirmation, 'admin' => 1, 'at' => time()];
            $raw['logs'][] = ['time' => time(), 'stage' => 'manual_finalize', 'message' => '超级管理员提交精确确认 ' . $confirmation . '：跳过文件覆盖'];
            $raw['logs'] = array_slice($raw['logs'], -500);
            if ($confirmation === self::ABANDON_CONFIRMATION) {
                $raw['state'] = 'abandoned';
                $raw['recovery_available'] = false;
                $raw['updated_at'] = time();
                $raw['error'] = '管理员确认放弃本任务，未执行任何文件或服务操作；原失败原因：' . ($previous !== '' ? $previous : '未记录') . '。备份保留在 ' . $directory . '/backup，' . \SandSystemUpdateRuntime::manualHint($directory);
                \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $raw);
                if (is_file($directory . '/failed.json')) unlink($directory . '/failed.json');
                return $this->publicTask($raw);
            }
            $raw['state'] = 'recovering';
            $raw['updated_at'] = time();
            \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $raw);
            if (is_file($directory . '/failed.json')) unlink($directory . '/failed.json');
            $this->launch($directory, 'finalize');
            return $this->publicTask($raw);
        } finally { \SandSystemUpdateRuntime::unlock($handles); }
    }

    private function exitHint(string $directory): string
    {
        return '仍可人工收尾：确认文本 ' . self::FINALIZE_CONFIRMATION . ' 跳过文件覆盖只执行收尾，' . self::ABANDON_CONFIRMATION . ' 仅关闭任务记录；' . \SandSystemUpdateRuntime::manualHint($directory);
    }

    private function launch(string $directory, string $mode): void
    {
        if (!in_array($mode, ['run', 'recover', 'finalize'], true)) throw new ApiException('系统更新执行模式无效', 400);
        $plan = \SandSystemUpdateRuntime::readJson($directory . '/plan.json');
        try {
            if (PHP_OS_FAMILY === 'Windows') \SandSystemUpdateRuntime::detachWindows($plan, $directory, $mode);
            else {
                $pipes = [];
                $process = proc_open([$plan['php'], $directory . '/worker.php', $directory, 'detach-' . $mode], [0 => ['file', \SandSystemUpdateRuntime::nullDevice(), 'r'], 1 => ['file', $directory . '/worker.log', 'a'], 2 => ['file', $directory . '/worker.log', 'a']], $pipes, $this->server, null, ['bypass_shell' => true]);
                if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('无法启动独立更新进程');
            }
        } catch (Throwable $error) {
            $task = \SandSystemUpdateRuntime::readJson($directory . '/task.json');
            $task['state'] = 'failed';
            $task['error'] = \SandSystemUpdateRuntime::redact($error->getMessage()) . '；' . $this->exitHint($directory);
            $task['updated_at'] = time();
            \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $task);
            throw new ApiException('无法启动独立更新进程，请检查 PHP CLI 与进程权限：' . \SandSystemUpdateRuntime::redact($error->getMessage()), 400);
        }
    }

    private function releases(bool $refresh = false): array
    {
        $cache = $this->root . '/releases.json';
        if (!$refresh && is_file($cache)) { $saved = \SandSystemUpdateRuntime::readJson($cache); if ($saved['time'] > time() - 300) return $saved['releases']; }
        $releases = [];
        foreach (\SandSystemUpdateRuntime::PACKAGES as $package => $plugin) {
            $repository = basename($package);
            $remote = $this->fetch('https://api.github.com/repos/supdger/' . $repository . '/releases?per_page=20', true);
            usort($remote, static fn (array $a, array $b): int => version_compare(ltrim($b['tag_name'], 'v'), ltrim($a['tag_name'], 'v')));
            foreach ($remote as $release) {
                if ($release['draft'] || $release['prerelease'] || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $release['tag_name'], $match)) continue;
                $contractAsset = null;
                foreach ($release['assets'] ?? [] as $asset) if ($asset['name'] === 'system-update.json') $contractAsset = $asset;
                if ($contractAsset === null) continue;
                $url = $contractAsset['browser_download_url'] ?? '';
                if (!str_starts_with($url, 'https://github.com/supdger/' . $repository . '/releases/download/')) throw new RuntimeException('官方发行契约地址无效');
                $contract = $this->fetch($url);
                self::validateContract($contract, $package, $match[1]);
                $releases[] = $contract + ['name' => $plugin === 'sandadmin' ? '系统' : '插件管理器', 'notes' => substr($release['body'] ?? '', 0, 8000)];
                break; // Latest stable eligible release per package.
            }
        }
        usort($releases, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        if (is_dir($this->root)) \SandSystemUpdateRuntime::writeJson($cache, ['time' => time(), 'releases' => $releases]);
        return $releases;
    }

    public static function validateContract(array $contract, string $package, string $version): void
    {
        if (($contract['format'] ?? null) !== 1 || ($contract['package'] ?? '') !== $package || ($contract['version'] ?? '') !== $version
            || ($contract['schema_changes'] ?? null) !== false || ($contract['skeleton_changes'] ?? null) !== false
            || !preg_match('/^[a-f0-9]{40}$/D', $contract['reference'] ?? '') || !preg_match('/^\d+\.\d+\.\d+$/D', $contract['host_min'] ?? '')
            || !is_array($contract['files'] ?? null) || !$contract['files']) throw new RuntimeException('发行缺少无数据库/系统骨架变更的有效更新契约');
        foreach ($contract['files'] as $path => $hash) {
            \SandSystemUpdateRuntime::relative($path);
            if (!preg_match('#^(server|sandadmin-artd|tools)/#', $path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('发行文件摘要清单无效');
        }
        if (isset($contract['host_max']) && $contract['host_max'] !== '' && (!preg_match('/^\d+\.\d+\.\d+$/D', $contract['host_max']) || version_compare($contract['host_max'], $contract['host_min'], '<'))) throw new RuntimeException('发行系统兼容范围无效');
    }

    private function fetch(string $url, bool $api = false): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL 不可用，无法读取官方发行');
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => !$api, CURLOPT_MAXREDIRS => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'SandPackage-SystemUpdate/0.2', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
        $body = curl_exec($handle); $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if (!is_string($body) || $code !== 200 || strlen($body) > 4194304) throw new RuntimeException('官方发行读取失败（HTTP ' . $code . '）' . ($error !== '' ? '：' . $error : ''));
        $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('官方发行响应格式无效');
        return $decoded;
    }

    private function plan(array $targets): array
    {
        $environment = SystemUpdateEnvironment::resolve($this->server, $this->settings, config('process', []), config('app.public_path', $this->server . '/public'), config('server.pid_file', $this->server . '/runtime/webman.pid'));
        $plan = [
            'server' => $this->server, 'root' => $this->root,
            'frontend' => $environment['frontend'],
            'static' => $environment['static'], 'storage' => (new PluginStorage())->root(),
            'php' => $this->settings['php'] ?? PHP_BINARY,
            'composer' => $this->settings['composer'] ?? ['composer'], 'pnpm' => $this->settings['pnpm'] ?? ['pnpm'],
            'reload' => $this->settings['reload'] ?? [], 'health' => $this->settings['health'] ?? [], 'targets' => $targets, 'pinned' => $this->pins(),
        ];
        if ($environment['deployment'] !== []) $plan['deployment'] = $environment['deployment'];
        if ($environment['frontend_base'] !== null) $plan['frontend_base'] = $environment['frontend_base'];
        if (PHP_OS_FAMILY === 'Windows') {
            $directories = array_values(array_filter(explode(PATH_SEPARATOR, getenv('PATH') ?: '')));
            foreach (['composer', 'pnpm'] as $tool) if ($plan[$tool] === [$tool]) $plan[$tool] = SystemUpdateEnvironment::nativeWindowsCommand($tool, $directories, $plan['php']);
        }
        foreach (['server', 'root', 'frontend', 'static', 'storage', 'php'] as $key) $plan[$key] = \SandSystemUpdateRuntime::normalizePath($plan[$key]);
        return $plan;
    }

    private function environmentChecks(): array
    {
        try { $plan = $this->plan([]); }
        catch (Throwable $error) { return [$this->check('deployment', '系统自动准备', false, $error->getMessage())]; }
        $native = function_exists('proc_open');
        $reason = '';
        if (PHP_OS_FAMILY === 'Windows') {
            try { \SandSystemUpdateRuntime::powershell(); }
            catch (Throwable $error) { $native = false; $reason = $error->getMessage(); }
        } else $native = $native && function_exists('pcntl_fork') && function_exists('pcntl_exec') && function_exists('posix_setsid') && function_exists('posix_kill');
        $checks = [$this->check('platform', '独立执行器', $native, $native ? '本机支持独立 PHP CLI 更新进程' : ($reason !== '' ? $reason : 'Windows 需要 proc_open 与 PowerShell；Linux/macOS 需要 proc_open、pcntl 和 posix'))];
        foreach (['reload' => '服务重载', 'health' => '健康检查', 'composer' => 'Composer', 'pnpm' => '前端构建'] as $key => $label) {
            $automatic = in_array($key, ['reload', 'health'], true) && $plan[$key] === [] && !empty($plan['deployment']);
            $ok = $automatic || (is_array($plan[$key]) && array_is_list($plan[$key]) && count($plan[$key]) > 0);
            $message = $automatic ? '系统自动执行' . $label : ($ok ? '系统已配置命令：' . implode(' ', $plan[$key]) : '系统未找到可执行的' . $label . '程序');
            // "Configured" is not "working": a crashing shim must fail here, not mid-upgrade.
            if ($ok && $native && !$automatic && in_array($key, ['composer', 'pnpm'], true)) {
                [$ok, $probe] = $this->probe($key, [...$plan[$key], '--version'], $key === 'pnpm' ? $plan['frontend'] : $plan['server']);
                $message = $ok ? '命令验活通过：' . implode(' ', $plan[$key]) . ' → ' . $probe : $label . '命令无法执行：' . $probe;
            }
            $checks[] = $this->check($key, $label, $ok, $message);
        }
        if ($native) {
            [$phpOk, $phpProbe] = $this->probe('php', [$plan['php'], '-v'], $this->server);
            $checks[] = $this->check('php', 'PHP CLI', $phpOk, $phpOk ? '命令验活通过：' . $plan['php'] . ' → ' . $phpProbe : 'PHP CLI 无法执行：' . $phpProbe);
        }
        try { SystemUpdateEnvironment::staticFiles($plan); $checks[] = $this->check('static', '静态发布目录', true, '系统自动核对并准备专用静态发布基线'); }
        catch (Throwable $error) { $checks[] = $this->check('static', '静态发布目录', false, \SandSystemUpdateRuntime::redact($error->getMessage())); }
        try { [$readyOk, $readyMessage] = SystemUpdateEnvironment::runtimeReadiness($this->server, $plan, config('static.enable')); }
        catch (Throwable $error) { $readyOk = false; $readyMessage = \SandSystemUpdateRuntime::redact($error->getMessage()); }
        $checks[] = $this->check('runtime', '重载与健康路径', $readyOk, $readyMessage);
        foreach (['frontend' => '前端源码', 'server' => '系统目录'] as $key => $label) $checks[] = $this->check($key, $label, is_dir($plan[$key]) && is_writable($plan[$key]), is_dir($plan[$key]) && is_writable($plan[$key]) ? '目录存在且可写' : $label . '不可写或不存在');
        return $checks;
    }

    private function pins(): array
    {
        $pins = [];
        foreach (\SandSystemUpdateRuntime::readJson($this->server . '/composer.lock')['packages'] ?? [] as $package) {
            if (!isset(\SandSystemUpdateRuntime::PACKAGES[$package['name']])) continue;
            $pins[$package['name']] = ['version' => ltrim($package['version'], 'v'), 'reference' => $package['source']['reference'] ?? '', 'files' => \SandSystemUpdateRuntime::tree($this->server . '/vendor/' . $package['name'], \SandSystemUpdateRuntime::VOLATILE)];
        }
        if (count($pins) !== 2) throw new RuntimeException('系统缺少完整基础包锁定版本');
        return $pins;
    }

    private function installed(): array
    {
        $versions = [];
        foreach (\SandSystemUpdateRuntime::readJson($this->server . '/composer.lock')['packages'] ?? [] as $package) {
            if (!isset(\SandSystemUpdateRuntime::PACKAGES[$package['name']])) continue;
            $versions[] = ['package' => $package['name'], 'name' => $package['name'] === 'supdger/sand-core' ? '系统' : '插件管理器', 'version' => ltrim($package['version'], 'v')];
        }
        return $versions;
    }

    private function prepare(): void
    {
        foreach ([$this->root, $this->root . '/plans', $this->root . '/jobs'] as $directory) {
            \SandSystemUpdateRuntime::safePath($directory);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new ApiException('更新状态目录不可写', 400);
            chmod($directory, 0700);
        }
    }

    /**
     * Bounded liveness probe with a short cache: three subprocesses must not be paid on every
     * status poll, while a repaired toolchain is re-checked within a minute.
     *
     * @return array{0: bool, 1: string}
     */
    private function probe(string $key, array $argv, string $cwd): array
    {
        $cache = $this->root . '/toolchain.json';
        $signature = hash('sha256', json_encode([$argv, $cwd, PHP_OS_FAMILY], JSON_THROW_ON_ERROR));
        $saved = [];
        if (is_file($cache)) { try { $saved = \SandSystemUpdateRuntime::readJson($cache); } catch (Throwable) { $saved = []; } }
        $entry = $saved[$key] ?? null;
        if (is_array($entry) && ($entry['signature'] ?? '') === $signature && is_int($entry['time'] ?? null)
            && $entry['time'] > time() - (($entry['ok'] ?? false) ? 300 : 60)) return [(bool) $entry['ok'], (string) ($entry['message'] ?? '')];
        try { $message = SystemUpdateEnvironment::probeTool($argv, is_dir($cwd) ? $cwd : $this->server); $ok = true; }
        catch (Throwable $error) { $ok = false; $message = \SandSystemUpdateRuntime::redact($error->getMessage()); }
        $saved[$key] = ['signature' => $signature, 'ok' => $ok, 'message' => $message, 'time' => time()];
        if (is_dir($this->root)) { try { \SandSystemUpdateRuntime::writeJson($cache, $saved); } catch (Throwable) {} }
        return [$ok, $message];
    }

    private function tasks(): array
    {
        $tasks = [];
        foreach (glob($this->root . '/jobs/*/task.json') ?: [] as $file) $tasks[] = $this->readTask(dirname($file));
        usort($tasks, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return $tasks;
    }

    private function latest(): ?array
    {
        $tasks = $this->tasks();
        foreach ($tasks as $task) if (in_array($task['state'], ['queued', 'running', 'recovering', 'recovery_required'], true) || ($task['state'] === 'failed' && $task['recovery_available'])) return $this->task($task['id']);
        return isset($tasks[0]) ? $this->task($tasks[0]['id']) : null;
    }

    private function assertNoPending(): void
    {
        foreach ($this->tasks() as $task) if (in_array($task['state'], ['queued', 'running', 'recovering', 'recovery_required'], true) || ($task['state'] === 'failed' && $task['recovery_available'])) throw new ApiException('已有系统更新任务尚未收尾，请先查看或恢复', 400);
    }

    private function check(string $code, string $label, bool $ok, string $message): array { return ['code' => $code, 'label' => $label, 'state' => $ok ? 'ok' : 'fail', 'message' => $message]; }
    private function failed(array $checks): bool { foreach ($checks as $check) if ($check['state'] === 'fail') return true; return false; }
    private function publicTargets(array $targets): array { return array_map(static fn (array $target): array => array_intersect_key($target, array_flip(['package', 'name', 'version', 'notes'])), $targets); }
    /** The confirmed manual exits stay visible for every task the automatic path cannot finish. */
    private function publicTask(array $task): array
    {
        $task['finalize_available'] = in_array($task['state'] ?? '', ['failed', 'recovery_required'], true);
        $task['finalize_confirmation'] = self::FINALIZE_CONFIRMATION;
        $task['abandon_confirmation'] = self::ABANDON_CONFIRMATION;
        return array_intersect_key($task, array_flip(['id', 'state', 'stage', 'targets', 'created_at', 'updated_at', 'logs', 'error', 'recovery_available', 'finalize_available', 'finalize_confirmation', 'abandon_confirmation']));
    }
}
