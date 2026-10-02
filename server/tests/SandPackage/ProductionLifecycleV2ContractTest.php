<?php
// Legacy recovery regression only; normal lifecycle is covered by UpstreamPostgresLifecycleTest.php.

declare(strict_types=1);

/**
 * Neutral, in-memory-PDO exercise of the public InstallLogic entry points.
 * It uses a temporary registry/runtime tree; it never connects to PostgreSQL.
 */

namespace plugin\sandadmin\exception { if (!class_exists(ApiException::class)) { class ApiException extends \RuntimeException {} } }
namespace plugin\sandadmin\app\cache { if (!class_exists(UserMenuCache::class)) { final class UserMenuCache { public static function clearMenuCache(): void {} } } }
namespace support { if (!class_exists(Log::class)) { final class Log { public static function error(string $message, array $context = []): void {} public static function info(string $message, array $context = []): void {} } } }
namespace Saithink\Saipackage\service {
    final class Server {
        public static function getIni(string $directory): array { $file = rtrim($directory, DIRECTORY_SEPARATOR) . '/info.ini'; return is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_TYPED) ?: []) : []; }
        public static function setIni(string $directory, array $info): bool {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) return false;
            $lines = [];
            foreach ($info as $key => $value) {
                if (is_bool($value)) $value = $value ? 1 : 0;
                if (!is_int($value) && !is_string($value)) return false;
                $lines[] = $key . ' = ' . (is_int($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            return file_put_contents(rtrim($directory, DIRECTORY_SEPARATOR) . '/info.ini', implode("\n", $lines) . "\n") !== false;
        }
        public static function getConfig(string $directory, string $name): array { return []; }
        public static function getDepend(string $directory): array { return []; }
        public static function restart(): bool { return true; }
    }
    final class Filesystem {
        public static function dirIsEmpty(string $directory): bool { return !is_dir($directory) || !(new \FilesystemIterator($directory))->valid(); }
        public static function zipDir(array $files, string $target): bool { return file_put_contents($target, 'fixture-backup') !== false; }
        public static function zip(array $files, string $target): bool { return self::zipDir($files, $target); }
        public static function unzip(string $file, string $directory = ''): string {
            $zip = new \ZipArchive();
            if ($zip->open($file) !== true) throw new \RuntimeException('fixture zip open failed');
            $directory = $directory !== '' ? $directory : substr($file, 0, -4);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('fixture unzip directory failed');
            if (!$zip->extractTo($directory)) throw new \RuntimeException('fixture unzip failed');
            $zip->close();
            return $directory;
        }
        public static function delDir(string $directory): bool { if (is_file($directory) || is_link($directory)) return unlink($directory); if (!is_dir($directory)) return true; foreach (new \FilesystemIterator($directory) as $item) { self::delDir($item->getPathname()); } return rmdir($directory); }
    }
    final class Version { public static function compare(string $minimum, string $actual): bool { return version_compare($actual, $minimum, '>='); } }
    final class Depends {}
}
namespace think\facade {
    final class Db {
        public static object $pdo;
        public static int $explicitConnectCalls = 0;
        public static function connect(string $name): object {
            return new class {
                public function connect(): object { Db::$explicitConnectCalls++; return Db::$pdo; }
                public function getPdo(): false { return false; }
                public function close(): void {}
            };
        }
    }
}
namespace {
    use plugin\sandpackage\app\logic\LegacyInstallLogic as InstallLogic;
    use think\facade\Db;

    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PluginStorage.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeIdentityBinding.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeRecoveryVerifier.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradePackageIdentity.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeRecoveryInspector.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeRecoveryAudit.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeRecoveryFileTransaction.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeRecoveryCoordinator.php';
    require_once dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/LegacyInstallLogic.php';

    function runtime_path(): string { return $GLOBALS['sandpackage_fixture_root'] . '/runtime'; }
    function base_path(): string { return $GLOBALS['sandpackage_fixture_root'] . '/application'; }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key): mixed { return $key === 'plugin.sandadmin.app.version' ? '6.1.0' : null; }
    function productionLifecycleExpect(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); echo "[PASS] {$message}\n"; }
    function productionLifecyclePhase(bool $value, string $message): void { $GLOBALS['production_lifecycle_total']++; if (!$value) throw new \RuntimeException($message); $GLOBALS['production_lifecycle_passed']++; echo "[PASS] {$message}\n"; }
    function productionLifecycleDelete(string $path): void { if (!file_exists($path) && !is_link($path)) return; if (is_file($path) || is_link($path)) { unlink($path); return; } foreach (new \FilesystemIterator($path) as $item) productionLifecycleDelete($item->getPathname()); rmdir($path); }
    function productionLifecycleWrite(string $path, string $contents): void { if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true); if (file_put_contents($path, $contents) === false) throw new \RuntimeException('fixture write failed'); }
    /** @return array<string,mixed> */
    function productionLifecycleStaticInfo(string $app, string $version): array { return ['app' => $app, 'title' => 'Neutral fixture', 'about' => 'Production lifecycle contract', 'author' => 'Fixture', 'version' => $version, 'support' => '6.x']; }
    function productionLifecycleNormalizedInfo(string $directory): string {
        $info = \Saithink\Saipackage\service\Server::getIni($directory);
        foreach (['state', 'stage', 'stage_label', 'last_error', 'last_error_code', 'diagnostic_id', 'failed_stage', 'update', 'package_backup_id', 'registration_manifest', 'runtime_manifest', 'upgrade_from_version', 'deployment_backup_id', 'registration_candidate', 'service_catalog_registered', 'dependency_recovery_state', 'composer_dependent_wait_install', 'npm_dependent_wait_install', 'candidate_archive_sha256', 'candidate_payload_manifest_sha256', 'recovery_descriptor_sha256', 'update_sql_sha256', 'failed_upgrade_replacement_id', 'replacement_candidate_state'] as $field) unset($info[$field]);
        ksort($info, SORT_STRING);
        return json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    function productionLifecycleBuildV2Package(string $directory, string $app, string $version): void {
        productionLifecycleWrite($directory . '/install.sql', "SELECT 'INSTALL_FIXTURE';");
        productionLifecycleWrite($directory . '/update.sql', 'UPDATE_FIXTURE;');
        productionLifecycleWrite($directory . '/uninstall.sql', "SELECT 'UNINSTALL_FIXTURE';");
        \Saithink\Saipackage\service\Server::setIni($directory, productionLifecycleStaticInfo($app, $version));
        \Saithink\Saipackage\service\Server::setIni($directory . '/plugin/' . $app, ['app' => $app, 'version' => $version]);
        productionLifecycleWrite($directory . '/plugin/' . $app . '/config/app.php', "<?php return ['version' => '" . $version . "'];\n");
        productionLifecycleWrite($directory . '/plugin/' . $app . '/app/functions.php', "<?php\n");
        productionLifecycleWrite($directory . '/plugin/' . $app . '/app/payload.php', "<?php // neutral " . $version . "\n");
        productionLifecycleWrite($directory . '/sandadmin-artd/src/views/plugin/' . $app . '/index.vue', '<template>neutral ' . $version . '</template>');
        if ($version === '1.0.0') return;
        $identity = new \plugin\sandpackage\app\logic\FailedUpgradePackageIdentity();
        $payloadDigest = $identity->descriptorPayloadDigest(
            $directory,
            \plugin\sandpackage\app\logic\FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1,
            $app,
        );
        $updateDigest = hash_file('sha256', $directory . '/update.sql');
        $profile = [
            'schema' => 'sandpackage.failed-upgrade-recovery-profile/v2', 'id' => 'neutral_partial',
            'app' => $app, 'from_version' => '1.0.0', 'to_version' => $version, 'state' => 'partial',
            'assertions' => [['type' => 'relation_absent', 'name' => 'sample_plugin_future']],
        ];
        $descriptor = [
            'schema' => 'sandpackage.failed-upgrade-recovery/v2', 'app' => $app,
            'from_version' => '1.0.0', 'to_version' => $version,
            'candidate_payload' => ['algorithm' => 'sandpackage-normalized-package-manifest/v1', 'digest' => $payloadDigest],
            'profile' => $profile, 'update_lifecycle' => ['path' => 'update.sql', 'sha256' => $updateDigest],
        ];
        $descriptorRaw = \plugin\sandpackage\app\logic\FailedUpgradeRecoveryVerifier::canonicalJson($descriptor);
        productionLifecycleWrite($directory . '/recovery/failed-upgrade.v2.json', $descriptorRaw);
        productionLifecycleWrite($directory . '/plugin/' . $app . '/recovery/failed-upgrade.v2.json', $descriptorRaw);
    }
    function productionLifecycleZip(string $directory, string $target): void {
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('fixture zip creation failed');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            if (!$item->isFile() || !$zip->addFile($item->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName()))) throw new \RuntimeException('fixture zip add failed');
        }
        $zip->close();
    }

    $root = '/private/tmp/sandpackage-production-lifecycle-' . bin2hex(random_bytes(8));
    $GLOBALS['sandpackage_fixture_root'] = $root;
    mkdir(runtime_path(), 0700, true);
    Db::$pdo = new class {
        public array $statements = [];
        public bool $failUpdate = false;
        public function exec(string $sql): int|false { $this->statements[] = $sql; return $this->failUpdate && strpos($sql, 'UPDATE_FIXTURE') !== false ? false : 1; }
        public function prepare(string $sql): object {
            return new class($sql) {
                /** @var array<string,mixed> */ private array $values = [];
                public function __construct(private string $sql) {}
                public function bindValue(string $name, mixed $value, int $type): bool { $this->values[$name] = $value; return true; }
                public function execute(): bool { return true; }
                public function fetchAll(): array {
                    if (strpos($this->sql, 'to_regclass') !== false) return [['present' => false]];
                    return [];
                }
            };
        }
    };
    try {
        $app = 'sample-plugin';
        $package = runtime_path() . '/sandpackage/' . $app;
        foreach (['install.sql' => 'CREATE TABLE sample_plugin_item (id integer);', 'update.sql' => 'UPDATE_FIXTURE;', 'uninstall.sql' => 'DROP TABLE sample_plugin_item;'] as $file => $sql) productionLifecycleWrite($package . '/' . $file, $sql);
        productionLifecycleWrite($package . '/plugin/' . $app . '/app.php', '<?php return [];');
        productionLifecycleWrite($package . '/sandadmin-artd/src/views/plugin/' . $app . '/index.vue', '<template/>');
        \Saithink\Saipackage\service\Server::setIni($package, ['app' => $app, 'title' => 'Sample', 'about' => 'Neutral fixture', 'author' => 'Fixture', 'version' => '1.0.0', 'state' => InstallLogic::WAIT_INSTALL, 'stage' => 'ready', 'update' => 0]);

        $logic = new InstallLogic($app);
        $installed = $logic->install(false);
        productionLifecycleExpect(($installed['state'] ?? null) === InstallLogic::INSTALLED, 'public InstallLogic install transitions a zero-plugin neutral fixture to installed');
        productionLifecycleExpect(in_array('CREATE TABLE sample_plugin_item (id integer)', Db::$pdo->statements, true), 'public InstallLogic install uses the PostgreSQL lifecycle executor through fake PDO');
        $logic->uninstall();
        productionLifecycleExpect(!is_dir($package), 'public InstallLogic uninstall removes the neutral fixture registry after lifecycle success');

        // A candidate without the complete v2 retained identity must fail before
        // its update script can execute; this is a real InstallLogic boundary.
        foreach (['install.sql' => 'CREATE TABLE sample_plugin_item (id integer);', 'update.sql' => 'UPDATE_FIXTURE;', 'uninstall.sql' => 'DROP TABLE sample_plugin_item;'] as $file => $sql) productionLifecycleWrite($package . '/' . $file, $sql);
        productionLifecycleWrite($package . '/plugin/' . $app . '/app.php', '<?php return [];');
        productionLifecycleWrite($package . '/sandadmin-artd/src/views/plugin/' . $app . '/index.vue', '<template/>');
        \Saithink\Saipackage\service\Server::setIni($package, ['app' => $app, 'title' => 'Sample', 'about' => 'Neutral fixture', 'author' => 'Fixture', 'version' => '1.1.0', 'state' => InstallLogic::WAIT_INSTALL, 'stage' => 'ready', 'update' => 1, 'upgrade_from_version' => '1.0.0']);
        $before = Db::$pdo->statements;
        try { $logic->install(false, 'UPGRADE sample-plugin@1.0.0->1.1.0'); throw new \RuntimeException('incomplete v2 upgrade candidate was accepted'); }
        catch (\plugin\sandadmin\exception\ApiException) { productionLifecycleExpect(Db::$pdo->statements === $before, 'public InstallLogic blocks an incomplete v2 upgrade candidate before database_update'); }

        // Full production entry path: only fixture construction uses reflection;
        // every state transition from database_update failure onward uses the
        // real public InstallLogic API.
        $GLOBALS['production_lifecycle_passed'] = 0;
        $GLOBALS['production_lifecycle_total'] = 0;
        productionLifecycleDelete($package);
        productionLifecycleDelete(base_path() . '/plugin/' . $app);
        productionLifecycleDelete(dirname(base_path()) . '/sandadmin-artd/src/views/plugin/' . $app);
        productionLifecycleBuildV2Package($package, $app, '1.0.0');
        \Saithink\Saipackage\service\Server::setIni($package, [...productionLifecycleStaticInfo($app, '1.0.0'), 'state' => InstallLogic::WAIT_INSTALL, 'stage' => 'ready']);
        Db::$pdo->statements = [];
        $logic = new InstallLogic($app);
        $stable = $logic->install(false);
        productionLifecyclePhase(($stable['state'] ?? null) === InstallLogic::INSTALLED, 'baseline install establishes the neutral 1.0 runtime');

        $reflection = new \ReflectionClass($logic);
        $deploymentDigest = $reflection->getMethod('verifyDeploymentMatchesPackage');
        $deploymentDigest->setAccessible(true);
        $stableInfo = $logic->getInfo();
        productionLifecyclePhase(
            is_string($stableInfo['registration_manifest'] ?? null)
            && hash_equals($deploymentDigest->invoke($logic), $stableInfo['registration_manifest']),
            'baseline install persists the actual deployed manifest without a manual registry rewrite'
        );
        $backupMethod = $reflection->getMethod('backupPackage');
        $backupMethod->setAccessible(true);
        $backupId = $backupMethod->invoke($logic);
        $candidateJournal = $reflection->getMethod('candidateTransactionPath');
        $candidateJournal->setAccessible(true);
        $journalPath = $candidateJournal->invoke($logic);
        if (is_file($journalPath)) unlink($journalPath);
        $backupManifest = json_decode((string) file_get_contents(runtime_path() . '/sandpackage/backups/' . $backupId . '/registration_manifest.json'), true, 64, JSON_THROW_ON_ERROR);

        productionLifecycleBuildV2Package($package, $app, '1.1.0');
        $originalArchive = $root . '/original-candidate.zip';
        productionLifecycleZip($package, $originalArchive);
        chmod($originalArchive, 0400);
        $capture = $reflection->getMethod('captureFailedUpgradeCandidateIdentity');
        $capture->setAccessible(true);
        $markers = $capture->invoke($logic, $package, $originalArchive, hash_file('sha256', $originalArchive));
        \Saithink\Saipackage\service\Server::setIni($package, [
            ...productionLifecycleStaticInfo($app, '1.1.0'),
            'state' => InstallLogic::WAIT_INSTALL, 'stage' => 'ready', 'update' => 1,
            'package_backup_id' => $backupId,
            'registration_manifest' => $backupManifest['registration_manifest'],
            'runtime_manifest' => $backupManifest['runtime_manifest_hash'],
            'upgrade_from_version' => '1.0.0',
            ...$markers,
        ]);
        Db::$pdo->failUpdate = true;
        try { $logic->install(false, 'UPGRADE sample-plugin@1.0.0->1.1.0'); throw new \RuntimeException('fixture database_update unexpectedly succeeded'); }
        catch (\plugin\sandadmin\exception\ApiException) {}
        Db::$pdo->failUpdate = false;
        $failed = $logic->getInfo();
        productionLifecyclePhase(($failed['state'] ?? null) === InstallLogic::FAILED && ($failed['failed_stage'] ?? null) === 'database_update', 'public upgrade retains the exact failed database_update shape');
        $listPresentation = InstallLogic::presentInfo($failed);
        productionLifecyclePhase(($listPresentation['allowed_actions'] ?? null) === ['prepare_failed_upgrade_replacement'], 'public list presentation uses the canonical v2 prepare action');

        $partialIdentity = $failed;
        unset($partialIdentity['update_sql_sha256']);
        \Saithink\Saipackage\service\Server::setIni($package, $partialIdentity);
        try { $logic->inspectFailedUpgradeRecovery(1); throw new \RuntimeException('partial v2 candidate identity was accepted'); }
        catch (\plugin\sandadmin\exception\ApiException) { productionLifecyclePhase(true, 'partially registered v2 candidate identity fails closed'); }

        // Simulate a real pre-6.1 failure: backup lineage exists, but the four
        // recovery-v2 candidate digests were never registered and the active
        // failed candidate no longer matches the replacement archive.
        foreach (['candidate_archive_sha256', 'candidate_payload_manifest_sha256', 'recovery_descriptor_sha256', 'update_sql_sha256'] as $field) unset($failed[$field]);
        \Saithink\Saipackage\service\Server::setIni($package, $failed);
        productionLifecycleWrite($package . '/update.sql', 'LEGACY_FAILED_CANDIDATE;');
        productionLifecyclePhase((InstallLogic::presentInfo($logic->getInfo())['allowed_actions'] ?? null) === ['prepare_failed_upgrade_replacement'], 'legacy failed state with wholly absent v2 identity enters replacement bootstrap');

        productionLifecycleWrite(base_path() . '/plugin/' . $app . '/app/payload.php', '<?php // runtime drift');
        $inspection = $logic->inspectFailedUpgradeRecovery(1);
        productionLifecyclePhase(($inspection['runtime_restore_required'] ?? false) === true && ($inspection['allowed_actions'] ?? null) === ['restore_runtime_from_backup'], 'public inspect makes runtime restore the sole drift action');
        $faulting = new class($app) extends InstallLogic {
            protected function candidateFault(string $point): void { if ($point === 'runtime_restore.quarantined.1') throw new \RuntimeException('fixture runtime interruption'); }
        };
        try { $faulting->restoreRuntimeFromBackup('RESTORE RUNTIME sample-plugin@1.0.0', 1); throw new \RuntimeException('runtime restore interruption was not raised'); }
        catch (\RuntimeException $error) { if ($error->getMessage() !== 'fixture runtime interruption') throw $error; }
        productionLifecyclePhase(($logic->inspectFailedUpgradeRecovery(1)['allowed_actions'] ?? null) === ['restore_runtime_from_backup'], 'public inspect retains restore action after an interrupted file transaction');
        $restored = $logic->restoreRuntimeFromBackup('RESTORE RUNTIME sample-plugin@1.0.0', 1);
        productionLifecyclePhase(($restored['state'] ?? null) === 'verification_required' && ($restored['allowed_actions'] ?? null) === ['prepare_failed_upgrade_replacement'], 'public restore converges interruption and returns to verification_required');
        $restoredAgain = $logic->restoreRuntimeFromBackup('RESTORE RUNTIME sample-plugin@1.0.0', 1);
        productionLifecyclePhase(($restoredAgain['status'] ?? null) === 'already_restored' && ($restoredAgain['restore_id'] ?? null) === ($restored['restore_id'] ?? null), 'public runtime restore replay is idempotent');

        $replacementDirectory = $root . '/replacement-package';
        productionLifecycleBuildV2Package($replacementDirectory, $app, '1.1.0');
        $replacementArchive = $root . '/replacement.zip';
        productionLifecycleZip($replacementDirectory, $replacementArchive);
        $upload = new class($replacementArchive) { public function __construct(private string $path) {} public function getPathname(): string { return $this->path; } };
        $prepared = $logic->prepareFailedUpgradeReplacement($upload, 1);
        $replacementId = (string) ($prepared['replacement_id'] ?? '');
        productionLifecyclePhase(strlen($replacementId) === 32 && ctype_xdigit($replacementId) && strtolower($replacementId) === $replacementId, 'public prepare retains a private identity-bound replacement');
        $connectCallsBeforeGateA = Db::$explicitConnectCalls;
        $verified = $logic->verifyPreparedFailedUpgradeReplacement((string) $prepared['replacement_id'], 1);
        productionLifecyclePhase(($verified['verdict'] ?? null) === 'retry_safe' && ($verified['allowed_actions'] ?? null) === ['replace_failed_upgrade_candidate']
            && ($verified['assertions_total'] ?? null) === 1 && ($verified['assertions_passed'] ?? null) === 1
            && ($verified['failed_assertion_ids'] ?? null) === [] && ($verified['audit_written'] ?? null) === false
            && is_string($verified['evidence_fingerprint'] ?? null) && strlen($verified['evidence_fingerprint']) === 64,
            'formal Gate A binds the prepared replacement to the real backup in a read-only transaction without audit writes');
        productionLifecyclePhase(Db::$explicitConnectCalls === $connectCallsBeforeGateA + 1, 'formal Gate A explicitly initializes the named PostgreSQL connection before using its PDO');
        $replaced = $logic->replaceFailedUpgradeCandidate((string) $prepared['replacement_id'], 'REPLACE sample-plugin@1.1.0', 1);
        productionLifecyclePhase(($replaced['state'] ?? null) === 'ready', 'public replace activates only the verified candidate');
        $activeGate = $logic->verifyFailedUpgradeRecovery(1);
        productionLifecyclePhase(($activeGate['audit_written'] ?? null) === false && ($activeGate['assertions_total'] ?? null) === 1
            && ($activeGate['assertions_passed'] ?? null) === 1 && ($activeGate['failed_assertion_ids'] ?? null) === [],
            'active-candidate Gate A remains explicitly read-only after v2 identity registration');
        $completed = $logic->retryFailedUpgrade('RETRY sample-plugin@1.0.0->1.1.0', 1);
        productionLifecyclePhase(($completed['state'] ?? null) === 'installed' && $logic->getInstallState() === InstallLogic::INSTALLED, 'public retry completes update and reaches installed');
        productionLifecyclePhase(file_get_contents(base_path() . '/plugin/' . $app . '/app/payload.php') === "<?php // neutral 1.1.0\n", 'retry deploys the verified 1.1 runtime payload');
        try { $logic->retryFailedUpgrade('RETRY sample-plugin@1.0.0->1.1.0', 1); throw new \RuntimeException('installed retry replay was accepted'); }
        catch (\plugin\sandadmin\exception\ApiException) { productionLifecyclePhase($logic->getInstallState() === InstallLogic::INSTALLED, 'retry replay fails closed without changing installed state'); }

        echo 'Production failed-upgrade lifecycle subphases: ' . $GLOBALS['production_lifecycle_passed'] . '/' . $GLOBALS['production_lifecycle_total'] . " passed\n";
        echo "Production InstallLogic lifecycle behavior: 4/4 passed\n";
    } finally { productionLifecycleDelete($root); }
}
