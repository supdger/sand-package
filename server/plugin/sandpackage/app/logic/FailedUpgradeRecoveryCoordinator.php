<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

use plugin\sandadmin\exception\ApiException;

/** Owns the action ordering of the v2 recovery contract. */
final class FailedUpgradeRecoveryCoordinator
{
    public function __construct(
        private readonly string $app,
        private readonly FailedUpgradeRecoveryInspector $inspector,
        private readonly FailedUpgradeRecoveryFileTransaction $files,
        private readonly FailedUpgradeRecoveryAudit $audit,
    ) {
    }

    /** @param array<string,mixed> $info @param callable():array<string,mixed> $diagnose */
    public function inspect(array $info, callable $diagnose): array
    {
        $runtime = $diagnose();
        $runtime['pending_transaction'] = $this->files->hasPending($this->app);
        return $this->inspector->inspect($this->app, $info, $runtime);
    }

    /**
     * @param array<string,mixed> $info
     * @param array{actor_type:string,actor_id:string,actor_name:string} $actor
     * @param callable():array<string,mixed> $diagnose
     * @param callable(array<string,mixed>):array{sources:list<string>,targets:list<string>,manifests:list<array<string,string>>} $restorePlan
     * @param callable(string):void|null $fault
     * @return array<string,mixed>
     */
    public function restore(array $info, string $confirmation, array $actor, callable $diagnose, callable $restorePlan, ?callable $fault = null): array
    {
        $actor = FailedUpgradeRecoveryAudit::normalizeActor($actor);
        $inspection = $this->inspect($info, $diagnose);
        $expected = 'RESTORE RUNTIME ' . $this->app . '@' . (string) $inspection['from_version'];
        if (!hash_equals($expected, $confirmation)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复确认内容不匹配', 400);
        }
        $runtime = $diagnose();
        if (($inspection['allowed_actions'] ?? null) !== ['restore_runtime_from_backup']) {
            if (($runtime['required'] ?? true) !== false) {
                throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复状态不一致', 400);
            }
            return $this->restoredResponse($info, $runtime, null, 'already_restored');
        }
        $plan = $restorePlan($runtime);
        $transaction = $this->files->restore(
            $this->app,
            (string) $runtime['backup_id'],
            (string) $runtime['backup']['runtime_manifest_hash'],
            $plan['sources'],
            $plan['targets'],
            $plan['manifests'],
            $fault,
        );
        $after = $diagnose();
        if (($after['required'] ?? true) !== false || $this->files->hasPending($this->app)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：运行文件恢复未收敛', 400);
        }
        $this->audit->write([
            'action' => 'restore_runtime_from_backup',
            'app' => $this->app,
            'from_version' => (string) $info['upgrade_from_version'],
            'to_version' => (string) $info['version'],
            ...$actor,
            'failed_stage' => 'database_update',
            'backup_id' => (string) $runtime['backup_id'],
            'runtime_manifest_sha256' => (string) $runtime['backup']['runtime_manifest_hash'],
            'drift_target_count' => count($runtime['diff']),
            'confirmation_result' => 'confirmed',
        ]);
        return $this->restoredResponse($info, $runtime, $transaction['id'], 'restored');
    }

    /** @param array<string,mixed> $info @param array<string,mixed> $runtime @return array<string,mixed> */
    private function restoredResponse(array $info, array $runtime, ?string $transactionId, string $status): array
    {
        $restoreId = substr(hash('sha256', json_encode([
            'app' => $this->app,
            'backup_id' => $runtime['backup_id'],
            'runtime_manifest_hash' => $runtime['backup']['runtime_manifest_hash'],
        ], JSON_THROW_ON_ERROR)), 0, 32);
        $response = [
            'app' => $this->app,
            'from_version' => (string) $info['upgrade_from_version'],
            'to_version' => (string) $info['version'],
            'state' => 'verification_required',
            'status' => $status,
            'restore_id' => $restoreId,
            'runtime_manifest_hash' => (string) $runtime['backup']['runtime_manifest_hash'],
            'recovery_mode' => 'verification_required',
            'runtime_restore_required' => false,
            'allowed_actions' => ['prepare_failed_upgrade_replacement'],
            'message' => '已按已验证备份恢复运行文件；候选包、数据库和登记失败形状未修改，请重新开始替换候选核验。',
        ];
        if ($transactionId !== null) $response['transaction_id'] = $transactionId;
        return $response;
    }

    /** @param array<string,mixed> $inspection */
    public function assertAction(array $inspection, string $action): void
    {
        if (($inspection['allowed_actions'] ?? null) !== [$action]) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：恢复步骤顺序不合法', 400);
        }
    }

    /** @param array<string,mixed> $info @param callable():array<string,mixed> $diagnose */
    public function prepare(array $info, callable $diagnose): void
    {
        $this->assertRuntimeReady($info, $diagnose);
    }

    /** @param array<string,mixed> $info @param callable():array<string,mixed> $diagnose */
    public function verify(array $info, string $replacementId, callable $diagnose): void
    {
        $this->assertRuntimeReady($info, $diagnose);
        if (preg_match('/^[a-f0-9]{32}$/D', $replacementId) !== 1) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换候选标识非法', 400);
        }
    }

    /** @param array<string,mixed> $info @param callable():array<string,mixed> $diagnose */
    public function replace(array $info, string $replacementId, string $confirmation, callable $diagnose): void
    {
        $this->verify($info, $replacementId, $diagnose);
        if (!hash_equals('REPLACE ' . $this->app . '@' . (string) $info['version'], $confirmation)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：替换确认内容不匹配', 400);
        }
    }

    /** @param array<string,mixed> $info @param callable():array<string,mixed> $diagnose */
    public function retry(array $info, string $confirmation, callable $diagnose): void
    {
        $this->assertRuntimeReady($info, $diagnose);
        $expected = 'RETRY ' . $this->app . '@' . (string) $info['upgrade_from_version'] . '->' . (string) $info['version'];
        if (!hash_equals($expected, $confirmation)) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：重试确认内容不匹配', 400);
        }
    }

    /** @param array<string,mixed> $info @param callable():array<string,mixed> $diagnose */
    private function assertRuntimeReady(array $info, callable $diagnose): void
    {
        $inspection = $this->inspect($info, $diagnose);
        if (($inspection['runtime_restore_required'] ?? true) !== false) {
            throw new ApiException('FAILED_UPGRADE_RECOVERY_BLOCKED：必须先恢复并核验升级前运行文件', 400);
        }
    }
}
