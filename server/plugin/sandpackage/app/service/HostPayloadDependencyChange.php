<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;
use Throwable;

/** Exact, resumable host manifest edits for a controlled plugin. */
final class HostPayloadDependencyChange
{
    private string $journal;
    private string $configPath;

    public function __construct(
        private readonly string $stateRoot,
        private readonly string $app,
        private readonly string $candidateRoot,
        private readonly string $composerPath,
        private readonly string $packagePath,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new RuntimeException('依赖变更插件标识无效');
        }
        foreach ([$stateRoot, $candidateRoot, $composerPath, $packagePath] as $path) {
            HostPayloadPlan::assertSafePath($path);
        }
        $this->configPath = HostPayloadPlan::directoryPrefix($candidateRoot) . 'config.json';
        $this->journal = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.dependencies.json';
    }

    public function hasJournal(): bool
    {
        HostPayloadPlan::assertSafePath($this->journal);
        return is_file($this->journal);
    }

    public function begin(): void
    {
        if ($this->hasJournal()) {
            $record = $this->read();
            if (!in_array($record['phase'], ['complete', 'rolled_back'], true)) {
                throw new RuntimeException('已有未完成的依赖清单变更');
            }
            $this->archiveFinished();
        }
        [$config, $configSha256] = $this->configuration();
        $id = bin2hex(random_bytes(16));
        $backup = $this->backupRoot($id);
        if (!mkdir($backup, 0700, true)) throw new RuntimeException('无法创建依赖清单备份目录');
        $entries = [];
        foreach ([
            ['kind' => 'composer', 'path' => $this->composerPath, 'backup' => 'composer.json'],
            ['kind' => 'package', 'path' => $this->packagePath, 'backup' => 'package.json'],
        ] as $spec) {
            if (!self::affected($config, $spec['kind'])) continue;
            $path = $spec['path'];
            HostPayloadPlan::assertSafePath($path);
            if (!is_file($path) || filesize($path) > 1048576) {
                throw new RuntimeException('宿主依赖清单缺失或过大');
            }
            $before = file_get_contents($path);
            if (!is_string($before)) throw new RuntimeException('无法读取宿主依赖清单');
            $after = self::render($before, $config, $spec['kind']);
            $backupPath = $backup . '/' . $spec['backup'];
            if (file_put_contents($backupPath, $before, LOCK_EX) !== strlen($before)
                || !chmod($backupPath, 0600)) {
                throw new RuntimeException('无法备份宿主依赖清单');
            }
            $entries[] = [
                'kind' => $spec['kind'], 'path' => $path, 'backup' => $spec['backup'],
                'before' => hash('sha256', $before), 'after' => hash('sha256', $after),
            ];
        }
        $this->save([
            'schema' => 1, 'app' => $this->app, 'id' => $id,
            'phase' => 'pending', 'config_sha256' => $configSha256,
            'entries' => $entries,
        ]);
        $this->inspectSnapshot();
    }

    /** @return array{phase:string,fingerprint:string,composer:bool,npm:bool} */
    public function inspectSnapshot(): array
    {
        return $this->inspectDependencySnapshot(false);
    }

    /** Only dead-job recovery may retain a verified update of its own provider requirement. */
    public function inspectDependencyRecoverySnapshot(): array
    {
        return $this->inspectDependencySnapshot(true);
    }

    private function inspectDependencySnapshot(bool $providerRecovery): array
    {
        $record = $this->read();
        [$config, $configSha256] = $this->configuration();
        if ($configSha256 !== $record['config_sha256']) {
            throw new RuntimeException('升级依赖声明已变化');
        }
        $expectedKinds = [];
        foreach (['composer', 'package'] as $kind) {
            if (self::affected($config, $kind)) $expectedKinds[] = $kind;
        }
        if (array_column($record['entries'], 'kind') !== $expectedKinds) {
            throw new RuntimeException('依赖清单事务缺少声明的宿主文件');
        }
        $actual = [];
        $backupRoot = $this->backupRoot($record['id']);
        $expectedNames = [];
        $provider = null;
        foreach ($record['entries'] as $entry) {
            $backupPath = $backupRoot . '/' . $entry['backup'];
            HostPayloadPlan::assertSafePath($backupPath);
            $before = is_file($backupPath) && !is_link($backupPath)
                ? file_get_contents($backupPath) : false;
            if (!is_string($before) || hash('sha256', $before) !== $entry['before']
                || hash('sha256', self::render($before, $config, $entry['kind'])) !== $entry['after']) {
                throw new RuntimeException('依赖清单备份或预期结果已变化');
            }
            $path = $entry['path'];
            HostPayloadPlan::assertSafePath($path);
            $current = is_file($path) ? hash_file('sha256', $path) : null;
            $allowed = match ($record['phase']) {
                'complete' => [$entry['after']],
                'rolled_back' => [$entry['before']],
                default => [$entry['before'], $entry['after']],
            };
            if (!in_array($current, $allowed, true)) {
                if (!$providerRecovery || $record['phase'] !== 'complete' || $entry['kind'] !== 'composer') throw new RuntimeException('宿主依赖清单被外部改写');
                $provider = $this->inspectProviderRequirement(self::render($before, $config, 'composer'), $path, $config);
                $provider['journal_sha256'] = hash_file('sha256', $this->journal);
                $provider['original_after_sha256'] = $entry['after'];
            }
            $actual[$entry['kind']] = $current;
            $expectedNames[$entry['backup']] = hash_file('sha256', $backupPath);
        }
        $names = is_dir($backupRoot) ? scandir($backupRoot) : false;
        if (!is_array($names) || array_values(array_diff($names, ['.', '..'])) !== array_keys($expectedNames)) {
            throw new RuntimeException('依赖清单备份目录包含未知内容');
        }
        $proof = [$record, $expectedNames, $actual];
        if ($provider !== null) $proof[] = $provider;
        $snapshot = [
            'phase' => $record['phase'],
            'fingerprint' => hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR)),
            'composer' => !empty($config['require']) || !empty($config['require-dev']),
            'npm' => !empty($config['dependencies']) || !empty($config['devDependencies']),
        ];
        if ($provider !== null) $snapshot['provider_requirement_update'] = $provider;
        return $snapshot;
    }

    private function inspectProviderRequirement(string $expected, string $path, array $config): array
    {
        $name = 'supdger/sand-package';
        if (isset($config['require'][$name]) || isset($config['require-dev'][$name])) throw new RuntimeException('候选自身声明安装器依赖，不能作为提供方更新处理');
        $actual = is_file($path) && filesize($path) <= 1048576 ? file_get_contents($path) : false;
        $old = json_decode($expected, true, 64, JSON_THROW_ON_ERROR);
        $next = is_string($actual) ? json_decode($actual, true, 64, JSON_THROW_ON_ERROR) : null;
        $previous = $old['require'][$name] ?? null;
        $requirement = $next['require'][$name] ?? null;
        if (!is_string($previous) || !is_string($requirement) || $previous === $requirement || $requirement === '' || strlen($requirement) > 256) throw new RuntimeException('提供方依赖变化不是已有 require 的单个版本值');
        [$offset, $length] = $this->providerRequirementToken($expected);
        $this->providerRequirementToken($actual);
        $replacement = json_encode($requirement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (substr_replace($expected, $replacement, $offset, $length) !== $actual) throw new RuntimeException('除提供方 require 版本值之外的清单内容已变化');
        $lockPath = dirname($this->composerPath) . '/composer.lock';
        $installedPath = dirname($this->composerPath) . '/vendor/composer/installed.json';
        $lock = $this->providerDocument($lockPath);
        $installed = $this->providerDocument($installedPath);
        $locked = $this->providerPackage($lock['packages'] ?? null);
        $current = $this->providerPackage($installed['packages'] ?? $installed);
        if ($this->providerPackage($lock['packages-dev'] ?? [], false) !== null) throw new RuntimeException('提供方不能同时登记为开发依赖');
        if (!class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled($name, false)) throw new RuntimeException('当前执行环境没有已安装的提供方身份');
        $identity = self::providerIdentityConditions($requirement, $locked, $current, $installedPath);
        if (in_array(false, $identity['conditions'], true)) throw new RuntimeException('提供方锁定记录、安装记录、版本要求与实际执行源码不一致');
        $version = $identity['version'];
        $reference = $identity['reference'];
        $installPath = $identity['install_path'];
        $packageRoot = $identity['package_root'];
        $code = [];
        foreach ([self::class, DependencyCommandRecovery::class, HostPayloadRuntimeChangeFiles::class, \plugin\sandpackage\app\logic\InstallLogic::class,
            \plugin\sandpackage\app\command\Recover::class, \plugin\sandpackage\app\controller\InstallController::class] as $class) {
            $relative = str_replace('plugin\\sandpackage\\', 'plugin/sandpackage/', $class);
            $relative = str_replace('\\', '/', $relative) . '.php';
            $expectedFile = $packageRoot . '/server/' . $relative;
            HostPayloadPlan::assertSafePath($expectedFile);
            if (!is_file($expectedFile)) throw new RuntimeException('已安装提供方缺少恢复入口源码');
            $code[$class] = hash_file('sha256', $expectedFile);
            if (!class_exists($class, false)) continue;
            $file = (new \ReflectionClass($class))->getFileName();
            $projection = base_path() . '/' . $relative;
            if (!is_string($file) || !in_array(realpath($file), [realpath($expectedFile), realpath($projection)], true)
                || hash_file('sha256', $file) !== $code[$class]) throw new RuntimeException('当前恢复入口源码与已安装提供方不一致');
        }
        return ['from_requirement' => $previous, 'to_requirement' => $requirement,
            'version' => $version, 'reference' => $reference, 'install_path' => realpath($installPath),
            'lock_sha256' => hash_file('sha256', $lockPath), 'installed_sha256' => hash_file('sha256', $installedPath), 'code' => $code,
            'other_lock_history_verified' => false];
    }

    /** Locate just the provider value in the root require object, keeping every other byte. */
    private function providerRequirementToken(string $raw): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null/', $raw, $matches, PREG_OFFSET_CAPTURE);
        $depth = 0;
        $requireDepth = null;
        $requireSeen = false;
        $keys = [];
        $value = null;
        foreach ($matches[0] as $index => [$token, $offset]) {
            if ($token === '{' || $token === '[') { $depth++; continue; }
            if ($token === '}' || $token === ']') {
                if ($depth === $requireDepth) $requireDepth = null;
                $depth--; continue;
            }
            if ($token[0] !== '"' || ($matches[0][$index + 1][0] ?? null) !== ':') continue;
            $key = json_decode($token, true, 8, JSON_THROW_ON_ERROR);
            if ($depth === 1 && $key === 'require') {
                if ($requireSeen || $token !== '"require"' || ($matches[0][$index + 2][0] ?? null) !== '{') throw new RuntimeException('root require 路径重复、转义或类型不可信');
                $requireSeen = true; $requireDepth = 2;
            } elseif ($depth === $requireDepth) {
                if (isset($keys[$key]) || $token !== json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) throw new RuntimeException('require 字段重复或转义形式不可信');
                $keys[$key] = true;
                if ($key === 'supdger/sand-package') {
                    [$scalar, $position] = $matches[0][$index + 2] ?? ['', 0];
                    if ($scalar === '' || $scalar[0] !== '"') throw new RuntimeException('提供方 require 不是字符串标量');
                    $value = [$position, strlen($scalar)];
                }
            }
        }
        if ($value === null) throw new RuntimeException('root require 缺少唯一提供方版本值');
        return $value;
    }

    /** Current worker metadata only; never refreshes Composer's process-local records. */
    public static function inspectCurrentProvider(string $composerPath): array
    {
        $unknown = ['status'=>'unavailable','runtime'=>['version'=>null,'reference'=>null],
            'lock'=>['version'=>null,'reference'=>null],'installed'=>['version'=>null,'reference'=>null],
            'conditions'=>null,'identity_matches'=>null];
        try {
            $name = 'supdger/sand-package';
            if (!class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled($name, false)) return $unknown;
            $runtime = ['version'=>\Composer\InstalledVersions::getPrettyVersion($name),'reference'=>\Composer\InstalledVersions::getReference($name)];
            $unknown['runtime'] = self::publicProviderIdentity($runtime);
            $manifest = self::providerDocument($composerPath);
            $requirement = $manifest['require'][$name] ?? null;
            if (!is_string($requirement)) return $unknown;
            $lock = self::providerDocument(dirname($composerPath).'/composer.lock');
            $installedPath = dirname($composerPath).'/vendor/composer/installed.json';
            $installed = self::providerDocument($installedPath);
            $locked = self::providerPackage($lock['packages'] ?? null);
            $current = self::providerPackage($installed['packages'] ?? $installed);
            $identity = self::providerIdentityConditions($requirement,$locked,$current,$installedPath);
            return ['status'=>'inspected','runtime'=>self::publicProviderIdentity($runtime),
                'lock'=>self::publicProviderIdentity(['version'=>$locked['version']??null,'reference'=>$locked['source']['reference']??null]),
                'installed'=>self::publicProviderIdentity(['version'=>$current['version']??null,'reference'=>$current['source']['reference']??null]),
                'conditions'=>$identity['conditions'],'identity_matches'=>!in_array(false,$identity['conditions'],true)];
        } catch (Throwable) { return $unknown; }
    }

    private static function publicProviderIdentity(array $identity): array
    {
        return ['version'=>is_string($identity['version']) && strlen($identity['version'])<=128 && preg_match('/^[vV]?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$identity['version'])===1 ? $identity['version'] : null,
            'reference'=>is_string($identity['reference']) && preg_match('/^[a-f0-9]{40}$/D',$identity['reference'])===1 ? $identity['reference'] : null];
    }

    private static function providerIdentityConditions(string $requirement, array $locked, array $current, string $installedPath): array
    {
        $name = 'supdger/sand-package';
        $version = \Composer\InstalledVersions::getPrettyVersion($name);
        $reference = \Composer\InstalledVersions::getReference($name);
        $installPath = \Composer\InstalledVersions::getInstallPath($name);
        $packageRoot = is_string($installPath) ? realpath($installPath) : false;
        $recordPath = $current['install-path'] ?? null;
        $recordPath = is_string($recordPath) ? dirname($installedPath).'/'.$recordPath : null;
        return ['version'=>$version,'reference'=>$reference,'install_path'=>$installPath,'package_root'=>$packageRoot,
            'conditions'=>[
                'runtime_version_is_string'=>is_string($version),
                'lock_version_equals_runtime'=>($locked['version']??null)===$version,
                'installed_version_equals_runtime'=>($current['version']??null)===$version,
                'runtime_reference_valid'=>is_string($reference) && preg_match('/^[a-f0-9]{40}$/D',$reference)===1,
                'lock_reference_equals_runtime'=>($locked['source']['reference']??null)===$reference,
                'installed_reference_equals_runtime'=>($current['source']['reference']??null)===$reference,
                'source_record_equal'=>($locked['source']??null)===($current['source']??null),
                'dist_record_equal'=>($locked['dist']??null)===($current['dist']??null),
                'runtime_install_path_is_string'=>is_string($installPath),
                'runtime_install_path_resolves'=>is_string($packageRoot),
                'installed_install_path_is_string'=>is_string($recordPath),
                'install_path_equals_runtime'=>is_string($recordPath) && realpath($recordPath)===realpath($packageRoot ?: ''),
                'requirement_matches_runtime'=>is_string($version) && self::providerVersionMatches($requirement,$version),
            ]];
    }

    private static function providerDocument(string $path): array
    {
        HostPayloadPlan::assertSafePath($path);
        if (!is_file($path) || filesize($path) > 8388608) throw new RuntimeException('提供方锁定或安装记录缺失、过大或类型不可信');
        $document = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($document)) throw new RuntimeException('提供方锁定或安装记录损坏');
        return $document;
    }

    private static function providerPackage(mixed $packages, bool $required = true): ?array
    {
        if (!is_array($packages) || !array_is_list($packages)) throw new RuntimeException('提供方包列表类型不可信');
        $found = null;
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) throw new RuntimeException('提供方包条目损坏');
            if ($package['name'] !== 'supdger/sand-package') continue;
            if ($found !== null) throw new RuntimeException('提供方包条目重复');
            $found = $package;
        }
        if ($required && $found === null) throw new RuntimeException('锁定或安装记录缺少提供方');
        return $found;
    }

    /** Recognized stable constraints; unknown forms never bypass the original raw manifest gate. */
    private static function providerVersionMatches(string $requirement, string $version): bool
    {
        $version = ltrim($version, 'vV');
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version) !== 1) return false;
        if ($requirement === '*') return true;
        if (preg_match('/^([~^])v?(\d+)\.(\d+)\.(\d+)$/D', $requirement, $range) === 1) {
            $minimum = $range[2].'.'.$range[3].'.'.$range[4];
            $maximum = $range[1] === '~' || ($range[2] === '0' && $range[3] !== '0')
                ? $range[2].'.'.((int)$range[3]+1).'.0'
                : ($range[2] === '0' ? '0.0.'.((int)$range[4]+1) : ((int)$range[2]+1).'.0.0');
            return version_compare($version, $minimum, '>=') && version_compare($version, $maximum, '<');
        }
        foreach (preg_split('/[ \t]+/', trim($requirement)) as $part) {
            if (preg_match('/^(>=|<=|>|<|=)?v?(\d+\.\d+\.\d+)$/D', $part, $bound) !== 1
                || !version_compare($version, $bound[2], $bound[1] ?: '==')) return false;
        }
        return true;
    }

    /** @return array{composer:bool,npm:bool} */
    public function apply(): array
    {
        $snapshot = $this->inspectSnapshot();
        if ($snapshot['phase'] === 'rolled_back') {
            throw new RuntimeException('已回滚的依赖清单不能继续部署');
        }
        if ($snapshot['phase'] === 'complete') {
            return ['composer' => $snapshot['composer'], 'npm' => $snapshot['npm']];
        }
        $record = $this->read();
        [$config] = $this->configuration();
        foreach ($record['entries'] as $entry) {
            $path = $entry['path'];
            $current = hash_file('sha256', $path);
            if ($current === $entry['after']) continue;
            if ($current !== $entry['before']) throw new RuntimeException('依赖清单发布前发生变化');
            $backup = $this->backupRoot($record['id']) . '/' . $entry['backup'];
            $after = self::render((string) file_get_contents($backup), $config, $entry['kind']);
            $temporary = $path . '.sandpackage-' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('无法暂存依赖清单');
            try {
                if (fwrite($handle, $after) !== strlen($after) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('依赖清单暂存未持久化');
                }
            } finally {
                fclose($handle);
            }
            try {
                if (hash_file('sha256', $path) !== $entry['before'] || !rename($temporary, $path)) {
                    throw new RuntimeException('宿主依赖清单在替换前发生变化');
                }
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
        }
        $record['phase'] = 'complete';
        $this->save($record);
        $this->inspectSnapshot();
        return ['composer' => $snapshot['composer'], 'npm' => $snapshot['npm']];
    }

    public function markRolledBack(): void
    {
        $this->inspectSnapshot();
        $record = $this->read();
        if ($record['phase'] === 'rolled_back') return;
        if ($record['phase'] !== 'pending') throw new RuntimeException('已部署的依赖清单不能标记为回滚');
        foreach ($record['entries'] as $entry) {
            if (hash_file('sha256', $entry['path']) !== $entry['before']) {
                throw new RuntimeException('依赖清单已变化，不能声明升级回滚');
            }
        }
        $record['phase'] = 'rolled_back';
        $this->save($record);
    }

    public function restore(): void
    {
        $snapshot = $this->inspectSnapshot();
        if ($snapshot['phase'] === 'rolled_back') return;
        $record = $this->read();
        foreach ($record['entries'] as $entry) {
            $path = $entry['path'];
            HostPayloadPlan::assertSafePath($path);
            $current = hash_file('sha256', $path);
            if ($current === $entry['before']) continue;
            if ($current !== $entry['after']) {
                throw new RuntimeException('宿主依赖清单恢复前发生变化');
            }
            $backup = $this->backupRoot($record['id']) . '/' . $entry['backup'];
            $before = file_get_contents($backup);
            if (!is_string($before) || hash('sha256', $before) !== $entry['before']) {
                throw new RuntimeException('宿主依赖备份已变化');
            }
            $temporary = $path . '.sandpackage-' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new RuntimeException('无法暂存依赖清单恢复内容');
            try {
                if (fwrite($handle, $before) !== strlen($before) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('依赖清单恢复内容未持久化');
                }
            } finally {
                fclose($handle);
            }
            try {
                if (hash_file('sha256', $path) !== $entry['after'] || !rename($temporary, $path)) {
                    throw new RuntimeException('宿主依赖清单恢复时发生变化');
                }
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
        }
        $record['phase'] = 'rolled_back';
        $this->save($record);
        $this->inspectSnapshot();
    }

    public function archiveFinished(): void
    {
        $record = $this->read();
        if (!in_array($record['phase'], ['complete', 'rolled_back'], true)) {
            throw new RuntimeException('依赖清单变更尚未完成');
        }
        $this->inspectSnapshot();
        $raw = file_get_contents($this->journal);
        $history = $this->journal . '.' . hash('sha256', $raw) . '.history';
        HostPayloadPlan::assertSafePath($history);
        if (file_exists($history) || !rename($this->journal, $history)) {
            throw new RuntimeException('无法归档依赖清单变更记录');
        }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function configuration(): array
    {
        HostPayloadPlan::assertSafePath($this->configPath);
        if (!is_file($this->configPath) || filesize($this->configPath) > 1048576) {
            throw new RuntimeException('升级依赖声明缺失或过大');
        }
        $raw = file_get_contents($this->configPath);
        $config = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($config)) throw new RuntimeException('升级依赖声明无效');
        foreach (['require', 'require-dev', 'dependencies', 'devDependencies'] as $field) {
            if (!isset($config[$field])) continue;
            if (!is_array($config[$field])) throw new RuntimeException('升级依赖列表无效');
            foreach ($config[$field] as $package => $version) {
                if (!is_string($package) || $package === '' || !is_string($version) || $version === '') {
                    throw new RuntimeException('升级依赖项无效');
                }
            }
        }
        if (isset($config['composerConfig']) && !is_array($config['composerConfig'])) {
            throw new RuntimeException('Composer 配置声明无效');
        }
        return [$config, hash('sha256', $raw)];
    }

    private static function affected(array $config, string $kind): bool
    {
        return $kind === 'composer'
            ? !empty($config['require']) || !empty($config['require-dev']) || !empty($config['composerConfig'])
            : !empty($config['dependencies']) || !empty($config['devDependencies']);
    }

    private static function render(string $before, array $config, string $kind): string
    {
        $document = json_decode($before, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !is_string($document['name'] ?? null)) {
            throw new RuntimeException('宿主依赖清单格式无效');
        }
        $fields = $kind === 'composer'
            ? ['require', 'require-dev'] : ['dependencies', 'devDependencies'];
        foreach ($fields as $field) {
            if (empty($config[$field])) continue;
            if (!isset($document[$field])) $document[$field] = [];
            if (!is_array($document[$field])) throw new RuntimeException('宿主依赖列表格式无效');
            $document[$field] = array_merge($document[$field], $config[$field]);
        }
        if ($kind === 'composer') {
            if (!empty($config['composerConfig'])
                && isset($document['config']) && !is_array($document['config'])) {
                throw new RuntimeException('宿主 Composer 配置格式无效');
            }
            foreach ($config['composerConfig'] ?? [] as $key => $value) {
                if (is_array($value)) {
                    if (!isset($document['config'][$key])) $document['config'][$key] = [];
                    if (!is_array($document['config'][$key])) {
                        throw new RuntimeException('宿主 Composer 配置类型冲突');
                    }
                    $document['config'][$key] = array_merge($document['config'][$key], $value);
                } else {
                    $document['config'][$key] = $value;
                }
            }
        }
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $raw = is_file($this->journal) && filesize($this->journal) <= 1048576
            ? file_get_contents($this->journal) : false;
        $record = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($record) || count($record) !== 6 || ($record['schema'] ?? null) !== 1
            || ($record['app'] ?? null) !== $this->app
            || !is_string($record['id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $record['id']) !== 1
            || !in_array($record['phase'] ?? null, ['pending', 'complete', 'rolled_back'], true)
            || !is_string($record['config_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $record['config_sha256']) !== 1
            || !is_array($record['entries'] ?? null) || count($record['entries']) > 2) {
            throw new RuntimeException('依赖清单变更记录损坏');
        }
        $expected = ['composer' => $this->composerPath, 'package' => $this->packagePath];
        foreach ($record['entries'] as $entry) {
            if (!is_array($entry) || count($entry) !== 5
                || !isset($expected[$entry['kind'] ?? ''])
                || $entry['path'] !== $expected[$entry['kind']]
                || $entry['backup'] !== ($entry['kind'] === 'composer' ? 'composer.json' : 'package.json')
                || !is_string($entry['before'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['before']) !== 1
                || !is_string($entry['after'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['after']) !== 1) {
                throw new RuntimeException('依赖清单变更路径记录无效');
            }
        }
        return $record;
    }

    private function backupRoot(string $id): string
    {
        $path = HostPayloadPlan::directoryPrefix($this->stateRoot) . $this->app . '.dependency-backup-' . $id;
        HostPayloadPlan::assertSafePath($path);
        return $path;
    }

    public function confirmProviderRecovery(array $checkpoint, string $closedSha256, array $current): void
    {
        if (!isset($checkpoint['before']['transactions']['dependencies']['provider_requirement_update'])) return;
        $path = $this->transitionPath();
        $record = $this->readProviderTransition();
        if ($record === null) {
            $record = ['schema'=>1, 'app'=>$this->app, 'recovery_sha256'=>$closedSha256,
                'original'=>$this->providerBinding($checkpoint['before']), 'active_command'=>null, 'completed'=>null];
            $this->save($record, $path);
        } else {
            if ($record['recovery_sha256'] === $closedSha256 && $record['original'] === $this->providerBinding($checkpoint['before'])) {
                // The preceding transition write may have completed before checkpoint archival.
            } else {
                $this->validateProviderTransition($record);
                if ($checkpoint['purpose'] === 'interrupted-dependency') {
                    $binding=$this->providerBinding($checkpoint['before']);
                    foreach (['identity','candidate','runtime','owned'] as $field) if ($binding[$field]!==$record['original'][$field]) throw new RuntimeException('Provider transition origin changed');
                    $record['active_command']=null;$record['completed']=null;$this->save($record,$path);
                }
            }
        }
        if ($checkpoint['purpose'] === 'completed-dependency') $this->recordProviderCompletion(($record['active_command']['type'] ?? ''), ($checkpoint['before']['info']['dependency_completed_nonce'] ?? ''), $current);
    }

    public function bindProviderCommand(string $type, string $nonce, array $snapshot): void
    {
        $record=$this->readProviderTransition();if ($record===null) return;
        $this->validateProviderTransition($record);
        $binding=$this->providerBinding($snapshot);
        foreach (['identity','candidate','runtime','owned'] as $field) if ($binding[$field]!==$record['original'][$field]) throw new RuntimeException('Provider new job candidate changed');
        $old=$this->confirmedProviderRequirement($record);$now=$binding['transactions']['dependencies']['provider_requirement_update'];
        $confirmedPath=$this->providerBindingPath();
        if ($record['active_command']===null && is_file($confirmedPath)) {
            $confirmed=json_decode(file_get_contents($confirmedPath),true,128,JSON_THROW_ON_ERROR);
            if ($binding!==$this->providerBinding($confirmed['before'])) throw new RuntimeException('Confirmed provider job inputs changed');
        }
        foreach(['lock_sha256','installed_sha256'] as $field){unset($old[$field],$now[$field]);}
        if($old!==$now)throw new RuntimeException('Provider new job identity changed');
        $record['active_command']=['type'=>$type,'nonce'=>$nonce,'before'=>$binding];$record['completed']=null;
        $this->save($record,$this->transitionPath());
    }

    public function recoveredRuntimeArchive(): ?string
    {
        $record=$this->readProviderTransition();if ($record===null) return null;
        $this->validateProviderTransition($record);
        return $record['original']['transactions']['runtime']['journal_sha256']??null;
    }

    public function providerCompletionProof(string $type, string $nonce, array $snapshot): array
    {
        $record=$this->readProviderTransition();
        if ($record===null) throw new RuntimeException('Provider transition missing');
        $this->validateProviderTransition($record);
        if (($record['active_command']['nonce']??null)!==$nonce || ($record['active_command']['type']??null)!==$type) throw new RuntimeException('Provider completion is not the bound new job');
        $this->assertProviderTarget($record,$this->providerBinding($snapshot));
        return ['nonce'=>$nonce,'binding'=>$this->providerBinding($snapshot)];
    }

    public function recordProviderCompletion(string $type, string $nonce, array $snapshot): void
    {
        $record=$this->readProviderTransition();
        if ($record === null) return;
        $this->validateProviderTransition($record);
        if (($snapshot['info']['state']??null)!==1 || ($snapshot['info']['dependency_completed_nonce']??null)!==$nonce
            || !empty($snapshot['info']['dependency_command_nonce']) || !empty($snapshot['info']['composer_dependent_wait_install'])
            || !empty($snapshot['info']['npm_dependent_wait_install'])) return;
        if (($record['active_command']['nonce']??null)!==$nonce || ($record['active_command']['type']??null)!==$type) throw new RuntimeException('Provider completion is not the bound new job');
        $binding=$this->providerBinding($snapshot);
        $origin=$record['original'];
        foreach (['identity','candidate','runtime','owned'] as $field) {
            if ($binding[$field]!==$origin[$field]) throw new RuntimeException('Provider completion candidate changed');
        }
        $old=$this->confirmedProviderRequirement($record);
        $now=$binding['transactions']['dependencies']['provider_requirement_update'];
        foreach (['lock_sha256','installed_sha256'] as $field) {unset($old[$field],$now[$field]);}
        if ($old!==$now) throw new RuntimeException('Provider completion identity changed');
        $completed=['nonce'=>$nonce,'binding'=>$binding];
        if (($snapshot['info']['dependency_provider_completion_sha256']??null)!==hash('sha256',json_encode($completed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))) throw new RuntimeException('Provider completion proof is not durably registered');
        $record['completed']=$completed;
        $this->save($record,$this->transitionPath());
    }

    private function assertProviderTarget(array $record, array $binding): void
    {
        foreach (['identity','candidate','runtime','owned'] as $field) {
            if ($binding[$field]!==$record['original'][$field]) throw new RuntimeException('Provider completion candidate changed');
        }
        $old=$this->confirmedProviderRequirement($record);$now=$binding['transactions']['dependencies']['provider_requirement_update'];
        foreach (['lock_sha256','installed_sha256'] as $field) {unset($old[$field],$now[$field]);}
        if ($old!==$now) throw new RuntimeException('Provider completion identity changed');
    }

    public function archiveRecoveredProviderChange(array $snapshot, ?callable $fault = null): void
    {
        $record=$this->readProviderTransition();
        if ($record===null || $record['completed']===null) throw new RuntimeException('Provider transition is not completed');
        $this->validateProviderTransition($record);
        $this->confirmedProviderRequirement($record);
        if ($snapshot['transactions']['dependencies']===null) $snapshot['transactions']['dependencies']=$this->inspectArchivedDependency($record);
        if (($snapshot['info']['state']??null)!==1 || ($snapshot['info']['dependency_completed_nonce']??null)!==$record['completed']['nonce']
            || !empty($snapshot['info']['dependency_command_nonce']) || !empty($snapshot['info']['composer_dependent_wait_install'])
            || !empty($snapshot['info']['npm_dependent_wait_install']) || $this->providerBinding($snapshot)!==$record['completed']['binding']
            || ($snapshot['info']['dependency_provider_completion_sha256']??null)!==hash('sha256',json_encode($record['completed'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))) throw new RuntimeException('Provider transition completion scene changed');
        $sha=$record['original']['transactions']['dependencies']['provider_requirement_update']['journal_sha256'];
        $history=$this->journal.'.'.$sha.'.history';HostPayloadPlan::assertSafePath($history);
        if (is_file($this->journal)) {
            if (hash_file('sha256',$this->journal)!==$sha || file_exists($history) || !rename($this->journal,$history)) throw new RuntimeException('Provider dependency archive changed');
        } elseif (!is_file($history) || hash_file('sha256',$history)!==$sha) throw new RuntimeException('Provider dependency archive is missing');
        $fault?->__invoke('dependency-journal-archived');
        $bindingPath=$this->providerBindingPath();
        if (file_exists($bindingPath)) {
            $this->confirmedProviderRequirement($record);
            $bindingHistory=$bindingPath.'.'.hash_file('sha256',$bindingPath).'.history';
            HostPayloadPlan::assertSafePath($bindingHistory);
            if (file_exists($bindingHistory) || !rename($bindingPath,$bindingHistory)) throw new RuntimeException('Provider binding archive incomplete');
        }
        $fault?->__invoke('provider-binding-archived');
        $path=$this->transitionPath();$closed=$path.'.'.hash_file('sha256',$path).'.history';HostPayloadPlan::assertSafePath($closed);
        if (file_exists($closed) || !rename($path,$closed)) throw new RuntimeException('Provider transition archive incomplete');
    }

    public function hasProviderTransition(): bool {
        if (!file_exists($this->transitionPath()) && file_exists($this->providerBindingPath())) throw new RuntimeException('Provider binding transition missing');
        return file_exists($this->transitionPath());
    }

    /** A provider update after reset needs a new confirmation before any job begins. */
    public function inspectProviderBinding(array $snapshot): ?array
    {
        $record=$this->readProviderTransition();
        if ($record===null) return null;
        $active=HostPayloadPlan::directoryPrefix($this->stateRoot).$this->app.'.dependency-recovery.json';
        HostPayloadPlan::assertSafePath($active);
        if (file_exists($active)) {
            if (file_exists($this->providerBindingPath())) $this->confirmedProviderRequirement($record);
            return null;
        }
        $this->validateProviderTransition($record);
        if ($record['active_command']!==null || $record['completed']!==null) return null;
        $info=$snapshot['info'];
        if (($info['state']??null)!==4 || !empty($info['dependency_command_nonce'])
            || !empty($info['dependency_completed_nonce']) || !empty($info['dependency_failed_nonce'])
            || empty($info['composer_dependent_wait_install']) && empty($info['npm_dependent_wait_install'])) return null;
        $marker=HostPayloadPlan::directoryPrefix(dirname($this->stateRoot)).'locks/host-upstream.process.json';
        HostPayloadPlan::assertSafePath($marker);
        if (file_exists($marker) || file_exists(HostPayloadPlan::directoryPrefix($this->stateRoot).$this->app.'.dependency-recovery.json')) return null;
        $binding=$this->providerBinding($snapshot);
        foreach (['identity','candidate','runtime','owned'] as $field) {
            if ($binding[$field]!==$record['original'][$field]) throw new RuntimeException('Provider binding candidate changed');
        }
        $old=$this->confirmedProviderRequirement($record);
        $now=$binding['transactions']['dependencies']['provider_requirement_update']??null;
        if (!is_array($now)) throw new RuntimeException('Provider binding current proof missing');
        foreach (['from_requirement','journal_sha256','original_after_sha256','other_lock_history_verified'] as $field) {
            if (($now[$field]??null)!==($old[$field]??null)) throw new RuntimeException('Provider binding dependency origin changed');
        }
        foreach (['lock_sha256','installed_sha256'] as $field) {unset($old[$field],$now[$field]);}
        if ($old===$now) return null;
        $path=$this->providerBindingPath();
        return ['app'=>$this->app,'operation'=>'provider-binding-refresh','phase'=>'confirmation_required','state'=>4,
            'actions'=>['refresh-provider-binding'],'command_success_proven'=>false,'sql_replayed'=>false,
            'fingerprint'=>hash('sha256',json_encode([$snapshot,$record,is_file($path)?hash_file('sha256',$path):null,hash_file('sha256',$this->transitionPath())],JSON_THROW_ON_ERROR)),
            'next_action'=>'使用本次完整确认更新提供方绑定；不会启动依赖任务或修改原恢复证明'];
    }

    public function confirmProviderBinding(array $snapshot, string $confirmation): array
    {
        $inspection=$this->inspectProviderBinding($snapshot);
        if ($inspection===null || !hash_equals('RECOVER DEPENDENCY '.$this->app.' '.$inspection['fingerprint'],$confirmation)) {
            throw new RuntimeException('请重新检查提供方绑定并使用本次完整确认');
        }
        $record=$this->readProviderTransition();
        $provider=$snapshot['transactions']['dependencies']['provider_requirement_update'];
        $binding=['schema'=>1,'app'=>$this->app,'recovery_sha256'=>$record['recovery_sha256'],
            'original_sha256'=>hash('sha256',json_encode($record['original'],JSON_THROW_ON_ERROR)),
            'provider'=>$provider,'before'=>$snapshot,
            'previous_sha256'=>is_file($this->providerBindingPath())?hash_file('sha256',$this->providerBindingPath()):null,
            'confirmation_fingerprint'=>$inspection['fingerprint'],'transition_sha256'=>hash_file('sha256',$this->transitionPath())];
        $path=$this->providerBindingPath();
        if (file_exists($path)) {
            $this->confirmedProviderRequirement($record);
            $history=$path.'.'.hash_file('sha256',$path).'.history';HostPayloadPlan::assertSafePath($history);
            if (file_exists($history) || !rename($path,$history)) throw new RuntimeException('Previous provider binding archive incomplete');
        }
        $this->save($binding,$path);
        return ['app'=>$this->app,'action'=>'refresh-provider-binding','state'=>4,'sql_replayed'=>false,
            'command_started'=>false,'next_action'=>'提供方绑定已更新；从正式依赖入口明确执行仍等待的任务'];
    }

    private function providerBindingPath(): string
    {
        $path=HostPayloadPlan::directoryPrefix($this->stateRoot).$this->app.'.provider-dependency-binding.json';
        HostPayloadPlan::assertSafePath($path);return $path;
    }

    private function confirmedProviderRequirement(array $record, ?string $path = null): array
    {
        $path ??= $this->providerBindingPath();
        if (!file_exists($path)) {
            $old=$record['original']['transactions']['dependencies']['provider_requirement_update'];
            if ($record['completed']===null) return $old;
            $target=$record['completed']['binding']['transactions']['dependencies']['provider_requirement_update'];
            foreach (['lock_sha256','installed_sha256'] as $field) {unset($old[$field],$target[$field]);}
            if ($old===$target) return $record['original']['transactions']['dependencies']['provider_requirement_update'];
            $matched=[];
            foreach (glob($path.'.*.history') ?: [] as $history) {
                HostPayloadPlan::assertSafePath($history);
                if (!is_file($history) || substr($history,-72,-8)!==hash_file('sha256',$history)) throw new RuntimeException('Provider binding archive changed');
                $provider=$this->confirmedProviderRequirement($record,$history);
                foreach (['lock_sha256','installed_sha256'] as $field) unset($provider[$field]);
                if ($provider===$target) $matched[]=$history;
            }
            if (count($matched)!==1) throw new RuntimeException('Confirmed provider binding archive missing');
            return $this->confirmedProviderRequirement($record,$matched[0]);
        }
        if (!is_file($path) || filesize($path)>16777216) throw new RuntimeException('Provider binding record unsafe');
        $binding=json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        if (!is_array($binding) || count($binding)!==9 || ($binding['schema']??null)!==1 || ($binding['app']??null)!==$this->app
            || ($binding['recovery_sha256']??null)!==$record['recovery_sha256']
            || ($binding['original_sha256']??null)!==hash('sha256',json_encode($record['original'],JSON_THROW_ON_ERROR))
            || !is_array($binding['provider']??null) || !is_array($binding['before']??null)
            || !array_key_exists('previous_sha256',$binding) || !is_string($binding['transition_sha256']??null) || preg_match('/^[a-f0-9]{64}$/D',$binding['transition_sha256'])!==1 || !is_string($binding['confirmation_fingerprint']??null)) throw new RuntimeException('Provider binding is not the confirmed recovery origin');
        $before=$binding['before'];$info=$before['info']??[];
        if (($info['state']??null)!==4 || !empty($info['dependency_command_nonce']) || !empty($info['dependency_completed_nonce'])
            || !empty($info['dependency_failed_nonce']) || empty($info['composer_dependent_wait_install']) && empty($info['npm_dependent_wait_install'])
            || ($before['transactions']['dependencies']['provider_requirement_update']??null)!==$binding['provider']) throw new RuntimeException('Provider binding confirmation scene invalid');
        $origin=$this->providerBinding($before);
        foreach (['identity','candidate','runtime','owned'] as $field) if ($origin[$field]!==$record['original'][$field]) throw new RuntimeException('Provider binding confirmation candidate changed');
        foreach (['from_requirement','journal_sha256','original_after_sha256','other_lock_history_verified'] as $field) {
            if (($binding['provider'][$field]??null)!==($record['original']['transactions']['dependencies']['provider_requirement_update'][$field]??null)) throw new RuntimeException('Provider binding confirmation origin changed');
        }
        $previous=$binding['previous_sha256'];
        if ($previous!==null) {
            if (!is_string($previous) || preg_match('/^[a-f0-9]{64}$/D',$previous)!==1) throw new RuntimeException('Previous provider binding invalid');
            $history=$this->providerBindingPath().'.'.$previous.'.history';HostPayloadPlan::assertSafePath($history);
            if (!is_file($history) || hash_file('sha256',$history)!==$previous) throw new RuntimeException('Previous provider binding archive changed');
        }
        $confirmed=$record;$confirmed['active_command']=null;$confirmed['completed']=null;
        $expected=hash('sha256',json_encode([$before,$confirmed,$previous,$binding['transition_sha256']],JSON_THROW_ON_ERROR));
        if (!hash_equals($expected,$binding['confirmation_fingerprint'])) throw new RuntimeException('Provider binding confirmation changed');
        return $binding['provider'];
    }

    public function inspectProviderTransition(?array $checkpoint, array $current): ?string
    {
        $record=$this->readProviderTransition();if ($record===null) return null;
        $nonce=$current['info']['dependency_command_nonce']??null;
        if (!empty($nonce) && ($record['active_command']['nonce']??null)!==$nonce) throw new RuntimeException('Provider transition does not own current job');
        $active=HostPayloadPlan::directoryPrefix($this->stateRoot).$this->app.'.dependency-recovery.json';
        if ($checkpoint!==null && ($checkpoint['phase']??null)==='nonce_cleared' && is_file($active)
            && hash_file('sha256',$active)===$record['recovery_sha256'] && $record['original']===$this->providerBinding($checkpoint['before']) && $record['completed']===null) {
            return hash_file('sha256',$this->transitionPath());
        }
        $this->validateProviderTransition($record);
        $this->confirmedProviderRequirement($record);
        $path=$this->providerBindingPath();
        if (!file_exists($path)) return hash_file('sha256',$this->transitionPath());
        $this->confirmedProviderRequirement($record);
        return hash('sha256',json_encode([hash_file('sha256',$this->transitionPath()),hash_file('sha256',$path)],JSON_THROW_ON_ERROR));
    }

    private function inspectArchivedDependency(array $record): array
    {
        $journal=$this->journal;$sha=$record['original']['transactions']['dependencies']['provider_requirement_update']['journal_sha256'];
        try {$this->journal=$journal.'.'.$sha.'.history';return $this->inspectDependencyRecoverySnapshot();}
        finally {$this->journal=$journal;}
    }

    private function providerBinding(array $snapshot): array
    {
        $identity=array_intersect_key($snapshot['info'],array_flip(['app','version','package_sha256','lifecycle_driver']));
        unset($snapshot['candidate']['info.ini']);
        return ['identity'=>$identity,'candidate'=>$snapshot['candidate'],'runtime'=>$snapshot['runtime'],'owned'=>$snapshot['owned'],
            'transactions'=>$snapshot['transactions'],'dependencies'=>$snapshot['dependencies']];
    }

    private function transitionPath(): string
    {
        $path=HostPayloadPlan::directoryPrefix($this->stateRoot).$this->app.'.provider-dependency-transition.json';
        HostPayloadPlan::assertSafePath($path);return $path;
    }

    private function readProviderTransition(): ?array
    {
        $path=$this->transitionPath();if (!file_exists($path)) {
            if (file_exists($this->providerBindingPath())) throw new RuntimeException('Provider binding transition missing');
            return null;
        }
        if (!is_file($path) || filesize($path)>16777216) throw new RuntimeException('Provider transition record unsafe');
        $record=json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        if (!is_array($record) || count($record)!==6 || ($record['schema']??null)!==1 || ($record['app']??null)!==$this->app
            || !is_string($record['recovery_sha256']??null) || preg_match('/^[a-f0-9]{64}$/D',$record['recovery_sha256'])!==1
            || !is_array($record['original']??null) || !array_key_exists('active_command',$record) || !array_key_exists('completed',$record)
            || ($record['active_command']!==null && (!is_array($record['active_command']) || count($record['active_command'])!==3
                || !in_array($record['active_command']['type']??null,['composer','npm'],true) || !is_string($record['active_command']['nonce']??null)
                || preg_match('/^[a-f0-9]{32}$/D',$record['active_command']['nonce'])!==1 || !is_array($record['active_command']['before']??null)))
            || ($record['completed']!==null && (!is_array($record['completed']) || count($record['completed'])!==2
                || !is_string($record['completed']['nonce']??null) || preg_match('/^[a-f0-9]{32}$/D',$record['completed']['nonce'])!==1
                || !is_array($record['completed']['binding']??null)))) throw new RuntimeException('Provider transition record corrupt');
        return $record;
    }

    private function validateProviderTransition(array $record): void
    {
        $history=HostPayloadPlan::directoryPrefix($this->stateRoot).$this->app.'.dependency-recovery.json.'.$record['recovery_sha256'].'.history';
        HostPayloadPlan::assertSafePath($history);
        if (!is_file($history) || hash_file('sha256',$history)!==$record['recovery_sha256']) throw new RuntimeException('Provider recovery is not durably closed');
        $checkpoint=json_decode(file_get_contents($history),true,128,JSON_THROW_ON_ERROR);
        if (!is_array($checkpoint) || count($checkpoint)!==8 || !is_string($checkpoint['id']??null) || preg_match('/^[a-f0-9]{32}$/D',$checkpoint['id'])!==1 || ($checkpoint['schema']??null)!==1 || ($checkpoint['app']??null)!==$this->app || ($checkpoint['phase']??null)!=='nonce_cleared'
            || ($checkpoint['purpose']??null)!=='interrupted-dependency' || !is_array($checkpoint['before']??null)
            || $record['original']!==$this->providerBinding($checkpoint['before'])) throw new RuntimeException('Provider recovery origin not bound');
        $marker=HostPayloadPlan::directoryPrefix(dirname($this->stateRoot)).'locks/host-upstream.process.json.'.$checkpoint['marker_sha256'].'.history';
        HostPayloadPlan::assertSafePath($marker);
        if (!is_file($marker) || hash_file('sha256',$marker)!==$checkpoint['marker_sha256']) throw new RuntimeException('Original provider recovery process archive changed');
        $process=json_decode(file_get_contents($marker),true,32,JSON_THROW_ON_ERROR);
        if (($process['app']??null)!==$this->app || ($process['nonce']??null)!==($checkpoint['before']['info']['dependency_command_nonce']??null)) throw new RuntimeException('Original provider recovery nonce changed');
    }

    /** @param array<string,mixed> $record */
    private function save(array $record, ?string $path = null): void
    {
        $path ??= $this->journal;
        HostPayloadPlan::assertSafePath($path);
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建依赖清单状态目录');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $body = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('无法创建依赖清单记录');
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('依赖清单记录未持久化');
            }
        } catch (Throwable $error) {
            fclose($handle);
            unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('无法保存依赖清单记录');
        }
    }
}
