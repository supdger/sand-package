<?php

declare(strict_types=1);

use plugin\sandpackage\app\logic\RepositoryLogic;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function packageBuildExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
}

function packageBuildDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        packageBuildDelete($item->getPathname());
    }
    rmdir($path);
}

function packageBuildWrite(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('fixture directory creation failed');
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('fixture write failed');
    }
}

function packageBuildFixture(string $root, string $app, string $version, array $config = []): string
{
    $source = $root . '/source-' . bin2hex(random_bytes(4));
    mkdir($source, 0700, true);
    packageBuildWrite($source . '/info.ini', implode("\n", [
        'app = ' . $app,
        'title = Repository package fixture',
        'about = Independent package build behavior test',
        'author = SandAdmin',
        'version = ' . $version,
        '',
    ]));
    packageBuildWrite($source . '/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    packageBuildWrite($source . '/install.sql', "SELECT 'install';\n");
    packageBuildWrite($source . '/update.sql', "SELECT 'update';\n");
    packageBuildWrite($source . '/uninstall.sql', "SELECT 'uninstall';\n");
    packageBuildWrite($source . '/README.md', "# Fixture\n");
    packageBuildWrite($source . '/LICENSE', "MIT\n");
    packageBuildWrite($source . '/NOTICE', "Fixture notice\n");
    packageBuildWrite($source . '/plugin/' . $app . '/config/app.php', "<?php\n\nreturn [\n    'version' => '" . $version . "',\n];\n");
    packageBuildWrite($source . '/plugin/' . $app . '/app/functions.php', "<?php\n");
    packageBuildWrite($source . '/sandadmin-artd/src/views/plugin/' . $app . '/index.vue', "<template>fixture</template>\n");
    return $source;
}

/** @return array<string,string> */
function packageBuildPayloadMap(string $directory): array
{
    $map = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
        $map[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($map, SORT_STRING);
    return $map;
}

function packageBuildAddRuntimeVendor(string $source, string $app): void
{
    packageBuildWrite($source . '/plugin/' . $app . '/vendor/autoload.php', "<?php return true;\n");
    packageBuildWrite($source . '/plugin/' . $app . '/vendor/acme/runtime/src/Library.php', "<?php\nnamespace Acme\\Runtime;\nfinal class Library {}\n");
}

function packageBuildWriteContract(
    string $source,
    string $app,
    ?int $fileCount = null,
    ?string $treeSha256 = null,
    array $otherGeneratedPayloads = [],
): void {
    $map = packageBuildPayloadMap($source . '/plugin/' . $app . '/vendor');
    $contract = [
        'schema' => 'fixture.release-build-contract/v1',
        'kind' => 'reviewed-runtime-payload-inputs',
        'generated_payloads' => array_merge([
            'plugin/' . $app . '/vendor' => [
                'file_count' => $fileCount ?? count($map),
                'tree_sha256' => $treeSha256 ?? hash(
                    'sha256',
                    json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ),
            ],
        ], $otherGeneratedPayloads),
    ];
    packageBuildWrite(
        $source . '/release-build-contract.json',
        json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
}

/** @return array{code:int,stdout:string,stderr:string} */
function packageBuildRun(array $arguments): array
{
    $script = dirname(__DIR__, 2) . '/scripts/build-plugin-package.php';
    $command = array_merge([PHP_BINARY, $script], $arguments);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start package builder');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function packageBuildExpectRejected(string $root, string $source, string $message, string $errorFragment): void
{
    $output = $root . '/rejected-output-' . bin2hex(random_bytes(4));
    mkdir($output, 0700);
    $result = packageBuildRun([$source, $output, 'v1.0.0', '6.0.0']);
    packageBuildExpect(
        $result['code'] !== 0 && str_contains($result['stderr'], $errorFragment),
        $message,
    );
    packageBuildExpect(
        iterator_count(new FilesystemIterator($output)) === 0,
        $message . ' and cleans failed outputs',
    );
}

$root = sys_get_temp_dir() . '/sandpackage-build-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);

try {
    $app = 'repository-fixture';
    $version = '1.2.3';
    $source = packageBuildFixture($root, $app, $version);
    $executionMarker = $root . '/plugin-php-was-executed';
    packageBuildWrite(
        $source . '/plugin/' . $app . '/config/app.php',
        "<?php\nfile_put_contents(" . var_export($executionMarker, true) . ", 'executed');\nreturn ['version' => '" . $version . "'];\n",
    );
    $output = $root . '/output';
    mkdir($output, 0700);
    $result = packageBuildRun([$source, $output, 'v1.2.3', '6.0.0', '6.9.9']);
    packageBuildExpect(
        $result['code'] === 0,
        'builds a valid plugin package without host bootstrap or database access'
            . ($result['stderr'] === '' ? '' : ': ' . trim($result['stderr'])),
    );
    packageBuildExpect(!file_exists($executionMarker), 'reads backend version statically without executing plugin PHP');

    $zipPath = $output . '/' . $app . '-' . $version . '.zip';
    $catalogPath = $output . '/' . $app . '-' . $version . '.catalog.json';
    packageBuildExpect(is_file($zipPath) && is_file($catalogPath), 'writes the ZIP and standalone catalog entry');
    $entry = json_decode((string) file_get_contents($catalogPath), true, 32, JSON_THROW_ON_ERROR);
    $catalog = RepositoryLogic::parseCatalog(json_encode(['schema' => 1, 'plugins' => [$entry]], JSON_THROW_ON_ERROR));
    packageBuildExpect($catalog['plugins'][0]['versions'][0]['sha256'] === hash_file('sha256', $zipPath), 'catalog SHA-256 matches the real ZIP bytes');

    $zip = new ZipArchive();
    packageBuildExpect($zip->open($zipPath) === true, 'opens the generated ZIP');
    foreach (['info.ini', 'config.json', 'install.sql', 'update.sql', 'uninstall.sql', 'README.md', 'LICENSE', 'NOTICE',
        'plugin/' . $app . '/config/app.php', 'sandadmin-artd/src/views/plugin/' . $app . '/index.vue'] as $name) {
        packageBuildExpect($zip->locateName($name) !== false, 'ZIP contains ' . $name);
    }
    $zip->close();

    $mismatch = packageBuildFixture($root, 'version-mismatch', '2.0.0');
    packageBuildWrite($mismatch . '/plugin/version-mismatch/config/app.php', "<?php return ['version' => '2.0.1'];\n");
    $mismatchOutput = $root . '/mismatch-output';
    mkdir($mismatchOutput, 0700);
    $result = packageBuildRun([$mismatch, $mismatchOutput, 'v2.0.0', '6.0.0']);
    packageBuildExpect($result['code'] !== 0 && str_contains($result['stderr'], '版本不一致'), 'rejects mismatched info.ini and backend versions');
    packageBuildExpect(iterator_count(new FilesystemIterator($mismatchOutput)) === 0, 'cleans its failed version-mismatch outputs');

    $legacy = packageBuildFixture($root, 'legacy-extension', '1.0.0', [
        'sand_platform' => ['required_plugins' => [['app' => 'sand-iam', 'version' => '1.0.0']]],
    ]);
    $legacyOutput = $root . '/legacy-output';
    mkdir($legacyOutput, 0700);
    $result = packageBuildRun([$legacy, $legacyOutput, 'v1.0.0', '6.0.0']);
    packageBuildExpect($result['code'] !== 0 && str_contains($result['stderr'], 'sand_platform'), 'rejects non-empty legacy sand_platform dependencies instead of hiding them');

    $collision = packageBuildFixture($root, 'existing-output', '1.0.0');
    $collisionOutput = $root . '/collision-output';
    mkdir($collisionOutput, 0700);
    $existingZip = $collisionOutput . '/existing-output-1.0.0.zip';
    packageBuildWrite($existingZip, 'keep-existing');
    $result = packageBuildRun([$collision, $collisionOutput, 'v1.0.0', '6.0.0']);
    packageBuildExpect($result['code'] !== 0 && file_get_contents($existingZip) === 'keep-existing', 'refuses to overwrite or delete an existing output');
    packageBuildExpect(!is_file($collisionOutput . '/existing-output-1.0.0.catalog.json'), 'does not leave a partial catalog beside an existing output');

    $realSource = packageBuildFixture($root, 'symlink-source', '1.0.0');
    $sourceLink = $root . '/source-link';
    symlink($realSource, $sourceLink);
    $linkOutput = $root . '/link-output';
    mkdir($linkOutput, 0700);
    $result = packageBuildRun([$sourceLink, $linkOutput, 'v1.0.0', '6.0.0']);
    packageBuildExpect($result['code'] !== 0 && str_contains($result['stderr'], '符号链接'), 'rejects a source path with a symlink parent');

    $runtimeApp = 'reviewed-runtime';
    $runtimeSource = packageBuildFixture($root, $runtimeApp, '1.0.0');
    packageBuildAddRuntimeVendor($runtimeSource, $runtimeApp);
    packageBuildWrite($runtimeSource . '/sdk/typescript/dist/not-packaged.js', "export const ignored = true;\n");
    packageBuildWriteContract($runtimeSource, $runtimeApp, null, null, [
        'sdk/typescript/dist' => ['file_count' => 1, 'tree_sha256' => str_repeat('f', 64)],
    ]);
    $runtimeOutput = $root . '/runtime-output';
    mkdir($runtimeOutput, 0700);
    $result = packageBuildRun([$runtimeSource, $runtimeOutput, 'v1.0.0', '6.0.0']);
    packageBuildExpect($result['code'] === 0, 'builds a reviewed runtime vendor payload');
    $runtimeZip = new ZipArchive();
    packageBuildExpect($runtimeZip->open($runtimeOutput . '/' . $runtimeApp . '-1.0.0.zip') === true, 'opens the reviewed runtime vendor ZIP');
    packageBuildExpect($runtimeZip->locateName('release-build-contract.json') !== false, 'includes the release build contract for traceability');
    packageBuildExpect($runtimeZip->locateName('plugin/' . $runtimeApp . '/vendor/autoload.php') !== false, 'includes declared runtime vendor files');
    packageBuildExpect($runtimeZip->locateName('plugin/' . $runtimeApp . '/vendor/acme/runtime/src/Library.php') !== false, 'includes the declared runtime vendor library');
    packageBuildExpect($runtimeZip->locateName('sdk/typescript/dist/not-packaged.js') === false, 'does not admit unrelated generated payload declarations');
    $runtimeZip->close();

    $undeclared = packageBuildFixture($root, 'undeclared-vendor', '1.0.0');
    packageBuildAddRuntimeVendor($undeclared, 'undeclared-vendor');
    packageBuildExpectRejected($root, $undeclared, 'rejects runtime vendor without an exact declaration', '缺少 release-build-contract.json');

    $wrongDeclaration = packageBuildFixture($root, 'wrong-declaration', '1.0.0');
    packageBuildAddRuntimeVendor($wrongDeclaration, 'wrong-declaration');
    packageBuildWrite(
        $wrongDeclaration . '/release-build-contract.json',
        json_encode([
            'schema' => 'fixture.release-build-contract/v1',
            'kind' => 'reviewed-runtime-payload-inputs',
            'generated_payloads' => [
                'plugin/some-other-app/vendor' => ['file_count' => 2, 'tree_sha256' => str_repeat('0', 64)],
            ],
        ], JSON_THROW_ON_ERROR),
    );
    packageBuildExpectRejected($root, $wrongDeclaration, 'rejects runtime vendor without the exact app-scoped key', '精确声明');

    foreach ([
        ['', 'reviewed-runtime-payload-inputs', 'a non-empty contract schema'],
        ['fixture.release-build-contract/v1', 'unreviewed-payload', 'the exact reviewed payload kind'],
    ] as [$schema, $kind, $label]) {
        $invalidApp = 'invalid-contract-' . bin2hex(random_bytes(2));
        $invalidContract = packageBuildFixture($root, $invalidApp, '1.0.0');
        packageBuildAddRuntimeVendor($invalidContract, $invalidApp);
        packageBuildWrite(
            $invalidContract . '/release-build-contract.json',
            json_encode([
                'schema' => $schema,
                'kind' => $kind,
                'generated_payloads' => [],
            ], JSON_THROW_ON_ERROR),
        );
        packageBuildExpectRejected($root, $invalidContract, 'requires ' . $label, '格式无效');
    }

    foreach ([
        [['file_count' => '2', 'tree_sha256' => str_repeat('0', 64)], 'an integer runtime vendor file count'],
        [['file_count' => 2049, 'tree_sha256' => str_repeat('0', 64)], 'a runtime vendor declaration bounded to 2048 files'],
        [['file_count' => 2, 'tree_sha256' => str_repeat('G', 64)], 'a lowercase SHA-256 runtime vendor digest'],
    ] as [$declaration, $label]) {
        $invalidApp = 'invalid-declaration-' . bin2hex(random_bytes(2));
        $invalidDeclaration = packageBuildFixture($root, $invalidApp, '1.0.0');
        packageBuildAddRuntimeVendor($invalidDeclaration, $invalidApp);
        packageBuildWrite(
            $invalidDeclaration . '/release-build-contract.json',
            json_encode([
                'schema' => 'fixture.release-build-contract/v1',
                'kind' => 'reviewed-runtime-payload-inputs',
                'generated_payloads' => ['plugin/' . $invalidApp . '/vendor' => $declaration],
            ], JSON_THROW_ON_ERROR),
        );
        packageBuildExpectRejected($root, $invalidDeclaration, 'requires ' . $label, '声明格式无效');
    }

    $oversizedContract = packageBuildFixture($root, 'oversized-contract', '1.0.0');
    packageBuildAddRuntimeVendor($oversizedContract, 'oversized-contract');
    packageBuildWrite(
        $oversizedContract . '/release-build-contract.json',
        json_encode([
            'schema' => 'fixture.release-build-contract/v1',
            'kind' => 'reviewed-runtime-payload-inputs',
            'generated_payloads' => [],
            'padding' => str_repeat('x', 1048576),
        ], JSON_THROW_ON_ERROR),
    );
    packageBuildExpectRejected($root, $oversizedContract, 'rejects an oversized release build contract', '超过 1 MiB');

    $tampered = packageBuildFixture($root, 'tampered-vendor', '1.0.0');
    packageBuildAddRuntimeVendor($tampered, 'tampered-vendor');
    packageBuildWriteContract($tampered, 'tampered-vendor');
    packageBuildWrite($tampered . '/plugin/tampered-vendor/vendor/autoload.php', "<?php return false;\n");
    packageBuildExpectRejected($root, $tampered, 'rejects a runtime vendor file changed after contract generation', '不一致');

    $extra = packageBuildFixture($root, 'extra-vendor-file', '1.0.0');
    packageBuildAddRuntimeVendor($extra, 'extra-vendor-file');
    packageBuildWriteContract($extra, 'extra-vendor-file');
    packageBuildWrite($extra . '/plugin/extra-vendor-file/vendor/acme/runtime/extra.php', "<?php\n");
    packageBuildExpectRejected($root, $extra, 'rejects an extra runtime vendor file', '不一致');

    $missing = packageBuildFixture($root, 'missing-vendor-file', '1.0.0');
    packageBuildAddRuntimeVendor($missing, 'missing-vendor-file');
    packageBuildWriteContract($missing, 'missing-vendor-file');
    unlink($missing . '/plugin/missing-vendor-file/vendor/acme/runtime/src/Library.php');
    packageBuildExpectRejected($root, $missing, 'rejects a missing runtime vendor file', '不一致');

    $wrongCount = packageBuildFixture($root, 'wrong-vendor-count', '1.0.0');
    packageBuildAddRuntimeVendor($wrongCount, 'wrong-vendor-count');
    packageBuildWriteContract($wrongCount, 'wrong-vendor-count', 3);
    packageBuildExpectRejected($root, $wrongCount, 'rejects an incorrect runtime vendor file count', '不一致');

    $wrongDigest = packageBuildFixture($root, 'wrong-vendor-digest', '1.0.0');
    packageBuildAddRuntimeVendor($wrongDigest, 'wrong-vendor-digest');
    packageBuildWriteContract($wrongDigest, 'wrong-vendor-digest', null, str_repeat('0', 64));
    packageBuildExpectRejected($root, $wrongDigest, 'rejects an incorrect runtime vendor tree digest', '不一致');

    $nestedVendor = packageBuildFixture($root, 'nested-vendor', '1.0.0');
    packageBuildAddRuntimeVendor($nestedVendor, 'nested-vendor');
    packageBuildWrite($nestedVendor . '/plugin/nested-vendor/vendor/acme/vendor/hidden.php', "<?php\n");
    packageBuildWriteContract($nestedVendor, 'nested-vendor');
    packageBuildExpectRejected($root, $nestedVendor, 'rejects a nested vendor directory', '禁止目录或文件');

    foreach ([
        '.env' => 'environment files',
        '.git/config' => 'Git metadata',
        'node_modules/package/index.js' => 'node_modules content',
    ] as $relative => $label) {
        $sensitiveApp = 'sensitive-' . str_replace(['.', '/', '_'], '-', trim($relative, '.'));
        $sensitive = packageBuildFixture($root, $sensitiveApp, '1.0.0');
        packageBuildAddRuntimeVendor($sensitive, $sensitiveApp);
        packageBuildWrite($sensitive . '/plugin/' . $sensitiveApp . '/vendor/' . $relative, 'sensitive');
        packageBuildWriteContract($sensitive, $sensitiveApp);
        packageBuildExpectRejected($root, $sensitive, 'rejects runtime vendor ' . $label, '禁止目录或文件');
    }

    $vendorSymlink = packageBuildFixture($root, 'vendor-symlink', '1.0.0');
    packageBuildAddRuntimeVendor($vendorSymlink, 'vendor-symlink');
    packageBuildWriteContract($vendorSymlink, 'vendor-symlink');
    $vendorLink = $vendorSymlink . '/plugin/vendor-symlink/vendor/acme/runtime/src/Library.php';
    unlink($vendorLink);
    symlink($vendorSymlink . '/README.md', $vendorLink);
    packageBuildExpectRejected($root, $vendorSymlink, 'rejects a runtime vendor symlink', '符号链接');

    echo "SandPackage repository package build test passed\n";
} finally {
    packageBuildDelete($root);
}
