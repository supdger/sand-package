<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use plugin\sandadmin\exception\ApiException;
use plugin\sandpackage\app\service\RepositoryClient;
use Throwable;
use ZipArchive;

/** Public repository distribution; installation remains owned by InstallLogic. */
final class RepositoryLogic
{
    private const README_NAMES = ['README.md', 'README.MD', 'Readme.md', 'readme.md'];

    public function __construct(
        private RepositoryClient $client,
        private string $repository,
        private string $ref,
        private string $hostVersion,
    ) {
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$~D', $repository)
            || !preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$~D', $ref)
            || str_contains($ref, '..') || str_contains($ref, '//')) {
            throw new ApiException('插件仓库配置无效');
        }
    }

    /** @param callable(?array, ?Throwable): void $complete */
    public function catalog(callable $complete): void
    {
        $this->fetchCatalog(true, function (?array $catalog, ?Throwable $error) use ($complete): void {
            if ($error !== null) { $complete(null, $error); return; }
            $complete(['repository' => $this->repository, 'ref' => $this->ref, 'plugins' => $catalog['plugins']], null);
        });
    }

    /** @param callable(?array, ?Throwable): void $complete */
    private function fetchCatalog(bool $includeLocalState, callable $complete): void
    {
        $ref = implode('/', array_map('rawurlencode', explode('/', $this->ref)));
        $url = 'https://raw.githubusercontent.com/' . $this->repository . '/' . $ref . '/catalog.json';
        $this->client->get($url, 1048576, function (?string $body, ?Throwable $error) use ($includeLocalState, $complete): void {
            if ($error !== null) { $complete(null, $error); return; }
            try {
                $catalog = self::parseCatalog($body ?? '', $this->repository);
                if ($includeLocalState) {
                    $catalog = $this->withLocalState($catalog);
                }
            } catch (Throwable $error) { $complete(null, $error); return; }
            $complete($catalog, null);
        });
    }

    /** The browser supplies an identity, never a URL. @param callable(?array, ?Throwable): void $complete */
    public function download(string $app, string $version, string $sha256, callable $complete): void
    {
        $this->catalog(function (?array $catalog, ?Throwable $error) use ($app, $version, $sha256, $complete): void {
            if ($error !== null) { $complete(null, $error); return; }
            try {
                $release = $this->selectRelease($catalog, $app, $version);
                if (!hash_equals($release['sha256'], $sha256)) throw new ApiException('插件清单已变化，请刷新后重新选择版本');
                if (!in_array($release['action'] ?? null, ['install', 'upgrade'], true)) {
                    throw new ApiException((string) ($release['action_reason'] ?? '当前安装状态不能准备此插件版本'));
                }
                $url = $this->releaseUrl($release);
            } catch (Throwable $error) { $complete(null, $error); return; }
            $this->client->get($url, 5242880, function (?string $body, ?Throwable $error) use ($app, $version, $sha256, $complete): void {
                if ($error !== null) { $complete(null, $error); return; }
                try { $result = $this->stage($body ?? '', $app, $version, $sha256); }
                catch (Throwable $error) { $complete(null, $error); return; }
                $complete($result, null);
            });
        });
    }

    /** Documentation is package-only and deliberately ignores broken local state. @param callable(?array, ?Throwable): void $complete */
    public function document(string $app, string $version, string $sha256, callable $complete): void
    {
        $this->fetchCatalog(false, function (?array $catalog, ?Throwable $error) use ($app, $version, $sha256, $complete): void {
            if ($error !== null) { $complete(null, $error); return; }
            try {
                $release = $this->selectRelease($catalog, $app, $version);
                if (!hash_equals($release['sha256'], $sha256)) throw new ApiException('插件清单已变化，请刷新后重新选择版本');
                $url = $this->releaseUrl($release);
            } catch (Throwable $error) { $complete(null, $error); return; }
            $this->client->get($url, 5242880, function (?string $body, ?Throwable $error) use ($app, $version, $sha256, $complete): void {
                if ($error !== null) { $complete(null, $error); return; }
                try {
                    $markdown = $this->readDocument($body ?? '', $app, $version, $sha256);
                } catch (Throwable $error) { $complete(null, $error); return; }
                $complete(['app' => $app, 'version' => $version, 'markdown' => $markdown], null);
            });
        });
    }

    /** Supplemental declarations only: no installation, dependency update or SQL execution. */
    public function cleanupPackage(string $app, string $version, string $sha256, callable $complete): void
    {
        $this->fetchCatalog(false, function (?array $catalog, ?Throwable $error) use ($app, $version, $sha256, $complete): void {
            if ($error !== null) { $complete(null, $error); return; }
            try {
                $release = $this->selectRelease($catalog, $app, $version);
                if (!hash_equals($release['sha256'], $sha256)) throw new ApiException('插件清单已变化，请刷新后重新选择清理包');
                if (!$this->compatible($release)) throw new ApiException('清理包不兼容当前宿主版本');
                $url = $this->releaseUrl($release);
            } catch (Throwable $error) { $complete(null, $error); return; }
            $this->client->get($url, 5242880, function (?string $body, ?Throwable $error) use ($app, $version, $sha256, $complete): void {
                if ($error !== null) { $complete(null, $error); return; }
                try {
                    $package = $this->cleanupDeclarations($body ?? '', $app, $version, $sha256);
                    $result = (new InstallLogic($app))->prepareCleanupPackage($package);
                } catch (Throwable $error) { $complete(null, $error); return; }
                $complete($result, null);
            });
        });
    }

    private function cleanupDeclarations(string $body, string $app, string $version, string $sha256): array
    {
        $file = $this->archiveFile($body, $sha256);
        try {
            $zip = $this->verifiedZip($file, $app, $version);
            try {
                if ($zip->numFiles > 2048) throw new ApiException('清理包文件数量过多');
                $names = []; $bytes = 0;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = $zip->statIndex($index);
                    if (!is_array($entry)) throw new ApiException('清理包目录无法读取');
                    $name = $entry['name'];
                    $key = rtrim($name, '/');
                    if ($name === '' || str_contains($name, '\\') || str_contains($name, ':')
                        || preg_match('/[\x00-\x1f]/', $name) || array_intersect(explode('/', $key), ['', '.', '..'])
                        || isset($names[$key])) throw new ApiException('清理包包含不安全或重复路径');
                    $names[$key] = true;
                    $zip->getExternalAttributesIndex($index, $os, $attributes);
                    if ((($attributes >> 16) & 0170000) === 0120000) throw new ApiException('清理包不能包含符号链接');
                    $bytes += $entry['size'];
                    if ($bytes > 67108864) throw new ApiException('清理包解压大小超过限制');
                }
                $package = ['app' => $app, 'version' => $version, 'sha256' => $sha256];
                foreach (['install', 'uninstall'] as $kind) {
                    $stat = $zip->statName($kind . '.sql');
                    if (!is_array($stat) || $stat['size'] > 4194304) throw new ApiException('清理包缺少有界的生命周期声明');
                    $sql = $zip->getFromName($kind . '.sql');
                    if (!is_string($sql) || preg_match('//u', $sql) !== 1) throw new ApiException('清理包 SQL 编码无效');
                    $package[$kind . '_sql'] = $sql;
                }
                return $package;
            } finally { $zip->close(); }
        } finally { if (is_file($file)) unlink($file); }
    }

    private function stage(string $body, string $app, string $version, string $sha256): array
    {
        $file = $this->archiveFile($body, $sha256);
        try {
            $zip = $this->verifiedZip($file, $app, $version);
            $zip->close();
            $info = (new InstallLogic())->uploadFromPath($file);
            return array_merge($info, InstallLogic::presentInfo($info));
        } finally { if (is_file($file)) unlink($file); }
    }

    private function readDocument(string $body, string $app, string $version, string $sha256): string
    {
        $file = $this->archiveFile($body, $sha256);
        try {
            $zip = $this->verifiedZip($file, $app, $version);
            try {
                $matches = [];
                foreach (self::README_NAMES as $name) {
                    if ($zip->locateName($name) !== false) {
                        $matches[] = $name;
                    }
                }
                if (count($matches) !== 1) throw new ApiException('插件包缺少唯一的根目录 README.md');
                $stat = $zip->statName($matches[0]);
                if (!is_array($stat) || ($stat['size'] ?? 0) > 262144) throw new ApiException('插件文档超过 256 KiB 限制');
                $markdown = $zip->getFromName($matches[0]);
                if (!is_string($markdown) || strlen($markdown) > 262144) throw new ApiException('插件文档超过 256 KiB 限制');
                if (preg_match('//u', $markdown) !== 1) throw new ApiException('插件文档必须是有效的 UTF-8 文本');
                return $markdown;
            } finally { $zip->close(); }
        } finally { if (is_file($file)) unlink($file); }
    }

    private function archiveFile(string $body, string $sha256): string
    {
        if (strlen($body) > 5242880 || !hash_equals($sha256, hash('sha256', $body))) {
            throw new ApiException('插件包校验失败');
        }
        $file = tempnam(sys_get_temp_dir(), 'sandpackage-download-');
        if ($file === false) throw new ApiException('无法创建插件下载临时文件');
        if (file_put_contents($file, $body) !== strlen($body)) {
            if (is_file($file)) unlink($file);
            throw new ApiException('插件包保存失败');
        }
        return $file;
    }

    private function verifiedZip(string $file, string $app, string $version): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new ApiException('下载文件不是有效的 ZIP 插件包');
        try {
            $stat = $zip->statName('info.ini');
            if (!is_array($stat) || ($stat['size'] ?? 0) > 16384) throw new ApiException('插件包缺少有效的 info.ini');
            $raw = $zip->getFromName('info.ini');
            $info = is_string($raw) ? @parse_ini_string($raw, true, INI_SCANNER_TYPED) : false;
            if (!is_array($info) || ($info['app'] ?? null) !== $app || ($info['version'] ?? null) !== $version) {
                throw new ApiException('插件包名称或版本与所选仓库版本不一致');
            }
            return $zip;
        } catch (Throwable $error) {
            $zip->close();
            throw $error;
        }
    }

    private function selectRelease(array $catalog, string $app, string $version): array
    {
        foreach ($catalog['plugins'] as $plugin) {
            if ($plugin['app'] !== $app) continue;
            foreach ($plugin['versions'] as $candidate) {
                if ($candidate['version'] === $version) {
                    $candidate['repository'] = $plugin['repository'];
                    return $candidate;
                }
            }
        }
        throw new ApiException('仓库中不存在此插件版本，请刷新插件清单');
    }

    private function releaseUrl(array $release): string
    {
        return 'https://github.com/' . $release['repository'] . '/releases/download/'
            . rawurlencode($release['tag']) . '/' . rawurlencode($release['asset']);
    }

    private function withLocalState(array $catalog): array
    {
        foreach ($catalog['plugins'] as &$plugin) {
            $local = (new InstallLogic($plugin['app']))->ordinaryStatus();
            $plugin['local'] = $local;
            foreach ($plugin['versions'] as &$release) {
                [$release['action'], $release['action_reason']] = $this->releaseAction($local, $release);
            }
            unset($release);
        }
        unset($plugin);
        return $catalog;
    }

    /** @return array{string,string} */
    private function releaseAction(array $local, array $release): array
    {
        if ($local['blocked']) {
            return ['manage', $local['reason']];
        }
        if (!$this->compatible($release)) {
            $range = $release['host_min'] . (isset($release['host_max']) ? ' 至 ' . $release['host_max'] : ' 或更高');
            return ['incompatible', '需要 SandAdmin ' . $range];
        }
        if ($local['state'] === InstallLogic::UNINSTALLED) {
            return ['install', '未安装，可以安装此版本'];
        }
        if ($local['state'] !== InstallLogic::INSTALLED || $local['installed_version'] === null) {
            return ['manage', $local['reason'] !== '' ? $local['reason'] : '当前安装状态需要从已安装插件管理页继续'];
        }
        $comparison = version_compare($release['version'], $local['installed_version']);
        if ($comparison === 0) return ['installed', '当前已经安装此版本'];
        if ($comparison < 0) return ['downgrade', '所选版本低于已安装版本，禁止降级'];
        return ['upgrade', '可从 ' . $local['installed_version'] . ' 升级到此版本'];
    }

    private function compatible(array $release): bool
    {
        return version_compare($this->hostVersion, $release['host_min'], '>=')
            && (!isset($release['host_max']) || version_compare($this->hostVersion, $release['host_max'], '<='));
    }

    /** Validate the entire manifest before displaying or trusting any release. */
    public static function parseCatalog(string $json, string $defaultRepository = 'supdger/sandadmin'): array
    {
        if (!self::validRepository($defaultRepository)) throw new ApiException('插件仓库配置无效');
        if (strlen($json) > 1048576) throw new ApiException('插件清单超过大小限制');
        try {
            $shape = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        }
        catch (Throwable) { throw new ApiException('插件清单不是有效的 JSON'); }
        if (!is_object($shape) || !is_array($shape->plugins ?? null) || !is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['plugins'] ?? null)
            || !array_is_list($data['plugins']) || count($data['plugins']) > 200) {
            throw new ApiException('插件清单格式不受支持');
        }
        $apps = [];
        $plugins = [];
        foreach ($data['plugins'] as $plugin) {
            if (!is_array($plugin)) throw new ApiException('插件清单条目无效');
            $app = self::text($plugin, 'app', 64);
            if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)
                || in_array($app, ['sandadmin', 'sandpackage', 'saiadmin', 'saipackage', 'locks', 'backups', 'fresh-recovery'], true)
                || isset($apps[$app])) throw new ApiException('插件清单包含无效或重复的插件标识');
            $apps[$app] = true;
            $repository = $plugin['repository'] ?? $defaultRepository;
            if (!is_string($repository) || !self::validRepository($repository)) {
                throw new ApiException('插件清单包含无效的插件仓库');
            }
            $item = ['app' => $app, 'repository' => $repository, 'title' => self::text($plugin, 'title', 200),
                'about' => self::text($plugin, 'about', 4000), 'author' => self::text($plugin, 'author', 200), 'versions' => []];
            if (!is_array($plugin['versions'] ?? null) || !array_is_list($plugin['versions']) || count($plugin['versions']) > 50) {
                throw new ApiException('插件版本清单无效');
            }
            $versions = [];
            foreach ($plugin['versions'] as $release) {
                if (!is_array($release)) throw new ApiException('插件版本条目无效');
                $version = self::version($release, 'version');
                if (isset($versions[$version])) throw new ApiException('插件版本重复');
                $versions[$version] = true;
                $tag = self::text($release, 'tag', 160);
                $asset = self::text($release, 'asset', 200);
                $sha = self::text($release, 'sha256', 64);
                if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $tag) || str_contains($tag, '..')
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', $asset)
                    || !preg_match('/^[a-f0-9]{64}$/D', $sha)) throw new ApiException('插件发布附件信息无效');
                $entry = ['version' => $version, 'tag' => $tag, 'asset' => $asset, 'sha256' => $sha,
                    'host_min' => self::version($release, 'host_min'), 'notes' => self::text($release, 'notes', 8000, true)];
                if (isset($release['host_max'])) {
                    $entry['host_max'] = self::version($release, 'host_max');
                    if (version_compare($entry['host_max'], $entry['host_min'], '<')) throw new ApiException('插件宿主兼容范围无效');
                }
                $item['versions'][] = $entry;
            }
            $plugins[] = $item;
        }
        return ['schema' => 1, 'plugins' => $plugins];
    }

    private static function validRepository(string $repository): bool
    {
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$~D', $repository) === 1;
    }

    private static function text(array $data, string $key, int $max, bool $optional = false): string
    {
        $value = $data[$key] ?? ($optional ? '' : null);
        if (!is_string($value) || strlen($value) > $max || (!$optional && trim($value) === '') || str_contains($value, "\0")) {
            throw new ApiException('插件清单字段无效：' . $key);
        }
        return $value;
    }

    private static function version(array $data, string $key): string
    {
        $value = self::text($data, $key, 80);
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $value)) throw new ApiException('插件清单版本号无效');
        return $value;
    }
}
