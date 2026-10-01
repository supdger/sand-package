<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Read-only proof that an existing PostgreSQL schema matches a package declaration. */
final class ExistingSchemaManifest
{
    public static function hasExistingTables(object $connection, string $app): bool
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || !method_exists($connection, 'quote')) {
            throw new RuntimeException('现有 schema 表检查输入无效');
        }
        $prefix = str_replace('-', '_', $app) . '_';
        $literal = $connection->quote($prefix);
        if (!is_string($literal)) throw new RuntimeException('现有 schema 表前缀不可引用');
        $rows = self::rows($connection,
            "SELECT EXISTS(SELECT 1 FROM pg_class WHERE relnamespace='public'::regnamespace
             AND relkind IN ('r','p') AND left(relname," . strlen($prefix) . ")=$literal) AS present");
        if (count($rows) !== 1 || !array_key_exists('present', $rows[0])) {
            throw new RuntimeException('现有 schema 表检查结果无效');
        }
        if (in_array($rows[0]['present'], [true, 't', '1', 1], true)) return true;
        if (in_array($rows[0]['present'], [false, 'f', '0', 0], true)) return false;
        throw new RuntimeException('现有 schema 表检查结果无效');
    }

    /** @return array{manifest_sha256:string,install_sql_sha256:string,ledger_rows:int,table_count:int} */
    public static function inspect(string $candidate, string $app, string $version, string $database, object $connection): array
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || $version === '' || $database === '' || !method_exists($connection, 'query')) {
            throw new RuntimeException('现有 schema 重接输入无效');
        }
        $root = rtrim($candidate, '/');
        $manifestPath = $root . '/existing-schema.json';
        $installPath = $root . '/install.sql';
        if (!is_file($manifestPath) || is_link($manifestPath) || !is_file($installPath) || is_link($installPath)
            || filesize($manifestPath) > 16384) {
            throw new RuntimeException('现有 schema 重接清单或安装 SQL 不可读取');
        }
        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) throw new RuntimeException('现有 schema 重接清单不可读取');
        $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $keys = ['app','catalog_schema_sha256','format','install_sql_sha256','ledger_rows','ledger_sha256','ledger_table',
            'table_count','table_names_sha256','version'];
        $actualKeys = is_array($manifest) ? array_keys($manifest) : [];
        sort($actualKeys);
        if (!is_array($manifest) || $actualKeys !== $keys
            || $manifest['format'] !== 1 || $manifest['app'] !== $app || $manifest['version'] !== $version
            || $manifest['ledger_table'] !== str_replace('-', '_', $app) . '_schema_migration'
            || !self::hashValue($manifest['install_sql_sha256'])
            || !self::hashValue($manifest['catalog_schema_sha256'])
            || !self::hashValue($manifest['ledger_sha256'])
            || !self::hashValue($manifest['table_names_sha256'])
            || !is_int($manifest['ledger_rows']) || $manifest['ledger_rows'] < 1
            || !is_int($manifest['table_count']) || $manifest['table_count'] < 1) {
            throw new RuntimeException('现有 schema 重接清单无效');
        }
        $installHash = hash_file('sha256', $installPath);
        if (!is_string($installHash) || !hash_equals($manifest['install_sql_sha256'], $installHash)) {
            throw new RuntimeException('现有 schema 清单与安装 SQL 不一致');
        }
        $identity = self::rows($connection, 'SELECT current_database() AS database,current_schema() AS schema_name');
        if (count($identity) !== 1 || ($identity[0]['database'] ?? null) !== $database
            || ($identity[0]['schema_name'] ?? null) !== 'public') {
            throw new RuntimeException('现有 schema 数据库身份不符');
        }
        $ledger = self::rows($connection,
            'SELECT migration_file,revision,trim(checksum) AS checksum,package_version FROM public.'
            . $manifest['ledger_table'] . ' ORDER BY migration_file');
        $canonical = '';
        foreach ($ledger as $row) {
            if (!is_string($row['migration_file'] ?? null) || !ctype_digit((string) ($row['revision'] ?? ''))
                || !self::hashValue($row['checksum'] ?? null)
                || !is_string($row['package_version'] ?? null)) {
                throw new RuntimeException('现有 schema 迁移账本无效');
            }
            $canonical .= $row['migration_file'] . '|' . $row['revision'] . '|' . $row['checksum']
                . '|' . $row['package_version'] . "\n";
        }
        if (count($ledger) !== $manifest['ledger_rows']
            || !hash_equals($manifest['ledger_sha256'], hash('sha256', $canonical))) {
            throw new RuntimeException('现有 schema 迁移账本与候选包不一致');
        }
        $prefix = str_replace('-', '_', $app) . '_';
        $tables = self::rows($connection,
            "SELECT relname FROM pg_class WHERE relnamespace='public'::regnamespace
             AND relkind IN ('r','p') AND left(relname," . strlen($prefix) . ")='" . $prefix . "'
             ORDER BY relname COLLATE \"C\"");
        $names = '';
        foreach ($tables as $row) {
            if (!is_string($row['relname'] ?? null)) throw new RuntimeException('现有 schema 表集合无效');
            $names .= $row['relname'] . "\n";
        }
        if (count($tables) !== $manifest['table_count']
            || !hash_equals($manifest['table_names_sha256'], hash('sha256', $names))) {
            throw new RuntimeException('现有 schema 表集合与候选包不一致');
        }
        $catalog = PostgresHostCatalogFingerprint::capture($connection, $app);
        if (!hash_equals($manifest['catalog_schema_sha256'], $catalog['schema'])) {
            throw new RuntimeException('现有 schema 结构与候选包声明不一致');
        }
        return [
            'manifest_sha256' => hash('sha256', $raw),
            'install_sql_sha256' => $installHash,
            'ledger_rows' => count($ledger),
            'table_count' => count($tables),
        ];
    }

    private static function hashValue(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(object $connection, string $sql): array
    {
        $result = $connection->query($sql);
        if (!is_object($result) || !method_exists($result, 'fetchAll')) {
            throw new RuntimeException('现有 schema 预检查询失败');
        }
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) throw new RuntimeException('现有 schema 预检结果无效');
        return $rows;
    }
}
