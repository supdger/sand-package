#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Install-route authentication gate.
 *
 * Run: php tools/assert-install-routes-auth.php
 *
 * Every public action of InstallController and SystemUpdateController must
 * disable its default route and re-register as an explicit, method-bound
 * route. The eight former default-route actions additionally carry an
 * explicit CheckLogin middleware on the route itself (the controller
 * Middleware attribute still applies; the route-level declaration is the
 * auditable second latch). Super-admin gating in both constructors must use
 * SystemException with a real HTTP 403 status.
 */

$root = dirname(__DIR__);
$failures = [];

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

$read = static function (string $path) use ($fail): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        $fail('无法读取 ' . $path);
        return '';
    }
    return $contents;
};

$routeFile = $root . '/server/plugin/sandpackage/config/route.php';
$route = $read($routeFile);

// Method names routed through the recovery foreach block generate their
// explicit route dynamically; membership in that list counts as registered.
$recoveryActions = [];
if (preg_match('/foreach \(\[(.*?)\] as \$sandpackageRecoveryAction\)/s', $route, $match) === 1) {
    preg_match_all("/'([A-Za-z]+)'/", $match[1], $names);
    $recoveryActions = $names[1];
}

$controllers = [
    'InstallController' => $root . '/server/plugin/sandpackage/app/controller/InstallController.php',
    'SystemUpdateController' => $root . '/server/plugin/sandpackage/app/controller/SystemUpdateController.php',
];

$assertions = 0;
foreach ($controllers as $controller => $file) {
    $source = $read($file);
    preg_match_all('/public function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $methods);
    foreach (array_unique($methods[1]) as $method) {
        if ($method === '__construct') {
            continue;
        }
        $assertions++;
        $disabledLiteral = preg_match(
            '/disableDefaultRoute\(\[\s*plugin\\\\sandpackage\\\\app\\\\controller\\\\' . $controller
                . '::class\s*,\s*\'' . preg_quote($method, '/') . '\'\s*\]\)/',
            $route,
        ) === 1;
        $disabledDynamic = $controller === 'InstallController' && in_array($method, $recoveryActions, true);
        if (!$disabledLiteral && !$disabledDynamic) {
            $fail("{$controller}::{$method} 未在 route.php 中 disableDefaultRoute");
        }
        $explicit = preg_match(
            '/Route::(?:get|post|any|put|delete|patch)\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[\s*plugin\\\\sandpackage\\\\app\\\\controller\\\\'
                . $controller . '::class\s*,\s*\'' . preg_quote($method, '/') . '\'\s*\]/',
            $route,
        ) === 1;
        if (!$explicit && !in_array($method, $recoveryActions, true)) {
            $fail("{$controller}::{$method} 未注册显式路由（也不在恢复 foreach 清单中）");
        }
    }

    // Super-admin gate: SystemException carrying a real HTTP 403, never ApiException.
    if (preg_match('/function __construct\(\)\s*\{(.*?)\n    \}/s', $source, $ctor) !== 1) {
        $fail("{$controller} 未找到构造器，无法核对超管门禁");
        continue;
    }
    $assertions++;
    if (!str_contains($ctor[1], 'new SystemException(')) {
        $fail("{$controller} 构造器超管门禁未使用 SystemException");
    }
    if (str_contains($ctor[1], 'new ApiException(')) {
        $fail("{$controller} 构造器超管门禁仍使用 ApiException（HTTP 状态被吞成 200）");
    }
    if (preg_match('/new SystemException\([^;]*,\s*403\s*,\s*null\s*,\s*403\s*\)/', $ctor[1]) !== 1) {
        $fail("{$controller} 构造器 SystemException 未携带业务码 403 与 httpStatus 403");
    }
}

// The eight former default-route actions must declare CheckLogin on the route.
foreach ([
    'index' => 'get',
    'upload' => 'post',
    'install' => 'post',
    'uninstall' => 'post',
    'reload' => 'post',
    'inspectCandidate' => 'post',
    'inspectDependencyRecovery' => 'post',
    'recoverDependency' => 'post',
] as $method => $verb) {
    $assertions++;
    if (preg_match(
        '/Route::' . $verb . '\(\s*\'\/app\/sandpackage\/install\/' . $method . '\'[^;]*;/s',
        $route,
        $statement,
    ) !== 1) {
        $fail("install/{$method} 缺少 {$verb} 显式路由");
        continue;
    }
    if (!str_contains($statement[0], 'middleware(')
        || !str_contains($statement[0], 'CheckLogin')) {
        $fail("install/{$method} 显式路由未声明 CheckLogin middleware");
    }
}

// Plugin version must track the CHANGELOG head.
$assertions++;
$app = $read($root . '/server/plugin/sandpackage/config/app.php');
$changelog = $read($root . '/CHANGELOG.md');
$appVersion = preg_match('/\'version\'\s*=>\s*\'([^\']+)\'/', $app, $v) === 1 ? $v[1] : null;
$changelogVersion = preg_match('/^## ([0-9]+\.[0-9]+\.[0-9]+)/m', $changelog, $v) === 1 ? $v[1] : null;
if ($appVersion === null || $changelogVersion === null || $appVersion !== $changelogVersion) {
    $fail('config/app.php 版本 ' . var_export($appVersion, true)
        . ' 与 CHANGELOG 顶部版本 ' . var_export($changelogVersion, true) . ' 不一致');
}

if ($failures !== []) {
    fwrite(STDERR, '安装路由鉴权门禁未通过：' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'install routes: all public actions explicit, method-bound, CheckLogin declared' . PHP_EOL);
fwrite(STDOUT, 'super-admin gate: SystemException with real HTTP 403 in both constructors' . PHP_EOL);
fwrite(STDOUT, 'plugin version ' . $appVersion . ' matches CHANGELOG head' . PHP_EOL);
fwrite(STDOUT, 'assertions: ' . $assertions . PHP_EOL);
