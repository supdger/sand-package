<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use InvalidArgumentException;
use support\Log;

/** Creates allowlisted recovery audit events and rejects accidental secret/path fields. */
final class FailedUpgradeRecoveryAudit
{
    /** @var list<string> */
    private const FIELDS = [
        'action', 'app', 'from_version', 'to_version', 'actor_type', 'actor_id',
        'actor_name', 'failed_stage', 'profile_hash', 'verdict', 'evidence_fingerprint',
        'candidate_archive_sha256', 'candidate_payload_manifest_sha256', 'descriptor_sha256',
        'update_sql_sha256', 'backup_id', 'runtime_manifest_sha256', 'drift_target_count',
        'confirmation_result', 'replacement_id', 'old_quarantine_id',
        'old_quarantine_manifest_sha256', 'new_candidate_digest',
    ];

    /** @return array{actor_type:string,actor_id:string,actor_name:string} */
    public static function webActor(int $adminId): array
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('恢复审计 Web 身份非法');
        }
        return ['actor_type' => 'web_admin', 'actor_id' => (string) $adminId, 'actor_name' => ''];
    }

    /** @return array{actor_type:string,actor_id:string,actor_name:string} */
    public static function cliActor(): array
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $account = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        return [
            'actor_type' => 'os_account',
            'actor_id' => (string) $uid,
            'actor_name' => is_array($account) && is_string($account['name'] ?? null) ? $account['name'] : '',
        ];
    }

    /** @param array<string,mixed> $actor @return array{actor_type:string,actor_id:string,actor_name:string} */
    public static function normalizeActor(array $actor): array
    {
        $keys = array_keys($actor);
        sort($keys, SORT_STRING);
        if ($keys !== ['actor_id', 'actor_name', 'actor_type']
            || !in_array($actor['actor_type'], ['web_admin', 'os_account'], true)
            || !is_string($actor['actor_id']) || preg_match('/^[0-9]{1,20}$/D', $actor['actor_id']) !== 1
            || !is_string($actor['actor_name']) || strlen($actor['actor_name']) > 128) {
            throw new InvalidArgumentException('恢复审计操作者身份非法');
        }
        return [
            'actor_type' => $actor['actor_type'],
            'actor_id' => $actor['actor_id'],
            'actor_name' => $actor['actor_name'],
        ];
    }

    /** @param array<string,mixed> $event */
    public function write(array $event): void
    {
        $unknown = array_diff(array_keys($event), self::FIELDS);
        if ($unknown !== [] || !is_string($event['action'] ?? null) || !is_string($event['app'] ?? null)) {
            throw new InvalidArgumentException('恢复审计字段不符合白名单');
        }
        $filtered = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $event)) {
                $filtered[$field] = $event[$field];
            }
        }
        Log::info('SandPackage failed upgrade recovery', $filtered);
    }
}
