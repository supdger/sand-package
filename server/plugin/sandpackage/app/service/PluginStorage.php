<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use plugin\sandadmin\exception\ApiException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Resolves the single persistent plugin registry root and safely discovers local plugins. */
final class PluginStorage
{
    private const RESERVED = ['sandadmin', 'sandpackage', 'saiadmin', 'saipackage', 'locks', 'backups', 'archives', 'uploads', 'replacements', 'quarantine', 'fresh-recovery', 'runtime-restores', 'cleanup'];

    public function __construct(private ?string $runtime = null, private ?string $server = null)
    {
        $this->runtime ??= rtrim(runtime_path(), DIRECTORY_SEPARATOR);
        $this->server ??= rtrim(base_path(), DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        $new = $this->newRoot();
        $old = $this->legacyRoot();
        $newData = $this->hasData($new);
        $oldData = $this->hasData($old);
        $oldLocked = $this->hasHeldLock($old);
        if ($newData && $oldData) throw new ApiException('新旧插件存储根同时含有数据；请在维护窗口使用 sandpackage:storage-migrate 检查，未执行变更');
        if ($oldLocked && $newData) throw new ApiException('旧插件存储根存在活动锁，不能切换到新根');
        return $oldData || $oldLocked ? $old : $new;
    }

    public function newRoot(): string
    {
        return $this->server . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sandpackage';
    }

    public function legacyRoot(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'sandpackage';
    }

    /** Explicit maintenance operation; inspecting never creates directories or locks. */
    public function migrate(bool $apply = false): array
    {
        $from = $this->legacyRoot();
        $to = $this->newRoot();
        $this->assertDirectoryPath($from);
        $this->assertDirectoryPath($to);
        $result = ['from' => $from, 'to' => $to, 'apply' => $apply, 'migrated' => false];
        if (!$this->hasData($from)) return $result + ['reason' => '旧存储根没有需迁移的数据'];
        if (file_exists($to)) throw new ApiException('新存储根已存在；不会自动删除或覆盖目录');
        $handles = [];
        try {
            $locks = glob($from . '/locks/*.lock') ?: [];
            sort($locks, SORT_STRING);
            foreach ($locks as $path) {
                if (is_link($path) || !is_file($path)) throw new ApiException('锁文件不安全');
                $handle = @fopen($path, 'r');
                if ($handle === false) throw new ApiException('无法读取安装操作锁');
                $handles[] = $handle;
                if (!flock($handle, LOCK_EX | LOCK_NB)) throw new ApiException('存在活动插件操作锁；拒绝迁移');
            }
            $this->assertMigrationReady($from);
            $result['reason'] = '数据检查通过；执行前仍须停止全部写者并确认维护窗口';
            if (!$apply) return $result;
            $parent = dirname($to);
            $existing = $parent;
            while (!is_dir($existing)) $existing = dirname($existing);
            if (stat($from)['dev'] !== stat($existing)['dev']) throw new ApiException('新旧存储不在同一文件系统；拒绝复制删除迁移');
            if (!is_dir($parent) && !@mkdir($parent, 0700, true)) throw new ApiException('无法准备新存储父目录；原数据保持不变');
            $this->assertDirectoryPath($to);
            if (file_exists($to) || !@rename($from, $to)) throw new ApiException('存储根原子迁移失败；原数据保持不变');
            return array_merge($result, ['migrated' => true, 'reason' => '完整旧根已原子迁移；请重新启动宿主']);
        } finally {
            foreach (array_reverse($handles) as $handle) { flock($handle, LOCK_UN); fclose($handle); }
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function managedRecords(): array
    {
        $records = [];
        $root = $this->root();
        foreach ($this->directories($root) as $app => $path) {
            $info = is_link($path) ? null : $this->readInfo($path, $app);
            if ($info !== null) $records[$app] = $info + ['_path' => $path];
            else $records[$app] = ['app' => $app, 'state' => 5, '_path' => $path, '_error' => '安装候选目录已被占用或元数据无效'];
        }
        // A crash after archiving the candidate must not hide the continuation entry.
        $cleanupDirectory = $root . '/cleanup';
        $this->assertDirectoryPath($cleanupDirectory);
        foreach (glob($cleanupDirectory . '/*.json') ?: [] as $journal) {
            $app = basename($journal, '.json');
            if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) || in_array($app, self::RESERVED, true)) continue;
            if (AbnormalPluginCleanup::pending($root, $app) && !isset($records[$app])) {
                $records[$app] = ['app' => $app, 'title' => $app, 'version' => '', 'state' => 8];
            }
        }
        return $records;
    }

    /** @return array<string,array<string,mixed>> */
    public function runtimePlugins(): array
    {
        $items = [];
        foreach ($this->directories($this->server . DIRECTORY_SEPARATOR . 'plugin') as $app => $path) {
            $info = is_link($path) ? null : $this->readInfo($path, $app);
            if ($info !== null) {
                $display = array_intersect_key($info, array_flip(['app', 'title', 'about', 'author', 'version']));
                $items[$app] = array_merge($display, ['state' => 6, 'detected_version' => $info['version'], 'installed_version' => null]);
            } elseif (is_link($path) || is_file($path . '/info.ini') || is_link($path . '/info.ini')) $items[$app] = ['app' => $app, 'state' => 99, '_path' => $path, '_error' => '运行插件元数据损坏或标识不匹配'];
        }
        return $items;
    }

    public function hasData(string $root): bool
    {
        $this->assertDirectoryPath($root);
        if (!is_dir($root)) return false;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isLink()) return true;
            if ($item->isDir()) continue;
            if (!$item->isFile()) return true;
            // Only empty, idle lock files directly in locks/ are disposable scaffolding.
            if (dirname($item->getPathname()) === $root . '/locks'
                && str_ends_with($item->getFilename(), '.lock') && $item->getSize() === 0
                && !$this->isHeld($item->getPathname())) continue;
            return true;
        }
        return false;
    }

    private function assertMigrationReady(string $root): void
    {
        foreach (['replacements', 'runtime-restores', 'quarantine'] as $name) {
            if (is_dir($root . '/' . $name) && $this->hasData($root . '/' . $name)) {
                throw new ApiException('存在恢复数据；请在原锁定宿主处理后再迁移');
            }
        }
        foreach ($this->managedRecords() as $info) {
            if (isset($info['_error']) || !in_array($info['state'] ?? null, [1, '1'], true)) {
                throw new ApiException('存在未完成或无效安装登记；拒绝迁移');
            }
            foreach (['operation_pending', 'registration_candidate', 'failed_upgrade', 'process_recovery_required', 'dependency_command_nonce'] as $field) {
                if (!empty($info[$field])) throw new ApiException('存在未完成安装标记；拒绝迁移');
            }
        }
        $this->assertCompletedFreshAudit($root . '/fresh-recovery');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item->isLink()) throw new ApiException('存储根包含不安全文件；拒绝迁移');
            if ($item->isDir()) continue;
            if (!$item->isFile()) throw new ApiException('存储根包含不安全文件；拒绝迁移');
            $path = $item->getPathname();
            if (dirname($path) === $root . '/locks' && (!str_ends_with($path, '.lock') || $item->getSize() !== 0)) {
                throw new ApiException('存在待处理操作记录；拒绝迁移');
            }
            if (in_array(strtolower($item->getExtension()), ['json', 'ini', 'history'], true)) {
                if ($item->getSize() > 16777216) throw new ApiException('迁移元数据超限；需人工核查');
                $body = file_get_contents($path);
                if ($body === false) throw new ApiException('无法读取迁移元数据');
                if (str_contains(str_replace('\\/', '/', $body), $this->legacyRoot())) {
                    throw new ApiException('恢复记录绑定旧存储绝对路径；拒绝迁移');
                }
            }
        }
    }

    /** Finished fresh-install evidence is durable audit data, not an unfinished recovery. */
    private function assertCompletedFreshAudit(string $directory): void
    {
        $this->assertDirectoryPath($directory);
        if (!is_dir($directory)) return;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink() || !$entry->isFile() || $entry->getSize() > 16777216
                || !preg_match('/^([a-z][a-z0-9-]{1,63})\.json(?:\.([a-f0-9]{32})\.history)?$/D', $entry->getFilename(), $match)) {
                throw new ApiException('新装恢复数据结构未知；拒绝迁移');
            }
            $record = json_decode((string) file_get_contents($entry->getPathname()), true);
            if (!is_array($record) || ($record['format'] ?? null) !== 1 || ($record['app'] ?? null) !== $match[1]
                || !in_array($record['phase'] ?? null, ['complete', 'cleaned'], true)
                || !is_string($record['operation'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $record['operation'])
                || isset($match[2]) && $match[2] !== $record['operation']
                || !is_array($record['events'] ?? null) || !is_array($record['binding'] ?? null)
                || ($record['binding']['host'] ?? null) !== (realpath($this->server) ?: $this->server)) {
                throw new ApiException('新装恢复尚未结束或身份无效；拒绝迁移');
            }
        }
    }

    private function assertDirectoryPath(string $path): void
    {
        if (!str_starts_with($path, '/')) throw new ApiException('插件存储路径必须为绝对路径');
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new ApiException('插件存储路径不安全');
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current) || (file_exists($current) && !is_dir($current))) {
                throw new ApiException('插件存储目录或父路径不安全');
            }
        }
    }

    private function hasHeldLock(string $root): bool
    {
        $locks = $root . '/locks';
        if (!is_dir($locks) || is_link($locks)) return false;
        foreach (new \FilesystemIterator($locks, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.lock') && $this->isHeld($item->getPathname())) return true;
        }
        return false;
    }

    private function isHeld(string $path): bool
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) return true;
        $free = @flock($handle, LOCK_EX | LOCK_NB);
        if ($free) flock($handle, LOCK_UN);
        fclose($handle);
        return !$free;
    }

    /** @return array<string,string> */
    private function directories(string $root): array
    {
        $this->assertDirectoryPath($root);
        if (!is_dir($root)) return [];
        $directories = [];
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item->isDir() && !$item->isLink()) continue;
            $app = $item->getFilename();
            if ($this->isApp($app)) $directories[$app] = $item->getPathname();
        }
        return $directories;
    }

    private function readInfo(string $directory, string $app): ?array
    {
        $file = $directory . '/info.ini';
        if (!is_file($file) || is_link($file) || filesize($file) > 16384) return null;
        $info = @parse_ini_file($file, true, INI_SCANNER_TYPED);
        if (!is_array($info) || ($info['app'] ?? null) !== $app || !is_string($info['version'] ?? null) || strlen($info['version']) > 80) return null;
        foreach (['title', 'about', 'author'] as $field) {
            if (isset($info[$field]) && !is_string($info[$field])) return null;
        }
        return $info;
    }

    private function isApp(string $app): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) === 1 && !in_array($app, self::RESERVED, true);
    }
}
