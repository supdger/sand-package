<?php

declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        public static array $sql = [];
        public static string $failNeedle = '';
        public static bool $schemaDrift = false;
        public static bool $invalidDatabase = false;
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
                                if (Db::$invalidDatabase) return [];
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

    $root = sys_get_temp_dir() . '/sandpackage-upload-withdraw-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/server/plugin', 0700, true);
    mkdir($root . '/sandadmin-artd', 0700, true);
    ini_set('error_log', $root . '/expected-errors.log');
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function json(mixed $data, int $options = 0): \support\Response { return new \support\Response(200, ['Content-Type' => 'application/json'], json_encode($data, $options | JSON_THROW_ON_ERROR)); }
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
    function withdrawCheck(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
        echo "[PASS] $message\n";
    }
    function withdrawReject(callable $action, string $message): void {
        try { $action(); } catch (\Throwable $error) {
            withdrawCheck($error instanceof \plugin\sandadmin\exception\ApiException, $message);
            return;
        }
        throw new \RuntimeException($message);
    }
    function withdrawPackage(string $version, bool $extra): string {
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
        if (is_file($file)) unlink($file);
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE) !== true) throw new \RuntimeException('Cannot create fixture ZIP');
        foreach ($contents as $path => $body) $zip->addFromString($path, $body);
        $zip->close();
        return $file;
    }
    class NeutralWithdrawalController extends \plugin\sandpackage\app\controller\InstallController { protected function init(): void { $this->adminId = 1; } }
    function withdrawalRequest(array $fields, string $method = 'POST'): \support\Request {
        $body = http_build_query($fields);
        return new \support\Request($method . " /app/sandpackage/install/discardCandidate HTTP/1.1\r\nHost: fixture\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }
    try {
        $app = 'neutral-host';
        $logic = new InstallLogic($app);
        hostInstallWrite(base_path('composer.json'), '{"name":"test/host","require":{}}');
        hostInstallWrite($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
        $logic->uploadFromPath(withdrawPackage('1.0.0', false));
        withdrawReject(fn() => $logic->inspectCandidateWithdrawal(), 'First-install candidate has no withdrawal action');
        $logic->install(false);
        $logic->uploadFromPath(withdrawPackage('2.0.0', false));
        $logic->install(false, 'UPGRADE neutral-host@1.0.0->2.0.0');
        $installed = $logic->getInfo();
        $storage = (new PluginStorage())->root();
        $state = $storage . '/host-payload';
        $candidate = $storage . '/' . $app;
        $oldRegistry = file_get_contents($candidate . '/info.ini');
        $info = $logic->uploadFromPath(withdrawPackage('3.0.0', true));
        $before = Db::$sql;
        $backup = $storage . '/backups/' . $info['upgrade_backup_id'];
        $registry = file_get_contents($candidate . '/info.ini');
        $owned = HostPayloadOwnership::read($state, $app);
        $collision = base_path('app/Api/Neutral/NewMember.php');
        hostInstallWrite($collision, "<?php // new 3.0.0\n");
        hostInstallWrite(base_path('storage/shared/retained.txt'), 'shared data');
        $runtime = [];
        foreach ($logic->getAllowedPath() as $target) $runtime[$target] = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($target);
        require dirname(__DIR__, 2) . '/plugin/sandpackage/app/command/Recover.php';
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \plugin\sandpackage\app\command\Recover());
        withdrawCheck($tester->execute(['action'=>'inspect-host-upgrade','app'=>$app]) === 1, 'Lifecycle inspection remains distinct without a journal');
        withdrawCheck($tester->execute(['action'=>'inspect-candidate','app'=>$app]) === 0, 'Official candidate inspection CLI succeeds');
        $snapshot = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/server');
        $logic->inspectCandidateWithdrawal();
        withdrawCheck($snapshot === \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/server'), 'Inspection does not modify candidate, registry or journals');
        $inspection = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);
        withdrawCheck($inspection['operation'] === 'uploaded-upgrade-withdrawal' && $inspection['actions'] === ['discard-candidate']
            && preg_match('/^[a-f0-9]{64}$/D', $inspection['fingerprint']) === 1, 'Inspection exposes a bounded fingerprint and one action');
        $controller = new NeutralWithdrawalController();
        $api = json_decode($controller->inspectCandidate(withdrawalRequest(['appName'=>$app]))->rawBody(), true, 32, JSON_THROW_ON_ERROR);
        withdrawCheck(($api['data']['fingerprint'] ?? null) === $inspection['fingerprint'], 'Official API and CLI inspection agree');
        $confirm = 'DISCARD ' . $app . ' ' . $inspection['fingerprint'];
        function withdrawalNoWrite(callable $action, string $message): void {
            global $root, $before;
            $tree = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/server');
            $frontend = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/sandadmin-artd');
            withdrawReject($action, $message);
            withdrawCheck($tree === \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/server')
                && $frontend === \plugin\sandpackage\app\service\FreshInstallRecovery::tree($root . '/sandadmin-artd') && Db::$sql === $before, $message . ' preserves files, registry, journals and SQL');
        }
        withdrawalNoWrite(fn() => $logic->discardCandidate('DISCARD neutral-host stale'), 'Stale confirmation is rejected');
        foreach (['operation_pending','failed_upgrade','process_recovery_required','dependency_command_nonce','composer_dependent_wait_install','npm_dependent_wait_install','registration_candidate'] as $flag) {
            hostInstallWrite($candidate . '/info.ini', $registry . "\n$flag = 1\n");
            withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Candidate flag rejects: ' . $flag);
            hostInstallWrite($candidate . '/info.ini', $registry);
        }
        foreach (['lifecycle','runtime','dependencies','removal','uninstall-finalization'] as $kind) {
            $path = $state . '/' . $app . '.' . $kind . '.json';
            hostInstallWrite($path, '{unknown}');
            withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Existing transaction rejects: ' . $kind);
            unlink($path);
        }
        $changePath = $state . '/' . $app . '.change.json';
        $complete = file_get_contents($changePath);
        foreach (['pending','rolled_back','unknown'] as $phase) {
            $bad = json_decode($complete, true, 32, JSON_THROW_ON_ERROR); $bad['phase'] = $phase;
            hostInstallWrite($changePath, json_encode($bad, JSON_THROW_ON_ERROR));
            withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Noncomplete host transaction rejects: ' . $phase);
        }
        hostInstallWrite($changePath, $complete);
        unlink($changePath);
        $missing = $logic->inspectCandidateWithdrawal();
        withdrawCheck($missing['actions'] === ['discard-candidate'] && $missing['fingerprint'] !== $inspection['fingerprint'], 'Absent old change record is accepted only against exact old ownership and files');
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Change record disappearance invalidates confirmation');
        hostInstallWrite($changePath, $complete);
        $bad = json_decode($complete, true, 32, JSON_THROW_ON_ERROR); $bad['next'][0]['sha256'] = str_repeat('f',64);
        hostInstallWrite($changePath, json_encode($bad, JSON_THROW_ON_ERROR));
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Wrong old-complete manifest rejects');
        hostInstallWrite($changePath, $complete);
        $historical = json_decode($complete, true, 32, JSON_THROW_ON_ERROR);
        $extra = $historical['backup'] . '/unexpected'; hostInstallWrite($extra, 'extra');
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Historical backup extra member rejects'); unlink($extra);
        foreach ([$backup . '/info.ini', $candidate . '/update.sql', base_path('app/Api/Neutral/Probe.php'), base_path('plugin/' . $app . '/config/app.php')] as $path) {
            $original = file_get_contents($path); hostInstallWrite($path, $original . "\ntampered\n");
            withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Changed package or installed file rejects: ' . basename($path));
            hostInstallWrite($path, $original);
        }
        hostInstallWrite($candidate . '/info.ini', $registry . "\n");
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Raw registry whitespace drift invalidates confirmation');
        hostInstallWrite($candidate . '/info.ini', $registry);
        hostInstallWrite($candidate . '/info.ini', $registry . "\ntitle = Changed\n");
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Registry drift invalidates confirmation');
        hostInstallWrite($candidate . '/info.ini', $registry);
        hostInstallWrite($collision, 'changed external file');
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Incoming-only host drift invalidates confirmation');
        hostInstallWrite($collision, "<?php // new 3.0.0\n");
        Db::$schemaDrift = true;
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Current database schema drift invalidates confirmation');
        Db::$schemaDrift = false;
        Db::$invalidDatabase = true;
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Missing current database identity rejects before writes');
        Db::$invalidDatabase = false;
        $dependency = base_path('composer.json'); $original = file_get_contents($dependency);
        hostInstallWrite($dependency, $original . "\n");
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Dependency manifest drift invalidates confirmation');
        hostInstallWrite($dependency, $original);
        $lock = fopen($storage . '/locks/' . $app . '-operation.lock', 'c+'); flock($lock, LOCK_EX | LOCK_NB);
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Active app lock rejects'); flock($lock, LOCK_UN); fclose($lock);
        $journal = $storage . '/locks/' . $app . '-dependency.json'; hostInstallWrite($journal, '{}');
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Pending dependency command record rejects'); unlink($journal);
        $hostLock = fopen($storage . '/locks/upstream-host.lock', 'c+'); flock($hostLock, LOCK_EX | LOCK_NB);
        withdrawalNoWrite(fn() => $logic->discardCandidate($confirm), 'Active host lock rejects'); flock($hostLock, LOCK_UN); fclose($hostLock);
        $response = $controller->discardCandidate(withdrawalRequest(['appName'=>$app,'confirmation'=>$confirm]));
        withdrawCheck(json_decode($response->rawBody(), true)['data']['version'] === '2.0.0' && file_get_contents($candidate . '/info.ini') === $oldRegistry, 'Existing official discard API restores exact old registration');
        withdrawCheck(Db::$sql === $before && HostPayloadOwnership::read($state,$app) === $owned
            && file_get_contents($changePath) === $complete && file_get_contents($collision) === "<?php // new 3.0.0\n"
            && file_get_contents(base_path('storage/shared/retained.txt')) === 'shared data', 'Withdrawal preserves SQL, ownership, complete journal, incoming-only and shared files');
        foreach ($logic->getAllowedPath() as $target) withdrawCheck(\plugin\sandpackage\app\service\FreshInstallRecovery::tree($target) === $runtime[$target], 'Installed runtime tree remains unchanged');
        $finished = $logic->inspectCandidateWithdrawal();
        withdrawCheck($finished['actions'] === [] && $finished['withdrawal_phase'] === 'restored', 'Restored checkpoint exposes no replay action');
        withdrawalNoWrite(fn() => $logic->discardCandidate('DISCARD ' . $app . ' ' . $finished['fingerprint']), 'Completed withdrawal cannot replay writes');
        $replacement = $logic->uploadFromPath(withdrawPackage('3.0.0', false));
        withdrawCheck($replacement['state'] === InstallLogic::WAIT_INSTALL && $replacement['package_sha256'] !== $info['package_sha256'], 'Normal upload accepts same candidate version with a different artifact after withdrawal');
        $inspection = $logic->inspectCandidateWithdrawal();
        withdrawCheck($tester->execute(['action'=>'discard-candidate','app'=>$app,'--confirmation'=>'DISCARD ' . $app . ' ' . $inspection['fingerprint']]) === 0, 'Official discard CLI succeeds');
        foreach (['prepared','staged','quarantined','restored','final-save'] as $phase) {
            $info = $logic->uploadFromPath(withdrawPackage('3.0.0', true));
            $inspection = $logic->inspectCandidateWithdrawal();
            $mover = new \plugin\sandpackage\app\service\HostPayloadCandidateRollback($storage,$app,$info['upgrade_backup_id'],$info['upgrade_backup_tree_sha256'],$info['candidate_tree_sha256']);
            $quarantinedRecord = null;
            try { $mover->restore(static function(string $checkpoint) use ($phase, $state, $app, &$quarantinedRecord): void {
                if ($checkpoint === 'quarantined') $quarantinedRecord = file_get_contents($state . '/' . $app . '.rollback.json');
                if ($checkpoint === $phase || $phase === 'final-save' && $checkpoint === 'restored') throw new \RuntimeException('Fixture interruption');
            }); }
            catch (\RuntimeException $e) { if ($e->getMessage() !== 'Fixture interruption') throw $e; }
            if ($phase === 'final-save') {
                if (!is_string($quarantinedRecord)) throw new \RuntimeException('Missing pre-save checkpoint');
                hostInstallWrite($state . '/' . $app . '.rollback.json', $quarantinedRecord);
            }
            $candidateInodes = $phase === 'final-save' ? [stat($candidate)['ino'], stat($candidate . '/info.ini')['ino']] : null;
            withdrawCheck($logic->usesCandidateWithdrawal(), 'Official API driver is discoverable at checkpoint: ' . $phase);
            $candidateBeforeFinish = \plugin\sandpackage\app\service\FreshInstallRecovery::tree($candidate);
            withdrawCheck($tester->execute(['action'=>'inspect-candidate','app'=>$app]) === 0, 'Official CLI discovers interrupted withdrawal: ' . $phase);
            $resumed = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);
            if ($phase === 'staged') {
                $record = json_decode(file_get_contents($state . '/' . $app . '.rollback.json'), true, 32, JSON_THROW_ON_ERROR);
                $extraStage = $state . '/' . $app . '.rollback-' . $record['id'] . '.stage/unexpected';
                hostInstallWrite($extraStage, 'foreign staged member');
                withdrawalNoWrite(fn() => $logic->discardCandidate('DISCARD ' . $app . ' ' . $resumed['fingerprint']), 'Tampered staged tree rejects before resume');
                unlink($extraStage);
            }
            withdrawCheck($resumed['fingerprint'] !== $inspection['fingerprint'], 'Checkpoint change invalidates old confirmation: ' . $phase);
            withdrawalNoWrite(fn() => $logic->discardCandidate('DISCARD ' . $app . ' ' . $inspection['fingerprint']), 'Old checkpoint confirmation rejects: ' . $phase);
            if ($phase === 'final-save') withdrawCheck($resumed['actions'] === ['finish-journal'], 'Final-save window offers only completion of the existing journal');
            if ($phase !== 'restored') {
                withdrawCheck($tester->execute(['action'=>'discard-candidate','app'=>$app,'--confirmation'=>'DISCARD ' . $app . ' ' . $resumed['fingerprint']]) === 0, 'Fresh confirmation resumes withdrawal: ' . $phase);
            } else withdrawCheck($resumed['actions'] === [], 'Restored interrupted checkpoint needs no file rewrite');
            if ($phase === 'final-save') withdrawCheck($candidateBeforeFinish === \plugin\sandpackage\app\service\FreshInstallRecovery::tree($candidate)
                && $candidateInodes === [stat($candidate)['ino'], stat($candidate . '/info.ini')['ino']]
                && $logic->inspectCandidateWithdrawal()['actions'] === [], 'Final journal completion does not rewrite the restored candidate and closes replay');
            withdrawCheck($logic->getInstallState() === InstallLogic::INSTALLED && Db::$sql === $before && $logic->usesCandidateWithdrawal(), 'Interrupted withdrawal converges to installed registration without SQL: ' . $phase);
        }
        echo "Uploaded upgrade withdrawal: $checks checks passed; official CLI/API, recording database only\n";
    } finally {
        if (!str_starts_with($root, sys_get_temp_dir() . '/sandpackage-upload-withdraw-test-') || is_link($root)) throw new \RuntimeException('Unsafe fixture cleanup');
        hostInstallDelete($root);
    }
}
