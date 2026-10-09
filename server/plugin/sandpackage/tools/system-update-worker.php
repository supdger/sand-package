<?php
/** Independent native-PHP updater. No framework/vendor autoload is permitted here. */
declare(strict_types=1);

final class SandSystemUpdateRuntime
{
    public const PACKAGES = ['supdger/sand-core' => 'sandadmin', 'supdger/sand-package' => 'sandpackage'];
    public const STATIC_MANIFEST = '.sand-system-static-manifest.json';
    private array $job;
    private string $directory;
    private bool $recoveryMutated = false;

    public function __construct(string $directory)
    {
        $directory = self::normalizePath($directory);
        self::safePath($directory);
        $this->directory = $directory;
        $this->job = self::readJson($directory . '/task.json');
    }

    public static function readJson(string $file): array
    {
        self::safePath($file);
        $data = json_decode((string) file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('更新记录格式无效');
        return $data;
    }

    public static function writeJson(string $file, array $data): void
    {
        self::safePath($file);
        $temp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('无法保存更新记录');
        chmod($temp, 0600);
        try {
            // Windows readers may briefly deny FILE_SHARE_DELETE. Keep the old record intact,
            // retry only the atomic replacement, and never create an unlink/read gap.
            $deadline = microtime(true) + (PHP_OS_FAMILY === 'Windows' ? 2.0 : 0.0);
            do {
                if (@rename($temp, $file)) return;
                $error = error_get_last()['message'] ?? 'rename 失败';
                if (microtime(true) >= $deadline) throw new RuntimeException('无法原子保存更新记录：' . $error);
                usleep(10000);
            } while (true);
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }

    public static function normalizePath(string $path): string
    {
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path)) $path = str_replace('\\', '/', $path);
        return rtrim($path, '/');
    }

    /** Comparison uses Windows separator/case semantics, including paths not yet created. */
    public static function pathKey(string $path): string
    {
        $path = self::normalizePath($path);
        return preg_match('#^[A-Za-z]:/#', $path) ? strtolower($path) : $path;
    }

    public static function overlaps(string $a, string $b): bool
    {
        $a = self::pathKey($a); $b = self::pathKey($b);
        return $a === $b || str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/');
    }

    public static function safePath(string $path): void
    {
        $path = self::normalizePath($path);
        $windows = preg_match('#^[A-Za-z]:/#', $path) === 1;
        if ($path === '' || (!$windows && $path[0] !== '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)
            || str_contains($path, '//') || preg_match('#(?:^|/)\.\.?(/|$)#', $path)
            || (PHP_OS_FAMILY === 'Windows' && !$windows)) throw new RuntimeException('更新路径必须为规范绝对路径（Windows 使用本地盘符目录）');
        $current = $windows ? substr($path, 0, 2) : '';
        foreach (explode('/', $windows ? substr($path, 3) : $path) as $part) {
            if ($part === '') continue;
            if ($windows && (preg_match('/[<>:"|?*]/', $part) || preg_match('/[. ]$/', $part) || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part))) throw new RuntimeException('Windows 更新路径含保留名称或路径别名');
            $current .= '/' . $part;
            if (is_link($current)) throw new RuntimeException('更新路径不能经过符号链接');
            if (PHP_OS_FAMILY === 'Windows' && file_exists($current)) {
                $resolved = realpath($current);
                if ($resolved === false || self::pathKey($resolved) !== self::pathKey($current)) throw new RuntimeException('更新路径不能经过目录联接、重解析点或路径别名');
            }
        }
    }

    /** Relative-path to hash map. Links are preserved only when confined to this tree. */
    public static function tree(string $root, array $exclude = []): array
    {
        $root = self::normalizePath($root);
        self::safePath($root);
        if (!file_exists($root)) return [];
        if (is_file($root)) return ['' => hash_file('sha256', $root)];
        $files = [];
        $walk = static function (string $directory, string $prefix) use (&$walk, &$files, $root, $exclude): void {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
                $relative = $prefix . $item->getFilename();
                if (in_array(explode('/', $relative)[0], $exclude, true)) continue;
                if (PHP_OS_FAMILY === 'Windows') self::safePath($item->getPathname());
                if ($item->isLink()) {
                    $link = readlink($item->getPathname());
                    $resolved = realpath($item->getPathname());
                    if ($resolved === false || !str_starts_with(self::pathKey($resolved), self::pathKey($root) . '/')) throw new RuntimeException('更新目录含有外部或悬空链接：' . $relative);
                    $files[$relative] = 'link:' . $link;
                } elseif ($item->isDir()) $walk($item->getPathname(), $relative . '/');
                elseif ($item->isFile()) $files[$relative] = hash_file('sha256', $item->getPathname());
                else throw new RuntimeException('更新目录含特殊文件：' . $relative);
            }
        };
        $walk($root, '');
        ksort($files);
        return $files;
    }

    public static function modes(string $root, array $exclude = []): array
    {
        self::safePath($root); if (!file_exists($root)) return [];
        $result = ['' => fileperms($root) & 0777];
        if (is_dir($root)) {
            $walk = static function (string $dir, string $prefix) use (&$walk, &$result, $exclude): void {
                foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $item) {
                    $relative = $prefix . $item->getFilename();
                    if (in_array(explode('/', $relative)[0], $exclude, true) || $item->isLink()) continue;
                    if (PHP_OS_FAMILY === 'Windows') self::safePath($item->getPathname());
                    $result[$relative] = fileperms($item->getPathname()) & 0777;
                    if ($item->isDir()) $walk($item->getPathname(), $relative . '/');
                }
            }; $walk($root, '');
        }
        ksort($result); return $result;
    }

    public static function fingerprint(array $scopes): string
    {
        $map = [];
        foreach ($scopes as $scope) $map[$scope['path']] = [self::tree($scope['path'], $scope['exclude'] ?? []), self::modes($scope['path'], $scope['exclude'] ?? [])];
        return hash('sha256', json_encode($map, JSON_THROW_ON_ERROR));
    }

    public static function scopes(array $plan): array
    {
        $server = $plan['server'];
        return [
            ['path' => $server . '/composer.json'], ['path' => $server . '/composer.lock'],
            ['path' => $server . '/vendor'],
            ['path' => $server . '/plugin/sandadmin'], ['path' => $server . '/plugin/sandpackage'],
            ['path' => $plan['frontend'], 'exclude' => ['node_modules', '.git']],
            ['path' => $plan['static']],
        ];
    }

    public static function assertManaged(array $plan): void
    {
        foreach (['server', 'frontend', 'static', 'storage', 'root'] as $key) self::safePath($plan[$key]);
        if (self::overlaps($plan['frontend'], $plan['server'])) throw new RuntimeException('前端目录与宿主目录重叠');
        if (self::overlaps($plan['static'], $plan['frontend']) || str_starts_with(self::pathKey($plan['server']) . '/', self::pathKey($plan['static']) . '/')
            || self::overlaps($plan['static'], $plan['root'])) throw new RuntimeException('静态发布目录与更新源码或状态目录重叠');
        foreach (['app', 'config', 'storage', 'runtime', 'vendor', 'plugin'] as $protected) {
            if (self::overlaps($plan['static'], $plan['server'] . '/' . $protected)) throw new RuntimeException('静态发布目录与更新源码或状态目录重叠');
        }
        self::assertManagedSources($plan);
        $manifest = self::readJson($plan['static'] . '/' . self::STATIC_MANIFEST);
        $actual = self::tree($plan['static']);
        unset($actual[self::STATIC_MANIFEST]);
        if ($actual !== ($manifest['files'] ?? null)) throw new RuntimeException('静态发布目录含本地修改、未知文件或缺少发布基线');
        if (!is_file($plan['frontend'] . '/pnpm-lock.yaml')) throw new RuntimeException('前端 pnpm 锁文件缺失');
        $backupBytes = 0;
        foreach (self::scopes($plan) as $scope) {
            if (!file_exists($scope['path']) || !is_writable($scope['path'])) throw new RuntimeException('升级或备份范围不存在或不可写：' . $scope['path']);
            foreach (self::tree($scope['path'], $scope['exclude'] ?? []) as $relative => $hash) {
                if (!str_starts_with($hash, 'link:')) $backupBytes += filesize($scope['path'] . ($relative !== '' ? '/' . $relative : ''));
            }
        }
        $free = disk_free_space($plan['root']);
        if ($free === false || $free < $backupBytes * 2 + 134217728) throw new RuntimeException('可用磁盘空间不足以保存原版本与恢复现场，至少需要备份体积两倍并保留 128 MiB');
        $composer = self::readJson($plan['server'] . '/composer.json');
        if (isset($composer['config']['vendor-dir']) && $composer['config']['vendor-dir'] !== 'vendor') throw new RuntimeException('仅支持标准 Composer vendor 安装路径');
        foreach (self::readJson($plan['server'] . '/composer.lock')['packages'] ?? [] as $package) {
            if (in_array($package['type'] ?? '', ['composer-plugin', 'composer-installer'], true)) throw new RuntimeException('宿主依赖安装需要 Composer 插件，不能安全禁用安装插件');
        }
    }

    public static function assertManagedSources(array $plan): void
    {
        foreach (self::PACKAGES as $package => $plugin) {
            $source = $plan['server'] . '/vendor/' . $package . '/server/plugin/' . $plugin;
            if (!is_dir($source) || self::tree($source) !== self::tree($plan['server'] . '/plugin/' . $plugin)) throw new RuntimeException($plugin . ' 后端存在本地修改或发布基线缺失');
        }
        foreach (['.sand-core-source-manifest.json', '.sand-package-source-manifest.json'] as $manifest) {
            $data = self::readJson($plan['frontend'] . '/' . $manifest);
            if (!is_array($data['files'] ?? null) || !$data['files']) throw new RuntimeException('前端发布清单缺失');
            foreach ($data['files'] as $relative => $hash) {
                self::relative($relative);
                $file = $plan['frontend'] . '/' . $relative;
                self::safePath($file);
                if (!is_file($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) throw new RuntimeException('前端存在本地修改：' . $relative);
            }
        }
    }

    public static function assertFrontendCandidates(array $plan): array
    {
        $owned = []; $oldHashes = [];
        foreach (['supdger/sand-core' => '.sand-core-source-manifest.json', 'supdger/sand-package' => '.sand-package-source-manifest.json'] as $name => $manifest) {
            foreach (self::readJson($plan['frontend'] . '/' . $manifest)['files'] as $relative => $hash) {
                if (isset($owned[$relative]) && $owned[$relative] !== $name) throw new RuntimeException('两个前端包的文件归属重叠');
                $owned[$relative] = $name; $oldHashes[$relative] = $hash;
            }
        }
        $candidate = [];
        foreach (self::PACKAGES as $name => $plugin) {
            foreach (self::tree($plan['server'] . '/vendor/' . $name . '/sandadmin-artd') as $relative => $hash) {
                self::relative($relative); self::safePath($plan['frontend'] . '/' . $relative);
                if (isset($candidate[$relative]) && $candidate[$relative] !== $name) throw new RuntimeException('候选前端包文件归属重叠');
                $candidate[$relative] = $name;
                if (file_exists($plan['frontend'] . '/' . $relative) && ($owned[$relative] ?? null) !== $name) throw new RuntimeException('候选前端文件与本地未知文件冲突：' . $relative);
            }
        }
        return array_diff_key($oldHashes, $candidate);
    }

    public static function relative(string $path): void
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1f\x7f]/', $path) || preg_match('#(?:^|/)\.\.?(/|$)#', $path)) throw new RuntimeException('发布清单路径无效');
        if (PHP_OS_FAMILY === 'Windows') foreach (explode('/', $path) as $part) {
            if ($part === '' || preg_match('/[<>:"|?*]/', $part) || preg_match('/[. ]$/', $part) || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) throw new RuntimeException('发行文件含 Windows 保留名称或路径别名');
        }
    }

    /** @return list<resource> */
    public static function locks(array $plan): array
    {
        $handles = [];
        try {
            foreach ([$plan['root'], $plan['storage'] . '/locks'] as $directory) {
                self::safePath($directory);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('无法创建更新锁目录');
            }
            $files = [$plan['root'] . '/update.lock', $plan['storage'] . '/locks/upstream-host.lock', $plan['storage'] . '/locks/upload-preflight.lock'];
            foreach (glob($plan['storage'] . '/locks/*.lock') ?: [] as $file) $files[] = $file;
            foreach ([$plan['server'] . '/plugin', $plan['storage']] as $directory) {
                foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $plugin) {
                    $app = basename($plugin);
                    if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)) $files[] = $plan['storage'] . '/locks/' . $app . '-operation.lock';
                }
            }
            $files = array_unique($files);
            sort($files);
            foreach ($files as $file) {
                self::safePath($file);
                $handle = fopen($file, 'c+');
                if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) { if (is_resource($handle)) fclose($handle); throw new RuntimeException('已有插件或系统更新任务正在执行'); }
                $handles[] = $handle;
            }
            foreach (glob($plan['storage'] . '/locks/*.json') ?: [] as $file) throw new RuntimeException('插件依赖任务记录未收尾，请先恢复原任务');
            return $handles;
        } catch (Throwable $error) {
            self::unlock($handles);
            throw $error;
        }
    }

    public static function unlock(array $handles): void
    {
        foreach (array_reverse($handles) as $handle) { flock($handle, LOCK_UN); fclose($handle); }
    }

    public static function nullDevice(): string { return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'; }

    public static function powershell(): string
    {
        $system = getenv('SystemRoot');
        if (!is_string($system) || $system === '') throw new RuntimeException('Windows SystemRoot 未配置');
        $binary = self::normalizePath($system) . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        self::safePath($binary);
        if (!is_file($binary)) throw new RuntimeException('Windows PowerShell 不可用');
        return $binary;
    }

    /** Scripts are fixed source; data travels as JSON/base64, never executable shell text. */
    private static function powershellArguments(string $script, array $data): array
    {
        $payload = base64_encode(json_encode($data, JSON_THROW_ON_ERROR));
        $script = '$ErrorActionPreference="Stop"; $ProgressPreference="SilentlyContinue"; $data = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $payload . '")) | ConvertFrom-Json;' . "\n" . $script;
        // All source and the base64 payload are ASCII; EncodedCommand requires UTF-16LE.
        $wide = '';
        for ($i = 0, $length = strlen($script); $i < $length; $i++) $wide .= $script[$i] . "\0";
        return [self::powershell(), '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode($wide)];
    }

    private static function windowsNativeSource(): string
    {
        return <<<'CSHARP'
using System;
using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
public static class SandUpdateCommand {
    [StructLayout(LayoutKind.Sequential)] struct Basic {
        public long ProcessTime, JobTime; public uint Flags;
        public UIntPtr MinWorkingSet, MaxWorkingSet; public uint ActiveProcesses;
        public UIntPtr Affinity; public uint Priority, Scheduling;
    }
    [StructLayout(LayoutKind.Sequential)] struct Counters { public ulong ReadOps, WriteOps, OtherOps, ReadBytes, WriteBytes, OtherBytes; }
    [StructLayout(LayoutKind.Sequential)] struct Extended {
        public Basic Basic; public Counters IO;
        public UIntPtr ProcessMemory, JobMemory, PeakProcessMemory, PeakJobMemory;
    }
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr CreateJobObject(IntPtr attributes, string name);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool SetInformationJobObject(IntPtr job, int info, ref Extended limits, uint length);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [StructLayout(LayoutKind.Sequential)] struct Security { public int Length; public IntPtr Descriptor; [MarshalAs(UnmanagedType.Bool)] public bool Inherit; }
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)] struct Startup {
        public int Size; public string Reserved, Desktop, Title; public uint X,Y,Width,Height,CharsX,CharsY,Fill,Flags;
        public short Show, ReservedLength; public IntPtr ReservedBytes, Input, Output, Error;
    }
    [StructLayout(LayoutKind.Sequential)] struct ProcessInfo { public IntPtr Process, Thread; public uint ProcessId, ThreadId; }
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr CreateFile(string name, uint access, uint share, ref Security security, uint creation, uint flags, IntPtr template);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern bool CreateProcess(string executable, StringBuilder commandLine, IntPtr processSecurity, IntPtr threadSecurity, bool inherit, uint flags, IntPtr environment, string cwd, ref Startup startup, out ProcessInfo process);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool IsProcessInJob(IntPtr process, IntPtr job, out bool present);
    [DllImport("kernel32.dll", SetLastError=true)] static extern IntPtr GetStdHandle(int identifier);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool SetHandleInformation(IntPtr handle, uint mask, uint flags);
    [DllImport("kernel32.dll", SetLastError=true)] static extern uint WaitForSingleObject(IntPtr handle, uint timeout);
    [DllImport("kernel32.dll", SetLastError=true)] static extern bool GetExitCodeProcess(IntPtr process, out uint code);
    public static string Quote(string value) {
        var result = new StringBuilder("\""); int slashes = 0;
        foreach (char c in value) {
            if (c == '\\') { slashes++; continue; }
            if (c == '"') result.Append('\\', slashes * 2 + 1).Append(c);
            else result.Append('\\', slashes).Append(c);
            slashes = 0;
        }
        return result.Append('\\', slashes * 2).Append('"').ToString();
    }
    public static int Run(string executable, string[] args, string cwd, int parentId, string identityFile, string acknowledgement) {
        var parent = Process.GetProcessById(parentId);
        // Capture the process handle before command startup, avoiding PID reuse.
        var parentHandle = parent.Handle;
        IntPtr job = CreateJobObject(IntPtr.Zero, null);
        if (job == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
        var limits = new Extended(); limits.Basic.Flags = 0x2000; // KILL_ON_JOB_CLOSE; no breakaway.
        if (!SetInformationJobObject(job, 9, ref limits, (uint)Marshal.SizeOf(typeof(Extended))) ||
            !AssignProcessToJobObject(job, Process.GetCurrentProcess().Handle))
            throw new Win32Exception(Marshal.GetLastWin32Error());
        var self = Process.GetCurrentProcess();
        Console.OutputEncoding = new UTF8Encoding(false);
        System.IO.File.WriteAllText(identityFile, "{\"pid\":" + self.Id + ",\"started\":\"" + self.StartTime.ToUniversalTime().Ticks + "\"}", new UTF8Encoding(false));
        var wait = Stopwatch.StartNew();
        // Persist the exact launcher identity before any product-mutating child may run.
        while (!System.IO.File.Exists(acknowledgement)) {
            if (parent.HasExited || wait.ElapsedMilliseconds > 30000) { CloseHandle(job); return 126; }
            System.Threading.Thread.Sleep(50);
        }
        var commandLine = new StringBuilder(Quote(executable));
        foreach (string arg in args) commandLine.Append(' ').Append(Quote(arg));
        var startup = new Startup(); startup.Size = Marshal.SizeOf(typeof(Startup)); startup.Flags = 0x100;
        startup.Input = GetStdHandle(-10); startup.Output = GetStdHandle(-11); startup.Error = GetStdHandle(-12);
        foreach (IntPtr handle in new[] {startup.Input, startup.Output, startup.Error})
            if (!SetHandleInformation(handle, 1, 1)) throw new Win32Exception(Marshal.GetLastWin32Error());
        ProcessInfo child;
        if (!CreateProcess(executable, commandLine, IntPtr.Zero, IntPtr.Zero, true, 0x08000000, IntPtr.Zero, cwd, ref startup, out child))
            throw new Win32Exception(Marshal.GetLastWin32Error());
        CloseHandle(child.Thread);
        try {
            uint waitResult;
            while ((waitResult = WaitForSingleObject(child.Process, 100)) == 258) {
                if (parent.HasExited) { CloseHandle(job); return 126; }
            }
            if (waitResult != 0) throw new Win32Exception(Marshal.GetLastWin32Error());
            uint code;
            if (!GetExitCodeProcess(child.Process, out code)) throw new Win32Exception(Marshal.GetLastWin32Error());
            return unchecked((int)code);
        } finally { CloseHandle(child.Process); }
        // The job handle intentionally lives until this launcher exits; closing kills remaining descendants.
    }
    public static void Detach(string executable, string[] args, string cwd, string output, string error) {
        var security = new Security(); security.Length = Marshal.SizeOf(typeof(Security)); security.Inherit = true;
        IntPtr input = IntPtr.Zero, stdout = IntPtr.Zero, stderr = IntPtr.Zero;
        var invalid = new IntPtr(-1);
        try {
            input = CreateFile("NUL", 0x80000000, 3, ref security, 3, 0, IntPtr.Zero);
            stdout = CreateFile(output, 4, 3, ref security, 4, 0, IntPtr.Zero);
            stderr = CreateFile(error, 4, 3, ref security, 4, 0, IntPtr.Zero);
            if (input == invalid || stdout == invalid || stderr == invalid) throw new Win32Exception(Marshal.GetLastWin32Error());
            var startup = new Startup(); startup.Size = Marshal.SizeOf(typeof(Startup)); startup.Flags = 0x100;
            startup.Input = input; startup.Output = stdout; startup.Error = stderr;
            var commandLine = new StringBuilder(Quote(executable));
            foreach (string arg in args) commandLine.Append(' ').Append(Quote(arg));
            bool inJob;
            if (!IsProcessInJob(Process.GetCurrentProcess().Handle, IntPtr.Zero, out inJob)) throw new Win32Exception(Marshal.GetLastWin32Error());
            // Detach from host console and any hosting job, so host reload cannot terminate this worker.
            uint flags = 0x8 | 0x200;
            if (inJob) flags |= 0x1000000; // CREATE_BREAKAWAY_FROM_JOB; refusal is an explicit startup failure.
            ProcessInfo process;
            if (!CreateProcess(executable, commandLine, IntPtr.Zero, IntPtr.Zero, true, flags, IntPtr.Zero, cwd, ref startup, out process))
                throw new Win32Exception(Marshal.GetLastWin32Error());
            CloseHandle(process.Thread); CloseHandle(process.Process);
        } finally {
            foreach (IntPtr handle in new[] {input, stdout, stderr}) if (handle != IntPtr.Zero && handle != invalid) CloseHandle(handle);
        }
    }
}
CSHARP;
    }

    /** A Job Object binds the complete child tree to this command and to its PHP owner. */
    private static function windowsCommand(array $argv, string $cwd, callable $output, int $timeout): string
    {
        $script = '$data = [IO.File]::ReadAllText([string]$data.payload_file) | ConvertFrom-Json;' . "\n" . "Add-Type -TypeDefinition @'\n" . self::windowsNativeSource() . "\n" . <<<'POWERSHELL'
'@
$application = Get-Command -Name ([string]$data.argv[0]) -CommandType Application -ErrorAction Stop
if ([IO.Path]::GetExtension($application.Source) -ine '.exe') { throw 'Configure a native executable argv; use PHP for Composer PHAR and node.exe for pnpm CLI, not .cmd/.bat.' }
$arguments = @($data.argv | Select-Object -Skip 1)
$exitCode = [SandUpdateCommand]::Run($application.Source, [string[]]$arguments, [string]$data.cwd, [int]$data.parent, [string]$data.identity, [string]$data.ack)
exit $exitCode
POWERSHELL;
        $stdout = tempnam(sys_get_temp_dir(), 'sand-update-out-');
        $stderr = tempnam(sys_get_temp_dir(), 'sand-update-err-');
        $identityFile = tempnam(sys_get_temp_dir(), 'sand-update-process-');
        $payloadFile = tempnam(sys_get_temp_dir(), 'sand-update-argv-');
        if ($stdout === false || $stderr === false || $identityFile === false || $payloadFile === false) throw new RuntimeException('无法创建更新命令日志');
        chmod($stdout, 0600); chmod($stderr, 0600); chmod($identityFile, 0600); chmod($payloadFile, 0600);
        $ack = $stdout . '.ready';
        $process = null; $readers = []; $identity = ''; $code = -1; $captured = ''; $pending = '';
        $consume = static function (string $chunk) use (&$captured, &$pending, $output): void {
            if (strlen($captured) < 1048576) $captured .= substr($chunk, 0, 1048576 - strlen($captured));
            $pending .= $chunk;
            while (($newline = strpos($pending, "\n")) !== false) {
                $line = rtrim(substr($pending, 0, $newline), "\r"); $pending = substr($pending, $newline + 1);
                $output(self::redact($line));
            }
            if (strlen($pending) > 4096) { $output(self::redact(substr($pending, 0, 4096))); $pending = ''; }
        };
        try {
            self::writeJson(self::normalizePath($payloadFile), ['argv' => $argv, 'cwd' => $cwd, 'parent' => getmypid(), 'identity' => $identityFile, 'ack' => $ack]);
            $process = proc_open(self::powershellArguments($script, ['payload_file' => self::normalizePath($payloadFile)]),
                [0 => ['file', 'NUL', 'r'], 1 => ['file', $stdout, 'a'], 2 => ['file', $stderr, 'a']], $pipes, $cwd, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('无法启动 Windows 更新命令');
            $readers = [fopen($stdout, 'rb'), fopen($stderr, 'rb')];
            if (in_array(false, $readers, true)) throw new RuntimeException('无法读取更新命令日志');
            $start = microtime(true);
            do {
                if ($identity === '') {
                    $record = json_decode((string) file_get_contents($identityFile), true);
                    if (is_array($record) && is_int($record['pid'] ?? null) && $record['pid'] > 0 && is_string($record['started'] ?? null) && preg_match('/^[0-9]+$/D', $record['started'])) {
                        $identity = $record['pid'] . ':' . $record['started'];
                        $output('@system-windows-process:' . $identity);
                        if (file_put_contents($ack, 'ready') === false) throw new RuntimeException('无法确认更新子进程启动');
                    }
                }
                foreach ($readers as $reader) { $chunk = stream_get_contents($reader); if ($chunk !== false && $chunk !== '') $consume($chunk); }
                $status = proc_get_status($process);
                if (!$status['running']) { $code = $status['exitcode']; break; }
                if (microtime(true) - $start > $timeout) {
                    // The launcher owns the sole job handle. Terminating it closes that handle and all descendants.
                    if (!proc_terminate($process)) throw new RuntimeException('无法终止超时的 Windows 更新进程');
                    throw new RuntimeException('更新命令超时，Windows 作业进程树已终止');
                }
                usleep(50000);
            } while (true);
            foreach ($readers as $reader) { $tail = stream_get_contents($reader); if ($tail !== false) $consume($tail); }
            if ($pending !== '') $output(self::redact($pending));
        } finally {
            foreach ($readers as $reader) if (is_resource($reader)) fclose($reader);
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                $closed = proc_close($process); if ($code < 0) $code = $closed;
            }
            if ($identity !== '') $output('@system-windows-process-exited:' . $identity);
            foreach ([$stdout, $stderr, $identityFile, $ack, $payloadFile] as $file) if (is_file($file)) unlink($file);
        }
        if ($code !== 0) throw new RuntimeException('更新命令失败，退出码 ' . $code);
        return $captured;
    }

    public static function unixHostMaster(string $server, string $pidFile, ?int $requestParent = null): array
    {
        self::safePath($pidFile);
        $text = is_file($pidFile) ? trim((string)file_get_contents($pidFile)) : '';
        $pid = (int)$text;
        if (!ctype_digit($text) || $pid <= 1 || $requestParent !== null && $pid !== $requestParent || !posix_kill($pid, 0)) throw new RuntimeException('宿主主进程记录不可用或与当前进程归属不一致，框架已拒绝重载');
        $identity = trim(self::command(['/bin/ps', '-p', (string)$pid, '-o', 'lstart=', '-o', 'args='], $server, static function (string $line): void {}, 10));
        $start = self::normalizePath(realpath($server . '/start.php') ?: $server . '/start.php');
        if (!preg_match('/WorkerMan: master process\s+start_file=(.+)$/D', $identity, $match) || $match[1] !== $start) throw new RuntimeException('主进程不是当前宿主的 Workerman 实例，框架已拒绝重载');
        return ['pid' => $pid, 'identity' => $identity];
    }

    public static function reloadUnixHost(string $server, string $pidFile, array $master): void
    {
        if (!is_int($master['pid'] ?? null) || !is_string($master['identity'] ?? null)
            || self::unixHostMaster($server, $pidFile, $master['pid']) !== $master) throw new RuntimeException('宿主主进程身份已变化，框架已拒绝重载');
        if (!posix_kill($master['pid'], SIGUSR1)) throw new RuntimeException('框架无法向当前宿主发送重载信号');
    }

    /** Inspect only HTTP workers belonging to the already verified master. */
    public static function unixHttpWorkers(string $server, array $master, string $listen): array
    {
        if ($listen === '' || !is_int($master['pid'] ?? null)) throw new RuntimeException('宿主 HTTP 进程配置不可核对，恢复 CLI 已拒绝重载');
        $children = trim(self::command(['/usr/bin/pgrep', '-P', (string)$master['pid'], '.'], $server, static function (string $line): void {}, 10));
        $workers = [];
        foreach (preg_split('/\s+/', $children) as $child) {
            if (!ctype_digit($child) || (int)$child <= 1) throw new RuntimeException('宿主子进程记录无效');
            // A child can disappear during reload; it is absent from this snapshot.
            try {
                $identity = trim(self::command(['/bin/ps', '-p', $child, '-o', 'ppid=', '-o', 'lstart=', '-o', 'args='], $server, static function (string $line): void {}, 10));
            } catch (RuntimeException $error) {
                if (posix_kill((int)$child, 0)) throw $error;
                continue;
            }
            if (preg_match('/^([0-9]+)\s+/', $identity, $parent) && (int)$parent[1] === $master['pid']
                && str_contains($identity, 'WorkerMan: worker process ') && str_ends_with($identity, ' ' . $listen)) {
                $workers[$child] = $identity;
            }
        }
        return $workers;
    }

    /** Bind Windows reload to the running request's actual standard supervisor ancestry. */
    public static function windowsSupervisor(string $server, int $requestPid): array
    {
        $script = <<<'POWERSHELL'
$currentId = [int]$data.pid
for ($depth = 0; $depth -lt 12; $depth++) {
    $current = Get-CimInstance Win32_Process -Filter ("ProcessId=" + $currentId) -ErrorAction Stop
    if ($null -eq $current) { break }
    if ([string]$current.CommandLine -match '(?i)(?:^|[\s"\\/])windows\.php(?:"|\s|$)') {
        $native = Get-Process -Id $currentId -ErrorAction Stop
        [Console]::WriteLine((@{pid=$currentId; started=$native.StartTime.ToUniversalTime().Ticks.ToString()} | ConvertTo-Json -Compress)); exit 0
    }
    $currentId = [int]$current.ParentProcessId
    if ($currentId -le 0) { break }
}
throw 'The current HTTP worker has no standard Windows supervisor ancestor'
POWERSHELL;
        $text = self::command(self::powershellArguments($script, ['pid' => $requestPid]), $server, static function (string $line): void {}, 30);
        $identity = json_decode(trim($text), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($identity) || !self::processActive($identity)) throw new RuntimeException('Windows 宿主监督器进程身份无法核验');
        return $identity;
    }

    public static function processActive(mixed $identity): bool
    {
        if (is_int($identity)) return function_exists('posix_kill') ? posix_kill(-$identity, 0) : throw new RuntimeException('无法在此平台核验历史 Unix 进程组');
        if (!is_array($identity) || !isset($identity['pid'], $identity['started']) || !is_int($identity['pid']) || $identity['pid'] <= 0 || !preg_match('/^[0-9]+$/D', $identity['started'])) throw new RuntimeException('更新进程身份记录无效');
        $script = <<<'POWERSHELL'
$process = Get-Process -Id ([int]$data.pid) -ErrorAction SilentlyContinue
if ($null -eq $process) { exit 0 }
if ($process.StartTime.ToUniversalTime().Ticks.ToString() -eq [string]$data.started) { [Console]::WriteLine('ACTIVE') }
POWERSHELL;
        $text = self::command(self::powershellArguments($script, $identity), self::normalizePath(sys_get_temp_dir()), static function (string $line): void {}, 30);
        return str_contains($text, 'ACTIVE');
    }

    /** Native detached creation gives the worker its own handles, console and hosting-job lifetime. */
    public static function detachWindows(array $plan, string $directory, bool $recover): void
    {
        $script = "Add-Type -TypeDefinition @'\n" . self::windowsNativeSource() . "\n" . <<<'POWERSHELL'
'@
$application = Get-Command -Name ([string]$data.php) -CommandType Application -ErrorAction Stop
if ([IO.Path]::GetExtension($application.Source) -ine '.exe') { throw 'Configure the PHP CLI executable, not a batch wrapper.' }
[SandUpdateCommand]::Detach($application.Source, [string[]]@($data.arguments), [string]$data.server, [string]$data.output, [string]$data.error)
POWERSHELL;
        $log = $directory . '/dispatch.log';
        $process = proc_open(self::powershellArguments($script, ['php' => $plan['php'], 'arguments' => [$directory . '/worker.php', $directory, $recover ? 'recover' : 'run'], 'server' => $plan['server'], 'output' => $directory . '/worker.log', 'error' => $directory . '/worker-error.log']),
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $plan['server'], null, ['bypass_shell' => true]);
        if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Windows 独立进程启动失败，请检查任务 dispatch.log');
    }

    public static function command(array $argv, string $cwd, callable $output, int $timeout = 1800): string
    {
        if (!$argv || !array_is_list($argv)) throw new RuntimeException('更新命令未配置');
        foreach ($argv as $argument) if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) throw new RuntimeException('更新命令参数无效');
        if (PHP_OS_FAMILY === 'Windows') return self::windowsCommand($argv, self::normalizePath($cwd), $output, $timeout);
        $pipes = [];
        $launcher = <<<'NATIVE'
if (posix_setsid() < 0) exit(126);
$args = array_slice($_SERVER['argv'], 1); $binary = array_shift($args);
if (!str_contains($binary, '/')) { foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) { $path = $dir . '/' . $binary; if (is_file($path) && is_executable($path)) { $binary = $path; break; } } }
pcntl_exec($binary, $args); exit(127);
NATIVE;
        $process = proc_open([PHP_BINARY, '-r', $launcher, ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('无法启动更新命令');
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $start = microtime(true); $captured = ''; $pending = ''; $code = -1; $initial = proc_get_status($process); $group = $initial['pid']; $output('@system-process:' . $group);
        try {
            while (true) {
                foreach ($pipes as $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if ($chunk !== false && $chunk !== '') {
                        if (strlen($captured) < 1048576) $captured .= substr($chunk, 0, 1048576 - strlen($captured));
                        $pending .= $chunk;
                        while (($newline = strpos($pending, "\n")) !== false) {
                            $output(self::redact(substr($pending, 0, $newline)));
                            $pending = substr($pending, $newline + 1);
                        }
                        if (strlen($pending) > 4096) { $output(self::redact(substr($pending, 0, 4096))); $pending = ''; }
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $code = $status['exitcode']; break; }
                if (microtime(true) - $start > $timeout) { posix_kill(-$group, SIGTERM); usleep(300000); posix_kill(-$group, SIGKILL); proc_terminate($process, SIGKILL); throw new RuntimeException('更新命令超时，执行进程组已终止'); }
                usleep(50000);
            }
            foreach ($pipes as $pipe) { $tail = stream_get_contents($pipe); if ($tail !== false) $pending .= $tail; }
            if ($pending !== '') $output(self::redact($pending));
        } finally {
            foreach ($pipes as $pipe) fclose($pipe);
            $closed = proc_close($process);
            if ($code < 0) $code = $closed;
            $output('@system-process-exited:' . $group);
        }
        if ($code !== 0) throw new RuntimeException('更新命令失败，退出码 ' . $code);
        return $captured;
    }

    public static function redact(string $text): string
    {
        $text = preg_replace('#(https?://)[^\s/@]+:[^\s/@]+@#', '$1[凭据已隐藏]@', $text) ?? '';
        return substr(preg_replace('/(?i)(password|token|authorization|secret)(\s*[=:]\s*)[^\s]+/', '$1$2[已隐藏]', $text) ?? '', 0, 4096);
    }

    public static function composerArguments(array $plan, bool $dryRun): array
    {
        $argv = [...$plan['composer'], 'require'];
        $versions = $plan['pinned'];
        foreach ($plan['targets'] as $target) $versions[$target['package']]['version'] = $target['version'];
        foreach ($versions as $package => $pin) $argv[] = $package . ':' . $pin['version'];
        $argv = [...$argv, '--with-all-dependencies', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist', '--no-progress'];
        if ($dryRun) $argv[] = '--dry-run';
        return $argv;
    }

    private function emit(string $stage, string $message): void
    {
        if (preg_match('/^@system-windows-process(-exited)?:([0-9]+):([0-9]+)$/D', $message, $windows)) {
            $identities = $this->job['windows_processes'] ?? [];
            if ($windows[1] === '') { $identities[$windows[2]] = ['pid' => (int) $windows[2], 'started' => $windows[3]]; $message = 'Windows 作业子进程已启动'; }
            else { unset($identities[$windows[2]]); $message = 'Windows 作业子进程已退出'; }
            $this->job['windows_processes'] = $identities;
        }
        if (preg_match('/^@system-process(-exited)?:([0-9]+)$/D', $message, $match)) {
            $groups = $this->job['process_groups'] ?? [];
            if ($match[1] === '') { $groups[] = (int) $match[2]; $message = '独立子进程已启动'; }
            else { $groups = array_values(array_diff($groups, [(int) $match[2]])); $message = '独立子进程已退出'; }
            $this->job['process_groups'] = $groups;
        }
        $this->job['stage'] = $stage;
        $this->job['updated_at'] = time();
        $this->job['logs'][] = ['time' => time(), 'stage' => $stage, 'message' => self::redact($message)];
        $this->job['logs'] = array_slice($this->job['logs'], -500);
        $this->save();
        fwrite(STDOUT, '[' . $stage . '] ' . self::redact($message) . PHP_EOL);
        fflush(STDOUT);
    }

    private function save(): void { self::writeJson($this->directory . '/task.json', $this->job); }

    private function phase(string $stage, callable $action): void
    {
        $this->emit($stage, '开始');
        $action();
        $this->emit($stage, '完成');
    }

    public function run(bool $recover = false): void
    {
        $plan = self::readJson($this->directory . '/plan.json');
        $handles = [];
        try {
            $deadline = microtime(true) + 10;
            do {
                try { $handles = self::locks($plan); break; }
                catch (RuntimeException $error) { if (microtime(true) >= $deadline) throw $error; usleep(100000); }
            } while (true);
            $this->job['state'] = $recover ? 'recovering' : 'running';
            $this->job['pid'] = getmypid();
            $this->save();
            if ($recover) {
                $this->restore($plan);
                $this->job['state'] = 'recovered';
                $this->job['error'] = '';
                $this->job['recovery_available'] = false;
                $this->emit('recovered', '原版本文件已恢复，健康检查通过');
                return;
            }
            $this->phase('preflight', function () use ($plan): void {
                self::assertManaged($plan);
                if (!hash_equals($plan['fingerprint'], self::fingerprint(self::scopes($plan)))) throw new RuntimeException('预检后宿主文件已变化，请重新检查');
                self::command(self::composerArguments($plan, true), $plan['server'], fn (string $line) => $this->emit('preflight', $line));
            });
            $this->phase('backup', fn () => $this->backup($plan));
            $this->phase('composer', function () use ($plan): void {
                $this->job['mutated'] = true; $this->save();
                self::command(self::composerArguments($plan, false), $plan['server'], fn (string $line) => $this->emit('composer', $line));
                $this->verifyPackages($plan);
            });
            $this->phase('publish', function () use ($plan): void {
                $obsolete = self::assertFrontendCandidates($plan);
                foreach (self::PACKAGES as $package => $plugin) {
                    $root = $plan['server'] . '/vendor/' . $package;
                    $publisher = $package === 'supdger/sand-core' && is_file($root . '/server/FrontendPublisher.php')
                        ? [$plan['php'], '-r', 'require $argv[1]; \SandAdmin\Core\FrontendPublisher::publish($argv[2]);', $root . '/server/FrontendPublisher.php', $plan['frontend']]
                        : [$plan['php'], $root . '/tools/publish-frontend.php', $plan['frontend']];
                    self::command($publisher, $plan['server'], fn (string $line) => $this->emit('publish', $line));
                    $source = $root . '/server/plugin/' . $plugin;
                    $destination = $plan['server'] . '/plugin/' . $plugin;
                    self::remove($destination, [], true);
                    self::copy($source, $destination);
                }
                foreach ($obsolete as $relative => $hash) {
                    $file = $plan['frontend'] . '/' . $relative; self::safePath($file);
                    if (!is_file($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) throw new RuntimeException('删除旧前端文件前发现外部修改：' . $relative);
                    if (!unlink($file)) throw new RuntimeException('无法移除发行已删除的受管理前端文件：' . $relative);
                }
            });
            $this->phase('frontend', function () use ($plan): void {
                self::command([...$plan['pnpm'], 'install', '--frozen-lockfile'], $plan['frontend'], fn (string $line) => $this->emit('frontend', $line));
                $previousBase = getenv('VITE_BASE_URL');
                try {
                    if (isset($plan['frontend_base'])) putenv('VITE_BASE_URL=' . $plan['frontend_base']);
                    self::command([...$plan['pnpm'], 'run', 'build'], $plan['frontend'], fn (string $line) => $this->emit('frontend', $line));
                } finally { if (isset($plan['frontend_base'])) putenv($previousBase === false ? 'VITE_BASE_URL' : 'VITE_BASE_URL=' . $previousBase); }
                $dist = $plan['frontend'] . '/dist';
                if (!is_file($dist . '/index.html')) throw new RuntimeException('前端构建未生成 index.html');
                self::remove($plan['static'], [], true);
                self::copy($dist, $plan['static']);
                if (!is_file($plan['static'] . '/index.html') || self::tree($plan['static']) !== self::tree($dist)) throw new RuntimeException('静态发布内容与构建产物不一致');
                self::writeJson($plan['static'] . '/' . self::STATIC_MANIFEST, ['files' => self::tree($plan['static'])]);
            });
            $this->reloadAndHealth($plan);
            $this->job['state'] = 'succeeded';
            $this->job['recovery_available'] = false;
            $this->emit('succeeded', '系统更新完成，健康检查通过');
        } catch (Throwable $error) {
            $this->job['state'] = $recover ? 'recovery_required' : 'failed';
            $this->job['error'] = self::redact($error->getMessage());
            if ($handles && ($this->job['mutated'] ?? false) && (!$recover || $this->recoveryMutated)) {
                try { $this->job['failure_fingerprint'] = self::fingerprint(self::scopes($plan)); }
                catch (Throwable) { $this->job['state'] = 'recovery_required'; }
            }
            $this->job['recovery_available'] = is_file($this->directory . '/backup.json') && isset($this->job['failure_fingerprint']);
            $this->emit('failed', $error->getMessage());
        } finally {
            self::unlock($handles);
        }
    }

    private function verifyPackages(array $plan): void
    {
        $lock = self::readJson($plan['server'] . '/composer.lock');
        $selected = array_column($plan['targets'], null, 'package');
        foreach ($plan['pinned'] as $name => $pin) {
            if (isset($selected[$name])) continue;
            $found = null;
            foreach ($lock['packages'] ?? [] as $package) if ($package['name'] === $name) $found = $package;
            if ($found === null || ltrim($found['version'], 'v') !== $pin['version'] || ($found['source']['reference'] ?? '') !== $pin['reference'] || self::tree($plan['server'] . '/vendor/' . $name) !== $pin['files']) throw new RuntimeException('未选择的基础包被依赖求解变更，拒绝发布：' . $name);
        }
        foreach ($plan['targets'] as $target) {
            $found = null;
            foreach ($lock['packages'] ?? [] as $package) if ($package['name'] === $target['package']) $found = $package;
            if ($found === null || ltrim($found['version'], 'v') !== $target['version'] || ($found['source']['reference'] ?? '') !== $target['reference']) throw new RuntimeException('安装版本或源码 revision 与确认的发行不一致');
            $dist = $found['dist'] ?? [];
            $repository = 'https://api.github.com/repos/supdger/' . basename($target['package']) . '/';
            if (!str_starts_with($dist['url'] ?? '', $repository) || ($dist['reference'] ?? '') !== $target['reference']) throw new RuntimeException('安装包下载来源或摘要 revision 与官方发行不一致');
            $installed = self::readJson($plan['server'] . '/vendor/' . $target['package'] . '/composer.json');
            if (($installed['name'] ?? '') !== $target['package']) throw new RuntimeException('已安装包身份不一致');
            // The complete release runtime/frontend payload is bound to its signed-off file list.
            foreach ($target['files'] as $relative => $hash) {
                self::relative($relative);
                $file = $plan['server'] . '/vendor/' . $target['package'] . '/' . $relative;
                self::safePath($file);
                if (!is_file($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) throw new RuntimeException('发行文件摘要不一致：' . $relative);
            }
            $root = $plan['server'] . '/vendor/' . $target['package'];
            $actual = [];
            foreach (['server', 'sandadmin-artd', 'tools'] as $scope) foreach ((is_dir($root . '/' . $scope) ? self::tree($root . '/' . $scope, ['node_modules', 'dist', '.DS_Store', '.idea', '.vscode']) : []) as $relative => $hash) { if (preg_match('#(?:^|/)\\.(?:env(?:[^/]*)?|DS_Store|idea|vscode)(?:/|$)#', $relative)) continue; $actual[$scope . '/' . $relative] = $hash; }
            ksort($actual); $expected = $target['files']; ksort($expected);
            if ($actual !== $expected) throw new RuntimeException('发行运行载荷包含清单外文件');
        }
    }

    private function backup(array $plan): void
    {
        $entries = [];
        foreach (self::scopes($plan) as $index => $scope) {
            $source = $scope['path']; $backup = $this->directory . '/backup/' . $index;
            $files = self::tree($source, $scope['exclude'] ?? []);
            self::copy($source, $backup, $scope['exclude'] ?? []);
            if (self::tree($backup) !== $files) throw new RuntimeException('备份摘要核对失败');
            $entries[] = $scope + ['backup' => $backup, 'files' => $files, 'modes' => self::modes($source, $scope['exclude'] ?? []), 'exists' => file_exists($source)];
        }
        self::writeJson($this->directory . '/backup.json', $entries);
    }

    private function restore(array $plan): void
    {
        if (!isset($this->job['failure_fingerprint']) || !hash_equals($this->job['failure_fingerprint'], self::fingerprint(self::scopes($plan)))) throw new RuntimeException('失败后宿主文件发生外部变化，拒绝覆盖；请保留备份人工核对');
        $entries = self::readJson($this->directory . '/backup.json');
        $scopes = self::scopes($plan);
        if (count($entries) !== count($scopes)) throw new RuntimeException('恢复备份范围不完整');
        foreach ($entries as $index => $entry) {
            if ($entry['path'] !== $scopes[$index]['path'] || $entry['backup'] !== $this->directory . '/backup/' . $index || (self::tree($entry['backup']) !== $entry['files'] || self::modes($entry['backup']) !== $entry['modes'])) throw new RuntimeException('恢复备份摘要或路径无效');
        }
        $this->phase('restore', function () use ($entries): void {
            $this->recoveryMutated = true;
            foreach ($entries as $entry) {
                self::remove($entry['path'], $entry['exclude'] ?? [], is_dir($entry['path']));
                if ($entry['exists']) self::copy($entry['backup'], $entry['path']);
            }
        });
        $this->phase('frontend-restore', fn () => self::command([...$plan['pnpm'], 'install', '--frozen-lockfile'], $plan['frontend'], fn (string $line) => $this->emit('frontend-restore', $line)));
        $this->reloadAndHealth($plan, true);
    }

    private function reloadAndHealth(array $plan, bool $restoring = false): void
    {
        $probe = $plan['root'] . '/health-probe.json';
        $token = bin2hex(random_bytes(32));
        if (!$plan['health']) self::writeJson($probe, ['token' => $token, 'expires_at' => time() + 150]);
        try {
            $this->phase('reload', function () use ($plan): void {
                if ($plan['reload']) { self::command($plan['reload'], $plan['server'], fn (string $line) => $this->emit('reload', $line), 120); return; }
                $deployment = $plan['deployment'];
                if ($deployment['reload_mode'] === 'windows-monitor') {
                    // The standard Windows supervisor owns restart; only mtime changes here.
                    if (!self::processActive($deployment['supervisor'])) throw new RuntimeException('Windows 宿主监督器已变化，已停止升级后的重载操作');
                    $trigger = $deployment['trigger']; self::safePath($trigger);
                    if (!is_file($trigger)) throw new RuntimeException('宿主重载监测文件已消失');
                    sleep(2);
                    if (!touch($trigger)) throw new RuntimeException('框架无法触发宿主进程监督器重载');
                    $this->emit('reload', '已通知 Windows 宿主监督器重载');
                } else {
                    $master = $deployment['master'];
                    self::reloadUnixHost($plan['server'], $deployment['pid_file'], $master);
                    $this->emit('reload', '已向当前 Workerman 宿主发送重载信号');
                }
            });
            $this->phase('health', function () use ($plan, $token, $restoring): void {
                if ($plan['health']) { self::command($plan['health'], $plan['server'], fn (string $line) => $this->emit('health', $line), 120); return; }
                if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL 不可用，框架无法验证重载后的服务');
                $expected = [];
                foreach (self::PACKAGES as $package => $plugin) {
                    $config = require $plan['server'] . '/plugin/' . $plugin . '/config/app.php';
                    $expected[$package] = $config['version'] ?? null;
                }
                $url = $plan['deployment']['url'] . $plan['deployment']['probe'];
                $deadline = microtime(true) + 90; $nextMessage = 0;
                do {
                    $handle = curl_init($url);
                    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['X-Sand-Update-Probe: ' . $token]]);
                    $body = curl_exec($handle); $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
                    $data = is_string($body) ? json_decode($body, true) : null;
                    if ($code === 200 && is_array($data) && hash_equals($token, $data['token'] ?? '') && ($data['versions'] ?? null) === $expected) {
                        $manifest = self::readJson($plan['static'] . '/' . self::STATIC_MANIFEST);
                        if ($restoring && ($manifest['files'] ?? null) === [] && self::tree($plan['static']) === [self::STATIC_MANIFEST => hash_file('sha256', $plan['static'] . '/' . self::STATIC_MANIFEST)]) { $this->emit('health', '原服务版本已恢复；原部署没有受管理静态页面'); return; }
                        $staticHandle = curl_init($plan['deployment']['url'] . $plan['deployment']['static_uri']);
                        curl_setopt_array($staticHandle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
                        $index = curl_exec($staticHandle); $staticCode = curl_getinfo($staticHandle, CURLINFO_RESPONSE_CODE); curl_close($staticHandle);
                        if ($staticCode === 200 && is_string($index) && is_file($plan['static'] . '/index.html') && hash_equals((string)hash_file('sha256', $plan['static'] . '/index.html'), hash('sha256', $index))) {
                            $this->verifyHttpAssets($plan, $index);
                            $this->emit('health', '宿主 HTTP 已恢复，运行版本、静态页面与入口资源发布核对通过'); return;
                        }
                    }
                    if (microtime(true) >= $nextMessage) { $this->emit('health', '等待宿主完成重载并载入目标版本'); $nextMessage = microtime(true) + 5; }
                    usleep(250000);
                } while (microtime(true) < $deadline);
                throw new RuntimeException('宿主未在限定时间内完成重载或版本核对，已保留任务与恢复备份');
            });
        } finally { if (!$plan['health'] && is_file($probe)) unlink($probe); }
    }

    /** Validate entry resources, including their deployment base, rather than index alone. */
    private function verifyHttpAssets(array $plan, string $index): void
    {
        preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i', $index, $matches);
        $base = substr($plan['deployment']['static_uri'], 0, -strlen('index.html'));
        $urls = array_unique($matches[1]);
        if (count($urls) > 128) throw new RuntimeException('静态入口引用资源过多，无法安全验证');
        foreach ($urls as $url) {
            $path = parse_url(html_entity_decode($url, ENT_QUOTES), PHP_URL_PATH);
            if (!is_string($path) || !preg_match('/\.(?:js|css)$/i', $path)) continue;
            if (preg_match('#^(?:https?:)?//#i', $url)) continue;
            if (!str_starts_with($path, '/')) $path = $base . ltrim($path, './');
            if (!str_starts_with($path, $base)) throw new RuntimeException('前端入口资源路径与静态部署不一致，升级未通过健康检查');
            $relative = substr($path, strlen($base)); self::relative($relative);
            $file = $plan['static'] . '/' . $relative; self::safePath($file);
            if (!is_file($file)) throw new RuntimeException('前端入口资源缺失：' . $relative);
            $handle = curl_init($plan['deployment']['url'] . $path);
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
            $body = curl_exec($handle); $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
            if ($code !== 200 || !is_string($body) || !hash_equals((string)hash_file('sha256', $file), hash('sha256', $body))) throw new RuntimeException('前端入口资源未正确发布：' . $relative);
        }
    }

    public function manualInspect(): array
    {
        $plan = self::readJson($this->directory . '/plan.json');
        $handles = self::locks($plan);
        try {
            $this->job = self::readJson($this->directory . '/task.json');
            foreach ([...($this->job['process_groups'] ?? []), ...array_values($this->job['windows_processes'] ?? [])] as $identity) if (self::processActive($identity)) throw new RuntimeException('中断任务仍有子进程活动，拒绝恢复，请先完成进程收尾');
            if (!in_array($this->job['state'], ['recovery_required', 'failed'], true) || !is_file($this->directory . '/backup.json')) throw new RuntimeException('此任务没有可检查的中断备份');
            $entries = self::readJson($this->directory . '/backup.json');
            $scopes = self::scopes($plan);
            if (count($entries) !== count($scopes)) throw new RuntimeException('中断备份不完整');
            $changes = [];
            foreach ($entries as $index => $entry) {
                if ($entry['path'] !== $scopes[$index]['path'] || $entry['backup'] !== $this->directory . '/backup/' . $index || self::tree($entry['backup']) !== $entry['files'] || self::modes($entry['backup']) !== $entry['modes']) throw new RuntimeException('中断备份摘要或范围无效');
                $current = self::tree($entry['path'], $entry['exclude'] ?? []);
                foreach (array_unique([...array_keys($entry['files']), ...array_keys($current)]) as $file) if (($current[$file] ?? null) !== ($entry['files'][$file] ?? null)) $changes[] = $entry['path'] . ($file !== '' ? '/' . $file : '');
            }
            $confirmation = bin2hex(random_bytes(32));
            $record = ['confirmation_hash' => hash('sha256', $confirmation), 'fingerprint' => self::fingerprint($scopes), 'backup_fingerprint' => hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)), 'expires_at' => time() + 600];
            self::writeJson($this->directory . '/manual-inspection.json', $record);
            return ['id' => $this->job['id'], 'confirmation' => $confirmation, 'fingerprint' => $record['fingerprint'], 'expires_at' => $record['expires_at'], 'changed_files' => $changes, 'scopes' => array_column($scopes, 'path'), 'warning' => '手工恢复将先保全当前现场，然后覆盖上述范围为备份版本；不恢复数据库、不处理未知后台进程。确认前核对已停止中断任务的子进程及外部写者。'];
        } finally { self::unlock($handles); }
    }

    public function manualRecover(string $confirmation, string $fingerprint): void
    {
        $plan = self::readJson($this->directory . '/plan.json');
        $handles = self::locks($plan);
        try {
            $this->job = self::readJson($this->directory . '/task.json');
            foreach ([...($this->job['process_groups'] ?? []), ...array_values($this->job['windows_processes'] ?? [])] as $identity) if (self::processActive($identity)) throw new RuntimeException('中断任务仍有子进程活动，拒绝恢复');
            $record = self::readJson($this->directory . '/manual-inspection.json');
            if (!in_array($this->job['state'], ['recovery_required', 'failed'], true) || $record['expires_at'] < time() || !hash_equals($record['confirmation_hash'], hash('sha256', $confirmation)) || !hash_equals($record['fingerprint'], $fingerprint) || !hash_equals($fingerprint, self::fingerprint(self::scopes($plan)))) throw new RuntimeException('手工恢复确认无效、已过期或现场变化');
            $entries = self::readJson($this->directory . '/backup.json');
            if (!hash_equals($record['backup_fingerprint'], hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)))) throw new RuntimeException('中断备份记录已变化');
            $rescue = $this->directory . '/rescue-' . bin2hex(random_bytes(8));
            mkdir($rescue, 0700);
            $snapshot = [];
            foreach (self::scopes($plan) as $index => $scope) {
                $current = self::tree($scope['path'], $scope['exclude'] ?? []);
                $exists = file_exists($scope['path']);
                if ($exists) self::copy($scope['path'], $rescue . '/' . $index, $scope['exclude'] ?? []);
                if (self::tree($rescue . '/' . $index) !== $current) throw new RuntimeException('当前现场保全摘要不一致');
                $snapshot[] = $scope + ['backup' => $rescue . '/' . $index, 'files' => $current, 'exists' => $exists];
            }
            self::writeJson($rescue . '/rescue.json', $snapshot);
            if (!hash_equals($fingerprint, self::fingerprint(self::scopes($plan)))) throw new RuntimeException('保全期间现场变化，拒绝恢复');
            if (!rename($this->directory . '/manual-inspection.json', $rescue . '/confirmation.used.json')) throw new RuntimeException('无法消费手工恢复确认');
            $this->job['original_failure_fingerprint'] ??= $this->job['failure_fingerprint'] ?? null;
            $this->job['failure_fingerprint'] = $fingerprint;
            $this->job['rescue'] = $rescue;
            $this->job['mutated'] = true;
            $this->job['state'] = 'recovering';
            $this->emit('explicit_manual_recovery', '管理员显式确认现场；已保全当前现场，开始备份版本恢复');
            try { $this->restore($plan); $this->job['state'] = 'recovered'; $this->job['error'] = ''; $this->job['recovery_available'] = false; $this->emit('recovered', '手工恢复完成，健康检查通过'); }
            catch (Throwable $error) {
                $this->job['state'] = 'recovery_required'; $this->job['error'] = $error->getMessage();
                if ($this->recoveryMutated) $this->job['failure_fingerprint'] = self::fingerprint(self::scopes($plan));
                $this->job['recovery_available'] = $this->recoveryMutated;
                $this->emit('failed', $error->getMessage());
                throw $error;
            }
        } finally { self::unlock($handles); }
    }

    public static function copy(string $source, string $destination, array $exclude = []): void
    {
        self::safePath($source); self::safePath($destination);
        if (!file_exists($source)) throw new RuntimeException('所需发布或备份源不存在：' . $source);
        $files = self::tree($source, $exclude);
        if (is_file($source)) { if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true); if (!copy($source, $destination)) throw new RuntimeException('文件复制失败'); chmod($destination, fileperms($source) & 0777); return; }
        if (!is_dir($destination)) mkdir($destination, fileperms($source) & 0777, true);
        chmod($destination, fileperms($source) & 0777);
        foreach (self::modes($source, $exclude) as $relative => $mode) { if ($relative !== '' && is_dir($source . '/' . $relative)) { if (!is_dir($destination . '/' . $relative)) mkdir($destination . '/' . $relative, $mode, true); chmod($destination . '/' . $relative, $mode); } }
        foreach ($files as $relative => $hash) {
            $target = $destination . '/' . $relative;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            $parts = explode('/', dirname($relative)); $parent = '';
            foreach ($parts as $part) { if ($part === '.') continue; $parent .= '/' . $part; chmod($destination . $parent, fileperms($source . $parent) & 0777); }
            if (str_starts_with($hash, 'link:')) { if (!symlink(substr($hash, 5), $target)) throw new RuntimeException('链接复制失败'); }
            else { if (!copy($source . '/' . $relative, $target)) throw new RuntimeException('文件复制失败'); chmod($target, fileperms($source . '/' . $relative) & 0777); }
        }
    }

    public static function remove(string $root, array $exclude = [], bool $keepRoot = false): void
    {
        self::safePath($root);
        if (!file_exists($root)) return;
        if (is_file($root)) { if (!unlink($root)) throw new RuntimeException('文件恢复失败'); return; }
        $walk = static function (string $directory, bool $top) use (&$walk, $exclude, $keepRoot): void {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
                if ($top && in_array($item->getFilename(), $exclude, true)) continue;
                if (PHP_OS_FAMILY === 'Windows') self::safePath($item->getPathname());
                if ($item->isDir() && !$item->isLink()) $walk($item->getPathname(), false);
                elseif (!unlink($item->getPathname())) throw new RuntimeException('文件恢复失败');
            }
            if ((!$top || (!$exclude && !$keepRoot)) && !rmdir($directory)) throw new RuntimeException('目录恢复失败');
        };
        $walk($root, true);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $directory = $argv[1] ?? '';
    $mode = $argv[2] ?? 'run';
    if (!in_array($mode, ['run', 'recover', 'detach-run', 'detach-recover', 'manual-inspect', 'manual-recover'], true)) { fwrite(STDERR, "更新模式无效\n"); exit(2); }
    if (str_starts_with($mode, 'detach-')) {
        if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) { fwrite(STDERR, "独立进程能力不可用\n"); exit(2); }
        $pid = pcntl_fork();
        if ($pid < 0) exit(2);
        if ($pid > 0) exit(0);
        if (posix_setsid() < 0) exit(2);
    }
    try {
        $runtime = new SandSystemUpdateRuntime($directory);
        if ($mode === 'manual-inspect') fwrite(STDOUT, json_encode($runtime->manualInspect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        elseif ($mode === 'manual-recover') $runtime->manualRecover($argv[3] ?? '', $argv[4] ?? '');
        else $runtime->run(str_ends_with($mode, 'recover'));
    }
    catch (Throwable $error) { fwrite(STDERR, SandSystemUpdateRuntime::redact($error->getMessage()) . "\n"); exit(1); }
}
