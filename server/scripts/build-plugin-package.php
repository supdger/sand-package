#!/usr/bin/env php
<?php

declare(strict_types=1);

use plugin\sandpackage\app\logic\RepositoryLogic;

const PACKAGE_MAX_FILES = 2048;
const PACKAGE_MAX_UNCOMPRESSED_BYTES = 67108864;
const PACKAGE_MAX_ZIP_BYTES = 5242880;
const PACKAGE_MAX_RELEASE_BUILD_CONTRACT_BYTES = 1048576;
const PACKAGE_REQUIRED_ROOT_FILES = [
    'info.ini',
    'config.json',
    'install.sql',
    'update.sql',
    'uninstall.sql',
    'README.md',
    'LICENSE',
];

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function absolutePath(string $path): string
{
    if ($path === '') {
        fail('路径不能为空');
    }
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        ? $path
        : getcwd() . DIRECTORY_SEPARATOR . $path;
}

function assertNoSymlinkComponents(string $path): void
{
    $absolute = absolutePath($path);
    $parts = explode(DIRECTORY_SEPARATOR, $absolute);
    $current = DIRECTORY_SEPARATOR;
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if ($part === '.' || $part === '..') {
            fail('路径不能包含 . 或 .. 段');
        }
        $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
        if (file_exists($current) || is_link($current)) {
            if (is_link($current)) {
                fail('路径不能包含符号链接：' . $current);
            }
        }
    }
}

function decodePhpString(string $literal): string
{
    $quote = $literal[0] ?? '';
    if (($quote !== "'" && $quote !== '"') || substr($literal, -1) !== $quote) {
        fail('config/app.php 的 version 必须是静态字符串');
    }
    $value = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
    }
    if (str_contains($value, '$')) {
        fail('config/app.php 的 version 不能包含变量插值');
    }
    return stripcslashes($value);
}

function nextMeaningfulToken(array $tokens, int &$index): mixed
{
    $count = count($tokens);
    while (++$index < $count) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $token;
    }
    return null;
}

function staticConfigVersion(string $path): string
{
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        fail('无法读取插件后端 config/app.php');
    }
    $tokens = token_get_all($raw);
    $returnIndex = null;
    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_RETURN) {
            $returnIndex = $index;
            break;
        }
    }
    if ($returnIndex === null) {
        fail('config/app.php 必须静态 return 配置数组');
    }

    $index = $returnIndex;
    $token = nextMeaningfulToken($tokens, $index);
    if (is_array($token) && $token[0] === T_ARRAY) {
        $token = nextMeaningfulToken($tokens, $index);
        if ($token !== '(') {
            fail('config/app.php 必须静态 return 配置数组');
        }
    } elseif ($token !== '[') {
        fail('config/app.php 必须静态 return 配置数组');
    }

    $depth = 1;
    $version = null;
    $count = count($tokens);
    for ($index++; $index < $count && $depth > 0; $index++) {
        $token = $tokens[$index];
        if ($token === '[' || $token === '(') {
            $depth++;
            continue;
        }
        if ($token === ']' || $token === ')') {
            $depth--;
            continue;
        }
        if ($depth !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING
            || decodePhpString($token[1]) !== 'version') {
            continue;
        }
        $arrow = nextMeaningfulToken($tokens, $index);
        if (!is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW) {
            continue;
        }
        $value = nextMeaningfulToken($tokens, $index);
        if (!is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
            fail('config/app.php 的 version 必须是静态字符串');
        }
        if ($version !== null) {
            fail('config/app.php 不能声明多个顶层 version');
        }
        $version = decodePhpString($value[1]);
    }
    if ($version === null) {
        fail('config/app.php 缺少顶层静态 version');
    }
    return $version;
}

function hasForbiddenPayloadComponent(string $relative, bool $allowRuntimeVendor = false): bool
{
    $parts = explode('/', $relative);
    if ($allowRuntimeVendor && ($parts[0] ?? null) === 'vendor') {
        array_shift($parts);
    }
    foreach ($parts as $part) {
        if (in_array($part, ['.git', 'node_modules', 'vendor'], true)
            || $part === '.env' || str_starts_with($part, '.env.')) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<int,array{source:string,archive:string,size:int}> $files
 */
function addPayloadTree(string $root, string $archiveRoot, array &$files, bool $allowRuntimeVendor = false): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $source = $item->getPathname();
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
        $archive = $archiveRoot . '/' . $relative;
        if ($item->isLink() || is_link($source)) {
            fail('载荷不能包含符号链接：' . $archive);
        }
        if (hasForbiddenPayloadComponent($relative, $allowRuntimeVendor)) {
            fail('载荷包含禁止目录或文件：' . $archive);
        }
        if ($item->isDir()) {
            continue;
        }
        if (!$item->isFile()) {
            fail('载荷只能包含普通文件：' . $archive);
        }
        $size = $item->getSize();
        if ($size < 0) {
            fail('无法读取载荷文件大小：' . $archive);
        }
        $files[] = ['source' => $source, 'archive' => $archive, 'size' => $size];
    }
}

/**
 * @return array{key:string,file_count:int,tree_sha256:string}|array{key:null}
 */
function parseReleaseBuildContract(string $raw, string $app): array
{
    $contract = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($contract)
        || !is_string($contract['schema'] ?? null) || trim($contract['schema']) === ''
        || ($contract['kind'] ?? null) !== 'reviewed-runtime-payload-inputs'
        || !is_array($contract['generated_payloads'] ?? null)) {
        fail('release-build-contract.json 格式无效');
    }
    $key = 'plugin/' . $app . '/vendor';
    if (!array_key_exists($key, $contract['generated_payloads'])) {
        return ['key' => null];
    }
    $declaration = $contract['generated_payloads'][$key];
    if (!is_array($declaration)) {
        fail('runtime vendor 声明格式无效');
    }
    $fields = array_keys($declaration);
    sort($fields, SORT_STRING);
    if ($fields !== ['file_count', 'tree_sha256']
        || !is_int($declaration['file_count']) || $declaration['file_count'] < 1
        || $declaration['file_count'] > PACKAGE_MAX_FILES
        || !is_string($declaration['tree_sha256'])
        || preg_match('/^[0-9a-f]{64}$/D', $declaration['tree_sha256']) !== 1) {
        fail('runtime vendor 声明格式无效');
    }
    return [
        'key' => $key,
        'file_count' => $declaration['file_count'],
        'tree_sha256' => $declaration['tree_sha256'],
    ];
}

/**
 * @return array{path:string,key:string,file_count:int,tree_sha256:string}|array{path:string,key:null}|null
 */
function releaseBuildContract(string $source, string $app): ?array
{
    $path = $source . '/release-build-contract.json';
    if (!file_exists($path) && !is_link($path)) {
        return null;
    }
    if (!is_file($path) || is_link($path)) {
        fail('release-build-contract.json 必须是普通文件');
    }
    $size = filesize($path);
    if (!is_int($size) || $size > PACKAGE_MAX_RELEASE_BUILD_CONTRACT_BYTES) {
        fail('release-build-contract.json 超过 1 MiB');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        fail('无法读取 release-build-contract.json');
    }
    return ['path' => $path] + parseReleaseBuildContract($raw, $app);
}

/** @param array<string,string> $map */
function payloadTreeSha256(array $map): string
{
    ksort($map, SORT_STRING);
    return hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param array<int,array{source:string,archive:string,size:int}> $files
 * @return array<string,string>
 */
function sourcePayloadMap(array $files, string $archiveRoot): array
{
    $prefix = rtrim($archiveRoot, '/') . '/';
    $map = [];
    foreach ($files as $file) {
        if (!str_starts_with($file['archive'], $prefix)) {
            continue;
        }
        $relative = substr($file['archive'], strlen($prefix));
        $hash = hash_file('sha256', $file['source']);
        if ($relative === '' || !is_string($hash) || isset($map[$relative])) {
            fail('runtime vendor 文件映射无效');
        }
        $map[$relative] = $hash;
    }
    ksort($map, SORT_STRING);
    return $map;
}

/** @param array{file_count:int,tree_sha256:string} $expected */
function assertPayloadMap(array $map, array $expected, string $phase): void
{
    if (count($map) !== $expected['file_count']
        || !hash_equals($expected['tree_sha256'], payloadTreeSha256($map))) {
        fail('runtime vendor 与 release-build-contract.json 不一致（' . $phase . '）');
    }
}

/**
 * Re-read the closed archive so source changes during ZIP creation cannot bypass the reviewed payload digest.
 * @param array{file_count:int,tree_sha256:string}|null $runtimeVendor
 */
function verifyClosedZip(
    string $path,
    string $app,
    string $runtimeVendorRoot,
    bool $contractExpected,
    ?array $runtimeVendor,
): void
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fail('无法重新读取生成的 ZIP 安装包');
    }
    $map = [];
    $bytes = 0;
    $prefix = rtrim($runtimeVendorRoot, '/') . '/';
    try {
        if ($zip->numFiles > PACKAGE_MAX_FILES) {
            fail('安装包文件数量超过 2048');
        }
        $contractStat = $zip->statName('release-build-contract.json');
        if ($contractExpected) {
            if (!is_array($contractStat) || !is_int($contractStat['size'] ?? null)
                || $contractStat['size'] > PACKAGE_MAX_RELEASE_BUILD_CONTRACT_BYTES) {
                fail('ZIP 中的 release-build-contract.json 无效');
            }
            $contractRaw = $zip->getFromName('release-build-contract.json');
            if (!is_string($contractRaw)) {
                fail('ZIP 中的 release-build-contract.json 无法读取');
            }
            $zipContract = parseReleaseBuildContract($contractRaw, $app);
            $zipRuntimeVendor = $zipContract['key'] === null ? null : [
                'file_count' => $zipContract['file_count'],
                'tree_sha256' => $zipContract['tree_sha256'],
            ];
            if (($runtimeVendor === null) !== ($zipRuntimeVendor === null)
                || ($runtimeVendor !== null && ($runtimeVendor['file_count'] !== $zipRuntimeVendor['file_count']
                    || !hash_equals($runtimeVendor['tree_sha256'], $zipRuntimeVendor['tree_sha256'])))) {
                fail('ZIP 中的 release-build-contract.json 在打包期间发生变化');
            }
        } elseif ($contractStat !== false) {
            fail('ZIP 包含未检查的 release-build-contract.json');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !is_string($stat['name'] ?? null) || !is_int($stat['size'] ?? null)) {
                fail('生成的 ZIP 条目不可读取');
            }
            $bytes += $stat['size'];
            if ($bytes > PACKAGE_MAX_UNCOMPRESSED_BYTES) {
                fail('安装包解压大小超过 64 MiB');
            }
            $name = $stat['name'];
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            $stream = $zip->getStream($name);
            if ($relative === '' || !is_resource($stream) || isset($map[$relative])) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                fail('ZIP 中的 runtime vendor 文件映射无效');
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);
            $map[$relative] = hash_final($context);
        }
    } finally {
        $zip->close();
    }
    ksort($map, SORT_STRING);
    if ($runtimeVendor === null) {
        if ($map !== []) {
            fail('ZIP 包含未经 release-build-contract.json 声明的 runtime vendor');
        }
        return;
    }
    assertPayloadMap($map, $runtimeVendor, 'ZIP 复核');
}

function validateVersion(string $value, string $field): string
{
    if (!preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $value)) {
        fail($field . ' 不是受支持的版本号');
    }
    return $value;
}

function main(array $argv): void
{
    if (count($argv) < 5 || count($argv) > 6) {
        fail('用法：php server/scripts/build-plugin-package.php <源码目录> <输出目录> <Release tag> <host_min> [host_max]');
    }
    [, $sourceInput, $outputInput, $tag, $hostMin] = $argv;
    $hostMax = $argv[5] ?? null;

    assertNoSymlinkComponents($sourceInput);
    assertNoSymlinkComponents($outputInput);
    $source = realpath($sourceInput);
    $output = realpath($outputInput);
    if ($source === false || !is_dir($source)) {
        fail('插件源码目录不存在');
    }
    if ($output === false || !is_dir($output) || !is_writable($output)) {
        fail('输出目录不存在或不可写');
    }

    foreach (PACKAGE_REQUIRED_ROOT_FILES as $name) {
        $path = $source . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || is_link($path)) {
            fail('插件源码缺少根部必备文件：' . $name);
        }
    }
    $info = parse_ini_file($source . '/info.ini', false, INI_SCANNER_RAW);
    if (!is_array($info)) {
        fail('info.ini 格式无效');
    }
    foreach (['app', 'title', 'about', 'author', 'version'] as $field) {
        if (!isset($info[$field]) || !is_string($info[$field]) || trim($info[$field]) === '') {
            fail('info.ini 缺少字段：' . $field);
        }
    }
    $app = $info['app'];
    $version = validateVersion($info['version'], '插件版本');
    if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)) {
        fail('info.ini 的 app 标识无效');
    }
    $config = json_decode((string) file_get_contents($source . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        fail('config.json 必须是 JSON 对象');
    }
    if (!empty($config['sand_platform'])) {
        fail('config.json 包含非空 sand_platform，依赖旧 Sand 平台安装扩展，不能生成普通发布包');
    }

    $backend = $source . '/plugin/' . $app;
    assertNoSymlinkComponents($backend);
    if (!is_dir($backend) || is_link($backend)) {
        fail('插件后端载荷目录缺失：plugin/' . $app);
    }
    $backendVersion = staticConfigVersion($backend . '/config/app.php');
    if (!hash_equals($version, $backendVersion)) {
        fail('info.ini 与 plugin/' . $app . '/config/app.php 的版本不一致');
    }
    $contract = releaseBuildContract($source, $app);
    $runtimeVendorPath = $backend . '/vendor';
    $runtimeVendorExists = file_exists($runtimeVendorPath) || is_link($runtimeVendorPath);
    $runtimeVendorDeclared = $contract !== null && $contract['key'] !== null;
    if ($runtimeVendorExists && !$runtimeVendorDeclared) {
        fail('runtime vendor 缺少 release-build-contract.json 精确声明');
    }
    if ($runtimeVendorDeclared && !$runtimeVendorExists) {
        fail('release-build-contract.json 声明的 runtime vendor 不存在');
    }

    validateVersion($hostMin, 'host_min');
    if ($hostMax !== null) {
        validateVersion($hostMax, 'host_max');
        if (version_compare($hostMax, $hostMin, '<')) {
            fail('host_max 不能低于 host_min');
        }
    }
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $tag) || str_contains($tag, '..')) {
        fail('Release tag 无效');
    }

    $files = [];
    foreach (PACKAGE_REQUIRED_ROOT_FILES as $name) {
        $files[] = ['source' => $source . '/' . $name, 'archive' => $name, 'size' => filesize($source . '/' . $name)];
    }
    if (is_file($source . '/NOTICE')) {
        if (is_link($source . '/NOTICE')) {
            fail('NOTICE 不能是符号链接');
        }
        $files[] = ['source' => $source . '/NOTICE', 'archive' => 'NOTICE', 'size' => filesize($source . '/NOTICE')];
    }
    if ($contract !== null) {
        $files[] = [
            'source' => $contract['path'],
            'archive' => 'release-build-contract.json',
            'size' => filesize($contract['path']),
        ];
    }
    addPayloadTree($backend, 'plugin/' . $app, $files, $runtimeVendorDeclared);
    $runtimeVendor = null;
    if ($runtimeVendorDeclared) {
        $runtimeVendor = [
            'file_count' => $contract['file_count'],
            'tree_sha256' => $contract['tree_sha256'],
        ];
        $sourceMap = sourcePayloadMap($files, 'plugin/' . $app . '/vendor');
        assertPayloadMap($sourceMap, $runtimeVendor, '源码检查');
    }
    $frontend = $source . '/sandadmin-artd/src/views/plugin/' . $app;
    if (file_exists($frontend) || is_link($frontend)) {
        assertNoSymlinkComponents($frontend);
        if (!is_dir($frontend) || is_link($frontend)) {
            fail('管理端载荷路径必须是普通目录');
        }
        addPayloadTree($frontend, 'sandadmin-artd/src/views/plugin/' . $app, $files);
    }
    if (count($files) > PACKAGE_MAX_FILES) {
        fail('安装包文件数量超过 2048');
    }
    $bytes = array_sum(array_column($files, 'size'));
    if ($bytes > PACKAGE_MAX_UNCOMPRESSED_BYTES) {
        fail('安装包解压大小超过 64 MiB');
    }

    $asset = $app . '-' . $version . '.zip';
    $catalogName = $app . '-' . $version . '.catalog.json';
    $zipTarget = $output . '/' . $asset;
    $catalogTarget = $output . '/' . $catalogName;
    if (file_exists($zipTarget) || is_link($zipTarget) || file_exists($catalogTarget) || is_link($catalogTarget)) {
        fail('输出文件已存在，拒绝覆盖');
    }

    $zipTemp = tempnam($output, '.sandpackage-zip-');
    $catalogTemp = tempnam($output, '.sandpackage-catalog-');
    if ($zipTemp === false || $catalogTemp === false) {
        if (is_string($zipTemp)) {
            @unlink($zipTemp);
        }
        if (is_string($catalogTemp)) {
            @unlink($catalogTemp);
        }
        fail('无法创建输出临时文件');
    }
    $created = [];
    try {
        $zip = new ZipArchive();
        if ($zip->open($zipTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fail('无法创建 ZIP 安装包');
        }
        try {
            foreach ($files as $file) {
                if (is_link($file['source']) || !$zip->addFile($file['source'], $file['archive'])) {
                    fail('无法写入 ZIP：' . $file['archive']);
                }
            }
        } finally {
            $zip->close();
        }
        verifyClosedZip(
            $zipTemp,
            $app,
            'plugin/' . $app . '/vendor',
            $contract !== null,
            $runtimeVendor,
        );
        $zipBytes = filesize($zipTemp);
        if ($zipBytes === false || $zipBytes > PACKAGE_MAX_ZIP_BYTES) {
            fail('ZIP 安装包超过 5 MiB');
        }
        $sha256 = hash_file('sha256', $zipTemp);
        if (!is_string($sha256)) {
            fail('无法计算 ZIP SHA-256');
        }
        $release = [
            'version' => $version,
            'tag' => $tag,
            'asset' => $asset,
            'sha256' => $sha256,
            'host_min' => $hostMin,
            'notes' => '',
        ];
        if ($hostMax !== null) {
            $release['host_max'] = $hostMax;
        }
        $plugin = [
            'app' => $app,
            'title' => $info['title'],
            'about' => $info['about'],
            'author' => $info['author'],
            'versions' => [$release],
        ];
        $catalog = ['schema' => 1, 'plugins' => [$plugin]];
        RepositoryLogic::parseCatalog(json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $catalogJson = json_encode($plugin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($catalogTemp, $catalogJson) !== strlen($catalogJson)) {
            fail('无法写入目录条目');
        }
        if (!link($zipTemp, $zipTarget)) {
            fail('ZIP 输出已存在或无法创建，拒绝覆盖');
        }
        $created[] = $zipTarget;
        if (!link($catalogTemp, $catalogTarget)) {
            fail('目录条目输出已存在或无法创建，拒绝覆盖');
        }
        $created[] = $catalogTarget;
        echo $zipTarget . PHP_EOL;
        echo $catalogTarget . PHP_EOL;
        echo $sha256 . PHP_EOL;
    } catch (Throwable $error) {
        foreach ($created as $path) {
            @unlink($path);
        }
        throw $error;
    } finally {
        @unlink($zipTemp);
        @unlink($catalogTemp);
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "server/vendor/autoload.php 不存在，请先在 server/ 安装依赖\n");
    exit(1);
}
require $autoload;

try {
    main($argv);
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
