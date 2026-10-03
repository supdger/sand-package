<?php

declare(strict_types=1);
require_once __DIR__ . '/SystemUpdateReleaseTools.php';

$started = microtime(true);
$archiveFile = null;
$exitCode = 0;
try {
    $args = array_slice($argv, 1);
    if (!in_array('--no-schema-change', $args, true) || !in_array('--no-skeleton-change', $args, true)) {
        throw new RuntimeException('需维护者明确声明 --no-schema-change --no-skeleton-change；有数据库或骨架变更的发行不支持一键升级');
    }
    $args = array_values(array_filter($args, static fn (string $arg): bool => !in_array($arg, ['--no-schema-change', '--no-skeleton-change'], true)));
    if (count($args) < 4 || count($args) > 5) throw new RuntimeException('用法：php build-system-update-contract.php <source绝对目录> <version> <host_min> <output绝对路径> [host_max] --no-schema-change --no-skeleton-change');
    [$source, $version, $hostMin, $output] = $args;
    foreach ([$version, $hostMin, $args[4] ?? $hostMin] as $value) {
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $value)) throw new RuntimeException('版本必须使用稳定三段版本');
    }
    if (isset($args[4]) && version_compare($args[4], $hostMin, '<')) throw new RuntimeException('host_max 不能小于 host_min');
    SandSystemUpdateReleaseTools::absolute($source);
    $source = realpath($source) ?: throw new RuntimeException('源码目录不存在');
    SandSystemUpdateReleaseTools::absolute($output);
    $outputParent = realpath(dirname($output)) ?: throw new RuntimeException('输出父目录不存在');
    if ($outputParent === $source || str_starts_with($outputParent . '/', $source . '/')) throw new RuntimeException('契约输出必须放在源码目录之外，避免污染发行树或自包含');
    fwrite(STDOUT, "步骤 1/3：核对源码归属、Git 提交与干净发行树\n");
    $git = static fn (array $arguments): string => SandSystemUpdateReleaseTools::command(array_merge(['git', '-C', $source], $arguments), $source);
    $repository = trim($git(['rev-parse', '--show-toplevel']));
    $git = static fn (array $arguments): string => SandSystemUpdateReleaseTools::command(array_merge(['git', '-C', $repository], $arguments), $repository);
    $reference = trim($git(['rev-parse', '--verify', 'HEAD^{commit}']));
    if (!preg_match('/^[a-f0-9]{40}$/D', $reference)) throw new RuntimeException('Git reference 不是可验证的 40 位提交');
    $prefix = $source === $repository ? '' : substr($source, strlen($repository) + 1) . '/';
    if ($prefix !== '') SandSystemUpdateReleaseTools::relative(rtrim($prefix, '/'));
    $pathspec = $prefix === '' ? '.' : rtrim($prefix, '/');
    if ($git(['status', '--porcelain', '--untracked-files=all', '--', $pathspec]) !== '') throw new RuntimeException('源码有未提交或未跟踪改动；请先在正式发行源完成审查与提交');
    $tagNames = array_filter(explode("\n", trim($git(['tag', '--list', $version, 'v' . $version]))));
    foreach ($tagNames as $tag) {
        if (trim($git(['rev-parse', '--verify', $tag . '^{commit}'])) !== $reference) throw new RuntimeException('已有版本 tag 未指向当前发行提交');
    }
    foreach (explode("\0", $git(['ls-tree', '-rz', '--full-tree', $reference, '--', $pathspec])) as $record) {
        if ($record === '') continue;
        if (!preg_match('/^(\d{6}) (?:blob|commit) [a-f0-9]{40}\t(.+)$/sD', $record, $entry)) throw new RuntimeException('Git tree 记录无效');
        $relative = substr($entry[2], strlen($prefix));
        if (!preg_match('#^(server|sandadmin-artd|tools)/#', $relative) || SandSystemUpdateReleaseTools::excluded($relative)) continue;
        SandSystemUpdateReleaseTools::relative($relative);
        if (!in_array($entry[1], ['100644', '100755'], true)) throw new RuntimeException('发行载荷含符号链接或子仓库：' . $relative);
    }
    $archiveFile = tempnam(sys_get_temp_dir(), 'sand-update-contract-');
    if ($archiveFile === false) throw new RuntimeException('无法创建临时 archive');
    unlink($archiveFile); $archiveFile .= '.tar';
    $git(['archive', '--format=tar', '--output=' . $archiveFile, $reference, '--', $pathspec]);
    $archive = new PharData($archiveFile);
    $composerPath = $prefix . 'composer.json';
    if (!isset($archive[$composerPath])) throw new RuntimeException('发行 Git tree 缺少 composer.json');
    $composer = json_decode($archive[$composerPath]->getContent(), true, 128, JSON_THROW_ON_ERROR);
    $supportedExcludes = ['/sandadmin-artd/node_modules', '/sandadmin-artd/dist', '/sandadmin-artd/.env*', '/**/.DS_Store', '/**/.idea', '/**/.vscode', '/vendor', '/composer.lock', '/.github', '/.gitattributes', '/tests', '/server/tests', '/server/plugin/sandpackage/tests', '/sandadmin-artd/**/failed-upgrade-recovery.behavior*', '/sandadmin-artd/**/failed-upgrade-recovery.directives-mock.ts', '/sandadmin-artd/**/failed-upgrade-recovery.http-mock.ts', '/sandadmin-artd/**/failed-upgrade-recovery.index-mount.ts', '/sandadmin-artd/**/failed-upgrade-recovery.index-viewport-entry.ts', '/sandadmin-artd/**/failed-upgrade-recovery.tsconfig.json', '/sandadmin-artd/**/failed-upgrade-recovery.viewport.html', '/sandadmin-artd/**/failed-upgrade-recovery.vite.config.mts', '/server/plugin/sandadmin/tests'];
    foreach ($composer['archive']['exclude'] ?? [] as $exclude) {
        if (!in_array($exclude, $supportedExcludes, true)) throw new RuntimeException('Composer archive 排除规则已变化，需先同步更新执行器与发行工具：' . $exclude);
    }
    $package = $composer['name'] ?? '';
    if (!in_array($package, ['supdger/sand-core', 'supdger/sand-package'], true)) throw new RuntimeException('仅支持官方 Sand Core/Sand Package 源码单元');
    $plugin = $package === 'supdger/sand-core' ? 'sandadmin' : 'sandpackage';
    $versionFile = $prefix . 'server/plugin/' . $plugin . '/config/app.php';
    if (!isset($archive[$versionFile]) || !preg_match('/[\x27\x22]version[\x27\x22]\s*=>\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $archive[$versionFile]->getContent(), $match) || $match[1] !== $version) throw new RuntimeException('发行版本与 Git tree 的 app.version 不一致');
    fwrite(STDOUT, "步骤 2/3：从 Git archive 生成完整载荷摘要\n");
    $files = [];
    $archiveRoot = 'phar://' . $archiveFile . '/';
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        $gitPath = substr($entry->getPathname(), strlen($archiveRoot));
        if (!str_starts_with($gitPath, $prefix)) continue;
        $relative = substr($gitPath, strlen($prefix));
        if (!preg_match('#^(server|sandadmin-artd|tools)/#', $relative)) continue;
        SandSystemUpdateReleaseTools::relative($relative);
        if (SandSystemUpdateReleaseTools::excluded($relative)) continue;
        if (!$entry->isFile() || $entry->isLink()) throw new RuntimeException('发行载荷含符号链接或非常规文件：' . $relative);
        $files[$relative] = hash('sha256', $entry->getContent());
    }
    if (!$files) throw new RuntimeException('发行载荷为空');
    ksort($files, SORT_STRING);
    $contract = ['format' => 1, 'package' => $package, 'version' => $version, 'reference' => $reference, 'host_min' => $hostMin, 'schema_changes' => false, 'skeleton_changes' => false, 'files' => $files];
    if (isset($args[4])) $contract['host_max'] = $args[4];
    fwrite(STDOUT, "步骤 3/3：写入 system-update 契约\n");
    SandSystemUpdateReleaseTools::json($output, $contract);
    fwrite(STDOUT, sprintf("成功：%s，%d 个载荷文件，耗时 %.2f 秒；只生成本地契约，未发布\n", $output, count($files), microtime(true) - $started));
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("失败：%s（耗时 %.2f 秒）\n", $error->getMessage(), microtime(true) - $started));
    $exitCode = 1;
} finally {
    if (is_string($archiveFile) && is_file($archiveFile)) unlink($archiveFile);
}

exit($exitCode);
