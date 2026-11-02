#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 发布排除规则一致性门禁。
 *
 * 校验 server/PublishExclusions.php（单一来源）与以下通道一致：
 *   - server/FrontendPublisher.php、server/Install.php 的排除判定
 *   - composer.json 的 archive.exclude
 *   - .gitattributes 的 export-ignore
 *   - 若存在，../sand-core/server/PublishExclusions.php 的前端排除集
 *
 * 用法：php tools/assert-publish-exclusions.php [--no-cross-package]
 */

use SandAdmin\Package\PublishExclusions;

$root = dirname(__DIR__);
require $root . '/server/PublishExclusions.php';

$crossPackage = !in_array('--no-cross-package', $argv, true);
$failures = [];
$notices = [];

$diff = static function (array $expected, array $actual): string {
    $lines = [];
    foreach (array_diff($expected, $actual) as $item) {
        $lines[] = '    缺少：' . $item;
    }
    foreach (array_diff($actual, $expected) as $item) {
        $lines[] = '    多余：' . $item;
    }

    return implode(PHP_EOL, $lines);
};

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$archive = is_array($composer) ? ($composer['archive']['exclude'] ?? []) : [];
$archive = is_array($archive) ? array_values(array_map('strval', $archive)) : [];
sort($archive);
if ($archive !== PublishExclusions::archiveExclude()) {
    $failures[] = 'composer.json archive.exclude 与 PublishExclusions::archiveExclude() 不一致：' . PHP_EOL
        . $diff(PublishExclusions::archiveExclude(), $archive);
}

$attributesPath = $root . '/.gitattributes';
if (!is_file($attributesPath)) {
    $failures[] = '缺少 .gitattributes，无法校验 export-ignore 口径';
} else {
    $attributes = [];
    foreach (file($attributesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_ends_with($line, 'export-ignore')) {
            continue;
        }
        $attributes[] = $line;
    }
    sort($attributes);
    if ($attributes !== PublishExclusions::exportIgnore()) {
        $failures[] = '.gitattributes export-ignore 与 PublishExclusions::exportIgnore() 不一致：' . PHP_EOL
            . $diff(PublishExclusions::exportIgnore(), $attributes);
    }
}

$publisher = (string) file_get_contents($root . '/server/FrontendPublisher.php');
if (!str_contains($publisher, 'PublishExclusions::publishExcluded(')) {
    $failures[] = 'server/FrontendPublisher.php 未经 PublishExclusions::publishExcluded() 判定排除规则';
}
if (preg_match('#function\s+excluded\s*\(#', $publisher) === 1) {
    $failures[] = 'server/FrontendPublisher.php 自带 excluded()，排除规则存在第二个来源';
}
if (!str_contains((string) file_get_contents($root . '/server/Install.php'), 'PublishExclusions::excluded(')) {
    $failures[] = 'server/Install.php 未经 PublishExclusions::excluded() 判定后端载荷排除规则';
}

$corePath = dirname($root) . '/sand-core/server/PublishExclusions.php';
if (!$crossPackage) {
    $notices[] = '已按 --no-cross-package 跳过与 sand-core 的排除集比对';
} elseif (!is_file($corePath)) {
    $notices[] = '未发现 ' . $corePath . '，跳过与 sand-core 的排除集比对';
} else {
    require $corePath;
    $coreClass = 'SandAdmin\\Core\\PublishExclusions';
    if (!class_exists($coreClass)) {
        $notices[] = 'sand-core 的 PublishExclusions 类名不符预期，需人工对齐排除集';
    } else {
        $comparable = false;
        foreach (['DIRECTORIES', 'FILES', 'FILE_PREFIXES'] as $name) {
            if (!defined($coreClass . '::' . $name)) {
                continue;
            }
            $comparable = true;
            $coreValue = (array) constant($coreClass . '::' . $name);
            $own = (array) constant(PublishExclusions::class . '::' . $name);
            sort($coreValue);
            sort($own);
            if ($coreValue !== $own) {
                $failures[] = "sand-core 与 sand-package 的 PublishExclusions::{$name} 不一致：" . PHP_EOL
                    . $diff($own, $coreValue);
            }
        }
        if (!$comparable) {
            $notices[] = 'sand-core 的 PublishExclusions 未暴露 DIRECTORIES/FILES/FILE_PREFIXES，需人工对齐排除集';
        }
    }
}

foreach ($notices as $notice) {
    fwrite(STDOUT, '[notice] ' . $notice . PHP_EOL);
}
if ($failures !== []) {
    fwrite(STDERR, '发布排除规则不一致：' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'publish exclusions consistent' . PHP_EOL);
