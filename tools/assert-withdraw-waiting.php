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
$check('已安装不能撤回', WaitingInstallWithdrawal::describeRefusal($ready, 1, false, false, false), '只有停在等待依赖安装的插件可以撤回安装');
$check(
    '命令还在执行不能撤回',
    WaitingInstallWithdrawal::describeRefusal($ready + ['dependency_command_nonce' => 'abc'], 4, false, false, false),
    '依赖命令还在执行，先等它结束后再撤回',
);
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
if (!is_string($controller) || !str_contains($controller, 'function withdrawWaitingInstall')) {
    $failures[] = 'InstallController 缺少 withdrawWaitingInstall';
}
if (!is_string($route) || !str_contains($route, 'withdrawWaitingInstall')) {
    $failures[] = 'route.php 未注册 withdrawWaitingInstall';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'withdraw-waiting ok' . PHP_EOL;
