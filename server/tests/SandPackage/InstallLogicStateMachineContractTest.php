<?php

declare(strict_types=1);

/**
 * Failed-upgrade state-machine acceptance entry.
 *
 * Recovery transitions are exercised through public component behavior by a
 * neutral plugin fixture. This entry intentionally does not inspect production
 * source strings and contains no business-plugin versions or fingerprints.
 */

$repositoryRoot = dirname(__DIR__, 3);
require $repositoryRoot . '/server/tests/SandPackage/NeutralFailedUpgradeRecoveryContractTest.php';
require $repositoryRoot . '/server/tests/SandPackage/ProductionLifecycleV2ContractTest.php';

// behavior-test-gate: static-rule -- package release metadata only.
$metadata = require $repositoryRoot . '/server/plugin/sandpackage/config/app.php';
if (!is_array($metadata) || ($metadata['version'] ?? null) !== '0.1.0') {
    throw new RuntimeException('SandPackage 0.1.0 contract version is not active');
}

echo "SandPackage v2 state-machine contract passed\n";
