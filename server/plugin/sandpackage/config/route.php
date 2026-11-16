<?php

use Webman\Route;

// Remaining install actions are explicit, method-bound routes. CheckLogin is
// declared here in addition to the controller Middleware attribute so the
// route table alone shows every entry requires an authenticated admin.
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'index']);
Route::get('/app/sandpackage/install/index', [plugin\sandpackage\app\controller\InstallController::class, 'index'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'upload']);
Route::post('/app/sandpackage/install/upload', [plugin\sandpackage\app\controller\InstallController::class, 'upload'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'install']);
Route::post('/app/sandpackage/install/install', [plugin\sandpackage\app\controller\InstallController::class, 'install'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'uninstall']);
Route::post('/app/sandpackage/install/uninstall', [plugin\sandpackage\app\controller\InstallController::class, 'uninstall'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'reload']);
Route::post('/app/sandpackage/install/reload', [plugin\sandpackage\app\controller\InstallController::class, 'reload'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectCandidate']);
Route::post('/app/sandpackage/install/inspectCandidate', [plugin\sandpackage\app\controller\InstallController::class, 'inspectCandidate'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectDependencyRecovery']);
Route::post('/app/sandpackage/install/inspectDependencyRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'inspectDependencyRecovery'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'recoverDependency']);
Route::post('/app/sandpackage/install/recoverDependency', [plugin\sandpackage\app\controller\InstallController::class, 'recoverDependency'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'registerExisting']);
Route::post('/app/sandpackage/install/registerExisting', [plugin\sandpackage\app\controller\InstallController::class, 'registerExisting'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttach']);
Route::post('/app/sandpackage/install/inspectExistingSchemaAttach', [plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttach'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'attachExistingSchema']);
Route::post('/app/sandpackage/install/attachExistingSchema', [plugin\sandpackage\app\controller\InstallController::class, 'attachExistingSchema'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttachRecovery']);
Route::post('/app/sandpackage/install/inspectExistingSchemaAttachRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttachRecovery'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'continueExistingSchemaAttach']);
Route::post('/app/sandpackage/install/continueExistingSchemaAttach', [plugin\sandpackage\app\controller\InstallController::class, 'continueExistingSchemaAttach'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'discardCandidate']);
Route::post('/app/sandpackage/install/discardCandidate', [plugin\sandpackage\app\controller\InstallController::class, 'discardCandidate'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'verifyFailedUpgradeRecovery']);
Route::post('/app/sandpackage/install/verifyFailedUpgradeRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'verifyFailedUpgradeRecovery'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectFailedUpgradeRecovery']);
Route::post('/app/sandpackage/install/inspectFailedUpgradeRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'inspectFailedUpgradeRecovery'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'restoreRuntimeFromBackup']);
Route::post('/app/sandpackage/install/restoreRuntimeFromBackup', [plugin\sandpackage\app\controller\InstallController::class, 'restoreRuntimeFromBackup'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectInterruptedPreUpgradeBackup']);
Route::post('/app/sandpackage/install/inspectInterruptedPreUpgradeBackup', [plugin\sandpackage\app\controller\InstallController::class, 'inspectInterruptedPreUpgradeBackup'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'restoreInterruptedPreUpgradeBackup']);
Route::post('/app/sandpackage/install/restoreInterruptedPreUpgradeBackup', [plugin\sandpackage\app\controller\InstallController::class, 'restoreInterruptedPreUpgradeBackup'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'prepareFailedUpgradeReplacement']);
Route::post('/app/sandpackage/install/prepareFailedUpgradeReplacement', [plugin\sandpackage\app\controller\InstallController::class, 'prepareFailedUpgradeReplacement'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'replaceFailedUpgradeCandidate']);
Route::post('/app/sandpackage/install/replaceFailedUpgradeCandidate', [plugin\sandpackage\app\controller\InstallController::class, 'replaceFailedUpgradeCandidate'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'retryFailedUpgrade']);
Route::post('/app/sandpackage/install/retryFailedUpgrade', [plugin\sandpackage\app\controller\InstallController::class, 'retryFailedUpgrade'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectDependencies']);
Route::post('/app/sandpackage/install/inspectDependencies', [plugin\sandpackage\app\controller\InstallController::class, 'inspectDependencies'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectUninstall']);
Route::post('/app/sandpackage/install/inspectUninstall', [plugin\sandpackage\app\controller\InstallController::class, 'inspectUninstall'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);

// No HTTP terminal and no dependency-start executor. Those actions are not
// registered. Dependency installs run as `php webman sandpackage:dependency`.

// Recovery surface of the upstream PostgreSQL lifecycle driver. These share the
// InstallLogic used by `php webman sandpackage:recover`; the CLI is unchanged.
foreach ([
    'inspectHostUpgradeRecovery',
    'recoverHostUpgradeRollback',
    'continueHostUpgrade',
    'inspectHostUninstallRecovery',
    'recoverHostUninstallRollback',
    'continueHostUninstall',
    'inspectFreshInstallRecovery',
    'recoverFreshInstall',
    'inspectFreshCandidate',
    'discardFreshCandidate',
    'withdrawWaitingInstall',
    'retryFailedInstall',
    'continueWaitingInstall',
    'inspectGlobalGuards',
    'cleanupGlobalGuards',
] as $sandpackageRecoveryAction) {
    Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, $sandpackageRecoveryAction]);
    Route::post(
        '/app/sandpackage/install/' . $sandpackageRecoveryAction,
        [plugin\sandpackage\app\controller\InstallController::class, $sandpackageRecoveryAction],
    )->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
}
unset($sandpackageRecoveryAction);

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryCatalog']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryDownload']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryDocument']);
Route::get('/tool/install/repository/catalog', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryCatalog'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::post('/tool/install/repository/download', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryDownload'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::get('/tool/install/repository/document', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryDocument'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'limits']);
Route::get('/app/sandpackage/install/limits', [plugin\sandpackage\app\controller\InstallController::class, 'limits'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectCleanup']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'cleanup']);
Route::post('/tool/install/cleanup/inspect', [plugin\sandpackage\app\controller\InstallController::class, 'inspectCleanup'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::post('/tool/install/cleanup', [plugin\sandpackage\app\controller\InstallController::class, 'cleanup'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryCleanupPackage']);
Route::post('/tool/install/cleanup/package', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryCleanupPackage'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);

// System package updates are explicit, super-admin-only endpoints.
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'status']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'inspect']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'start']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'task']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'recover']);
Route::get('/app/sandpackage/systemUpdate/status', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'status'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::post('/app/sandpackage/systemUpdate/inspect', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'inspect'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::post('/app/sandpackage/systemUpdate/start', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'start'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::get('/app/sandpackage/systemUpdate/task', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'task'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::post('/app/sandpackage/systemUpdate/recover', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'recover'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'finalize']);
Route::post('/app/sandpackage/systemUpdate/finalize', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'finalize'])
    ->middleware(\plugin\sandadmin\app\middleware\CheckLogin::class);

// This health protocol is loopback-only and requires the independent worker's random challenge.
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateProbeController::class, 'probe']);
Route::get('/app/sandpackage/systemUpdate/probe', [plugin\sandpackage\app\controller\SystemUpdateProbeController::class, 'probe']);
