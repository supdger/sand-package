<?php

declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        public static array $sql = [];
        public static string $failNeedle = '';
        public static bool $schemaDrift = false;
        public static function connect(string $name): object
        {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected database connection');
            return new class {
                public function connect(): object { return $this; }
                public function inTransaction(): bool { return false; }
                public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
                public function query(string $sql): object {
                    return new class($sql) {
                        public function __construct(private string $sql) {}
                        public function fetchAll(int $mode): array {
                            if (str_contains($this->sql, 'SELECT EXISTS(')) {
                                return [['present' => false]];
                            }
                            if (str_contains($this->sql, 'current_database()')) {
                                return [['database' => 'recording-fixture', 'oid' => '1', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']];
                            }
                            return Db::$schemaDrift && str_contains($this->sql, 'FROM pg_class')
                                ? [['relname' => 'external_schema_change']] : [];
                        }
                    };
                }
                public function exec(string $sql): int {
                    Db::$sql[] = trim($sql);
                    if (Db::$failNeedle !== '' && str_contains($sql, Db::$failNeedle)) {
                        throw new \RuntimeException('recorded SQL failure');
                    }
                    return 1;
                }
            };
        }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache {
        public static bool $failOnce = false;
        public static function clearMenuCache(): void {
            if (self::$failOnce) {
                self::$failOnce = false;
                throw new \RuntimeException('injected cache failure');
            }
        }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\HostPayloadOwnership;
    use plugin\sandpackage\app\service\HostPayloadLifecycleJournal;
    use plugin\sandpackage\app\service\HostPayloadPlan;
    use plugin\sandpackage\app\service\PluginStorage;
    use Saithink\Saipackage\service\Server;
    use think\facade\Db;

    $root = sys_get_temp_dir() . '/sandpackage-presql-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/server/plugin', 0700, true);
    mkdir($root . '/sandadmin-artd', 0700, true);
    ini_set('error_log', $root . '/expected-errors.log');
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key, mixed $default = null): mixed { return $key === 'plugin.sandadmin.app.version' ? '0.1.0' : $default; }
    function hostInstallWrite(string $path, string $contents): void {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }
    function hostInstallDelete(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (new \FilesystemIterator($path) as $item) hostInstallDelete($item->getPathname());
        rmdir($path);
    }

    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    foreach (['HostPayloadManifest', 'HostPayloadPlan', 'HostPayloadOwnership', 'HostPayloadFreshFiles', 'HostPayloadChangeFiles', 'HostPayloadRuntimeChangeFiles', 'HostPayloadRemovalFiles', 'HostPayloadUninstallFinalization', 'HostPayloadDependencyChange', 'HostPayloadCandidateRollback', 'HostPayloadLifecycleJournal', 'PostgresHostCatalogFingerprint'] as $component) {
        require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/' . $component . '.php';
    }
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';


    $checks = 0;
    function preSqlCheck(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    }
    function preSqlReject(callable $action, string $message): void {
        try { $action(); } catch (\Throwable $error) {
            preSqlCheck($error instanceof \plugin\sandadmin\exception\ApiException, $message . ': wrong rejection');
            return;
        }
        throw new \RuntimeException($message);
    }
    function preSqlPackage(string $version, bool $extra): string {
        global $root;
        $app = 'neutral-host';
        $contents = [
            'info.ini' => "app = neutral-host\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = $version\nsupport = \">=0.1.0\"\n",
            'config.json' => "{}\n",
            'install.sql' => "CREATE TABLE neutral_host (id bigint);\n",
            'update.sql' => "SELECT '$version';\n",
            'uninstall.sql' => "DROP TABLE neutral_host;\n",
            'plugin/' . $app . '/config/app.php' => "<?php return ['version' => '$version'];\n",
            'sandadmin-artd/src/views/plugin/' . $app . '/index.vue' => '<template>' . $version . '</template>',
            'app/Api/Neutral/Probe.php' => "<?php // $version\n",
        ];
        if ($extra) $contents['app/Api/Neutral/NewMember.php'] = "<?php // new $version\n";
        $files = [];
        foreach ($contents as $path => $body) {
            if (str_starts_with($path, 'app/')) $files[] = ['path' => $path, 'sha256' => hash('sha256', $body)];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $contents['host-payload.json'] = json_encode(['schema' => 1, 'app' => $app, 'files' => $files], JSON_THROW_ON_ERROR);
        $file = $root . '/neutral-' . $version . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE) !== true) throw new \RuntimeException('Cannot create fixture ZIP');
        foreach ($contents as $path => $body) $zip->addFromString($path, $body);
        $zip->close();
        return $file;
    }
    try {
        $app = 'neutral-host';
        $logic = new InstallLogic($app);
        hostInstallWrite(base_path('composer.json'), '{"name":"test/host","require":{}}');
        hostInstallWrite($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
        $logic->uploadFromPath(preSqlPackage('1.0.0', false));
        $logic->install(false);
        $logic->uploadFromPath(preSqlPackage('2.0.0', false));
        $logic->install(false, 'UPGRADE neutral-host@1.0.0->2.0.0');
        $state = HostPayloadPlan::directoryPrefix((new PluginStorage())->root()) . 'host-payload';
        $changePath = $state . '/' . $app . '.change.json';
        $complete = file_get_contents($changePath);
        $completeRecord = json_decode($complete, true, 32, JSON_THROW_ON_ERROR);
        $owned = HostPayloadOwnership::read($state, $app);
        $oldHost = file_get_contents(base_path('app/Api/Neutral/Probe.php'));
        $collision = base_path('app/Api/Neutral/NewMember.php');
        hostInstallWrite($collision, 'externally owned collision');
        $info = $logic->uploadFromPath(preSqlPackage('3.0.0', true));
        $candidate = (new PluginStorage())->root() . '/' . $app;
        $backup = (new PluginStorage())->root() . '/backups/' . $info['upgrade_backup_id'];
        $sqlBefore = Db::$sql;
        preSqlReject(fn() => $logic->install(false, 'UPGRADE neutral-host@2.0.0->3.0.0'), 'Conflict did not stop installation');
        preSqlCheck(Db::$sql === $sqlBefore, 'Preflight conflict executed SQL');
        preSqlCheck(file_get_contents($changePath) === $complete, 'Conflict changed prior complete journal');
        preSqlCheck(HostPayloadOwnership::read($state, $app) === $owned, 'Conflict changed installed ownership');
        require dirname(__DIR__, 2) . '/plugin/sandpackage/app/command/Recover.php';
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \plugin\sandpackage\app\command\Recover());
        $status = $tester->execute(['action' => 'inspect-host-upgrade', 'app' => $app]);
        $inspection = json_decode(trim($tester->getDisplay()), true);
        preSqlCheck($status === 0, 'Official CLI cannot inspect pre-SQL conflict: ' . $tester->getDisplay());
        preSqlCheck(($inspection['sql_phase'] ?? null) === 'sql_not_started'
            && ($inspection['file_phase'] ?? null) === 'not_started'
            && ($inspection['actions'] ?? null) === ['restore-old-candidate']
            && preg_match('/^[a-f0-9]{64}$/D', $inspection['fingerprint'] ?? '') === 1,
            'Pre-SQL inspection did not expose a bound rollback-only action');
        $confirmation = 'RESTORE ' . $app . ' ' . $inspection['fingerprint'];
        preSqlReject(fn() => $logic->continueHostUpgrade('CONTINUE ' . $app . ' ' . $inspection['fingerprint']), 'Pre-SQL continue was allowed');
        preSqlReject(fn() => $logic->recoverHostUpgradeRollback('RESTORE ' . $app . ' stale'), 'Stale confirmation was allowed');
        $candidateInfo = file_get_contents($candidate . '/info.ini');
        hostInstallWrite($candidate . '/info.ini', $candidateInfo . "\ntitle = ChangedFixture\n");
        preSqlReject(fn() => $logic->recoverHostUpgradeRollback($confirmation), 'Registry drift did not invalidate confirmation');
        hostInstallWrite($candidate . '/info.ini', $candidateInfo);
        // Missing journal is safe only with exact installed ownership and unchanged old files.
        unlink($changePath);
        $missing = $logic->inspectHostUpgradeRecovery();
        preSqlCheck($missing['actions'] === ['restore-old-candidate'] && $missing['fingerprint'] !== $inspection['fingerprint'], 'Missing journal was not bound');
        preSqlReject(fn() => $logic->recoverHostUpgradeRollback($confirmation), 'Journal disappearance did not invalidate confirmation');
        hostInstallWrite($changePath, $complete);
        foreach (['pending', 'rolled_back', 'unknown'] as $phase) {
            $invalid = $completeRecord; $invalid['phase'] = $phase;
            hostInstallWrite($changePath, json_encode($invalid, JSON_THROW_ON_ERROR));
            preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Noncomplete old transaction was accepted: ' . $phase);
        }
        $invalid = $completeRecord; $invalid['app'] = 'foreign-plugin';
        hostInstallWrite($changePath, json_encode($invalid, JSON_THROW_ON_ERROR));
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Foreign old transaction was accepted');
        $invalid = $completeRecord; $invalid['next'][0]['sha256'] = str_repeat('f', 64);
        hostInstallWrite($changePath, json_encode($invalid, JSON_THROW_ON_ERROR));
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Wrong installed manifest was accepted');
        hostInstallWrite($changePath, '{broken');
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Malformed old journal was accepted');
        hostInstallWrite($changePath, $complete);
        $priorBackupFile = $completeRecord['backup'] . '/' . $completeRecord['old'][0]['path'];
        $priorBackup = file_get_contents($priorBackupFile);
        hostInstallWrite($priorBackupFile, 'tampered historical backup');
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Tampered historical backup was accepted');
        hostInstallWrite($priorBackupFile, $priorBackup);
        unlink($priorBackupFile);
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Missing historical backup member was accepted');
        hostInstallWrite($priorBackupFile, $priorBackup);
        hostInstallWrite($completeRecord['backup'] . '/unknown-extra', 'extra');
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Extra historical backup member was accepted');
        unlink($completeRecord['backup'] . '/unknown-extra');
        unlink($priorBackupFile);
        symlink(base_path('app/Api/Neutral/Probe.php'), $priorBackupFile);
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Symlink historical backup member was accepted');
        unlink($priorBackupFile);
        hostInstallWrite($priorBackupFile, $priorBackup);
        hostInstallWrite(base_path('app/Api/Neutral/Probe.php'), 'partial or foreign host change');
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Changed registered host member was accepted');
        hostInstallWrite(base_path('app/Api/Neutral/Probe.php'), $oldHost);
        $ownedPath = $state . '/' . $app . '.owned.json'; $ownedRaw = file_get_contents($ownedPath);
        unlink($ownedPath);
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Missing installed ownership was accepted');
        hostInstallWrite($ownedPath, $ownedRaw);
        $sqlBody = file_get_contents($candidate . '/update.sql');
        hostInstallWrite($candidate . '/update.sql', "SELECT 'changed';\n");
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Candidate drift was accepted');
        hostInstallWrite($candidate . '/update.sql', $sqlBody);
        $oldInfo = file_get_contents($backup . '/info.ini');
        hostInstallWrite($backup . '/info.ini', $oldInfo . "\nversion = 8.0.0\n");
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Old installed identity drift was accepted');
        hostInstallWrite($backup . '/info.ini', $oldInfo);
        Db::$schemaDrift = true;
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Database drift was accepted');
        Db::$schemaDrift = false;
        foreach (['runtime', 'dependencies'] as $kind) {
            $path = $state . '/' . $app . '.' . $kind . '.json';
            hostInstallWrite($path, '{}');
            preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Unexpected later file transaction was accepted');
            unlink($path);
        }
        $lifecyclePath = $state . '/' . $app . '.lifecycle.json';
        $lifecycle = file_get_contents($lifecyclePath); $record = json_decode($lifecycle, true, 32, JSON_THROW_ON_ERROR);
        foreach (['sql_commit_unknown', 'sql_committed_deploy_pending'] as $phase) {
            $altered = $record; $altered['phase'] = $phase;
            hostInstallWrite($lifecyclePath, json_encode($altered, JSON_THROW_ON_ERROR));
            preSqlReject(fn() => $logic->recoverHostUpgradeRollback($confirmation), 'Unknown or committed SQL permitted rollback');
        }
        hostInstallWrite($lifecyclePath, $lifecycle);
        // Invalid database proof must stop before any candidate or file recovery write.
        $invalidProofs = [];
        $invalidProofs['null_before'] = $record;
        $invalidProofs['null_before']['database_before'] = null;
        $invalidProofs['missing_before'] = $record;
        unset($invalidProofs['missing_before']['database_before']);
        $invalidProofs['contradictory_after'] = $record;
        $invalidProofs['contradictory_after']['database_after'] = $record['database_before'];
        $invalidProofs['wrong_type_before'] = $record;
        $invalidProofs['wrong_type_before']['database_before'] = 'invalid';
        $invalidProofs['missing_identity_before'] = $record;
        unset($invalidProofs['missing_identity_before']['database_before']['identity']);
        $invalidProofs['invalid_hash_before'] = $record;
        $invalidProofs['invalid_hash_before']['database_before']['identity'] = str_repeat('z', 64);
        $invalidProofs['extra_field_before'] = $record;
        $invalidProofs['extra_field_before']['database_before']['extra'] = true;
        $invalidProofs['wrong_database_identity'] = $record;
        $invalidProofs['wrong_database_identity']['database_before']['identity'] = str_repeat('e', 64);
        $invalidProofs['missing_after'] = $record;
        unset($invalidProofs['missing_after']['database_after']);
        foreach ($invalidProofs as $case => $invalidProof) {
            hostInstallWrite($lifecyclePath, json_encode($invalidProof, JSON_THROW_ON_ERROR));
            $serverBefore = \plugin\sandpackage\app\service\FreshInstallRecovery::tree(base_path());
            $frontendBefore = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/sandadmin-artd');
            $status = $tester->execute(['action' => 'inspect-host-upgrade', 'app' => $app]);
            $invalidInspection = json_decode(trim($tester->getDisplay()), true);
            preSqlCheck($status !== 0 && !is_array($invalidInspection), 'Invalid database proof exposed recovery actions: ' . $case);
            $status = $tester->execute(['action' => 'restore-host-upgrade', 'app' => $app, '--confirmation' => $confirmation]);
            preSqlCheck($status !== 0, 'Invalid database proof allowed restore: ' . $case);
            preSqlCheck($serverBefore === \plugin\sandpackage\app\service\FreshInstallRecovery::tree(base_path())
                && $frontendBefore === \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/sandadmin-artd'),
                'Rejected database proof changed candidate, host, registry or journal bytes: ' . $case);
            preSqlCheck(Db::$sql === $sqlBefore && $logic->getInfo()['version'] === '3.0.0'
                && !file_exists($state . '/' . $app . '.rollback.json'), 'Rejected database proof executed SQL or began partial recovery: ' . $case);
        }
        hostInstallWrite($lifecyclePath, $lifecycle);
        // Unregistered collisions are preserved, but every byte is confirmation-bound.
        hostInstallWrite($collision, 'changed external collision');
        preSqlReject(fn() => $logic->recoverHostUpgradeRollback($confirmation), 'Collision drift did not invalidate confirmation');
        hostInstallWrite($collision, 'externally owned collision');
        $status = $tester->execute(['action' => 'restore-host-upgrade', 'app' => $app, '--confirmation' => $confirmation]);
        $result = json_decode(trim($tester->getDisplay()), true);
        preSqlCheck($status === 0 && ($result['state'] ?? null) === InstallLogic::INSTALLED
            && ($result['sql_replayed'] ?? null) === false, 'Official CLI did not restore the installed candidate');
        preSqlCheck(Db::$sql === $sqlBefore, 'Restore or rejection replayed SQL');
        preSqlCheck($logic->getInfo()['version'] === '2.0.0' && $logic->getInstallState() === InstallLogic::INSTALLED, 'Restore did not return to safe installed state');
        preSqlCheck(file_get_contents($changePath) === $complete && HostPayloadOwnership::read($state, $app) === $owned
            && file_get_contents($collision) === 'externally owned collision', 'Restore changed historical journal, ownership or conflict');
        preSqlCheck(is_dir($result['failed_candidate'] ?? ''), 'Failed candidate was not retained');
        preSqlReject(fn() => $logic->recoverHostUpgradeRollback($confirmation), 'Completed rollback accepted stale replay');
        // A journal for the current incoming payload is never an old completed transaction.
        $logic->uploadFromPath(preSqlPackage('3.0.0', true));
        preSqlReject(fn() => $logic->install(false, 'UPGRADE neutral-host@2.0.0->3.0.0'), 'Current pending setup did not fail before SQL');
        unlink($collision);
        $currentFiles = \plugin\sandpackage\app\service\HostPayloadManifest::inspectDirectory($candidate, $app);
        (new \plugin\sandpackage\app\service\HostPayloadChangeFiles(base_path(), $state, $candidate, $app, $currentFiles))->begin();
        hostInstallWrite($collision, 'externally owned collision');
        preSqlReject(fn() => $logic->inspectHostUpgradeRecovery(), 'Valid current pending transaction was mistaken for an unstarted change');
        hostInstallWrite($changePath, $complete);
        $currentInspection = $logic->inspectHostUpgradeRecovery();
        $logic->recoverHostUpgradeRollback('RESTORE ' . $app . ' ' . $currentInspection['fingerprint']);
        preSqlCheck(Db::$sql === $sqlBefore, 'Pending transaction rejection replayed SQL');
        // Repeat with no historic journal and demonstrate a safe new normal attempt.
        unlink($changePath);
        $logic->uploadFromPath(preSqlPackage('3.0.0', true));
        preSqlReject(fn() => $logic->install(false, 'UPGRADE neutral-host@2.0.0->3.0.0'), 'Repeated conflict bypassed protection');
        preSqlCheck(Db::$sql === $sqlBefore && !file_exists($changePath), 'Retry created a transaction or executed SQL before conflict');
        $missing = $logic->inspectHostUpgradeRecovery();
        $logic->recoverHostUpgradeRollback('RESTORE ' . $app . ' ' . $missing['fingerprint']);
        preSqlCheck(Db::$sql === $sqlBefore && $logic->getInstallState() === InstallLogic::INSTALLED && !file_exists($changePath), 'Missing-journal restore manufactured a transaction');
        // Candidate quarantine is recoverable at each persisted checkpoint without host writes.
        hostInstallWrite($collision, "<?php // new 3.0.0\n");
        foreach (['prepared', 'staged', 'quarantined', 'restored'] as $interruptedPhase) {
            $retryInfo = $logic->uploadFromPath(preSqlPackage('3.0.0', true));
            preSqlReject(fn() => $logic->install(false, 'UPGRADE neutral-host@2.0.0->3.0.0'), 'Same-hash unregistered file bypassed conflict protection');
            $beforeInterruption = $logic->inspectHostUpgradeRecovery();
            $rollback = new \plugin\sandpackage\app\service\HostPayloadCandidateRollback(
                (new PluginStorage())->root(), $app, $retryInfo['upgrade_backup_id'],
                $retryInfo['upgrade_backup_tree_sha256'], $retryInfo['candidate_tree_sha256'],
            );
            try {
                $rollback->restore(static function (string $phase) use ($interruptedPhase): void {
                    if ($phase === $interruptedPhase) throw new \RuntimeException('checkpoint fault');
                });
                throw new \RuntimeException('Checkpoint interruption did not trigger');
            } catch (\RuntimeException $error) {
                if ($error->getMessage() !== 'checkpoint fault') throw $error;
            }
            $resumedInspection = $logic->inspectHostUpgradeRecovery();
            preSqlCheck($resumedInspection['rollback_phase'] === $interruptedPhase
                && $resumedInspection['fingerprint'] !== $beforeInterruption['fingerprint'], 'Interrupted recovery was not newly bound');
            preSqlReject(fn() => $logic->recoverHostUpgradeRollback('RESTORE ' . $app . ' ' . $beforeInterruption['fingerprint']), 'Pre-interruption confirmation was accepted');
            $status = $tester->execute(['action' => 'restore-host-upgrade', 'app' => $app,
                '--confirmation' => 'RESTORE ' . $app . ' ' . $resumedInspection['fingerprint']]);
            preSqlCheck($status === 0 && $logic->getInstallState() === InstallLogic::INSTALLED
                && Db::$sql === $sqlBefore && !file_exists($changePath)
                && file_get_contents($collision) === "<?php // new 3.0.0\n", 'Interrupted restore changed SQL, journal or same-hash conflict');
        }
        unlink($collision);
        $logic->uploadFromPath(preSqlPackage('3.0.0', true));
        $logic->install(false, 'UPGRADE neutral-host@2.0.0->3.0.0');
        preSqlCheck(array_slice(Db::$sql, count($sqlBefore)) === ['BEGIN', "SELECT '3.0.0'", 'COMMIT']
            && $logic->getInfo()['version'] === '3.0.0' && $logic->getInstallState() === InstallLogic::INSTALLED, 'Fresh authorized attempt did not execute SQL exactly once');
        echo "Pre-SQL upgrade recovery: $checks checks passed; official CLI inspect/restore, recording SQL only\n";
    } finally {
        if (!str_starts_with($root, sys_get_temp_dir() . '/sandpackage-presql-test-') || is_link($root)) {
            throw new \RuntimeException('Refusing cleanup outside owned pre-SQL fixture');
        }
        hostInstallDelete($root);
    }
}
