<?php

declare(strict_types=1);

$tools = dirname(__DIR__, 3) . '/tools';
if (!is_file($tools . '/build-system-update-contract.php')) $tools = __DIR__;
require_once $tools . '/SystemUpdateReleaseTools.php';
$root = sys_get_temp_dir() . '/sand-update-tools-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$passed = 0;
function runTool(array $argv, string $cwd): array
{
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch fixture command');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out . $err];
}
function check(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $passed++; fwrite(STDOUT, 'PASS: ' . $name . "\n");
}
function put(string $file, string $content): void
{
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, $content);
}
function removeFixture(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') removeFixture($path . '/' . $entry);
    rmdir($path);
}
try {
    $source = $root . '/source'; mkdir($source);
    put($source . '/composer.json', '{"name":"supdger/sand-package"}');
    put($source . '/server/plugin/sandpackage/config/app.php', "<?php return ['version' => '0.2.0'];\n");
    put($source . '/server/a.php', "<?php echo 'release';\n");
    put($source . '/tools/tool.php', "<?php echo 'tool';\n");
    put($source . '/sandadmin-artd/src/main.ts', 'export default 1');
    put($source . '/sandadmin-artd/dist/index.html', 'excluded-dist');
    put($source . '/server/.env.local', 'excluded-secret');
    put($source . '/tools/.idea/a.xml', 'excluded-idea');
    put($source . '/server/archive-ignored.php', 'export-ignored');
    put($source . '/.gitattributes', "server/archive-ignored.php export-ignore\n");
    foreach ([['git', 'init', '-q'], ['git', 'add', '.'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture']] as $command) {
        [$code, $text] = runTool($command, $source); if ($code !== 0) throw new RuntimeException($text);
    }
    $build = [PHP_BINARY, $tools . '/build-system-update-contract.php', $source, '0.2.0', '0.1.0', $root . '/system-update.json', '--no-schema-change', '--no-skeleton-change'];
    [$code, $text] = runTool($build, $root);
    check($code === 0, 'clean Git archive contract: ' . $text);
    $contract = json_decode(file_get_contents($root . '/system-update.json'), true, 128, JSON_THROW_ON_ERROR);
    check($contract['package'] === 'supdger/sand-package' && $contract['format'] === 1 && preg_match('/^[a-f0-9]{40}$/D', $contract['reference']) === 1, 'identity and real Git reference');
    check(count($contract['files']) === 4 && !isset($contract['files']['sandadmin-artd/dist/index.html']) && !isset($contract['files']['server/.env.local']) && !isset($contract['files']['server/archive-ignored.php']), 'archive excludes and complete release payload');
    check($contract['files']['server/a.php'] === hash_file('sha256', $source . '/server/a.php'), 'payload content hash');
    put($source . '/server/a.php', 'dirty');
    [$code] = runTool($build, $root); check($code !== 0, 'reject tracked dirty source');
    runTool(['git', 'checkout', '--', 'server/a.php'], $source);
    put($source . '/server/untracked.php', 'untracked');
    [$code] = runTool($build, $root); check($code !== 0, 'reject untracked payload'); unlink($source . '/server/untracked.php');
    $invalid = $build; $invalid[3] = '0.2.0-rc.1'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject prerelease contract version');
    $invalid = $build; $invalid[3] = '0.3.0'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject mismatch between requested version and committed app.version');
    $invalid = $build; $invalid[5] = $source . '/system-update.json'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject source-contained contract output');
    $invalid = array_slice($build, 0, -1); [$code] = runTool($invalid, $root); check($code !== 0, 'require explicit no-skeleton-change declaration');
    runTool(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'tag', '0.2.0'], $source);
    put($source . '/server/a.php', 'next-commit'); runTool(['git', 'add', '.'], $source);
    runTool(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'next'], $source);
    [$code] = runTool($build, $root); check($code !== 0, 'reject existing version tag pointing to a different commit');
    $collection = $root . '/collection'; mkdir($collection);
    put($collection . '/sand-core/composer.json', '{"name":"supdger/sand-core"}');
    put($collection . '/sand-core/server/plugin/sandadmin/config/app.php', "<?php return ['version'=>'0.1.9'];");
    put($collection . '/sand-core/sandadmin-artd/src/a.ts', 'nested');
    foreach ([['git', 'init', '-q'], ['git', 'add', '.'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'nested']] as $command) runTool($command, $collection);
    [$code, $text] = runTool([PHP_BINARY, $tools . '/build-system-update-contract.php', $collection . '/sand-core', '0.1.9', '0.1.0', $root . '/nested.json', '--no-schema-change', '--no-skeleton-change'], $root);
    check($code === 0, 'nested source unit in collection repository: ' . $text);
    symlink('a.ts', $collection . '/sand-core/sandadmin-artd/src/link.ts');
    runTool(['git', 'add', '.'], $collection); runTool(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'symlink'], $collection);
    [$code] = runTool([PHP_BINARY, $tools . '/build-system-update-contract.php', $collection . '/sand-core', '0.1.9', '0.1.0', $root . '/nested.json', '--no-schema-change', '--no-skeleton-change'], $root);
    check($code !== 0, 'reject committed payload symlink');
    foreach (['../escape', '/absolute', 'a//b', 'a/./b', 'a/../b', 'a\\b', "a\0b", 'a:b'] as $unsafe) {
        try { SandSystemUpdateReleaseTools::relative($unsafe); check(false, 'unsafe relative ' . $unsafe); }
        catch (RuntimeException) { check(true, 'reject traversal or invalid relative path'); }
    }
    $frontend = $root . '/frontend'; $static = $root . '/static';
    put($frontend . '/dist/index.html', '<html></html>'); put($frontend . '/dist/assets/main.js', 'main');
    put($static . '/index.html', '<html></html>'); put($static . '/assets/main.js', 'main');
    $prepare = [PHP_BINARY, $tools . '/prepare-system-update.php', '--frontend=' . $frontend, '--static=' . $static];
    [$code, $text] = runTool($prepare, $root); check($code === 0, 'prepare exact current static baseline: ' . $text);
    $manifest = json_decode(file_get_contents($static . '/' . SandSystemUpdateReleaseTools::STATIC_MANIFEST), true, 128, JSON_THROW_ON_ERROR);
    check($manifest['files'] === SandSystemUpdateReleaseTools::tree($frontend . '/dist'), 'static baseline exact file hashes');
    [$code] = runTool($prepare, $root); check($code === 0, 'idempotent prepare with existing baseline');
    put($static . '/custom.txt', 'custom'); [$code] = runTool($prepare, $root); check($code !== 0, 'reject unknown static files'); unlink($static . '/custom.txt');
    put($static . '/index.html', 'edited'); [$code] = runTool($prepare, $root); check($code !== 0, 'reject edited static content'); put($static . '/index.html', '<html></html>');
    unlink($static . '/assets/main.js'); [$code] = runTool($prepare, $root); check($code !== 0, 'reject missing static asset'); put($static . '/assets/main.js', 'main');
    symlink($static, $root . '/linked'); $invalid = $prepare; $invalid[3] = '--static=' . $root . '/linked'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject symlink static root');
    symlink($static . '/index.html', $static . '/symlink.html'); [$code] = runTool($prepare, $root); check($code !== 0, 'reject symlink static payload'); unlink($static . '/symlink.html');
    unlink($static . '/' . SandSystemUpdateReleaseTools::STATIC_MANIFEST);
    symlink($root . '/target-manifest.json', $static . '/' . SandSystemUpdateReleaseTools::STATIC_MANIFEST); [$code] = runTool($prepare, $root); check($code !== 0, 'reject dangling manifest symlink');
    $invalid = $prepare; $invalid[3] = '--static=' . $frontend . '/dist'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject static inside frontend');
    $invalid = $prepare; $invalid[3] = '--static=' . $root; [$code] = runTool($invalid, $root); check($code !== 0, 'reject static parent of frontend');
    put($root . '/server/config/index.html', '<html></html>'); $invalid = $prepare; $invalid[3] = '--static=' . $root . '/server/config'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject static host config path');
    $invalid = $prepare; $invalid[3] = '--static=/'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject filesystem root');
    $invalid = $prepare; $invalid[3] = '--static=' . $static . '/../static'; [$code] = runTool($invalid, $root); check($code !== 0, 'reject absolute directory traversal');
    fwrite(STDOUT, sprintf("System update release tools: %d checks passed\n", $passed));
} finally { removeFixture($root); }
