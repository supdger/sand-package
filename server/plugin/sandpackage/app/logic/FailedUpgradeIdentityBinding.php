<?php
declare(strict_types=1);

namespace plugin\sandpackage\app\logic;

/**
 * Immutable, lock-scoped package/runtime identity observed by InstallLogic.
 * All properties are required, and a readonly object prevents a caller from
 * changing a field between validation and fingerprint construction.
 */
final readonly class FailedUpgradeIdentityBinding
{
    public function __construct(
        public string $archiveSha256,
        public string $backupId,
        public string $backupPackageManifestSha256,
        public string $candidatePayloadManifestSha256,
        public string $deploymentManifestSha256,
        public string $descriptorSha256,
        public string $previousRegistrationManifestSha256,
        public string $runtimeManifestSha256,
        public string $updateSqlSha256,
    ) {}

    /** @return array<string,string> */
    public function values(): array
    {
        return [
            'archive_sha256' => $this->archiveSha256,
            'backup_id' => $this->backupId,
            'backup_package_manifest_sha256' => $this->backupPackageManifestSha256,
            'candidate_payload_manifest_sha256' => $this->candidatePayloadManifestSha256,
            'deployment_manifest_sha256' => $this->deploymentManifestSha256,
            'descriptor_sha256' => $this->descriptorSha256,
            'previous_registration_manifest_sha256' => $this->previousRegistrationManifestSha256,
            'runtime_manifest_sha256' => $this->runtimeManifestSha256,
            'update_sql_sha256' => $this->updateSqlSha256,
        ];
    }
}
