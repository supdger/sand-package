<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;

/** Read-only PostgreSQL identity and public-schema fingerprint for one plugin prefix. */
final class PostgresHostCatalogFingerprint
{
    /** @return array{identity:string,schema:string} */
    public static function capture(object $connection, string $app): array
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) !== 1
            || !method_exists($connection, 'quote') || !method_exists($connection, 'query')) {
            throw new RuntimeException('PostgreSQL 结构指纹输入无效');
        }
        $prefix = str_replace('-', '_', $app);
        $literal = $connection->quote($prefix);
        if (!is_string($literal)) throw new RuntimeException('PostgreSQL 结构指纹无法引用插件前缀');
        $match = "left(c.relname, char_length($literal)) = $literal";
        $queries = [
            'relations' => "SELECT c.relname, c.relkind::text, c.relpersistence::text, c.relrowsecurity, c.relforcerowsecurity,
                CASE WHEN c.relkind IN ('v','m') THEN pg_get_viewdef(c.oid, true) ELSE NULL END AS view_definition
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname='public' AND $match ORDER BY c.relname, c.relkind",
            'columns' => "SELECT c.relname, a.attnum, a.attname, a.atttypid::text, a.atttypmod, a.attnotnull,
                a.attidentity, a.attgenerated, pg_get_expr(d.adbin,d.adrelid) AS default_expression
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                JOIN pg_attribute a ON a.attrelid=c.oid
                LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum
                WHERE n.nspname='public' AND $match AND a.attnum>0 AND NOT a.attisdropped
                ORDER BY c.relname,a.attnum",
            'constraints' => "SELECT c.relname, con.conname, pg_get_constraintdef(con.oid, true) AS definition
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                JOIN pg_constraint con ON con.conrelid=c.oid
                WHERE n.nspname='public' AND $match ORDER BY c.relname,con.conname",
            'indexes' => "SELECT c.relname, i.relname AS index_name, pg_get_indexdef(i.oid) AS definition
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                JOIN pg_index x ON x.indrelid=c.oid
                JOIN pg_class i ON i.oid=x.indexrelid
                WHERE n.nspname='public' AND $match ORDER BY c.relname,i.relname",
            'triggers' => "SELECT c.relname, t.tgname, pg_get_triggerdef(t.oid, true) AS definition
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                JOIN pg_trigger t ON t.tgrelid=c.oid
                WHERE n.nspname='public' AND $match AND NOT t.tgisinternal
                ORDER BY c.relname,t.tgname",
            'policies' => "SELECT c.relname, p.polname, p.polcmd, p.polpermissive,
                pg_get_expr(p.polqual,p.polrelid) AS using_expression,
                pg_get_expr(p.polwithcheck,p.polrelid) AS check_expression
                FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                JOIN pg_policy p ON p.polrelid=c.oid
                WHERE n.nspname='public' AND $match ORDER BY c.relname,p.polname",
        ];
        $identity = self::rows($connection,
            "SELECT current_database() AS database,
                (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
                current_user AS username, inet_server_addr()::text AS address,
                inet_server_port() AS port");
        if (count($identity) !== 1) throw new RuntimeException('PostgreSQL 数据库身份不可确认');
        $schema = [];
        foreach ($queries as $name => $sql) {
            $schema[$name] = self::rows($connection, $sql);
        }
        return [
            'identity' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            'schema' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function rows(object $connection, string $sql): array
    {
        $result = $connection->query($sql);
        if (!is_object($result) || !method_exists($result, 'fetchAll')) {
            throw new RuntimeException('PostgreSQL 结构指纹查询失败');
        }
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) throw new RuntimeException('PostgreSQL 结构指纹结果无效');
        return $rows;
    }
}
