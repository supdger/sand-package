<?php

declare(strict_types=1);

/**
 * 一键续做只看已经装上的包；重新安装要真的再跑一次安装。
 *
 * Run: php tools/assert-continue-waiting.php
 */

require dirname(__DIR__) . '/server/plugin/sandpackage/app/service/WaitingInstallWithdrawal.php';
require dirname(__DIR__) . '/server/plugin/sandpackage/app/service/WaitingInstallContinuation.php';

use plugin\sandpackage\app\service\WaitingInstallContinuation;

$failures = [];

$check = static function (string $label, ?string $refusal, ?string $expected) use (&$failures): void {
    if ($refusal !== $expected) {
        $failures[] = $label . '：期望 ' . var_export($expected, true) . '，实际 ' . var_export($refusal, true);
    }
};

$waiting = [
    'state' => 4,
    'lifecycle_driver' => 'saipackage-pg-v1',
    'composer_dependent_wait_install' => 1,
];
$check('等待依赖可以续做', WaitingInstallContinuation::describeContinueRefusal($waiting, 4), null);
$check(
    '安装中断可以续做',
    WaitingInstallContinuation::describeContinueRefusal($waiting + ['dependency_failed_nonce' => 'abc'], 4),
    null,
);
$check('已安装不能续做', WaitingInstallContinuation::describeContinueRefusal($waiting, 1), '只有停在等待依赖或安装中断的插件可以续做');
$check(
    '升级不能从这里续做',
    WaitingInstallContinuation::describeContinueRefusal($waiting + ['upgrade_from_version' => '0.1.0'], 4),
    '这次不是可以续做的全新安装',
);
$check(
    '旧安装流程不能续做',
    WaitingInstallContinuation::describeContinueRefusal(['lifecycle_driver' => 'legacy', 'dependency_command_nonce' => 'abc'], 4),
    '安装记录不是当前安装流程，不能从这里续做',
);

$declared = [
    'require' => [
        'php' => '>=8.2',
        'ext-pdo' => '*',
        'ca-check/stopmarker' => '1.0.0',
    ],
    'dependencies' => [
        'ca-check-ui' => '1.0.0',
    ],
];
$missing = WaitingInstallContinuation::missingPackages($declared, ['ca-check/stopmarker' => true]);
$names = array_map(static function (array $item): string {
    return $item['source'] . ':' . $item['name'];
}, $missing);
if ($names !== ['npm:ca-check-ui']) {
    $failures[] = '已经装上的后台包和运行环境不应再报缺，实际 ' . implode(',', $names);
}
$text = WaitingInstallContinuation::missingText($missing);
if (!str_contains($text, '页面依赖 ca-check-ui') || str_contains($text, 'SQL') || str_contains($text, '/Users/')) {
    $failures[] = '缺包说明应只写包名，实际 ' . $text;
}
$lines = WaitingInstallContinuation::commandLines('ca-stop', $missing);
if ($lines !== [['label' => '装页面依赖', 'text' => 'php webman sandpackage:dependency run ca-stop npm']]) {
    $failures[] = '缺页面依赖时应给出对应命令';
}
$both = WaitingInstallContinuation::missingPackages($declared, []);
$bothLines = WaitingInstallContinuation::commandLines('ca-stop', $both);
$bothText = array_map(static function (array $line): string {
    return $line['text'];
}, $bothLines);
if (!in_array('php webman sandpackage:dependency run ca-stop composer', $bothText, true)
    || !in_array('php webman sandpackage:dependency run ca-stop npm', $bothText, true)) {
    $failures[] = '后台和页面都缺时应各给一条命令';
}

$root = dirname(__DIR__);
$logic = file_get_contents($root . '/server/plugin/sandpackage/app/logic/InstallLogic.php');
$controller = file_get_contents($root . '/server/plugin/sandpackage/app/controller/InstallController.php');
$route = file_get_contents($root . '/server/plugin/sandpackage/config/route.php');
if (!is_string($logic) || !str_contains($logic, 'function continueWaitingInstall')) {
    $failures[] = 'InstallLogic 缺少 continueWaitingInstall';
}
if (!is_string($logic) || !str_contains($logic, 'function retireUncommittedJournal')) {
    $failures[] = 'InstallLogic 缺少 retireUncommittedJournal';
}
if (!is_string($logic) || !str_contains($logic, '$this->install(false)')) {
    $failures[] = '重新安装应再调用 install';
}
if (!is_string($controller) || !str_contains($controller, 'function continueWaitingInstall')) {
    $failures[] = 'InstallController 缺少 continueWaitingInstall';
}
if (!is_string($route) || !str_contains($route, 'continueWaitingInstall')) {
    $failures[] = 'route.php 未注册 continueWaitingInstall';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'continue-waiting ok' . PHP_EOL;
