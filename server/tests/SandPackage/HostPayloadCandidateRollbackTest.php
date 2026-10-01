<?php

declare(strict_types=1);

require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadCandidateRollback.php';

use plugin\sandpackage\app\service\FreshInstallRecovery;
use plugin\sandpackage\app\service\HostPayloadCandidateRollback;

function rollbackWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $contents);
}

function rollbackDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) rollbackDelete($entry->getPathname());
    rmdir($path);
}

function rollbackDigest(string $path, array $exclude = []): string
{
    return hash('sha256', json_encode(FreshInstallRecovery::tree($path, $exclude), JSON_THROW_ON_ERROR));
}

$root = sys_get_temp_dir() . '/sandpackage-candidate-rollback-' . bin2hex(random_bytes(8));
$storage = $root . '/storage';
$app = 'neutral-host';
$backupId = $app . '-' . bin2hex(random_bytes(8));
$backup = $storage . '/backups/' . $backupId;
$candidate = $storage . '/' . $app;
rollbackWrite($backup . '/info.ini', "app=\"neutral-host\"\nversion=\"1.0.0\"\nstate=1\n");
rollbackWrite($backup . '/plugin/neutral-host/start.php', "<?php // old\n");
rollbackWrite($candidate . '/info.ini', "app=\"neutral-host\"\nversion=\"2.0.0\"\nstate=8\n");
rollbackWrite($candidate . '/plugin/neutral-host/start.php', "<?php // new\n");
$oldHash = rollbackDigest($backup);
$newHash = rollbackDigest($candidate, ['info.ini']);
try {
    $rollback = new HostPayloadCandidateRollback($storage, $app, $backupId, $oldHash, $newHash);
    try {
        $rollback->restore(static function (string $phase): void {
            if ($phase === 'quarantined') throw new RuntimeException('injected interruption');
        });
        throw new RuntimeException('Expected interruption after candidate quarantine');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'injected interruption') throw $error;
    }
    if (is_dir($candidate)) throw new RuntimeException('Interrupted rollback did not quarantine failed candidate');
    $pending = HostPayloadCandidateRollback::pendingState($storage, $app);
    if ($pending === null || $pending['phase'] !== 'quarantined'
        || !is_dir($pending['source'])) {
        throw new RuntimeException('Interrupted candidate recovery could not locate the failed package');
    }
    rollbackWrite($candidate . '/foreign.php', "<?php // foreign\n");
    try {
        HostPayloadCandidateRollback::pendingState($storage, $app);
        throw new RuntimeException('Occupied recovery target was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Occupied recovery target was accepted') throw $error;
    }
    rollbackDelete($candidate);
    $result = $rollback->restore();
    if ($result['phase'] !== 'restored' || !$result['resumed']
        || rollbackDigest($candidate) !== $oldHash
        || !is_dir($result['failed_candidate'])
        || file_get_contents($result['failed_candidate'] . '/plugin/neutral-host/start.php') !== "<?php // new\n") {
        throw new RuntimeException('Interrupted candidate rollback did not converge');
    }
    if (HostPayloadCandidateRollback::pendingState($storage, $app)['phase'] !== 'restored') {
        throw new RuntimeException('Restored candidate was not inspectable before audit archive');
    }
    $rollback->archiveFinished();
    if (is_file($storage . '/host-payload/' . $app . '.rollback.json')
        || count(glob($storage . '/host-payload/' . $app . '.rollback.json.*.history') ?: []) !== 1) {
        throw new RuntimeException('Completed candidate rollback audit was not archived');
    }

    $second = $root . '/second';
    $secondBackup = $app . '-' . bin2hex(random_bytes(8));
    rollbackWrite($second . '/backups/' . $secondBackup . '/info.ini', "app=\"neutral-host\"\nversion=\"1.0.0\"\nstate=1\n");
    rollbackWrite($second . '/backups/' . $secondBackup . '/old.php', "<?php // old\n");
    rollbackWrite($second . '/' . $app . '/info.ini', "app=\"neutral-host\"\nversion=\"2.0.0\"\nstate=8\n");
    rollbackWrite($second . '/' . $app . '/new.php', "<?php // new\n");
    $secondRollback = new HostPayloadCandidateRollback(
        $second, $app, $secondBackup,
        rollbackDigest($second . '/backups/' . $secondBackup),
        rollbackDigest($second . '/' . $app, ['info.ini']),
    );
    try {
        $secondRollback->restore(static function (string $phase): void {
            if ($phase === 'quarantined') throw new RuntimeException('injected interruption');
        });
        throw new RuntimeException('Expected second interruption');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'injected interruption') throw $error;
    }
    $failed = glob($second . '/host-payload/' . $app . '.rollback-*.failed') ?: [];
    if (count($failed) !== 1) throw new RuntimeException('Failed candidate quarantine not found');
    rollbackWrite($failed[0] . '/new.php', "<?php // tampered\n");
    try {
        $secondRollback->restore();
        throw new RuntimeException('Tampered failed candidate was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Tampered failed candidate was accepted') throw $error;
    }
    echo "Host payload candidate rollback and interruption checks passed\n";
} finally {
    rollbackDelete($root);
}
