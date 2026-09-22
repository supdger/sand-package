<?php

declare(strict_types=1);

use plugin\sandpackage\app\logic\FailedUpgradeIdentityBinding;
use plugin\sandpackage\app\logic\FailedUpgradeRecoveryVerifier;

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeIdentityBinding.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/FailedUpgradeRecoveryVerifier.php';

function recoveryV2Expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "[PASS] {$message}\n";
}

$digest = str_repeat('a', 64);
$profile = [
    'app' => 'sample-plugin',
    'assertions' => [['relations' => ['sample_plugin_item'], 'type' => 'relations_exact']],
    'from_version' => '1.0.0',
    'id' => 'fixture-baseline',
    'schema' => 'sandpackage.failed-upgrade-recovery-profile/v2',
    'state' => 'baseline',
    'to_version' => '1.1.0',
];
$descriptor = [
    'app' => 'sample-plugin',
    'candidate_payload' => ['algorithm' => 'sandpackage-normalized-package-manifest/v1', 'digest' => $digest],
    'from_version' => '1.0.0',
    'profile' => $profile,
    'schema' => 'sandpackage.failed-upgrade-recovery/v2',
    'to_version' => '1.1.0',
    'update_lifecycle' => ['path' => 'update.sql', 'sha256' => $digest],
];
$raw = FailedUpgradeRecoveryVerifier::canonicalJson($descriptor);
$binding = new FailedUpgradeIdentityBinding($digest, 'sample-plugin-fixture', $digest, $digest, $digest, hash('sha256', $raw), $digest, $digest, $digest);
$pdo = new class {
    public array $transactions = [];
    public function exec(string $sql): int { $this->transactions[] = $sql; return 1; }
    public function prepare(string $sql): object { return new class { public function bindValue(string $name, mixed $value, int $type): bool { return true; } public function execute(): bool { return true; } public function fetchAll(): array { return [['name' => 'sample_plugin_item']]; } }; }
};
$verifier = new FailedUpgradeRecoveryVerifier();
$result = $verifier->verify($raw, $pdo, $binding);
recoveryV2Expect($result['status'] === 'retry_safe' && preg_match('/^[a-f0-9]{64}$/D', (string) $result['profile_hash']) === 1, 'accepts neutral candidate-owned v2 profile through fixed catalog query');
recoveryV2Expect($pdo->transactions === ['BEGIN READ ONLY', 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY', 'ROLLBACK'], 'wraps catalog inspection in a read-only transaction');
foreach ([
    array_replace_recursive($descriptor, ['to_version' => '1.0.0']),
    array_replace_recursive($descriptor, ['profile' => ['from_version' => '0.9.0']]),
    array_replace_recursive($descriptor, ['profile' => ['app' => 'other-plugin']]),
] as $invalidBinding) {
    try { $verifier->parseDescriptor(FailedUpgradeRecoveryVerifier::canonicalJson($invalidBinding)); throw new RuntimeException('mismatched v2 profile binding accepted'); }
    catch (InvalidArgumentException) { echo "[PASS] rejects non-increasing or mismatched descriptor/profile tuple\n"; }
}
$wideProfile = $profile;
$wideProfile['assertions'] = array_fill(0, 107, ['relations' => ['sample_plugin_item'], 'type' => 'relations_exact']);
$wide = $descriptor; $wide['profile'] = $wideProfile;
recoveryV2Expect(is_array($verifier->parseDescriptor(FailedUpgradeRecoveryVerifier::canonicalJson($wide))), 'accepts profile assertion count required by a large plugin');
$fixedCharacter = $descriptor;
$fixedCharacter['profile']['assertions'] = [[
    'column' => 'request_fingerprint',
    'data_type' => 'character',
    'default_expression' => '',
    'identity_generation' => null,
    'is_identity' => 'NO',
    'nullable' => 'NO',
    'ordinal_position' => 2,
    'table' => 'sample_plugin_security_operation',
    'type' => 'column_exact',
]];
recoveryV2Expect(is_array($verifier->parseDescriptor(FailedUpgradeRecoveryVerifier::canonicalJson($fixedCharacter))), 'accepts PostgreSQL fixed-length character columns');
$checkConstraint = $descriptor;
$exactCheckDefinition = "CHECK (((request_fingerprint)::text ~ '^[0-9a-f]{64}$'::text))";
$checkConstraint['profile']['assertions'] = [[
    'constraint_type' => 'c',
    'definition' => $exactCheckDefinition,
    'name' => 'ck_sample_plugin_security_operation_fingerprint',
    'table' => 'sample_plugin_security_operation',
    'type' => 'constraint_exact',
    'validated' => true,
]];
$checkRaw = FailedUpgradeRecoveryVerifier::canonicalJson($checkConstraint);
$checkBinding = new FailedUpgradeIdentityBinding($digest, 'sample-plugin-fixture', $digest, $digest, $digest, hash('sha256', $checkRaw), $digest, $digest, $digest);
$checkPdo = new class {
    public function exec(string $sql): int { return 1; }
    public function prepare(string $sql): object {
        return new class {
            public function bindValue(string $name, mixed $value, int $type): bool { return true; }
            public function execute(): bool { return true; }
            public function fetchAll(): array { return [['type' => 'c', 'validated' => true, 'definition' => "CHECK (((request_fingerprint)::text ~ '^[0-9a-f]{64}$'::text))"]]; }
        };
    }
};
recoveryV2Expect($verifier->verify($checkRaw, $checkPdo, $checkBinding)['status'] === 'retry_safe', 'matches exact PostgreSQL CHECK constraint definitions');
foreach ([
    ["CHECK (value = 'A B')", "CHECK (value = 'ab')"],
    ['CHECK ((a + b) * c > 0)', 'CHECK (a + b * c > 0)'],
    ['CHECK ((value)::integer > 0)', 'CHECK ((value)::bigint > 0)'],
    ["CHECK ((value)::text ~ '^(ab)+$'::text)", "CHECK ((value)::text ~ '^ab+$'::text)"],
] as [$expectedDefinition, $actualDefinition]) {
    $semanticCheck = $checkConstraint;
    $semanticCheck['profile']['assertions'][0]['definition'] = $expectedDefinition;
    $semanticRaw = FailedUpgradeRecoveryVerifier::canonicalJson($semanticCheck);
    $semanticBinding = new FailedUpgradeIdentityBinding($digest, 'sample-plugin-fixture', $digest, $digest, $digest, hash('sha256', $semanticRaw), $digest, $digest, $digest);
    $semanticPdo = new class($actualDefinition) {
        public function __construct(private string $definition) {}
        public function exec(string $sql): int { return 1; }
        public function prepare(string $sql): object {
            return new class($this->definition) {
                public function __construct(private string $definition) {}
                public function bindValue(string $name, mixed $value, int $type): bool { return true; }
                public function execute(): bool { return true; }
                public function fetchAll(): array { return [['type' => 'c', 'validated' => true, 'definition' => $this->definition]]; }
            };
        }
    };
    recoveryV2Expect($verifier->verify($semanticRaw, $semanticPdo, $semanticBinding)['status'] === 'FAILED_UPGRADE_RECOVERY_BLOCKED', 'rejects semantically distinct PostgreSQL CHECK definitions');
}
$prefixedIndex = $descriptor;
$prefixedIndex['profile']['assertions'] = [[
    'definition' => 'CREATE INDEX idx_sample_plugin_security_operation ON sample_plugin_security_operation USING btree (request_fingerprint)',
    'name' => 'idx_sample_plugin_security_operation',
    'table' => 'sample_plugin_security_operation',
    'type' => 'index_exact',
    'unique' => false,
]];
recoveryV2Expect(is_array($verifier->parseDescriptor(FailedUpgradeRecoveryVerifier::canonicalJson($prefixedIndex))), 'accepts bounded conventional PostgreSQL constraint and index name prefixes');
$menuRow = ['code' => 'sample_plugin:read', 'component' => '', 'hidden' => 1, 'icon' => '', 'name' => 'Read', 'parent_code' => 'sample_plugin:root', 'path' => '', 'slug' => 'sample_plugin:read', 'sort' => 1, 'status' => 1, 'type' => 3];
$duplicateMenu = $descriptor;
$duplicateMenu['profile']['assertions'] = [['rows' => [$menuRow, $menuRow], 'type' => 'menu_rows_exact']];
try { $verifier->parseDescriptor(FailedUpgradeRecoveryVerifier::canonicalJson($duplicateMenu)); throw new RuntimeException('duplicate menu code accepted'); }
catch (InvalidArgumentException) { echo "[PASS] rejects duplicate menu rows and enforces underscore app menu prefix\n"; }
$singleMenu = $descriptor;
$singleMenu['profile']['assertions'] = [['rows' => [$menuRow], 'type' => 'menu_rows_exact']];
$singleMenuRaw = FailedUpgradeRecoveryVerifier::canonicalJson($singleMenu);
$singleMenuBinding = new FailedUpgradeIdentityBinding($digest, 'sample-plugin-fixture', $digest, $digest, $digest, hash('sha256', $singleMenuRaw), $digest, $digest, $digest);
$catalogMenu = ['parent_code' => 'sample_plugin:root', 'name' => 'Read', 'slug' => 'sample_plugin:read', 'type' => 3, 'path' => '', 'component' => '', 'icon' => '', 'sort' => 1, 'is_hidden' => 1, 'status' => 1];
foreach ([[$catalogMenu, $catalogMenu], [$catalogMenu, array_replace($catalogMenu, ['name' => 'Different'])]] as $duplicateRows) {
    $duplicatePdo = new class($duplicateRows) {
        public function __construct(private array $rows) {}
        public function exec(string $sql): int { return 1; }
        public function prepare(string $sql): object {
            return new class($this->rows) {
                public function __construct(private array $rows) {}
                public function bindValue(string $name, mixed $value, int $type): bool { return true; }
                public function execute(): bool { return true; }
                public function fetchAll(): array { return $this->rows; }
            };
        }
    };
    $blocked = $verifier->verify($singleMenuRaw, $duplicatePdo, $singleMenuBinding);
    recoveryV2Expect($blocked['status'] === 'FAILED_UPGRADE_RECOVERY_BLOCKED', 'blocks duplicate catalog menu rows even when the profile declares one code');
}
foreach ([
    array_replace_recursive($descriptor, ['profile' => ['assertions' => [['type' => 'sql', 'query' => 'select 1']]]]),
    array_replace_recursive($descriptor, ['profile' => ['assertions' => [['type' => 'relation_absent', 'name' => '../escape']]]]),
] as $invalid) {
    try { $verifier->parseDescriptor(FailedUpgradeRecoveryVerifier::canonicalJson($invalid)); throw new RuntimeException('invalid v2 profile accepted'); }
    catch (InvalidArgumentException) { echo "[PASS] rejects unsafe profile vocabulary or path-like value\n"; }
}
echo "SandPackage failed-upgrade v2 behavior contract passed\n";
