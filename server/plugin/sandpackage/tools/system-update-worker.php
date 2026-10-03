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
        if (!rename($temp, $file)) throw new RuntimeException('无法原子保存更新记录');
    }

    public static function safePath(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || preg_match('#(?:^|/)\.\.?(/|$)#', $path)) throw new RuntimeException('更新路径必须为规范绝对路径');
        $current = '';
        foreach (explode('/', $path) as $part) {
            if ($part === '') continue;
            $current .= '/' . $part;
            if (is_link($current)) throw new RuntimeException('更新路径不能经过符号链接');
        }
    }

    /** Relative-path to hash map. Links are preserved only when confined to this tree. */
    public static function tree(string $root, array $exclude = []): array
    {
        self::safePath($root);
        if (!file_exists($root)) return [];
        if (is_file($root)) return ['' => hash_file('sha256', $root)];
        $files = [];
        $walk = static function (string $directory, string $prefix) use (&$walk, &$files, $root, $exclude): void {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
                $relative = $prefix . $item->getFilename();
                if (in_array(explode('/', $relative)[0], $exclude, true)) continue;
                if ($item->isLink()) {
                    $link = readlink($item->getPathname());
                    $resolved = realpath($item->getPathname());
                    if ($resolved === false || !str_starts_with($resolved, $root . '/')) throw new RuntimeException('更新目录含有外部或悬空链接：' . $relative);
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
        if ($plan['frontend'] === $plan['server'] || str_starts_with($plan['frontend'], $plan['server'] . '/') || str_starts_with($plan['server'], $plan['frontend'] . '/')) throw new RuntimeException('前端目录与宿主目录重叠');
        if ($plan['static'] === $plan['frontend'] || $plan['static'] === $plan['server'] || str_starts_with($plan['server'], $plan['static'] . '/')
            || str_starts_with($plan['static'], $plan['frontend'] . '/') || str_starts_with($plan['frontend'], $plan['static'] . '/')
            || str_starts_with($plan['static'], $plan['server'] . '/app') || str_starts_with($plan['static'], $plan['server'] . '/config') || str_starts_with($plan['static'], $plan['server'] . '/storage') || str_starts_with($plan['static'], $plan['server'] . '/runtime')
            || str_starts_with($plan['static'], $plan['server'] . '/vendor') || str_starts_with($plan['static'], $plan['server'] . '/plugin')
            || str_starts_with($plan['static'], $plan['root'] . '/') || $plan['static'] === $plan['root']) throw new RuntimeException('静态发布目录与更新源码或状态目录重叠');
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
        if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('#(?:^|/)\.\.?(/|$)#', $path)) throw new RuntimeException('发布清单路径无效');
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

    public static function command(array $argv, string $cwd, callable $output, int $timeout = 1800): string
    {
        if (!$argv || !array_is_list($argv)) throw new RuntimeException('更新命令未配置');
        foreach ($argv as $argument) if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) throw new RuntimeException('更新命令参数无效');
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
                self::command([...$plan['pnpm'], 'run', 'build'], $plan['frontend'], fn (string $line) => $this->emit('frontend', $line));
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
        $this->reloadAndHealth($plan);
    }

    private function reloadAndHealth(array $plan): void
    {
        $this->phase('reload', fn () => self::command($plan['reload'], $plan['server'], fn (string $line) => $this->emit('reload', $line), 120));
        $this->phase('health', fn () => self::command($plan['health'], $plan['server'], fn (string $line) => $this->emit('health', $line), 120));
    }

    public function manualInspect(): array
    {
        $plan = self::readJson($this->directory . '/plan.json');
        $handles = self::locks($plan);
        try {
            $this->job = self::readJson($this->directory . '/task.json');
            foreach ($this->job['process_groups'] ?? [] as $group) if (posix_kill(-$group, 0)) throw new RuntimeException('中断任务仍有子进程活动，拒绝恢复，请先完成进程收尾');
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
            foreach ($this->job['process_groups'] ?? [] as $group) if (posix_kill(-$group, 0)) throw new RuntimeException('中断任务仍有子进程活动，拒绝恢复');
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
