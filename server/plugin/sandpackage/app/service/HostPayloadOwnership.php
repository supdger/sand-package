<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Reads the exact file inventory published by a completed host-payload install. */
final class HostPayloadOwnership
{
    /**
     * @return list<array{path:string,sha256:string}>|null
     */
    public static function read(string $stateRoot, string $app): ?array
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1) {
            throw new RuntimeException('插件标识无效');
        }
        $path = HostPayloadPlan::directoryPrefix($stateRoot) . $app . '.owned.json';
        HostPayloadPlan::assertSafePath($path);
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path) || filesize($path) > 1048576) {
            throw new RuntimeException('宿主文件归属记录类型或大小无效');
        }
        $record = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || count($record) !== 3
            || ($record['schema'] ?? null) !== 1 || ($record['app'] ?? null) !== $app
            || !is_array($record['files'] ?? null) || !array_is_list($record['files'])) {
            throw new RuntimeException('宿主文件归属记录格式无效');
        }
        HostPayloadPlan::normalizeFiles($record['files'], $app);
        return $record['files'];
    }
}
