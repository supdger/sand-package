<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use plugin\sandadmin\exception\ApiException;
use ZipArchive;

/** Compressed ZIP limits shared by the builder and all package intake paths. */
final class PackageArchivePolicy
{
    public const MAX_BYTES = 16777216;

    public static function effectiveLimit(): int
    {
        $configured = config('plugin.sandpackage.upload.size', self::MAX_BYTES);
        $numericString = is_string($configured) && preg_match('/^[1-9][0-9]*$/D', $configured) === 1;
        if ($numericString && strlen($configured) <= strlen((string) self::MAX_BYTES)) {
            $configured = (int) $configured;
        }
        if (!is_int($configured) || $configured < 1 || $configured > self::MAX_BYTES) {
            $actual = is_int($configured) || $numericString ? (string) $configured : get_debug_type($configured);
            throw new ApiException(
                '插件 ZIP 容量配置 plugin.sandpackage.upload.size 无效：actual=' . $actual
                . '，max=' . self::MAX_BYTES . ' bytes；必须为 1 至 ' . self::MAX_BYTES . ' 的整数字节数',
                400,
            );
        }
        return $configured;
    }

    public static function assertSize(mixed $actual, int $max): void
    {
        if (!is_int($actual) || $actual < 0) {
            throw new ApiException('无法测量 ZIP 安装包大小；max=' . $max . ' bytes', 400);
        }
        if ($actual > $max) {
            throw new ApiException(
                'ZIP 安装包超过大小限制：actual=' . $actual . ' bytes，max=' . $max . ' bytes',
                400,
            );
        }
    }

    public static function assertFile(string $file, ?int $max = null): void
    {
        clearstatcache(true, $file);
        self::assertSize(@filesize($file), $max ?? self::effectiveLimit());
    }

    /** Validate member bytes before extraction, without buffering expanded files. */
    public static function verifyEntry(ZipArchive $zip, array $entry): void
    {
        if (str_ends_with($entry['name'], '/')) return;
        $stream = $zip->getStream($entry['name']);
        if (!is_resource($stream)) {
            throw new ApiException('ZIP 安装包成员内容无法读取：' . $entry['name'], 400);
        }
        try {
            $crc = hash_init('crc32b');
            $bytes = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    throw new ApiException('ZIP 安装包成员内容无法读取：' . $entry['name'], 400);
                }
                $bytes += strlen($chunk);
                if ($bytes > $entry['size']) {
                    throw new ApiException('ZIP 安装包成员大小校验失败：' . $entry['name'], 400);
                }
                hash_update($crc, $chunk);
            }
            if ($bytes !== $entry['size']
                || !hash_equals(substr(sprintf('%08x', (int) ($entry['crc'] ?? -1)), -8), hash_final($crc))) {
                throw new ApiException('ZIP 安装包成员内容校验失败：' . $entry['name'], 400);
            }
        } finally {
            fclose($stream);
        }
    }
}
