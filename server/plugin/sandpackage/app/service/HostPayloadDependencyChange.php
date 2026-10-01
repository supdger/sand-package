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
                throw new RuntimeException('宿主依赖清单被外部改写');
            }
            $actual[$entry['kind']] = $current;
            $expectedNames[$entry['backup']] = hash_file('sha256', $backupPath);
        }
        $names = is_dir($backupRoot) ? scandir($backupRoot) : false;
        if (!is_array($names) || array_values(array_diff($names, ['.', '..'])) !== array_keys($expectedNames)) {
            throw new RuntimeException('依赖清单备份目录包含未知内容');
        }
        return [
            'phase' => $record['phase'],
            'fingerprint' => hash('sha256', json_encode([$record, $expectedNames, $actual], JSON_THROW_ON_ERROR)),
            'composer' => !empty($config['require']) || !empty($config['require-dev']),
            'npm' => !empty($config['dependencies']) || !empty($config['devDependencies']),
        ];
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

    /** @param array<string,mixed> $record */
    private function save(array $record): void
    {
        HostPayloadPlan::assertSafePath($this->journal);
        $parent = dirname($this->journal);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('无法创建依赖清单状态目录');
        }
        $temporary = $this->journal . '.' . bin2hex(random_bytes(8)) . '.tmp';
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
        if (!rename($temporary, $this->journal)) {
            unlink($temporary);
            throw new RuntimeException('无法保存依赖清单记录');
        }
    }
}
