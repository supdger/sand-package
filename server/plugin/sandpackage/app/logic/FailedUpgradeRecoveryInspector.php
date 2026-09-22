<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use plugin\sandadmin\exception\ApiException;

/** Read-only state and runtime-drift decision. No lock or filesystem mutation. */
final class FailedUpgradeRecoveryInspector
{
    /**
     * @param array<string,mixed> $info
     * @param array{required:bool,backup_id:string,diff:list<array<string,mixed>>,pending_transaction?:bool} $runtime
     * @return array<string,mixed>
     */
    public function inspect(string $app, array $info, array $runtime): array
    {
        if (!$this->isRecoverable($app, $info)
            || !is_bool($runtime['required'] ?? null)
            || !is_string($runtime['backup_id'] ?? null)
            || !is_array($runtime['diff'] ?? null)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：该插件不是可检查的数据库升级失败状态', 400);
        }
        $restoreRequired = $runtime['required'] || (($runtime['pending_transaction'] ?? false) === true);
        return [
            'app' => $app,
            'from_version' => (string) $info['upgrade_from_version'],
            'to_version' => (string) $info['version'],
            'recovery_mode' => $restoreRequired ? 'runtime_restore_required' : 'verification_required',
            'runtime_restore_required' => $restoreRequired,
            // The public contract deliberately exposes a boolean only. File
            // names and target paths remain internal recovery evidence.
            'runtime_drift' => $restoreRequired,
            'allowed_actions' => [$restoreRequired ? 'restore_runtime_from_backup' : 'prepare_failed_upgrade_replacement'],
            'message' => $restoreRequired
                ? '升级前运行文件存在漂移或未完成的恢复事务；只能先按已验证备份恢复运行文件。'
                : '升级前运行文件与已验证备份一致；请预检替换候选后再核验。',
        ];
    }

    /** @param array<string,mixed> $info */
    private function isRecoverable(string $app, array $info): bool
    {
        if (($info['app'] ?? null) !== $app || !preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)
            || !in_array($info['state'] ?? null, [8, '8'], true)
            || ($info['stage'] ?? null) !== 'failed' || ($info['failed_stage'] ?? null) !== 'database_update'
            || !in_array($info['update'] ?? null, [1, '1'], true)
            || !$this->version($info['upgrade_from_version'] ?? null) || !$this->version($info['version'] ?? null)
            || version_compare((string) $info['version'], (string) $info['upgrade_from_version'], '<=')) {
            return false;
        }
        $candidateIdentityFields = ['candidate_archive_sha256', 'candidate_payload_manifest_sha256', 'recovery_descriptor_sha256', 'update_sql_sha256'];
        $presentCandidateIdentityFields = 0;
        foreach ($candidateIdentityFields as $field) {
            if (array_key_exists($field, $info)) {
                $presentCandidateIdentityFields++;
                if (!is_string($info[$field]) || preg_match('/^[a-f0-9]{64}$/D', $info[$field]) !== 1) {
                    return false;
                }
            }
        }
        // A historic database_update failure may predate recovery v2. It can
        // enter the replacement bootstrap only when none of the v2 identity
        // exists; a partially written identity is always ambiguous and blocked.
        if (!in_array($presentCandidateIdentityFields, [0, count($candidateIdentityFields)], true)) {
            return false;
        }
        foreach (['registration_manifest', 'runtime_manifest'] as $field) {
            if (!is_string($info[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $info[$field]) !== 1) {
                return false;
            }
        }
        return is_string($info['package_backup_id'] ?? null)
            && preg_match('/^' . preg_quote($app, '/') . '-package-[0-9]{14}-[a-f0-9]{12}$/D', $info['package_backup_id']) === 1;
    }

    private function version(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $value) === 1;
    }
}
