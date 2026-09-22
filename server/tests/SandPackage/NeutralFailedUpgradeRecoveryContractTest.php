<?php

declare(strict_types=1);

// This is a behavior test: it exercises public component contracts through a
// temporary neutral plugin fixture and never reads production source strings.

namespace plugin\sandadmin\exception {
    if (!class_exists(ApiException::class)) {
        class ApiException extends \RuntimeException {}
    }
}

namespace support {
    if (!class_exists(Log::class)) {
        final class Log
        {
            /** @var list<array<string,mixed>> */ public static array $events = [];
            /** @param array<string,mixed> $event */ public static function info(string $message, array $event = []): void { self::$events[] = $event; }
        }
    }
}

namespace {
    use plugin\sandpackage\app\logic\FailedUpgradePackageIdentity;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryAudit;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryCoordinator;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryFileTransaction;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryInspector;
    use plugin\sandpackage\app\logic\FailedUpgradeRecoveryVerifier;

    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradeIdentityBinding.php';
    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradeRecoveryVerifier.php';
    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradePackageIdentity.php';
    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradeRecoveryInspector.php';
    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradeRecoveryAudit.php';
    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradeRecoveryFileTransaction.php';
    require_once __DIR__ . '/../../plugin/sandpackage/app/logic/FailedUpgradeRecoveryCoordinator.php';

    $passed = 0;
    $total = 0;
    function neutralCheck(bool $condition, string $message): void
    {
        global $passed, $total;
        $total++;
        if (!$condition) throw new \RuntimeException($message);
        $passed++;
    }
    function neutralReject(callable $operation, string $message): void
    {
        $rejected = false;
        try { $operation(); } catch (\Throwable) { $rejected = true; }
        neutralCheck($rejected, $message);
    }
    function neutralWrite(string $path, string $content): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new \RuntimeException('fixture mkdir failed');
        if (file_put_contents($path, $content) === false) throw new \RuntimeException('fixture write failed');
    }
    function neutralDelete(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (new \FilesystemIterator($path) as $item) neutralDelete($item->getPathname());
        rmdir($path);
    }

    $root = sys_get_temp_dir() . '/sandpackage-neutral-recovery-' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    try {
        $managed = $root . '/managed';
        $sourceA = $root . '/backup/backend';
        $sourceB = $root . '/backup/frontend';
        $targetA = $root . '/runtime/backend';
        $targetB = $root . '/runtime/frontend';
        neutralWrite($sourceA . '/app.txt', 'stable-backend');
        neutralWrite($sourceB . '/view.txt', 'stable-frontend');
        neutralWrite($targetA . '/app.txt', 'drifted-backend');
        neutralWrite($targetB . '/extra.txt', 'unexpected');

        $files = new FailedUpgradeRecoveryFileTransaction($managed);
        $manifests = [$files->manifest($sourceA), $files->manifest($sourceB)];
        $runtimeHash = hash('sha256', json_encode($manifests, JSON_THROW_ON_ERROR));
        $digest = str_repeat('a', 64);
        $info = [
            'app' => 'neutral-fixture', 'version' => '1.1.0', 'upgrade_from_version' => '1.0.0',
            'state' => 8, 'stage' => 'failed', 'failed_stage' => 'database_update', 'update' => 1,
            'package_backup_id' => 'neutral-fixture-package-20260908120000-abcdef123456',
            'candidate_archive_sha256' => $digest, 'candidate_payload_manifest_sha256' => $digest,
            'recovery_descriptor_sha256' => $digest, 'update_sql_sha256' => $digest,
            'registration_manifest' => $digest, 'runtime_manifest' => $runtimeHash,
        ];
        $diagnose = static function () use ($files, $sourceA, $sourceB, $targetA, $targetB, $manifests, $runtimeHash, $info): array {
            $diff = [];
            foreach ([$targetA, $targetB] as $index => $target) {
                $actual = is_dir($target) && !is_link($target) ? $files->manifest($target) : [];
                if ($actual !== $manifests[$index]) $diff[] = ['target' => $index, 'changes' => ['different']];
            }
            return [
                'required' => $diff !== [], 'backup_id' => $info['package_backup_id'], 'diff' => $diff,
                'backup' => ['runtime_manifest_hash' => $runtimeHash],
            ];
        };
        $plan = static fn (): array => ['sources' => [$sourceA, $sourceB], 'targets' => [$targetA, $targetB], 'manifests' => $manifests];
        $coordinator = new FailedUpgradeRecoveryCoordinator('neutral-fixture', new FailedUpgradeRecoveryInspector(), $files, new FailedUpgradeRecoveryAudit());

        $inspection = $coordinator->inspect($info, $diagnose);
        neutralCheck($inspection['recovery_mode'] === 'runtime_restore_required' && $inspection['allowed_actions'] === ['restore_runtime_from_backup'], 'runtime drift did not gate every later action');
        neutralCheck($inspection['runtime_drift'] === true, 'inspect exposes runtime_drift as a boolean rather than runtime file paths');
        neutralReject(fn () => $coordinator->prepare($info, $diagnose), 'prepare bypassed runtime restoration');
        neutralReject(fn () => $coordinator->restore($info, 'wrong', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan), 'wrong confirmation was accepted');
        $restored = $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan);
        neutralCheck($restored['state'] === 'verification_required' && $restored['allowed_actions'] === ['prepare_failed_upgrade_replacement'], 'restore did not return to verification_required');
        neutralCheck($files->manifest($targetA) === $manifests[0] && $files->manifest($targetB) === $manifests[1], 'runtime targets do not match verified backup');
        $coordinator->prepare($info, $diagnose);
        neutralCheck(true, 'prepare gate did not accept restored runtime');
        neutralReject(fn () => $coordinator->verify($info, '../bad', $diagnose), 'verify accepted an unsafe replacement id');
        neutralReject(fn () => $coordinator->replace($info, str_repeat('b', 32), 'wrong', $diagnose), 'replace accepted wrong confirmation');
        neutralReject(fn () => $coordinator->retry($info, 'wrong', $diagnose), 'retry accepted wrong confirmation');
        $again = $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan);
        neutralCheck($again['status'] === 'already_restored' && $again['restore_id'] === $restored['restore_id'], 'repeated restore is not idempotent');

        neutralWrite($targetA . '/app.txt', 'drift-again');
        $crashed = false;
        try {
            $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan, static function (string $point): void {
                if ($point === 'runtime_restore.quarantined.1') throw new \RuntimeException('fixture interruption');
            });
        } catch (\RuntimeException $error) { $crashed = $error->getMessage() === 'fixture interruption'; }
        neutralCheck($crashed && $files->hasPending('neutral-fixture'), 'fault injection did not retain a recovery journal');
        if (function_exists('symlink')) {
            $journalLines = file($managed . '/locks/neutral-fixture-runtime-restore.transaction.json', FILE_IGNORE_NEW_LINES);
            $journal = json_decode(is_array($journalLines) ? implode("\n", $journalLines) : '', true, 32, JSON_THROW_ON_ERROR);
            $operationId = $journal['id'] ?? null;
            if (!is_string($operationId) || preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) throw new \RuntimeException('fixture operation id missing');
            $operationRoot = $managed . '/runtime-restores/neutral-fixture/' . $operationId;
            $outside = $root . '/outside-operation';
            mkdir($outside, 0700, true);
            neutralDelete($operationRoot);
            neutralCheck(symlink($outside, $operationRoot) && is_link($operationRoot), 'fixture replaced the operation root with a symlink');
            neutralReject(fn () => $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan), 'symlink-swapped operation root was blocked without writing outside the managed recovery tree');
            neutralCheck(!file_exists($outside . '/quarantine/target-1'), 'symlink-swapped operation root wrote a quarantine outside the managed recovery tree');
            unlink($operationRoot);
            mkdir($operationRoot . '/stage', 0700, true);
            mkdir($operationRoot . '/quarantine', 0700, true);
        }
        $resumed = $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan);
        neutralCheck($resumed['status'] === 'restored' && !$files->hasPending('neutral-fixture'), 'interrupted restore did not converge');

        // A prepared journal may survive after only part of its private stage
        // was copied. Safe stale files are discarded and rebuilt from backup.
        neutralWrite($targetA . '/app.txt', 'drift-for-partial-stage');
        neutralWrite($targetB . '/view.txt', 'drift-for-partial-stage');
        try {
            $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan, static function (string $point): void {
                if ($point === 'runtime_restore.journal.prepared') throw new \RuntimeException('prepared interruption');
            });
            throw new \RuntimeException('prepared interruption was not injected');
        } catch (\RuntimeException $error) { neutralCheck($error->getMessage() === 'prepared interruption', 'prepared journal interruption was retained'); }
        $journalLines = file($managed . '/locks/neutral-fixture-runtime-restore.transaction.json', FILE_IGNORE_NEW_LINES);
        $pending = json_decode(is_array($journalLines) ? implode("\n", $journalLines) : '', true, 32, JSON_THROW_ON_ERROR);
        $pendingId = $pending['id'] ?? null;
        if (!is_string($pendingId) || preg_match('/^[a-f0-9]{32}$/D', $pendingId) !== 1) throw new \RuntimeException('partial-stage operation id missing');
        $partialRoot = $managed . '/runtime-restores/neutral-fixture/' . $pendingId;
        neutralWrite($partialRoot . '/stage/target-1/app.txt', 'partial-and-drifted');
        neutralWrite($partialRoot . '/stage/target-1/extra.txt', 'must-not-survive');
        $partialResumed = $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan);
        neutralCheck($partialResumed['status'] === 'restored' && !$files->hasPending('neutral-fixture') && $files->manifest($targetA) === $manifests[0] && $files->manifest($targetB) === $manifests[1], 'partial or extra safe stage files were rebuilt from the verified backup and converged');

        if (function_exists('symlink')) {
            neutralWrite($targetA . '/app.txt', 'drift-for-linked-stage');
            try {
                $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan, static function (string $point): void {
                    if ($point === 'runtime_restore.journal.prepared') throw new \RuntimeException('linked prepared interruption');
                });
                throw new \RuntimeException('linked prepared interruption was not injected');
            } catch (\RuntimeException $error) { neutralCheck($error->getMessage() === 'linked prepared interruption', 'linked-stage journal interruption was retained'); }
            $journalLines = file($managed . '/locks/neutral-fixture-runtime-restore.transaction.json', FILE_IGNORE_NEW_LINES);
            $pending = json_decode(is_array($journalLines) ? implode("\n", $journalLines) : '', true, 32, JSON_THROW_ON_ERROR);
            $pendingId = $pending['id'] ?? null;
            if (!is_string($pendingId)) throw new \RuntimeException('linked-stage operation id missing');
            $linkedStage = $managed . '/runtime-restores/neutral-fixture/' . $pendingId . '/stage/target-1';
            $outsideStage = $root . '/outside-stage';
            mkdir($outsideStage, 0700, true);
            neutralCheck(symlink($outsideStage, $linkedStage) && is_link($linkedStage), 'fixture replaced a partial stage with a symlink');
            neutralReject(fn () => $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan), 'linked partial stage was rejected without writing outside the managed recovery tree');
            neutralCheck(!file_exists($outsideStage . '/app.txt'), 'linked partial stage wrote outside the managed recovery tree');
            unlink($linkedStage);
            $linkedResumed = $coordinator->restore($info, 'RESTORE RUNTIME neutral-fixture@1.0.0', FailedUpgradeRecoveryAudit::webActor(1), $diagnose, $plan);
            neutralCheck($linkedResumed['status'] === 'restored' && !$files->hasPending('neutral-fixture'), 'safe removal of a rejected linked partial stage permits convergence');
        }

        neutralReject(fn () => $files->restore('neutral-fixture', $info['package_backup_id'], $runtimeHash, [$sourceA, $sourceB], [$root . '/runtime/../escape', $targetB], $manifests), 'non-canonical target path was accepted');
        neutralReject(fn () => $files->restore('neutral-fixture', $info['package_backup_id'], $runtimeHash, [$sourceA, $sourceB], [$targetA, $targetB], [[], $manifests[1]]), 'wrong backup manifest was accepted');
        if (function_exists('symlink')) {
            $linked = $root . '/linked-backup';
            symlink($sourceA, $linked);
            neutralReject(fn () => $files->manifest($linked), 'symlink backup root was accepted');
        }

        $candidate = $root . '/candidate';
        neutralWrite($candidate . '/info.ini', "app=neutral-fixture\nversion=1.1.0\n");
        neutralWrite($candidate . '/update.sql', 'ALTER TABLE neutral_fixture_item ADD COLUMN note text;');
        $identity = new FailedUpgradePackageIdentity();
        $payload = $identity->payloadManifest($candidate, static fn (): string => '{"app":"neutral-fixture","version":"1.1.0"}');
        $descriptorPayloadDigest = $identity->descriptorPayloadDigest(
            $candidate,
            FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1,
            'neutral-fixture',
        );
        neutralCheck(isset($payload['info.ini'], $payload['update.sql']), 'candidate payload identity omitted lifecycle files');
        neutralCheck(strlen($descriptorPayloadDigest) === 64, 'candidate descriptor payload digest is invalid');
        neutralReject(fn () => $identity->descriptorPayloadDigest($candidate, 'unknown-payload/v9', 'neutral-fixture'), 'unknown candidate payload algorithm was accepted');
        neutralReject(fn () => $identity->descriptorPayloadDigest($candidate, FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'other-plugin'), 'descriptor app could select a foreign payload exclusion scope');
        $descriptor = [
            'schema' => 'sandpackage.failed-upgrade-recovery/v2',
            'app' => 'neutral-fixture',
            'from_version' => '1.0.0',
            'to_version' => '1.1.0',
            'candidate_payload' => ['algorithm' => FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'digest' => $descriptorPayloadDigest],
            'update_lifecycle' => ['path' => 'update.sql', 'sha256' => hash_file('sha256', $candidate . '/update.sql')],
            'profile' => [
                'schema' => 'sandpackage.failed-upgrade-recovery-profile/v2', 'id' => 'neutral_state',
                'app' => 'neutral-fixture', 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'state' => 'partial',
                'assertions' => [['type' => 'relation_absent', 'name' => 'neutral_fixture_future']],
            ],
        ];
        $descriptorRaw = FailedUpgradeRecoveryVerifier::canonicalJson($descriptor);
        neutralWrite($candidate . '/recovery/failed-upgrade.v2.json', $descriptorRaw);
        neutralWrite($candidate . '/recovery/failed-upgrade.v2.json.sha256', hash('sha256', $descriptorRaw));
        neutralWrite($candidate . '/plugin/neutral-fixture/recovery/failed-upgrade.v2.json', $descriptorRaw);
        neutralWrite($candidate . '/plugin/neutral-fixture/recovery/failed-upgrade.v2.json.sha256', hash('sha256', $descriptorRaw));
        $mirroredPayload = $identity->payloadManifest($candidate, static fn (): string => '{"app":"neutral-fixture","version":"1.1.0"}');
        $mirroredDescriptorDigest = $identity->descriptorPayloadDigest($candidate, FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'neutral-fixture');
        neutralCheck($mirroredPayload === $payload && hash_equals($descriptorPayloadDigest, $mirroredDescriptorDigest), 'root and same-app generated descriptor mirrors changed normalized payload identity');
        neutralWrite($candidate . '/plugin/other-plugin/recovery/failed-upgrade.v2.json', $descriptorRaw);
        neutralWrite($candidate . '/plugin/neutral-fixture/recovery/failed-upgrade.v2.json.bak', $descriptorRaw);
        $lookalikePayload = $identity->payloadManifest($candidate, static fn (): string => '{"app":"neutral-fixture","version":"1.1.0"}');
        $lookalikeDescriptorDigest = $identity->descriptorPayloadDigest($candidate, FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'neutral-fixture');
        neutralCheck(isset($lookalikePayload['plugin/other-plugin/recovery/failed-upgrade.v2.json'], $lookalikePayload['plugin/neutral-fixture/recovery/failed-upgrade.v2.json.bak'])
            && !hash_equals($descriptorPayloadDigest, $lookalikeDescriptorDigest),
            'foreign-app and lookalike descriptor paths remain bound into payload identity');
        $originalInfo = (string) file_get_contents($candidate . '/info.ini');
        neutralWrite($candidate . '/info.ini', $originalInfo . "title=changed\n");
        $changedInfoDigest = $identity->descriptorPayloadDigest($candidate, FailedUpgradePackageIdentity::NORMALIZED_PACKAGE_MANIFEST_V1, 'neutral-fixture');
        neutralCheck(!hash_equals($lookalikeDescriptorDigest, $changedInfoDigest), 'raw candidate info.ini bytes were omitted from public payload identity');
        neutralWrite($candidate . '/info.ini', $originalInfo);
        neutralCheck($identity->readDescriptor($candidate) === $descriptorRaw, 'candidate descriptor identity was not preserved');
        neutralWrite($candidate . '/recovery/failed-upgrade.v2.json', $descriptorRaw . "\n");
        neutralReject(fn () => $identity->readDescriptor($candidate), 'non-canonical descriptor was accepted');

        neutralReject(fn () => (new FailedUpgradeRecoveryInspector())->inspect('neutral-fixture', [...$info, 'state' => 1], ['required' => false, 'backup_id' => $info['package_backup_id'], 'diff' => []]), 'non-failed registry shape was accepted');
        neutralReject(fn () => (new FailedUpgradeRecoveryAudit())->write(['action' => 'x', 'app' => 'neutral-fixture', 'secret' => 'leak']), 'audit accepted a non-allowlisted field');
        neutralCheck(count(\support\Log::$events) === 4, 'restore audit count records each mutation while excluding idempotent replay and rejected attempts');

        echo "Neutral failed-upgrade recovery behavior: {$passed}/{$total} passed\n";
    } finally {
        neutralDelete($root);
    }
}
