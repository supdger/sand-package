<?php
declare(strict_types=1);
namespace plugin\sandpackage\app\service;

use plugin\sandadmin\exception\ApiException;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2) . '/tools/system-update-worker.php';

/** Super-admin API coordinator. Commands and paths are trusted host configuration only. */
final class SystemUpdate
{
    private array $settings;
    private string $root;
    private string $server;

    public function __construct()
    {
        $this->server = rtrim(base_path(), '/');
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
        $plan = $this->plan($targets);
        $candidateHost = $installed['supdger/sand-core'] ?? '';
        foreach ($targets as $target) if ($target['package'] === 'supdger/sand-core') $candidateHost = $target['version'];
        foreach ($targets as $target) {
            $range = '>=' . $target['host_min'] . (isset($target['host_max']) && $target['host_max'] !== '' ? ' <=' . $target['host_max'] : '');
            $checks[] = $this->check('release-' . basename($target['package']), $target['name'] . ' 发行兼容', HostVersionCompatibility::matches($range, $candidateHost), '目标宿主 ' . $candidateHost . '，发行要求 ' . $range);
        }
        foreach ((new PluginStorage())->runtimePlugins() as $app => $plugin) {
            if (in_array($app, ['sandadmin', 'sandpackage', 'saiadmin', 'saipackage'], true)) continue;
            $checks[] = $this->check('plugin-' . $app, ($plugin['title'] ?? $app) . ' 宿主兼容', HostVersionCompatibility::matches($plugin['support'] ?? null, $candidateHost), '插件 ' . $app . ' 声明 ' . ($plugin['support'] ?? '缺失') . '，目标宿主 ' . $candidateHost);
        }
        $handles = [];
        if (!$this->failed($checks)) {
            try {
                $handles = \SandSystemUpdateRuntime::locks($plan);
                $this->assertNoPending();
                \SandSystemUpdateRuntime::assertManaged($plan);
                $checks[] = $this->check('managed', '本地文件与发布基线', true, '已管理文件无修改，静态目录无未知碰撞');
                \SandSystemUpdateRuntime::command(\SandSystemUpdateRuntime::composerArguments($plan, true), $this->server, static function (string $line): void {}, 120);
                $checks[] = $this->check('solver', '依赖兼容', true, '精确版本 Composer 求解通过，不执行安装钩子');
                $plan['fingerprint'] = \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($plan));
            } catch (Throwable $error) { $checks[] = $this->check('preflight', '宿主升级预检', false, $error->getMessage()); }
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
            if (!hash_equals($record['plan']['fingerprint'], \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($record['plan'])))) throw new ApiException('预检后宿主文件已变化，请重新检查', 400);
            // Local deployment commands must not change between inspection and execution.
            $current = $this->plan($record['plan']['targets']);
            unset($record['plan']['fingerprint']);
            if ($current !== $record['plan']) throw new ApiException('宿主更新配置已变化，请重新检查', 400);
            $record['plan']['fingerprint'] = \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($current));
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
            $this->launch($directory, false);
            return $this->publicTask($task);
        } finally { \SandSystemUpdateRuntime::unlock($handles); }
    }

    public function task(mixed $id): array
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) throw new ApiException('系统更新任务编号无效', 400);
        $directory = $this->root . '/jobs/' . $id;
        if (!is_file($directory . '/task.json')) throw new ApiException('系统更新任务不存在', 400);
        $task = \SandSystemUpdateRuntime::readJson($directory . '/task.json');
        if (in_array($task['state'], ['queued', 'running', 'recovering'], true) && time() - $task['updated_at'] > 30) {
            $lock = @fopen($this->root . '/update.lock', 'r');
            if (is_resource($lock)) {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    try {
                        $task = \SandSystemUpdateRuntime::readJson($directory . '/task.json');
                        if (!in_array($task['state'], ['queued', 'running', 'recovering'], true)) return $this->publicTask($task);
                        $task['state'] = 'recovery_required';
                        $task['error'] = '独立升级进程已中断，请检查任务日志和备份后恢复';
                        $task['recovery_available'] = false;
                        // No fingerprint of an unobserved crash is fabricated. Recovery refuses unexplained external changes.
                        if (isset($task['failure_fingerprint'])) $task['recovery_available'] = is_file($directory . '/backup.json');
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
        if (!$task['recovery_available'] || !in_array($task['state'], ['failed', 'recovery_required'], true)) throw new ApiException('此任务没有可安全自动恢复的文件现场', 400);
        $directory = $this->root . '/jobs/' . $id;
        $plan = \SandSystemUpdateRuntime::readJson($directory . '/plan.json');
        $handles = \SandSystemUpdateRuntime::locks($plan);
        try {
            foreach ($this->tasks() as $other) if ($other['id'] !== $id && in_array($other['state'], ['queued', 'running', 'recovering', 'recovery_required'], true)) throw new ApiException('另一个系统任务尚未收尾', 400);
            $raw = \SandSystemUpdateRuntime::readJson($directory . '/task.json');
            if (!in_array($raw['state'], ['failed', 'recovery_required'], true) || !($raw['recovery_available'] ?? false)) throw new ApiException('该任务已在恢复或已完成，请刷新状态', 400);
            if (!isset($raw['failure_fingerprint']) || !hash_equals($raw['failure_fingerprint'], \SandSystemUpdateRuntime::fingerprint(\SandSystemUpdateRuntime::scopes($plan)))) throw new ApiException('失败后文件已被外部修改，拒绝覆盖，请核对备份', 400);
            $raw['state'] = 'recovering'; $raw['updated_at'] = time();
            \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $raw);
            $this->launch($directory, true);
            return $this->publicTask($raw);
        } finally { \SandSystemUpdateRuntime::unlock($handles); }
    }

    private function launch(string $directory, bool $recover): void
    {
        $plan = \SandSystemUpdateRuntime::readJson($directory . '/plan.json');
        $pipes = [];
        $process = proc_open([$plan['php'], $directory . '/worker.php', $directory, $recover ? 'detach-recover' : 'detach-run'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/worker.log', 'a'], 2 => ['file', $directory . '/worker.log', 'a']], $pipes, $this->server, null, ['bypass_shell' => true]);
        if (!is_resource($process) || proc_close($process) !== 0) {
            $task = \SandSystemUpdateRuntime::readJson($directory . '/task.json');
            $task['state'] = 'failed'; $task['error'] = '无法启动独立更新进程';
            \SandSystemUpdateRuntime::writeJson($directory . '/task.json', $task);
            throw new ApiException('无法启动独立更新进程，请检查 PHP CLI 与进程权限', 400);
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
                $releases[] = $contract + ['name' => $plugin === 'sandadmin' ? '宿主核心' : '插件管理器', 'notes' => substr($release['body'] ?? '', 0, 8000)];
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
            || !is_array($contract['files'] ?? null) || !$contract['files']) throw new RuntimeException('发行缺少无数据库/宿主骨架变更的有效更新契约');
        foreach ($contract['files'] as $path => $hash) {
            \SandSystemUpdateRuntime::relative($path);
            if (!preg_match('#^(server|sandadmin-artd|tools)/#', $path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('发行文件摘要清单无效');
        }
        if (isset($contract['host_max']) && $contract['host_max'] !== '' && (!preg_match('/^\d+\.\d+\.\d+$/D', $contract['host_max']) || version_compare($contract['host_max'], $contract['host_min'], '<'))) throw new RuntimeException('发行宿主兼容范围无效');
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
        return [
            'server' => $this->server, 'root' => $this->root,
            'frontend' => $this->settings['frontend'] ?? dirname($this->server) . '/sandadmin-artd',
            'static' => $this->settings['static'] ?? '', 'storage' => (new PluginStorage())->root(),
            'php' => $this->settings['php'] ?? PHP_BINARY,
            'composer' => $this->settings['composer'] ?? ['composer'], 'pnpm' => $this->settings['pnpm'] ?? ['pnpm'],
            'reload' => $this->settings['reload'] ?? [], 'health' => $this->settings['health'] ?? [], 'targets' => $targets, 'pinned' => $this->pins(),
        ];
    }

    private function environmentChecks(): array
    {
        $plan = $this->plan([]);
        $native = PHP_OS_FAMILY !== 'Windows' && function_exists('proc_open') && function_exists('pcntl_fork') && function_exists('pcntl_exec') && function_exists('posix_setsid') && function_exists('posix_kill');
        $checks = [$this->check('platform', '独立执行器', $native, $native ? '本机支持独立 PHP CLI 更新进程' : '需要 Linux/macOS PHP CLI、proc_open、pcntl 和 posix；当前平台暂不支持')];
        foreach (['reload' => '服务重载', 'health' => '健康检查', 'composer' => 'Composer', 'pnpm' => '前端构建'] as $key => $label) {
            $ok = is_array($plan[$key]) && array_is_list($plan[$key]) && count($plan[$key]) > 0;
            $checks[] = $this->check($key, $label, $ok, $ok ? '管理员固定命令已配置' : '请先在宿主 config/sand_system_update.php 配置 ' . $key);
        }
        $checks[] = $this->check('static', '静态发布目录', is_string($plan['static']) && $plan['static'] !== '' && is_file($plan['static'] . '/' . \SandSystemUpdateRuntime::STATIC_MANIFEST), '需要管理员配置专用静态目录，并用 prepare-system-update.php 核对现有 dist 后建立基线');
        foreach (['frontend' => '前端源码', 'server' => '宿主目录'] as $key => $label) $checks[] = $this->check($key, $label, is_dir($plan[$key]) && is_writable($plan[$key]), is_dir($plan[$key]) && is_writable($plan[$key]) ? '目录存在且可写' : $label . '不可写或不存在');
        return $checks;
    }

    private function pins(): array
    {
        $pins = [];
        foreach (\SandSystemUpdateRuntime::readJson($this->server . '/composer.lock')['packages'] ?? [] as $package) {
            if (!isset(\SandSystemUpdateRuntime::PACKAGES[$package['name']])) continue;
            $pins[$package['name']] = ['version' => ltrim($package['version'], 'v'), 'reference' => $package['source']['reference'] ?? '', 'files' => \SandSystemUpdateRuntime::tree($this->server . '/vendor/' . $package['name'])];
        }
        if (count($pins) !== 2) throw new RuntimeException('宿主缺少完整基础包锁定版本');
        return $pins;
    }

    private function installed(): array
    {
        $versions = [];
        foreach (\SandSystemUpdateRuntime::readJson($this->server . '/composer.lock')['packages'] ?? [] as $package) {
            if (!isset(\SandSystemUpdateRuntime::PACKAGES[$package['name']])) continue;
            $versions[] = ['package' => $package['name'], 'name' => $package['name'] === 'supdger/sand-core' ? '宿主核心' : '插件管理器', 'version' => ltrim($package['version'], 'v')];
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

    private function tasks(): array
    {
        $tasks = [];
        foreach (glob($this->root . '/jobs/*/task.json') ?: [] as $file) $tasks[] = \SandSystemUpdateRuntime::readJson($file);
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
    private function publicTask(array $task): array { return array_intersect_key($task, array_flip(['id', 'state', 'stage', 'targets', 'created_at', 'updated_at', 'logs', 'error', 'recovery_available'])); }
}
