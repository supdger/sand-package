<?php

declare(strict_types=1);

use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';

function lifecycleExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "[PASS] {$message}\n";
}

$statements = PostgresLifecycleSqlExecutor::split("-- one;\nCREATE TABLE plugin_sample (id bigint, note text default 'a;b'); /* outer /* nested ; */ ok */ CREATE FUNCTION plugin_sample_fn() RETURNS void AS \$tag\$ BEGIN PERFORM 'x;y'; END; \$tag\$ LANGUAGE plpgsql;\n");
lifecycleExpect(count($statements) === 2, 'splits only top-level PostgreSQL semicolons');
lifecycleExpect(str_contains($statements[1], "PERFORM 'x;y'"), 'keeps dollar-quoted function body intact');
foreach (["SELECT 'unterminated", '/* unterminated', 'SELECT $tag$unterminated'] as $invalid) {
    try { PostgresLifecycleSqlExecutor::split($invalid); throw new RuntimeException('accepted unterminated structure'); }
    catch (InvalidArgumentException) { echo "[PASS] rejects unterminated PostgreSQL structure\n"; }
}
try { PostgresLifecycleSqlExecutor::split('COPY plugin_sample FROM STDIN;'); throw new RuntimeException('accepted COPY FROM STDIN'); }
catch (InvalidArgumentException) { echo "[PASS] rejects COPY FROM STDIN\n"; }
try { PostgresLifecycleSqlExecutor::split("/* nested /* prefix */ */ COPY plugin_sample FROM STDIN;"); throw new RuntimeException('accepted comment-prefixed COPY FROM STDIN'); }
catch (InvalidArgumentException) { echo "[PASS] rejects comment-prefixed COPY FROM STDIN\n"; }
lifecycleExpect(PostgresLifecycleSqlExecutor::split("-- tail only\n/* and block */") === [], 'accepts pure trailing comments as a no-op');
lifecycleExpect(PostgresLifecycleSqlExecutor::split('-- tail only') === [], 'accepts EOF line comment as a no-op');
lifecycleExpect(count(PostgresLifecycleSqlExecutor::split('SELECT 1; -- end')) === 1, 'accepts EOF tail comment after a statement');
lifecycleExpect(count(PostgresLifecycleSqlExecutor::split("SELECT E'a\\\\'; SELECT 2;")) === 2, 'keeps PostgreSQL E-string escapes intact');

$explicit = PostgresLifecycleSqlExecutor::split("BEGIN; DO \$\$ BEGIN PERFORM 'first;second'; END; \$\$; COMMIT; BEGIN; CREATE TABLE plugin_sample_two (id bigint); COMMIT;");
lifecycleExpect(count($explicit) === 6, 'retains explicit multi-transaction and DO dollar-quote statements');
$explicitPdo = new class {
    public array $executed = [];
    public function exec(string $sql): int { $this->executed[] = trim($sql); return 1; }
};
$explicitFile = tempnam(sys_get_temp_dir(), 'sandpackage-explicit-');
file_put_contents($explicitFile, "BEGIN; DO \$\$ BEGIN PERFORM 'first;second'; END; \$\$; COMMIT; BEGIN; CREATE TABLE plugin_sample_two (id bigint); COMMIT;");
(new PostgresLifecycleSqlExecutor())->executeFile($explicitFile, $explicitPdo);
lifecycleExpect($explicitPdo->executed === array_map('trim', $explicit), 'preserves script-owned explicit transaction boundaries without wrapper transaction');
unlink($explicitFile);

$ownedFile = tempnam(sys_get_temp_dir(), 'sandpackage-owned-');
file_put_contents($ownedFile, 'BEGIN; SELECT 1; COMMIT;');
$ownedPdo = new class {
    public array $executed=[];
    public function inTransaction(): bool { return true; }
    public function exec(string $sql): int { $this->executed[]=$sql; return 1; }
};
$rejected = false;
try { (new PostgresLifecycleSqlExecutor())->executeFile($ownedFile, $ownedPdo); }
catch (RuntimeException) { $rejected = true; }
lifecycleExpect($rejected && $ownedPdo->executed === [], 'does not take over a caller-owned transaction');
unlink($ownedFile);

foreach ([
    ['SELECT 1; BEGIN; SELECT 2; COMMIT; SELECT 3;', ['SELECT 1', 'BEGIN', 'SELECT 2', 'COMMIT', 'SELECT 3']],
    ['SELECT 1; START TRANSACTION; SELECT 2; COMMIT; BEGIN; SELECT 3; COMMIT;', ['SELECT 1', 'START TRANSACTION', 'SELECT 2', 'COMMIT', 'BEGIN', 'SELECT 3', 'COMMIT']],
] as [$mixedSql, $expected]) {
    $mixedFile = tempnam(sys_get_temp_dir(), 'sandpackage-mixed-');
    file_put_contents($mixedFile, $mixedSql);
    $mixedPdo = new class { public array $executed=[]; public function exec(string $sql): int { $this->executed[]=trim($sql); return 1; } };
    (new PostgresLifecycleSqlExecutor())->executeFile($mixedFile, $mixedPdo);
    lifecycleExpect($mixedPdo->executed === $expected, 'preserves mixed script boundaries from the first statement');
    unlink($mixedFile);
}
foreach ([
    ['SELECT 1; BEGIN; FAIL; COMMIT;', ['SELECT 1', 'BEGIN', 'FAIL', 'ROLLBACK']],
    ['BEGIN; SELECT 1; COMMIT; FAIL;', ['BEGIN', 'SELECT 1', 'COMMIT', 'FAIL']],
] as [$mixedSql, $expected]) {
    $mixedFile = tempnam(sys_get_temp_dir(), 'sandpackage-mixed-failure-');
    file_put_contents($mixedFile, $mixedSql);
    $mixedPdo = new class {
        public array $executed=[];
        public function exec(string $sql): int|false {
            $this->executed[]=trim($sql);
            return trim($sql) === 'FAIL' ? false : 1;
        }
    };
    $failed = false;
    try { (new PostgresLifecycleSqlExecutor())->executeFile($mixedFile, $mixedPdo); }
    catch (RuntimeException) { $failed = true; }
    lifecycleExpect($failed && $mixedPdo->executed === $expected, 'mixed script failure rolls back only its active block');
    unlink($mixedFile);
}

$unclosedFile = tempnam(sys_get_temp_dir(), 'sandpackage-unclosed-');
file_put_contents($unclosedFile, "/* nested /* lead */ */ BEGIN; SELECT 1;");
$unclosedPdo = new class { public array $executed=[]; public function exec(string $sql): int { $this->executed[]=trim($sql); return 1; } };
try { (new PostgresLifecycleSqlExecutor())->executeFile($unclosedFile, $unclosedPdo); throw new RuntimeException('accepted unclosed explicit transaction'); }
catch (RuntimeException) { lifecycleExpect($unclosedPdo->executed === ['/* nested /* lead */ */ BEGIN', 'SELECT 1', 'ROLLBACK'], 'rolls back and fails unclosed explicit transaction'); }
unlink($unclosedFile);

$file = tempnam(sys_get_temp_dir(), 'sandpackage-lifecycle-');
file_put_contents($file, 'CREATE TABLE plugin_sample (id bigint); INSERT INTO plugin_sample VALUES (1);');
$pdo = new class {
    public array $executed = [];
    public function exec(string $sql): int|false { $this->executed[] = trim($sql); return str_contains($sql, 'INSERT') ? false : 1; }
};
try { (new PostgresLifecycleSqlExecutor())->executeFile($file, $pdo); throw new RuntimeException('executor accepted a failing statement'); }
catch (RuntimeException) { lifecycleExpect($pdo->executed === ['BEGIN', 'CREATE TABLE plugin_sample (id bigint)', 'INSERT INTO plugin_sample VALUES (1)', 'ROLLBACK'], 'rolls back exactly once after statement failure'); }
unlink($file);
echo "SandPackage PostgreSQL lifecycle executor behavior contract passed\n";

foreach (['ROLLBACK', 'ABORT', 'COMMIT AND CHAIN', 'PREPARE TRANSACTION \'test\'', 'ROLLBACK/**/', 'COMMIT/**/AND CHAIN', 'ABORT/* nested /* x */ y */'] as $ending) {
    $unsafeFile = tempnam(sys_get_temp_dir(), 'sandpackage-transaction-');
    file_put_contents($unsafeFile, "BEGIN; SELECT 1; $ending;");
    $recording = new class { public array $executed = []; public function exec(string $sql): int { $this->executed[] = $sql; return 1; } };
    $rejected = false;
    try { (new PostgresLifecycleSqlExecutor())->executeFile($unsafeFile, $recording); }
    catch (RuntimeException) { $rejected = true; }
    lifecycleExpect($rejected && $recording->executed === [], "rejects $ending before executing any statement");
    unlink($unsafeFile);
}
