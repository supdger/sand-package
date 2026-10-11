<?php

namespace plugin\sandpackage\app\service;

use Generator;
use plugin\sandpackage\app\logic\InstallLogic;
use support\Log;
use Tinywan\Jwt\JwtToken;
use Throwable;

/**
 * SandPackage-owned bridge for the two host-configured dependency tasks.
 *
 * The request chooses a fixed configuration key only. It cannot provide a
 * command, argument, environment, or working directory.
 */
final class TerminalRunner
{
    /** @var array<string,array{argv:list<string>,callback:'npm'|'composer'}> */
    private const ALLOWED = [
        'web-install.npm' => ['argv' => ['npm', 'install'], 'callback' => 'npm'],
        'web-install.yarn' => ['argv' => ['yarn', 'install'], 'callback' => 'npm'],
        'web-install.pnpm' => ['argv' => ['pnpm', 'install'], 'callback' => 'npm'],
        'composer.update' => ['argv' => ['composer', 'update', '--no-interaction'], 'callback' => 'composer'],
    ];

    private const MAX_OUTPUT_BYTES = 32768;
    private const MAX_OUTPUT_FRAMES = 512;
    private const DEFAULT_MAX_RUNTIME_SECONDS = 1800;
    private const CHILD_LAUNCHER = <<<'PHP'
if (!function_exists('posix_setsid') || !function_exists('pcntl_exec') || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) { fwrite(STDERR, "SandPackage process supervisor support unavailable\n"); exit(126); }
$args = array_slice($_SERVER['argv'], 1);
$allowed = ['npm' => true, 'yarn' => true, 'pnpm' => true, 'composer' => true];
if (!$args || !isset($allowed[$args[0]])) { exit(126); }
if (posix_setsid() < 0) { exit(126); }
$control = fopen('php://fd/3', 'r'); $report = fopen('php://fd/4', 'w');
if (!is_resource($control) || !is_resource($report)) { exit(126); }
$emit = static function (array $frame) use ($report): void { fwrite($report, json_encode($frame, JSON_UNESCAPED_SLASHES) . "\n"); fflush($report); };
$pgid = posix_getpgrp(); $emit(['event' => 'ready', 'launcher_pid' => getmypid(), 'pgid' => $pgid, 'descendant_pids' => [], 'time' => time()]);
if (trim((string) fgets($control)) !== 'GO') { exit(125); }
$worker = pcntl_fork();
if ($worker === -1) { $emit(['event' => 'failure', 'descendant_pids' => [], 'time' => time()]); exit(126); }
if ($worker === 0) { $path = getenv('PATH') ?: ''; foreach (explode(PATH_SEPARATOR, $path) as $dir) { $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $args[0]; if (is_file($candidate) && is_executable($candidate)) { pcntl_exec($candidate, array_slice($args, 1), getenv()); exit(127); } } exit(127); }
$known = []; do { $rows = shell_exec('/bin/ps -axo pid=,pgid= 2>/dev/null') ?: ''; foreach (preg_split('/\R/', $rows) as $row) { if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $row, $m) === 1 && (int) $m[2] === $pgid && (int) $m[1] !== getmypid()) { $known[(int) $m[1]] = (int) $m[1]; } } $emit(['event' => 'tracked', 'descendant_pids' => array_values($known), 'time' => time()]); $wait = pcntl_waitpid($worker, $status, WNOHANG); if ($wait === $worker) { break; } usleep(50000); } while (true);
$emit(['event' => 'exited', 'descendant_pids' => array_values($known), 'time' => time(), 'exit_code' => pcntl_wexitstatus($status)]); exit(pcntl_wexitstatus($status));
PHP;

    private ?InstallLogic $install = null;
    private ?string $callback = null;
    private ?string $nonce = null;
    private $process = null;
    private bool $compensated = false;
    private bool $terminalCompleted = false;
    private bool $businessFinalized = false;
    private bool $abortRequested = false;
    private bool $processRecoveryRequired = false;
    private ?int $processPid = null;
    private ?int $processGroupId = null;
    /** @var resource|null */
    private $launcherControl = null;
    /** @var resource|null */
    private $launcherReports = null;
    /** @var list<int> */
    private array $trackedProcessIds = [];
    private bool $processJournalStarted = false;
    private int $outputRemaining = self::MAX_OUTPUT_BYTES;
    private int $outputFrames = 0;

    public function exec(): Generator
    {
        $completed = false;
        $key = (string) request()->input('command', '');
        $extend = (string) request()->input('extend', '');
        $command = self::allowedCommand($key);
        $appName = $this->moduleFromExtend($extend);
        if (!$this->isSuperAdmin() || $command === null || $appName === null) {
            yield $this->output('命令不可用、确认信息不完整或无操作权限');
            yield $this->output('exec-error');
            $this->terminalCompleted = true;
            yield $this->output('exec-completed');
            return;
        }

        $this->callback = $command['callback'];
        $stage = 'dependency_init';
        $startedAt = hrtime(true);
        try {
            $this->install = new InstallLogic($appName);
            $stage = 'runtime_support';
            if (!self::canLaunchProcessGroup()) {
                throw new \RuntimeException('dependency process-group support is unavailable');
            }
            $stage = 'dependency_begin';
            $this->nonce = $this->install->beginDependencyCommand($this->callback);
            $stage = 'execution_lock';
            $this->install->acquireDependencyExecutionLock($this->callback, $this->nonce);
            $pipes = [];
            // Only inherit the host process environment; request data never
            // supplies environment values for these fixed dependency commands.
            $stage = 'process_spawn';
            $this->process = @proc_open($this->groupLauncherCommand($command['argv']), [1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r'], 4 => ['pipe', 'w']], $pipes, $command['cwd'], null);
            if (!is_resource($this->process)) {
                throw new \RuntimeException('dependency process did not start');
            }
            $stage = 'supervisor';
            $status = proc_get_status($this->process);
            $this->processPid = is_int($status['pid'] ?? null) ? $status['pid'] : null;
            $this->launcherControl = $pipes[3] ?? null;
            $this->launcherReports = $pipes[4] ?? null;
            if (!is_resource($this->launcherControl) || !is_resource($this->launcherReports)) {
                throw new \RuntimeException('dependency process supervisor IPC was unavailable');
            }
            stream_set_blocking($this->launcherReports, false);
            $groupDeadline = hrtime(true) + 1000000000;
            do {
                $groupId = ($this->processPid !== null && function_exists('posix_getpgid')) ? posix_getpgid($this->processPid) : false;
                if (is_int($groupId) && $groupId === $this->processPid) {
                    $this->processGroupId = $groupId;
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $groupDeadline);
            if ($this->processGroupId === null) {
                // Startup never isolated: signal only this known child, never
                // its inherited parent process group.
                @proc_terminate($this->process);
                @proc_close($this->process);
                $this->process = null;
                throw new \RuntimeException('dependency process group was not isolated');
            }
            $ready = $this->waitForLauncherReady();
            if ($ready === null || $ready['launcher_pid'] !== $this->processPid || $ready['pgid'] !== $this->processGroupId) {
                throw new \RuntimeException('dependency process supervisor did not report an isolated launcher');
            }
            $this->install->recordDependencyProcessStarted($this->callback, $this->nonce, $this->processPid, $this->processGroupId, [], $ready['time']);
            $this->processJournalStarted = true;
            if (fwrite($this->launcherControl, "GO\n") !== 3 || !fflush($this->launcherControl)) {
                throw new \RuntimeException('dependency process supervisor did not accept launch confirmation');
            }
            fclose($this->launcherControl);
            $this->launcherControl = null;
            foreach ([1, 2] as $descriptor) {
                $pipe = $pipes[$descriptor] ?? null;
                if (is_resource($pipe)) {
                    stream_set_blocking($pipe, false);
                }
            }
            $stage = 'stream';
            yield $this->output('connection-success');

            $exitCode = 1;
            $deadline = hrtime(true) + ($this->maximumRuntimeSeconds() * 1000000000);
            try {
                while (true) {
                    if ($this->abortRequested || !is_resource($this->process)) {
                        throw new \RuntimeException('dependency command was aborted before it was reaped');
                    }
                    if (hrtime(true) >= $deadline) {
                        throw new \RuntimeException('dependency command exceeded its wall-clock time limit');
                    }
                    if (!$this->clientIsConnected()) {
                        throw new \RuntimeException('client disconnected');
                    }
                    $this->drainLauncherReports();
                    if ($this->processRecoveryRequired) {
                        throw new \RuntimeException('dependency process supervision record could not be persisted');
                    }
                    foreach ([1, 2] as $descriptor) {
                        $pipe = $pipes[$descriptor] ?? null;
                        $chunk = stream_get_contents($pipe);
                        if (is_string($chunk) && $chunk !== '') {
                            $output = $this->limitOutput($chunk);
                            if ($output === null) {
                                throw new \RuntimeException('dependency command output exceeded the session limit');
                            }
                            if ($output === '') {
                                continue;
                            }
                            yield $this->output($output);
                        }
                    }
                    $status = proc_get_status($this->process);
                    if (!$status['running']) {
                        $exitCode = (int) $status['exitcode'];
                        break;
                    }
                    usleep(100000);
                }
            } catch (Throwable $e) {
                $this->terminateAndReap();
                throw $e;
            } finally {
                foreach ([1, 2] as $descriptor) {
                    $pipe = $pipes[$descriptor] ?? null;
                    if (is_resource($pipe) && $pipe !== $this->launcherReports) {
                        fclose($pipe);
                    }
                }
                $closeCode = (!$this->processRecoveryRequired && !$this->processJournalStarted && is_resource($this->process)) ? proc_close($this->process) : 1;
                if (!$this->processRecoveryRequired && !$this->processJournalStarted) {
                    $this->process = null;
                }
                if ($exitCode === -1 && is_int($closeCode)) {
                    $exitCode = $closeCode;
                }
            }
            $stage = 'process_exit';
            if ($exitCode !== 0) {
                throw new \RuntimeException('dependency process returned a non-zero exit status');
            }
            $stage = 'process_cleanup';
            if (!$this->terminateAndReap()) {
                throw new \RuntimeException('dependency process group could not be confirmed reaped');
            }

            $stage = 'completion';
            $result = $this->install->dependentInstallComplete($this->callback, $this->nonce, $this->callback === 'composer');
            if (($result['advanced'] ?? false) !== true) {
                throw new \RuntimeException('dependency command did not advance its installation state');
            }
            $this->businessFinalized = true;
            $completed = true;
            yield $this->output('exec-success');
        } catch (Throwable $error) {
            $incident = $this->recordFailure($error, $stage, $appName, $key, $startedAt);
            yield $this->output('依赖命令未完成（阶段：' . $stage . '，记录号：' . $incident . '）。请管理员按记录号查看宿主日志并检查安装状态；未声明回滚插件文件或数据库');
            yield $this->output('exec-error');
        } finally {
            $reaped = $this->terminateAndReap();
            if (!$completed && $reaped) {
                $this->compensate();
            }
            if ($reaped && $this->install !== null) {
                $this->install->releaseDependencyExecutionLock();
                $this->install->releaseDependencyCommand();
            }
        }
        $this->terminalCompleted = true;
        yield $this->output('exec-completed');
    }

    /** Called by the SSE controller if sending a response fails. */
    public function abort(): void
    {
        if ($this->terminalCompleted) {
            return;
        }
        if ($this->businessFinalized) {
            $this->recordDeliveryFailure();
            return;
        }
        $this->abortRequested = true;
        if (!$this->terminateAndReap()) {
            // Retain the durable app/host lease and execution flock. A later
            // recovery entry must prove the child is gone before replacing it.
            return;
        }
        $this->compensate();
        if ($this->install !== null) {
            $this->install->releaseDependencyExecutionLock();
            $this->install->releaseDependencyCommand();
        }
    }

    public function isTerminalCompleted(): bool
    {
        return $this->terminalCompleted;
    }

    public function isBusinessFinalized(): bool
    {
        return $this->businessFinalized;
    }

    public function recordDeliveryFailure(): void
    {
        error_log('SandPackage dependency command completed but its terminal response was not delivered');
    }

    /** Read-only hints from this worker; never tests a lock, write or process launch. */
    public static function inspectStart(string $commandKey): array
    {
        if (!isset(self::ALLOWED[$commandKey])) {
            throw new \plugin\sandadmin\exception\ApiException('依赖命令不可用', 400);
        }
        $functions = [];
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'posix_setsid', 'posix_getpgid', 'posix_kill',
            'pcntl_exec', 'pcntl_fork', 'pcntl_waitpid', 'posix_getpgrp', 'shell_exec', 'flock', 'fsync'] as $function) {
            $functions[$function] = function_exists($function);
        }
        $command = self::allowedCommand($commandKey);
        return [
            'operation' => 'dependency-start-inspection',
            'readonly_only' => true,
            'command_key' => $commandKey,
            'worker_pid' => getmypid(),
            'php_sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'functions' => $functions,
            'configuration_valid' => $command !== null,
            'workdir_readable' => $command !== null ? is_readable($command['cwd']) : null,
            'workdir_writable_hint' => $command !== null ? is_writable($command['cwd']) : null,
            'child_runtime_support' => 'unknown',
            'lock_acquisition' => 'unknown',
            'durable_write' => 'unknown',
            'process_launch' => 'unknown',
            'next_action' => '仅检查当前 worker 的能力和配置提示；实际锁、登记写入与进程启动仍未执行验证',
        ];
    }

    private function recordFailure(Throwable $error, string $stage, string $app, string $commandKey, int $startedAt): string
    {
        try {
            $incident = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $incident = 'unavailable';
        }
        $package = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $file = $error->getFile();
        $exceptionType = get_class($error);
        $context = [
            'incident' => $incident,
            'stage' => $stage,
            'app' => $app,
            'command_key' => $commandKey,
            'worker_pid' => getmypid(),
            'elapsed_ms' => (int) ((hrtime(true) - $startedAt) / 1000000),
            'exception_type' => preg_match('/^[a-zA-Z_][a-zA-Z0-9_\\\\]{0,191}$/D', $exceptionType) === 1 ? $exceptionType : 'Throwable',
            'source' => str_starts_with($file, $package) ? substr($file, strlen($package)) : 'external',
            'source_line' => $error->getLine(),
        ];
        try {
            Log::error('SandPackage dependency command failed', $context);
        } catch (Throwable) {
            try {
                error_log('SandPackage dependency command failed ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                // Diagnostics must not replace the command failure or its cleanup.
            }
        }
        return $incident;
    }

    /** @return array{argv:list<string>,cwd:string,callback:'npm'|'composer'}|null */
    public static function allowedCommand(string $key): ?array
    {
        $expected = self::ALLOWED[$key] ?? null;
        if ($expected === null || !str_contains($key, '.')) {
            return null;
        }
        [$group, $name] = explode('.', $key, 2);
        $configured = config('plugin.sandpackage.terminal.commands', [])[$group][$name] ?? null;
        if (!is_array($configured) || !is_string($configured['cwd'] ?? null) || !is_string($configured['command'] ?? null)
            || $configured['command'] !== implode(' ', $expected['argv']) || str_contains($configured['cwd'], '..')
            || is_link($configured['cwd'])) {
            return null;
        }
        $projectRoot = realpath(dirname(base_path()));
        $expectedPath = $group === 'composer'
            ? dirname(base_path()) . DIRECTORY_SEPARATOR . 'server'
            : dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd');
        $cwd = realpath($configured['cwd']);
        $allowedRoot = realpath($expectedPath);
        if ($cwd === false || $allowedRoot === false || $cwd !== $allowedRoot || $projectRoot === false
            || !self::hasTrustedPathComponents($projectRoot, $expectedPath)) {
            return null;
        }
        return ['argv' => $expected['argv'], 'cwd' => $cwd, 'callback' => $expected['callback']];
    }

    private static function hasTrustedPathComponents(string $projectRoot, string $path): bool
    {
        if (is_link($projectRoot) || !str_starts_with($path, $projectRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }
        $relative = substr($path, strlen($projectRoot) + 1);
        $current = $projectRoot;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return false;
            }
            $current .= DIRECTORY_SEPARATOR . $component;
            if (is_link($current) || !is_dir($current)) {
                return false;
            }
        }
        return true;
    }

    public static function safeOutput(string $output): string
    {
        $output = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? '';
        $output = preg_replace('/(?i)(["\']authorization["\']\s*:\s*)(["\'])[^"\']*\2/', '$1$2[已隐藏]$2', $output) ?? '';
        $output = preg_replace('/(?im)\bauthorization\s*[:=][^\r\n]*/', 'authorization=[已隐藏]', $output) ?? '';
        $output = preg_replace('/(?i)\bBearer\s+[A-Za-z0-9._~+\/-]+=*/', 'Bearer [已隐藏]', $output) ?? '';
        $output = preg_replace('/(?i)\b(authorization|password|token|secret|key|uuid)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,}]+)/', '$1=[已隐藏]', $output) ?? '';
        $output = preg_replace('/(?i)"(authorization|password|token|secret|key|uuid)"\s*:\s*"[^"]*"/', '"$1":"[已隐藏]"', $output) ?? '';
        $output = preg_replace('#(?:/Users|/private|/tmp|[A-Za-z]:\\\\)[^\s:]+#', '[本地路径]', $output) ?? '';
        return trim($output);
    }

    private function isSuperAdmin(): bool
    {
        try {
            $authorization = (string) request()->header('authorization', '');
            if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
                return false;
            }
            $extend = JwtToken::verify(1, $matches[1])['extend'] ?? [];
            return is_array($extend) && ($extend['id'] ?? null) === 1 && ($extend['plat'] ?? null) === 'sandadmin';
        } catch (Throwable) {
            return false;
        }
    }

    private function clientIsConnected(): bool
    {
        $connection = request()->connection ?? null;
        return is_object($connection) && (!isset($connection->status) || $connection->status !== 16);
    }

    private function moduleFromExtend(string $extend): ?string
    {
        return preg_match('/^module-install:([a-z][a-z0-9-]{1,63})$/', $extend, $matches) ? $matches[1] : null;
    }

    private function compensate(): void
    {
        if ($this->compensated || $this->install === null || $this->callback === null || $this->nonce === null) {
            return;
        }
        $this->compensated = true;
        try {
            $this->install->dependencyCommandFailed($this->callback, $this->nonce);
        } catch (Throwable) {
            // The lifecycle logic has already recorded a safe failure state or
            // refused an obsolete task. Never turn this into a second command.
        }
    }

    private function terminateAndReap(): bool
    {
        if (!is_resource($this->process)) {
            return !$this->processJournalStarted;
        }
        if ($this->processGroupId === null || !function_exists('posix_kill')) {
            $this->processRecoveryRequired = true;
            return false;
        }
        $this->drainLauncherReports();
        @posix_kill(-$this->processGroupId, SIGTERM);
        $deadline = hrtime(true) + 2000000000;
        while ((($status = proc_get_status($this->process))['running'] || @posix_kill(-$this->processGroupId, 0)) && hrtime(true) < $deadline) {
            usleep(50000);
        }
        // The launcher may exit on TERM before its TERM-resistant descendants.
        // Escalation must cover the isolated group, not only the launcher.
        if (($status ?? proc_get_status($this->process))['running'] || @posix_kill(-$this->processGroupId, 0)) {
            @posix_kill(-$this->processGroupId, SIGKILL);
            $deadline = hrtime(true) + 2000000000;
            while ((($status = proc_get_status($this->process))['running'] || @posix_kill(-$this->processGroupId, 0)) && hrtime(true) < $deadline) {
                usleep(50000);
            }
        }
        $this->drainLauncherReports();
        if (($status ?? proc_get_status($this->process))['running'] || !$this->trackedProcessesAreGone()) {
            $this->processRecoveryRequired = true;
            $this->recordProcessFailureBestEffort();
            return false;
        }
        if ($this->processJournalStarted && $this->install !== null && $this->callback !== null && $this->nonce !== null) {
            try {
                $this->install->confirmDependencyProcessReaped($this->callback, $this->nonce);
                $this->processJournalStarted = false;
            } catch (Throwable) {
                $this->processRecoveryRequired = true;
                return false;
            }
        }
        @proc_close($this->process);
        $this->process = null;
        if (is_resource($this->launcherReports)) {
            fclose($this->launcherReports);
        }
        $this->launcherReports = null;
        $this->processPid = null;
        $this->processGroupId = null;
        return true;
    }

    /** @return array{launcher_pid:int,pgid:int,time:int}|null */
    private function waitForLauncherReady(): ?array
    {
        $deadline = hrtime(true) + 1000000000;
        while (hrtime(true) < $deadline) {
            foreach ($this->readLauncherFrames() as $frame) {
                if (($frame['event'] ?? null) === 'ready' && is_int($frame['launcher_pid'] ?? null)
                    && is_int($frame['pgid'] ?? null) && is_int($frame['time'] ?? null)) {
                    return ['launcher_pid' => $frame['launcher_pid'], 'pgid' => $frame['pgid'], 'time' => $frame['time']];
                }
            }
            usleep(10000);
        }
        return null;
    }

    private function drainLauncherReports(): void
    {
        foreach ($this->readLauncherFrames() as $frame) {
            $descendants = $frame['descendant_pids'] ?? null;
            if (!is_array($descendants)) {
                continue;
            }
            foreach ($descendants as $pid) {
                if (is_int($pid) && $pid > 0 && $pid !== $this->processPid) {
                    $this->trackedProcessIds[$pid] = $pid;
                }
            }
            if ($this->processJournalStarted && $this->install !== null && $this->callback !== null && $this->nonce !== null) {
                try {
                    $this->install->updateDependencyProcessJournal($this->callback, $this->nonce, array_values($this->trackedProcessIds), (string) ($frame['event'] ?? 'tracked'), ($frame['event'] ?? null) === 'failure' ? time() : null);
                } catch (Throwable) {
                    $this->processRecoveryRequired = true;
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function readLauncherFrames(): array
    {
        if (!is_resource($this->launcherReports)) {
            return [];
        }
        $frames = [];
        while (($line = fgets($this->launcherReports)) !== false) {
            $frame = json_decode(trim($line), true);
            if (is_array($frame)) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }

    private function trackedProcessesAreGone(): bool
    {
        if ($this->processGroupId === null || !function_exists('posix_kill') || @posix_kill(-$this->processGroupId, 0)) {
            return false;
        }
        foreach ($this->trackedProcessIds as $pid) {
            if (@posix_kill($pid, 0)) {
                return false;
            }
        }
        return true;
    }

    private function recordProcessFailureBestEffort(): void
    {
        if (!$this->processJournalStarted || $this->install === null || $this->callback === null || $this->nonce === null) {
            return;
        }
        try {
            $this->install->updateDependencyProcessJournal($this->callback, $this->nonce, array_values($this->trackedProcessIds), 'RECOVERY_REQUIRED', time());
        } catch (Throwable) {
        }
    }

    private static function canLaunchProcessGroup(): bool
    {
        return function_exists('posix_setsid') && function_exists('posix_getpgid') && function_exists('posix_kill') && function_exists('pcntl_exec');
    }

    /** @param list<string> $argv @return list<string> */
    private function groupLauncherCommand(array $argv): array
    {
        return array_merge([PHP_BINARY, '-r', self::CHILD_LAUNCHER, '--'], $argv);
    }

    private function maximumRuntimeSeconds(): int
    {
        $configured = config('plugin.sandpackage.terminal.max_runtime_seconds', self::DEFAULT_MAX_RUNTIME_SECONDS);
        if (!is_int($configured) && !is_string($configured)) {
            return self::DEFAULT_MAX_RUNTIME_SECONDS;
        }
        return max(1, min((int) $configured, self::DEFAULT_MAX_RUNTIME_SECONDS));
    }

    private function limitOutput(string $output): ?string
    {
        $rawLength = strlen($output);
        if ($rawLength > $this->outputRemaining || $this->outputFrames >= self::MAX_OUTPUT_FRAMES) {
            return null;
        }
        $this->outputRemaining -= $rawLength;
        $this->outputFrames++;
        $safe = self::safeOutput($output);
        return $safe;
    }

    private function output(string $data): string
    {
        return (string) json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    }
}
