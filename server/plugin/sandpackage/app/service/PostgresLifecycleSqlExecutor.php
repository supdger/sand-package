<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Wraps implicit scripts in a transaction; preserves explicit transaction blocks.
 *
 * This intentionally does not delegate to the legacy package importer: that
 * importer is MySQL-oriented and cannot safely distinguish semicolons inside
 * PostgreSQL strings, comments or dollar quoted function bodies.
 */
final class PostgresLifecycleSqlExecutor
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $state = 'normal';
        $blockDepth = 0;
        $dollarTag = '';
        $escapeString = false;

        for ($offset = 0; $offset < $length; $offset++) {
            $char = $sql[$offset];
            $next = $offset + 1 < $length ? $sql[$offset + 1] : '';

            if ($state === 'single') {
                $buffer .= $char;
                if ($escapeString && $char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $offset++;
                } elseif ($char === "'" && $next === "'") {
                    $buffer .= $next;
                    $offset++;
                } elseif ($char === "'") {
                    $state = 'normal';
                    $escapeString = false;
                }
                continue;
            }
            if ($state === 'double') {
                $buffer .= $char;
                if ($char === '"' && $next === '"') {
                    $buffer .= $next;
                    $offset++;
                } elseif ($char === '"') {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'line') {
                $buffer .= $char;
                if ($char === "\n" || $char === "\r") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block') {
                $buffer .= $char;
                if ($char === '/' && $next === '*') {
                    $buffer .= $next;
                    $offset++;
                    $blockDepth++;
                } elseif ($char === '*' && $next === '/') {
                    $buffer .= $next;
                    $offset++;
                    $blockDepth--;
                    if ($blockDepth === 0) {
                        $state = 'normal';
                    }
                }
                continue;
            }
            if ($state === 'dollar') {
                if (substr($sql, $offset, strlen($dollarTag)) === $dollarTag) {
                    $buffer .= $dollarTag;
                    $offset += strlen($dollarTag) - 1;
                    $state = 'normal';
                } else {
                    $buffer .= $char;
                }
                continue;
            }

            if ($char === "'") {
                $state = 'single';
                $escapeString = $offset > 0 && ($sql[$offset - 1] === 'E' || $sql[$offset - 1] === 'e') && ($offset < 2 || !ctype_alnum($sql[$offset - 2]));
                $buffer .= $char;
                continue;
            }
            if ($char === '"') {
                $state = 'double';
                $buffer .= $char;
                continue;
            }
            if ($char === '-' && $next === '-') {
                $state = 'line';
                $buffer .= '--';
                $offset++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $state = 'block';
                $blockDepth = 1;
                $buffer .= '/*';
                $offset++;
                continue;
            }
            if ($char === '$' && preg_match('/\\A\\$[A-Za-z_][A-Za-z0-9_]*\\$|\\A\\$\\$/', substr($sql, $offset), $match) === 1) {
                $dollarTag = $match[0];
                $state = 'dollar';
                $buffer .= $dollarTag;
                $offset += strlen($dollarTag) - 1;
                continue;
            }
            if ($char === ';') {
                self::append($statements, $buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($state !== 'normal' && $state !== 'line') {
            throw new InvalidArgumentException('PostgreSQL 生命周期脚本含有未闭合的字符串、注释或 dollar quote');
        }
        self::append($statements, $buffer);
        foreach ($statements as $statement) {
            if (preg_match('/\\ACOPY\\s+(?:[^;]+)\\s+FROM\\s+STDIN\\b/is', self::leadingSql($statement)) === 1) {
                throw new InvalidArgumentException('PostgreSQL 生命周期脚本不允许 COPY FROM STDIN');
            }
        }
        return $statements;
    }

    public function executeFile(string $path, ?object $pdo = null, ?callable $observe = null): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('插件生命周期脚本不可读取');
        }
        $sql = file_get_contents($path);
        if (!is_string($sql)) {
            throw new RuntimeException('插件生命周期脚本不可读取');
        }
        $statements = self::split($sql);
        $scriptOwnsTransactions = false;
        foreach ($statements as $statement) {
            if (self::transactionCommand($statement) === 'begin') {
                $scriptOwnsTransactions = true;
            }
            $leading = self::transactionSql($statement);
            if (preg_match('/\A(?:ROLLBACK|ABORT|PREPARE\s+TRANSACTION)(?:\s|\z)/i', $leading)
                || preg_match('/\A(?:COMMIT|END)(?:\s|\z)/i', $leading)
                && !preg_match('/\A(?:COMMIT|END)(?:\s+(?:WORK|TRANSACTION))?\s*\z/i', $leading)) {
                throw new RuntimeException('生命周期脚本不能回滚后报告成功或使用未支持的事务结束形式');
            }
        }
        if ($statements === []) {
            if ($observe) $observe('sql_committed_deploy_pending');
            return;
        }
        if ($pdo === null) {
            $pdo = Db::connect('pgsql')->connect();
        }
        if (!method_exists($pdo, 'exec')) {
            throw new RuntimeException('PostgreSQL 连接不可用');
        }
        if (method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
            throw new RuntimeException('生命周期脚本不能接管已有数据库事务');
        }
        $transactionActive = false;
        $commitAttempted = false;
        $mode = 'none';
        try {
            if ($observe) $observe('sql_commit_unknown');
            foreach ($statements as $statement) {
                $transactionCommand = self::transactionCommand($statement);
                if ($transactionCommand === 'begin') {
                    if ($transactionActive) {
                        throw new RuntimeException('PostgreSQL 生命周期脚本包含嵌套事务');
                    }
                    self::exec($pdo, $statement);
                    $transactionActive = true;
                    $mode = 'explicit';
                    continue;
                }
                if (($transactionCommand === 'commit' || $transactionCommand === 'rollback') && !$transactionActive) {
                    throw new RuntimeException('PostgreSQL 生命周期脚本包含无活动事务的结束语句');
                }
                if (!$transactionActive && !$scriptOwnsTransactions) {
                    self::exec($pdo, 'BEGIN');
                    $transactionActive = true;
                    $mode = 'implicit';
                }
                if ($transactionCommand === 'commit' || !$transactionActive) {
                    if ($observe) $observe('sql_commit_unknown');
                    $commitAttempted = true;
                }
                self::exec($pdo, $statement);
                if ($transactionCommand === 'commit' || $transactionCommand === 'rollback') {
                    $transactionActive = false;
                }
            }
            if ($transactionActive) {
                if ($mode === 'explicit') {
                    throw new RuntimeException('PostgreSQL 生命周期脚本的显式事务未闭合');
                }
                if ($observe) $observe('sql_commit_unknown');
                $commitAttempted = true;
                self::exec($pdo, 'COMMIT');
                $transactionActive = false;
            }
            if ($observe) $observe('sql_committed_deploy_pending');
        } catch (Throwable $error) {
            $rolledBack = false;
            if ($transactionActive) {
                try {
                    self::exec($pdo, 'ROLLBACK');
                    $rolledBack = true;
                } catch (Throwable) {
                    // The original failure remains the business-facing cause.
                }
            }
            if ($observe && !$commitAttempted && $rolledBack) $observe('sql_not_committed');
            throw new RuntimeException('插件生命周期 SQL 执行失败', 0, $error);
        }
    }

    /** @param list<string> $statements */
    private static function append(array &$statements, string $statement): void
    {
        if (self::leadingSql($statement) !== '') {
            $statements[] = $statement;
        }
    }

    private static function exec(object $pdo, string $sql): void
    {
        $result = $pdo->exec($sql);
        if ($result === false) {
            throw new RuntimeException('PostgreSQL 拒绝生命周期语句');
        }
    }

    private static function transactionCommand(string $statement): ?string
    {
        $sql = self::transactionSql($statement);
        return match (true) {
            preg_match('/\\A(?:BEGIN|START\\s+TRANSACTION)(?:\\s|\\z)/i', $sql) === 1 => 'begin',
            preg_match('/\\A(?:COMMIT|END)(?:\\s|\\z)/i', $sql) === 1 => 'commit',
            preg_match('/\\AROLLBACK(?:\\s|\\z)/i', $sql) === 1 => 'rollback',
            default => null,
        };
    }

    private static function transactionSql(string $statement): string
    {
        $sql = self::leadingSql($statement);
        if (!preg_match('/\A(?:BEGIN|START|COMMIT|END|ROLLBACK|ABORT|PREPARE)\b/i', $sql)) return $sql;
        $words = [];
        while (preg_match('/\A([a-z_]+)/i', $sql, $match)) {
            $words[] = $match[1];
            // SQL comments are separators, including nested block comments.
            $sql = self::leadingSql(substr($sql, strlen($match[1])));
        }
        return implode(' ', $words) . ($sql === '' ? '' : ' ' . $sql);
    }

    private static function leadingSql(string $statement): string
    {
        $sql = ltrim($statement);
        while ($sql !== '') {
            if (str_starts_with($sql, '--')) {
                $sql = ltrim(substr($sql, strcspn($sql, "\r\n")));
                continue;
            }
            if (!str_starts_with($sql, '/*')) {
                break;
            }
            $depth = 1;
            $offset = 2;
            while ($offset < strlen($sql) && $depth > 0) {
                $pair = substr($sql, $offset, 2);
                if ($pair === '/*') { $depth++; $offset += 2; continue; }
                if ($pair === '*/') { $depth--; $offset += 2; continue; }
                $offset++;
            }
            if ($depth !== 0) return $sql;
            $sql = ltrim(substr($sql, $offset));
        }
        return $sql;
    }
}
