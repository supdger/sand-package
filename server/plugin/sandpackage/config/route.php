<?php

use Webman\Route;

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'registerExisting']);
Route::post('/app/sandpackage/install/registerExisting', [plugin\sandpackage\app\controller\InstallController::class, 'registerExisting']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttach']);
Route::post('/app/sandpackage/install/inspectExistingSchemaAttach', [plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttach']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'attachExistingSchema']);
Route::post('/app/sandpackage/install/attachExistingSchema', [plugin\sandpackage\app\controller\InstallController::class, 'attachExistingSchema']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttachRecovery']);
Route::post('/app/sandpackage/install/inspectExistingSchemaAttachRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'inspectExistingSchemaAttachRecovery']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'continueExistingSchemaAttach']);
Route::post('/app/sandpackage/install/continueExistingSchemaAttach', [plugin\sandpackage\app\controller\InstallController::class, 'continueExistingSchemaAttach']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'discardCandidate']);
Route::post('/app/sandpackage/install/discardCandidate', [plugin\sandpackage\app\controller\InstallController::class, 'discardCandidate']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'verifyFailedUpgradeRecovery']);
Route::post('/app/sandpackage/install/verifyFailedUpgradeRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'verifyFailedUpgradeRecovery']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectFailedUpgradeRecovery']);
Route::post('/app/sandpackage/install/inspectFailedUpgradeRecovery', [plugin\sandpackage\app\controller\InstallController::class, 'inspectFailedUpgradeRecovery']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'restoreRuntimeFromBackup']);
Route::post('/app/sandpackage/install/restoreRuntimeFromBackup', [plugin\sandpackage\app\controller\InstallController::class, 'restoreRuntimeFromBackup']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectInterruptedPreUpgradeBackup']);
Route::post('/app/sandpackage/install/inspectInterruptedPreUpgradeBackup', [plugin\sandpackage\app\controller\InstallController::class, 'inspectInterruptedPreUpgradeBackup']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'restoreInterruptedPreUpgradeBackup']);
Route::post('/app/sandpackage/install/restoreInterruptedPreUpgradeBackup', [plugin\sandpackage\app\controller\InstallController::class, 'restoreInterruptedPreUpgradeBackup']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'prepareFailedUpgradeReplacement']);
Route::post('/app/sandpackage/install/prepareFailedUpgradeReplacement', [plugin\sandpackage\app\controller\InstallController::class, 'prepareFailedUpgradeReplacement']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'replaceFailedUpgradeCandidate']);
Route::post('/app/sandpackage/install/replaceFailedUpgradeCandidate', [plugin\sandpackage\app\controller\InstallController::class, 'replaceFailedUpgradeCandidate']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'retryFailedUpgrade']);
Route::post('/app/sandpackage/install/retryFailedUpgrade', [plugin\sandpackage\app\controller\InstallController::class, 'retryFailedUpgrade']);

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryCatalog']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryDownload']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryDocument']);
Route::get('/tool/install/repository/catalog', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryCatalog']);
Route::post('/tool/install/repository/download', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryDownload']);
Route::get('/tool/install/repository/document', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryDocument']);

Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'inspectCleanup']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'cleanup']);
Route::post('/tool/install/cleanup/inspect', [plugin\sandpackage\app\controller\InstallController::class, 'inspectCleanup']);
Route::post('/tool/install/cleanup', [plugin\sandpackage\app\controller\InstallController::class, 'cleanup']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\InstallController::class, 'repositoryCleanupPackage']);
Route::post('/tool/install/cleanup/package', [plugin\sandpackage\app\controller\InstallController::class, 'repositoryCleanupPackage']);

// System package updates are explicit, super-admin-only endpoints.
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'status']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'inspect']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'start']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'task']);
Route::disableDefaultRoute([plugin\sandpackage\app\controller\SystemUpdateController::class, 'recover']);
Route::get('/app/sandpackage/systemUpdate/status', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'status']);
Route::post('/app/sandpackage/systemUpdate/inspect', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'inspect']);
Route::post('/app/sandpackage/systemUpdate/start', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'start']);
Route::get('/app/sandpackage/systemUpdate/task', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'task']);
Route::post('/app/sandpackage/systemUpdate/recover', [plugin\sandpackage\app\controller\SystemUpdateController::class, 'recover']);
