<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadManifest.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadLifecycleJournal.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresHostCatalogFingerprint.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';

use plugin\sandpackage\app\service\HostPayloadLifecycleJournal;
use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;

final class OutcomeConnection
{
    public array $executed = [];
    public string $failure = '';
    public bool $driftOnFailure = false;
    public bool $drift = false;

    public function inTransaction(): bool { return false; }
    public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
    public function query(string $sql): object
    {
        $rows = str_contains($sql, 'current_database()')
            ? [['database' => 'recording-fixture', 'oid' => '1', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']]
            : ($this->drift && str_contains($sql, 'FROM pg_class') ? [['relname' => 'external_change']] : []);
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function fetchAll(int $mode): array { return $this->rows; }
        };
    }

    public function exec(string $sql): int
    {
        $this->executed[] = trim($sql);
        if ($this->failure !== '' && str_contains($sql, $this->failure)) {
            if ($this->driftOnFailure) $this->drift = true;
            throw new RuntimeException('injected SQL or acknowledgement failure');
        }
        return 1;
    }
}

function outcomeRemove(string $path): void
{
    if (!file_exists($path)) return;
    if (is_file($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) outcomeRemove($entry->getPathname());
    rmdir($path);
}

$root = sys_get_temp_dir() . '/sandpackage-host-sql-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$sql = $root . '/update.sql';
try {
    $executor = new PostgresLifecycleSqlExecutor();
    foreach ([
        ['app' => 'known-rollback', 'script' => "BROKEN SQL;\n", 'failure' => 'BROKEN', 'drift' => false, 'phase' => 'sql_not_committed'],
        ['app' => 'catalog-drift', 'script' => "BROKEN SQL;\n", 'failure' => 'BROKEN', 'drift' => true, 'phase' => 'sql_commit_unknown'],
        ['app' => 'commit-unknown', 'script' => "SELECT 'updated';\n", 'failure' => 'COMMIT', 'drift' => false, 'phase' => 'sql_commit_unknown'],
        ['app' => 'committed', 'script' => "SELECT 'updated';\n", 'failure' => '', 'drift' => false, 'phase' => 'sql_committed_deploy_pending'],
    ] as $case) {
        file_put_contents($sql, $case['script']);
        $connection = new OutcomeConnection();
        $connection->failure = $case['failure'];
        $connection->driftOnFailure = $case['drift'];
        $journal = new HostPayloadLifecycleJournal($root, $case['app'], 'upgrade', str_repeat('a', 64), $connection);
        $journal->begin();
        try {
            $executor->executeFile($sql, $connection, $journal->observe(...));
            if ($case['failure'] !== '') throw new RuntimeException('Expected SQL failure');
        } catch (RuntimeException $error) {
            if ($case['failure'] === '') throw $error;
        }
        if ($journal->read()['phase'] !== $case['phase']
            || !HostPayloadLifecycleJournal::pending($root, $case['app'])
            || !is_array($journal->read()['database_before'])) {
            throw new RuntimeException('SQL outcome was not durably distinguished: ' . $case['app']);
        }
        if ($case['failure'] === '') {
            $journal->complete();
            if (HostPayloadLifecycleJournal::pending($root, $case['app'])
                || count(glob($root . '/' . $case['app'] . '.lifecycle.json.*.history') ?: []) !== 1) {
                throw new RuntimeException('Completed SQL lifecycle was not archived');
            }
        }
    }
    echo "Host payload SQL outcome recording passed\n";
} finally {
    outcomeRemove($root);
}
