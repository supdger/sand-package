<?php

declare(strict_types=1);

/**
 * 等待依赖安装的撤回门槛：允许的登记放行，其余情况给出拒绝原因。
 *
 * Run: php tools/assert-withdraw-waiting.php
 */

require dirname(__DIR__) . '/server/plugin/sandpackage/app/service/WaitingInstallWithdrawal.php';

use plugin\sandpackage\app\service\WaitingInstallWithdrawal;

$failures = [];

$check = static function (string $label, ?string $refusal, ?string $expected) use (&$failures): void {
    if ($refusal !== $expected) {
        $failures[] = $label . '：期望 ' . var_export($expected, true) . '，实际 ' . var_export($refusal, true);
    }
};

$ready = [
    'state' => 4,
    'lifecycle_driver' => 'saipackage-pg-v1',
    'npm_dependent_wait_install' => 1,
];

$check('等待依赖且无宿主载荷可以撤回', WaitingInstallWithdrawal::describeRefusal($ready, 4, false, false, false), null);
$check('已安装不能撤回', WaitingInstallWithdrawal::describeRefusal($ready, 1, false, false, false), '只有停在等待依赖、安装中断或安装失败的插件可以撤回安装');
$check(
    '安装中断仍可撤回',
    WaitingInstallWithdrawal::describeRefusal($ready + ['dependency_command_nonce' => 'abc'], 4, false, false, false),
    null,
);
$failed = $ready;
$failed['state'] = 8;
$check('安装失败可以撤回', WaitingInstallWithdrawal::describeRefusal($failed, 8, false, false, false), null);
$check('安装失败可以重新安装', WaitingInstallWithdrawal::describeRetryRefusal($failed, 8), null);
$check('等待依赖不能走重新安装', WaitingInstallWithdrawal::describeRetryRefusal($ready, 4), '只有安装失败的插件可以重新安装');
$check(
    '升级失败不从这里重装',
    WaitingInstallWithdrawal::describeRetryRefusal($failed + ['failed_upgrade' => 1], 8),
    '这次失败不能从这里重新安装',
);
if (WaitingInstallWithdrawal::shouldReplaySql('sql_not_committed') !== true) {
    $failures[] = '未提交的安装脚本应允许重放';
}
if (WaitingInstallWithdrawal::shouldReplaySql('sql_committed_deploy_pending') !== false) {
    $failures[] = '已提交的安装脚本不应重放';
}
$check(
    '升级不能按新装撤回',
    WaitingInstallWithdrawal::describeRefusal($ready + ['upgrade_from_version' => '0.1.0'], 4, false, false, false),
    '这次不是可以撤回的全新安装',
);
$check('其他插件依赖它不能撤回', WaitingInstallWithdrawal::describeRefusal($ready, 4, true, false, false), '还有其他插件可能依赖它，不能撤回');
$check('读不到邻居声明不能撤回', WaitingInstallWithdrawal::describeRefusal($ready, 4, false, true, false), '还有其他插件可能依赖它，不能撤回');
$check('已改宿主文件不能撤回', WaitingInstallWithdrawal::describeRefusal($ready, 4, false, false, true), '这个插件已经改过宿主文件，不能从这里撤回');
$check(
    '旧安装流程不能走这个入口',
    WaitingInstallWithdrawal::describeRefusal(['lifecycle_driver' => 'legacy'], 4, false, false, false),
    '安装记录不是当前安装流程，不能从这里撤回',
);

$logic = file_get_contents(dirname(__DIR__) . '/server/plugin/sandpackage/app/logic/InstallLogic.php');
$controller = file_get_contents(dirname(__DIR__) . '/server/plugin/sandpackage/app/controller/InstallController.php');
$route = file_get_contents(dirname(__DIR__) . '/server/plugin/sandpackage/config/route.php');
if (!is_string($logic) || !str_contains($logic, 'function withdrawWaitingInstall')) {
    $failures[] = 'InstallLogic 缺少 withdrawWaitingInstall';
}
if (!is_string($logic) || !str_contains($logic, 'function retryFailedInstall')) {
    $failures[] = 'InstallLogic 缺少 retryFailedInstall';
}
if (!is_string($controller) || !str_contains($controller, 'function withdrawWaitingInstall')) {
    $failures[] = 'InstallController 缺少 withdrawWaitingInstall';
}
if (!is_string($controller) || !str_contains($controller, 'function retryFailedInstall')) {
    $failures[] = 'InstallController 缺少 retryFailedInstall';
}
if (!is_string($route) || !str_contains($route, 'withdrawWaitingInstall')) {
    $failures[] = 'route.php 未注册 withdrawWaitingInstall';
}
if (!is_string($route) || !str_contains($route, 'retryFailedInstall')) {
    $failures[] = 'route.php 未注册 retryFailedInstall';
}
$packageRoot = dirname(__DIR__) . '/server/plugin/sandpackage';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    if (!is_string($text) || !str_contains($text, '插件市场')) {
        continue;
    }
    if (preg_match('/INSERT\s+INTO/i', $text) === 1) {
        $failures[] = '插件安装代码不应插入宿主菜单「插件市场」：' . $file->getFilename();
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'withdraw-waiting ok' . PHP_EOL;
