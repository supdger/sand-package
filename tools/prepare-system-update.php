<?php

declare(strict_types=1);
require_once __DIR__ . '/SystemUpdateReleaseTools.php';

$started = microtime(true);
try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--(frontend|static)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) throw new RuntimeException('用法：php prepare-system-update.php --frontend=<绝对目录> --static=<绝对目录>');
        $options[$match[1]] = SandSystemUpdateReleaseTools::absolute($match[2]);
    }
    if (count($options) !== 2) throw new RuntimeException('必须同时提供 frontend 和 static 绝对目录');
    $dist = $options['frontend'] . '/dist';
    $static = $options['static'];
    $frontendRoot = realpath($options['frontend']) ?: throw new RuntimeException('前端目录不存在');
    $staticRoot = realpath($static) ?: throw new RuntimeException('静态目录不存在');
    $overlaps = static fn (string $a, string $b): bool => $a === $b || str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/');
    if ($overlaps($frontendRoot, $staticRoot)) throw new RuntimeException('静态目录不能与前端源码目录重叠');
    $serverRoot = dirname($frontendRoot) . '/server';
    if ($staticRoot === $serverRoot || str_starts_with($serverRoot . '/', $staticRoot . '/')) throw new RuntimeException('静态目录不能覆盖宿主根目录');
    foreach (['app', 'config', 'vendor', 'plugin', 'runtime'] as $protected) {
        if ($overlaps($staticRoot, $serverRoot . '/' . $protected)) throw new RuntimeException('静态目录不能与宿主 ' . $protected . ' 目录重叠');
    }
    fwrite(STDOUT, "步骤 1/3：读取当前前端构建与专用静态目录\n");
    $distFiles = SandSystemUpdateReleaseTools::tree($dist);
    if (!isset($distFiles['index.html']) || isset($distFiles[SandSystemUpdateReleaseTools::STATIC_MANIFEST])) throw new RuntimeException('dist 必须包含 index.html，且不能包含保留清单文件');
    if (realpath($dist) === realpath($static)) throw new RuntimeException('静态发布目录必须与 dist 分离');
    $staticFiles = SandSystemUpdateReleaseTools::tree($static, SandSystemUpdateReleaseTools::STATIC_MANIFEST);
    fwrite(STDOUT, "步骤 2/3：逐文件比较路径与 SHA-256，检查额外文件和手工修改\n");
    if ($distFiles !== $staticFiles) {
        $extra = array_diff_key($staticFiles, $distFiles);
        $missing = array_diff_key($distFiles, $staticFiles);
        $changed = array_diff_assoc(array_intersect_key($staticFiles, $distFiles), $distFiles);
        throw new RuntimeException(sprintf('静态目录与 dist 不一致：额外 %d、缺失 %d、内容变化 %d；先由管理员核对当前部署，工具不会复制或覆盖业务文件', count($extra), count($missing), count($changed)));
    }
    fwrite(STDOUT, "步骤 3/3：建立可恢复的静态文件基线\n");
    SandSystemUpdateReleaseTools::json($static . '/' . SandSystemUpdateReleaseTools::STATIC_MANIFEST, ['files' => $distFiles]);
    fwrite(STDOUT, sprintf("成功：%d 个文件已核对，耗时 %.2f 秒\n", count($distFiles), microtime(true) - $started));
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("失败：%s（耗时 %.2f 秒）\n", $error->getMessage(), microtime(true) - $started));
    exit(1);
}
