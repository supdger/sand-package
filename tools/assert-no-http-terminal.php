#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * F5 gate: no HTTP terminal or arbitrary command endpoint.
 *
 * Run: php tools/assert-no-http-terminal.php
 *
 * Dangerous process functions may appear only in the whitelist below.
 * That list is the review record for this package; a new hit fails the gate.
 */

$root = dirname(__DIR__);
$failures = [];

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

$relative = static function (string $path) use ($root): string {
    return ltrim(substr($path, strlen($root)), '/');
};

$read = static function (string $path) use ($fail): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        $fail('无法读取 ' . $path);
        return '';
    }
    return $contents;
};

foreach ([
    'server/plugin/sandpackage/app/controller/IndexController.php',
    'sandadmin-artd/src/views/plugin/sandpackage/install/terminal.vue',
    'sandadmin-artd/src/views/plugin/sandpackage/store/terminal.ts',
] as $removed) {
    if (is_file($root . '/' . $removed)) {
        $fail($removed . ' 仍在发布面，HTTP 终端入口未删除');
    }
}

$controllerDir = $root . '/server/plugin/sandpackage/app/controller';
$controllerFiles = glob($controllerDir . '/*.php') ?: [];
$httpForbidden = [
    'function terminal',
    'inspectDependencyStart',
    'TerminalRunner',
    'Saithink\\Saipackage\\service\\Terminal',
    '/app/sandpackage/index/terminal',
    'index/terminal',
];
foreach ($controllerFiles as $file) {
    $contents = $read($file);
    foreach ($httpForbidden as $needle) {
        if (str_contains($contents, $needle)) {
            $fail($relative($file) . ' 含有 HTTP 终端痕迹：' . $needle);
        }
    }
    if (preg_match('/\bnew\s+Terminal\s*\(/', $contents) === 1 || preg_match('/\(new\s+Terminal\(\)\)\s*->\s*exec\s*\(/', $contents) === 1) {
        $fail($relative($file) . ' 仍构造在线终端');
    }
}

$route = $read($root . '/server/plugin/sandpackage/config/route.php');
foreach (['index/terminal', 'inspectDependencyStart', 'IndexController'] as $needle) {
    if (str_contains($route, $needle)) {
        $fail('route.php 仍注册或指向终端路由：' . $needle);
    }
}

$frontendDir = $root . '/sandadmin-artd/src/views/plugin/sandpackage';
$frontendIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($frontendDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($frontendIterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $name = $file->getFilename();
    if (!str_ends_with($name, '.vue') && !str_ends_with($name, '.ts')) {
        continue;
    }
    $contents = $read($file->getPathname());
    foreach (['/app/sandpackage/index/terminal', '依赖安装终端', "from './terminal.vue'", "from '../store/terminal'"] as $needle) {
        if (str_contains($contents, $needle)) {
            $fail($relative($file->getPathname()) . ' 仍发布 HTTP 终端客户端：' . $needle);
        }
    }
}

$dependencyCommand = $read($root . '/server/plugin/sandpackage/app/command/Dependency.php');
if (!str_contains($dependencyCommand, "protected static \$defaultName = 'sandpackage:dependency'")) {
    $fail('CLI 替换命令 sandpackage:dependency 不存在');
}
if (!str_contains($dependencyCommand, 'runCli(')) {
    $fail('sandpackage:dependency 未走 CLI 执行入口');
}
$runner = $read($root . '/server/plugin/sandpackage/app/service/TerminalRunner.php');
$execAt = strpos($runner, 'function exec(): Generator');
$runCliAt = strpos($runner, 'function runCli(');
$execBody = ($execAt !== false && $runCliAt !== false && $runCliAt > $execAt)
    ? substr($runner, $execAt, $runCliAt - $execAt)
    : '';
if (!str_contains($execBody, "throw new \\RuntimeException('HTTP terminal execution is disabled')")
    || preg_match('/(?<![\w>])proc_open\s*\(/', $execBody) === 1) {
    $fail('TerminalRunner::exec 仍可作为 HTTP 执行入口');
}
if (!str_contains($runner, 'dependency commands cannot run on an HTTP worker request')) {
    $fail('TerminalRunner::runCli 未拒绝处于 HTTP 请求中的调用');
}
$legacy = $read($root . '/server/compat/Saithink/Saipackage/service/Terminal.php');
if (preg_match('/\bproc_open\s*\(/', $legacy) === 1 || preg_match('/\bshell_exec\s*\(/', $legacy) === 1) {
    $fail('兼容 Terminal 类仍包含进程执行');
}
if (!str_contains($legacy, 'HTTP terminal execution is disabled')) {
    $fail('兼容 Terminal::exec 未硬关闭');
}

/**
 * Process spawners that are not an HTTP terminal for arbitrary commands.
 * Review any addition. Do not add a controller or a general shell wrapper.
 */
$processWhitelist = [
    'server/compat/Saithink/Saipackage/service/Server.php' => 'reload / full_restart of the current service, not a package-install terminal',
    'server/plugin/sandpackage/app/service/SystemUpdate.php' => 'super-admin system-update worker detach; not a general terminal',
    'server/plugin/sandpackage/tools/system-update-worker.php' => 'independent system-update CLI worker',
    'server/plugin/sandpackage/app/service/TerminalRunner.php' => 'allowlisted composer/npm commands, CLI only; exec() refuses HTTP',
];

$patterns = [
    'proc_open' => '/(?<![\w>])proc_open\s*\(/',
    'shell_exec' => '/(?<![\w>])shell_exec\s*\(/',
    'passthru' => '/(?<![\w>])passthru\s*\(/',
    'exec' => '/(?<!function )(?<![:\w>])exec\s*\(/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $path = $file->getPathname();
    $rel = $relative($path);
    if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'tools/')) {
        continue;
    }
    $contents = $read($path);
    $hits = [];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $hits[] = $name;
        }
    }
    if ($hits === []) {
        continue;
    }
    if (!isset($processWhitelist[$rel])) {
        $fail($rel . ' 出现未登记的进程执行函数：' . implode(', ', $hits));
        continue;
    }
}

if (!isset($processWhitelist['server/plugin/sandpackage/app/service/TerminalRunner.php'])) {
    $fail('白名单缺少 CLI 依赖命令实现');
}

if ($failures !== []) {
    fwrite(STDERR, 'HTTP 终端门禁未通过：' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'no HTTP terminal endpoint' . PHP_EOL);
fwrite(STDOUT, 'process whitelist:' . PHP_EOL);
foreach ($processWhitelist as $path => $reason) {
    fwrite(STDOUT, '  - ' . $path . ' — ' . $reason . PHP_EOL);
}
