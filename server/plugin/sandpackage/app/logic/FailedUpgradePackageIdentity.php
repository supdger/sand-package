<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use plugin\sandadmin\exception\ApiException;

/**
 * Builds the immutable identity of a failed-upgrade candidate.
 *
 * The component deliberately knows nothing about a concrete plugin. It reads
 * only the candidate-owned v2 descriptor and deterministic package files.
 */
final class FailedUpgradePackageIdentity
{
    public const NORMALIZED_PACKAGE_MANIFEST_V1 = 'sandpackage-normalized-package-manifest/v1';
    private const NORMALIZED_PACKAGE_MANIFEST_SCHEMA_V1 = 'sandpackage.normalized-package-manifest/v1';

    /** @var list<string> */
    private const ROOT_PAYLOAD_EXCLUSIONS = [
        'recovery/failed-upgrade.v2.json',
        'recovery/failed-upgrade.v2.json.sha256',
        'registration_manifest.json',
    ];

    public function readDescriptor(string $candidate): string
    {
        $file = rtrim($candidate, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'recovery'
            . DIRECTORY_SEPARATOR . 'failed-upgrade.v2.json';
        if (!$this->isSafeRegularFile($file)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包缺少安全的恢复描述文件', 400);
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包恢复描述文件不可读取', 400);
        }
        (new FailedUpgradeRecoveryVerifier())->parseDescriptor($raw);
        return $raw;
    }

    public function fileSha256(string $file, string $label, ?int $exactMode = null): string
    {
        if (!$this->isSafeRegularFile($file, $exactMode)) {
            throw new ApiException($label . '不安全或不可读取');
        }
        $hash = hash_file('sha256', $file);
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new ApiException($label . '摘要计算失败');
        }
        return $hash;
    }

    /**
     * @param callable(string):string $normalizedInfo returns canonical JSON for info.ini identity
     * @return array<string,array{size:int,sha256:string}>
     */
    public function payloadManifest(string $directory, callable $normalizedInfo): array
    {
        $this->assertSafeDirectory($directory);
        $normalizedInfoContent = $normalizedInfo($directory);
        try {
            $normalizedInfoValues = json_decode($normalizedInfoContent, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiException('候选包恢复载荷的基础身份不合法');
        }
        $app = is_array($normalizedInfoValues) ? ($normalizedInfoValues['app'] ?? null) : null;
        if (!is_string($app) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new ApiException('候选包恢复载荷的应用身份不合法');
        }
        $payloadExclusions = $this->payloadExclusions($app);
        $manifest = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                throw new ApiException('候选包恢复载荷包含不安全文件');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            if (in_array($relative, $payloadExclusions, true)
                || preg_match('/^registration_manifest\.json\.[a-f0-9]{16}\.tmp$/D', $relative) === 1) {
                continue;
            }
            if ($relative === 'info.ini') {
                $manifest[$relative] = ['size' => strlen($normalizedInfoContent), 'sha256' => hash('sha256', $normalizedInfoContent)];
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('候选包恢复载荷摘要计算失败');
            }
            $manifest[$relative] = ['size' => $item->getSize(), 'sha256' => $hash];
        }
        if (!isset($manifest['info.ini'], $manifest['update.sql'])) {
            throw new ApiException('候选包恢复载荷不完整');
        }
        ksort($manifest, SORT_STRING);
        return $manifest;
    }

    /**
     * Calculate the digest named by a candidate descriptor. Algorithm v1 is
     * deliberately distinct from the host's internal size-bearing manifest:
     * it binds a canonical wrapper around a pure path => SHA-256 map and hashes
     * the original candidate bytes, including info.ini.
     */
    public function descriptorPayloadDigest(string $directory, string $algorithm, string $app): string
    {
        if ($algorithm !== self::NORMALIZED_PACKAGE_MANIFEST_V1) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包载荷摘要算法不受支持', 400);
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包载荷应用身份不合法', 400);
        }
        $this->assertSafeDirectory($directory);
        $infoFile = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'info.ini';
        $info = $this->isSafeRegularFile($infoFile) ? parse_ini_file($infoFile, true, INI_SCANNER_TYPED) : false;
        if (!is_array($info) || ($info['app'] ?? null) !== $app) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：候选包载荷与根应用身份不匹配', 400);
        }
        $files = [];
        $exclusions = $this->payloadExclusions($app);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                throw new ApiException('候选包恢复载荷包含不安全文件');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
            if (in_array($relative, $exclusions, true)
                || preg_match('/^registration_manifest\.json\.[a-f0-9]{16}\.tmp$/D', $relative) === 1) {
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ApiException('候选包恢复载荷摘要计算失败');
            }
            $files[$relative] = $hash;
        }
        if (!isset($files['info.ini'], $files['update.sql'])) {
            throw new ApiException('候选包恢复载荷不完整');
        }
        ksort($files, SORT_STRING);
        return hash('sha256', FailedUpgradeRecoveryVerifier::canonicalJson([
            'algorithm' => self::NORMALIZED_PACKAGE_MANIFEST_V1,
            'files' => $files,
            'schema' => self::NORMALIZED_PACKAGE_MANIFEST_SCHEMA_V1,
        ]));
    }

    /** @param array<string,mixed> $manifest */
    public function manifestDigest(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function payloadExclusions(string $app): array
    {
        return [
            ...self::ROOT_PAYLOAD_EXCLUSIONS,
            'plugin/' . $app . '/recovery/failed-upgrade.v2.json',
            'plugin/' . $app . '/recovery/failed-upgrade.v2.json.sha256',
        ];
    }

    public function retainArchive(string $archive, string $expectedSha256, string $archiveDirectory): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)
            || !hash_equals($expectedSha256, $this->fileSha256($archive, '候选安装包归档', 0400))) {
            throw new ApiException('候选安装包归档未通过预检');
        }
        $this->preparePrivateDirectory($archiveDirectory);
        $target = rtrim($archiveDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $expectedSha256 . '.zip';
        if (is_file($target)) {
            if (!hash_equals($expectedSha256, $this->fileSha256($target, '候选安装包归档', 0400))) {
                throw new ApiException('候选安装包归档冲突');
            }
            return;
        }
        if (!copy($archive, $target) || !chmod($target, 0400)) {
            @unlink($target);
            throw new ApiException('无法封存候选安装包归档');
        }
        if (!hash_equals($expectedSha256, $this->fileSha256($target, '候选安装包归档', 0400))) {
            @unlink($target);
            throw new ApiException('无法封存候选安装包归档');
        }
    }

    private function preparePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ApiException('无法保存候选安装包归档');
        }
        if (is_link($directory) || !chmod($directory, 0700)) {
            throw new ApiException('无法保护候选安装包归档');
        }
        $this->assertSafeDirectory($directory);
    }

    private function assertSafeDirectory(string $directory): void
    {
        $stat = @lstat($directory);
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($stat) || is_link($directory) || (($stat['mode'] & 0170000) !== 0040000)
            || (($stat['mode'] & 0002) !== 0) || ($expectedUid !== null && $stat['uid'] !== $expectedUid)) {
            throw new ApiException('候选包目录安全属性不符合要求');
        }
    }

    private function isSafeRegularFile(string $file, ?int $exactMode = null): bool
    {
        $stat = @lstat($file);
        $expectedUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($stat) || is_link($file) || (($stat['mode'] & 0170000) !== 0100000)
            || (($stat['mode'] & 0002) !== 0) || ($expectedUid !== null && $stat['uid'] !== $expectedUid)
            || !is_readable($file)) {
            return false;
        }
        return $exactMode === null || (($stat['mode'] & 0777) === $exactMode);
    }
}
