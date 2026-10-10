<?php

declare(strict_types=1);

// Real multipart Request/controller intake and repository callbacks in a temporary
// host. No listener, authentication store, SQL, installed host or external network.

use plugin\sandadmin\exception\ApiException;
use plugin\sandpackage\app\controller\InstallController;
use plugin\sandpackage\app\logic\InstallLogic;
use plugin\sandpackage\app\logic\LegacyInstallLogic;
use plugin\sandpackage\app\logic\RepositoryLogic;
use plugin\sandpackage\app\service\PackageArchivePolicy;
use plugin\sandpackage\app\service\RepositoryClient;
use support\Request;
use support\Response;

$capacityRoot = sys_get_temp_dir() . '/sandpackage-capacity-' . bin2hex(random_bytes(6));
define('CAPACITY_FIXTURE_ROOT', $capacityRoot);
mkdir($capacityRoot . '/server/plugin', 0700, true);
mkdir($capacityRoot . '/sandadmin-artd', 0700);
$capacityConfig = [];
$capacityChecks = 0;

function base_path(string $path = ''): string
{
    global $capacityRoot;
    return $capacityRoot . '/server' . ($path === '' ? '' : '/' . $path);
}
function runtime_path(string $path = ''): string
{
    global $capacityRoot;
    return $capacityRoot . '/runtime' . ($path === '' ? '' : '/' . $path);
}
function env(string $name, mixed $default = null): mixed { return $default; }
function config(string $name, mixed $default = null): mixed
{
    global $capacityConfig;
    return array_key_exists($name, $capacityConfig) ? $capacityConfig[$name] : $default;
}
function json(mixed $data, int $options = 0): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, $options | JSON_THROW_ON_ERROR));
}

require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

final class CapacityController extends InstallController
{
    protected function init(): void { $this->adminId = 1; }
}
final class CapacityRepositoryClient implements RepositoryClient
{
    public array $limits = [];
    public string $catalog;
    public function __construct(public string $body, string $app)
    {
        $this->catalog = json_encode(['schema' => 1, 'plugins' => [[
            'app' => $app, 'title' => 'Capacity fixture', 'about' => 'Neutral fixture', 'author' => 'Test',
            'repository' => 'supdger/capacity-fixture', 'versions' => [[
                'version' => '1.0.0', 'tag' => 'v1.0.0', 'asset' => 'fixture.zip',
                'sha256' => hash('sha256', $body), 'host_min' => '0.1.0', 'notes' => '',
            ]],
        ]]], JSON_THROW_ON_ERROR);
    }
    public function get(string $url, int $maxBytes, callable $complete): void
    {
        $this->limits[] = $maxBytes;
        // Deliberately deliver an oversized body too: the archive guard must
        // defend against transports that do not enforce their declared budget.
        $complete(str_contains($url, 'raw.githubusercontent.com') ? $this->catalog : $this->body, null);
    }
}
function capacityCheck(bool $ok, string $message): void
{
    global $capacityChecks;
    if (!$ok) throw new RuntimeException($message);
    $capacityChecks++;
    echo "[PASS] $message\n";
}
function capacityReject(callable $operation, string $fragment, string $message): void
{
    try { $operation(); } catch (ApiException $error) {
        capacityCheck(str_contains($error->getMessage(), $fragment), $message . ': ' . $error->getMessage());
        return;
    }
    throw new RuntimeException($message . ': accepted');
}
function capacityDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) capacityDelete($item->getPathname());
    rmdir($path);
}
function capacityPackage(string $app, int $size, array $extra = []): string
{
    global $capacityRoot;
    $file = $capacityRoot . '/' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE) !== true) throw new RuntimeException('fixture ZIP creation failed');
    $files = [
        'info.ini' => "app = $app\ntitle = Capacity fixture\nabout = Neutral fixture\nauthor = Test\nversion = 1.0.0\nsupport = \">=0.1.0\"\n",
        'config.json' => '{}',
        'install.sql' => "SELECT 1;\n",
        'update.sql' => "SELECT 1;\n",
        'uninstall.sql' => "SELECT 1;\n",
        'README.md' => "# Capacity fixture\n",
        "plugin/$app/config/app.php" => "<?php return ['version' => '1.0.0'];\n",
        "sandadmin-artd/src/views/plugin/$app/index.vue" => '<template>Fixture</template>',
        "plugin/$app/capacity.dat" => '',
    ] + $extra;
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }
    $zip->close();
    if ($size > 0) {
        $padding = $size - filesize($file);
        if ($padding < 0) throw new RuntimeException('requested fixture is too small');
        $zip->open($file);
        $name = "plugin/$app/capacity.dat";
        $zip->addFromString($name, str_repeat('x', $padding));
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
        $zip->close();
        clearstatcache(true, $file);
        if (filesize($file) !== $size) throw new RuntimeException('fixture byte count mismatch');
    }
    return $file;
}
function capacityUpload(string $file): array
{
    $body = "--capacity-boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.zip\"\r\n"
        . "Content-Type: application/zip\r\n\r\n" . file_get_contents($file) . "\r\n--capacity-boundary--\r\n";
    $request = new Request("POST /app/sandpackage/install/upload HTTP/1.1\r\nHost: localhost\r\n"
        . "Content-Type: multipart/form-data; boundary=capacity-boundary\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    $response = (new CapacityController())->upload($request);
    return json_decode($response->rawBody(), true, 32, JSON_THROW_ON_ERROR);
}
function capacityRepository(string $operation, string $file, string $app): array
{
    $client = new CapacityRepositoryClient((string) file_get_contents($file), $app);
    $repository = new RepositoryLogic($client, 'supdger/capacity-fixture', 'main', '0.2.3');
    $calls = 0; $result = null; $failure = null;
    $repository->$operation($app, '1.0.0', hash('sha256', $client->body), function (?array $data, ?Throwable $error) use (&$calls, &$result, &$failure): void {
        $calls++; $result = $data; $failure = $error;
    });
    capacityCheck($calls === 1, "$operation completes exactly once");
    capacityCheck(end($client->limits) === PackageArchivePolicy::effectiveLimit(), "$operation forwards the effective ZIP budget");
    if ($failure !== null) throw $failure;
    return $result;
}

try {
    file_put_contents(base_path('composer.json'), '{"name":"test/host","require":{}}');
    file_put_contents($capacityRoot . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
    capacityCheck(PackageArchivePolicy::MAX_BYTES === 16777216, 'compressed hard cap is 16 MiB');
    foreach ([16777215, 16777216] as $bytes) {
        $app = 'capacity-' . $bytes;
        $file = capacityPackage($app, $bytes);
        $response = capacityUpload($file);
        capacityCheck($response['code'] === 200 && $response['data']['app'] === $app, "multipart upload accepts $bytes bytes");
        capacityCheck(is_file(base_path("storage/sandpackage/$app/info.ini")), 'accepted upload stages a candidate through the formal controller');
        capacityCheck(capacityRepository('document', $file, $app)['markdown'] === "# Capacity fixture\n", "document accepts $bytes bytes");
    }
    $oversized = capacityPackage('capacity-oversized', 16777217);
    capacityReject(fn() => capacityUpload($oversized), 'actual=16777217 bytes，max=16777216 bytes', 'formal upload rejects hard cap + 1');
    capacityCheck(!is_dir(base_path('storage/sandpackage/capacity-oversized')), 'oversized upload leaves no candidate');
    foreach (['download', 'document', 'cleanupPackage'] as $operation) {
        capacityReject(fn() => capacityRepository($operation, $oversized, 'capacity-oversized'), 'actual=16777217 bytes，max=16777216 bytes', "$operation archive rejects hard cap + 1");
    }
    $download = capacityPackage('capacity-download', 6291456);
    capacityCheck(capacityRepository('download', $download, 'capacity-download')['app'] === 'capacity-download', 'repository download above old 5 MiB stages a candidate');

    $capacityConfig['plugin.sandpackage.upload.size'] = 5242880;
    foreach ([5242879, 5242880] as $bytes) {
        $app = 'lower-' . $bytes;
        $file = capacityPackage($app, $bytes);
        capacityCheck(capacityUpload($file)['code'] === 200, "formal upload accepts lower boundary $bytes");
        capacityCheck(capacityRepository('document', $file, $app)['markdown'] === "# Capacity fixture\n", "document accepts lower boundary $bytes");
    }
    $lowerOverflow = capacityPackage('lower-overflow', 5242881);
    capacityReject(fn() => capacityUpload($lowerOverflow), 'actual=5242881 bytes，max=5242880 bytes', 'formal upload honors host lower limit');
    foreach (['download', 'document', 'cleanupPackage'] as $operation) {
        capacityReject(fn() => capacityRepository($operation, $lowerOverflow, 'lower-overflow'), 'actual=5242881 bytes，max=5242880 bytes', "$operation honors host lower limit");
    }
    capacityReject(fn() => (new InstallLogic())->uploadFromPath($lowerOverflow), 'actual=5242881 bytes，max=5242880 bytes', 'intake kernel protects the same lower limit');
    $legacy = new LegacyInstallLogic('lower-overflow');
    $copy = new ReflectionMethod($legacy, 'copyUploadArchiveToPrivate');
    $metadata = new ReflectionMethod($legacy, 'readUploadArchiveMetadata');
    capacityReject(fn() => $copy->invoke($legacy, $lowerOverflow), 'actual=5242881 bytes，max=5242880 bytes', 'legacy replacement copy rejects before writing');
    capacityReject(fn() => $metadata->invoke($legacy, $lowerOverflow), 'actual=5242881 bytes，max=5242880 bytes', 'legacy archived replacement rechecks capacity');
    $capacityConfig['plugin.sandpackage.upload.size'] = '5242880';
    capacityCheck(PackageArchivePolicy::effectiveLimit() === 5242880, 'canonical integer string remains compatible');
    foreach ([0, -1, 1.5, 'invalid', null, 16777217, '16777217', '99999999999999999999'] as $invalid) {
        $capacityConfig['plugin.sandpackage.upload.size'] = $invalid;
        capacityReject(fn() => PackageArchivePolicy::effectiveLimit(), '容量配置', 'invalid or oversized configuration is rejected');
    }
    $capacityConfig = [];
    capacityReject(fn() => PackageArchivePolicy::assertFile($capacityRoot . '/missing.zip'), '无法测量', 'missing file has a measurement error');
    $archive = new ReflectionMethod(RepositoryLogic::class, 'archiveFile');
    $repository = new RepositoryLogic(new CapacityRepositoryClient('', 'checksum-fixture'), 'supdger/capacity-fixture', 'main', '0.2.3');
    capacityReject(fn() => $archive->invoke($repository, 'changed body', str_repeat('0', 64)), '插件包校验失败', 'digest failure stays separate from capacity failure');

    foreach (['path' => ['../escape' => 'bad'], 'members' => array_fill_keys(array_map(fn(int $i): string => "extra-$i", range(1, 2040)), 'x')] as $kind => $extra) {
        $file = capacityPackage('unsafe-' . $kind, 6291456, $extra);
        capacityReject(fn() => capacityUpload($file), $kind === 'path' ? '不安全' : '数量', "6 MiB upload retains $kind safety rejection");
    }
    $link = capacityPackage('unsafe-link', 6291456, ['plugin/unsafe-link/link' => 'target']);
    $zip = new ZipArchive(); $zip->open($link);
    $zip->setExternalAttributesName('plugin/unsafe-link/link', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
    capacityReject(fn() => capacityUpload($link), '符号链接', '6 MiB upload retains symlink rejection');
    $expanded = capacityPackage('unsafe-expanded', 6291456);
    $expandedFile = $capacityRoot . '/expanded.dat';
    $stream = fopen($expandedFile, 'x+b');
    if (!is_resource($stream) || !ftruncate($stream, 67108865)) throw new RuntimeException('expanded fixture creation failed');
    fclose($stream);
    $zip = new ZipArchive(); $zip->open($expanded);
    $zip->addFile($expandedFile, 'plugin/unsafe-expanded/bomb.dat'); $zip->close();
    capacityReject(fn() => capacityUpload($expanded), '解压大小', 'upload retains 64 MiB expansion limit');
    $duplicate = capacityPackage('unsafe-duplicate', 6291456, ['duplicate-a' => 'one', 'duplicate-b' => 'two']);
    $raw = (string) file_get_contents($duplicate);
    file_put_contents($duplicate, str_replace('duplicate-b', 'duplicate-a', $raw));
    capacityReject(fn() => capacityUpload($duplicate), '重复路径', '6 MiB upload retains duplicate member rejection');
    $corrupt = capacityPackage('unsafe-crc', 6291456);
    $raw = (string) file_get_contents($corrupt);
    $offset = strpos($raw, str_repeat('x', 32));
    if ($offset === false) throw new RuntimeException('CRC fixture payload not found');
    $raw[$offset] = 'y';
    file_put_contents($corrupt, $raw);
    capacityReject(fn() => capacityUpload($corrupt), '成员内容校验失败', '6 MiB upload rejects corrupted member before staging');
    foreach (['README.md' => '# Capacity fixture', 'info.ini' => 'title = Capacity fixture'] as $member => $content) {
        $app = $member === 'README.md' ? 'document-readme-crc' : 'document-info-crc';
        $file = capacityPackage($app, 6291456);
        $zip = new ZipArchive(); $zip->open($file);
        $zip->setCompressionName($member, ZipArchive::CM_STORE); $zip->close();
        $raw = (string) file_get_contents($file);
        $offset = strpos($raw, $content);
        if ($offset === false) throw new RuntimeException('document CRC fixture member not found');
        $raw[$offset] = $raw[$offset] === '#' ? '!' : 'T';
        file_put_contents($file, $raw);
        capacityReject(fn() => capacityRepository('document', $file, $app), '成员内容校验失败', "$member with a matching archive digest rejects corrupt member CRC");
    }
    echo "SandPackage capacity behavior test passed ($capacityChecks checks)\n";
} finally {
    $fixtureRoot = realpath(CAPACITY_FIXTURE_ROOT);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($capacityRoot !== CAPACITY_FIXTURE_ROOT || $fixtureRoot === false || $temporaryRoot === false
        || dirname($fixtureRoot) !== $temporaryRoot
        || preg_match('/^sandpackage-capacity-[a-f0-9]{12}$/D', basename($fixtureRoot)) !== 1) {
        throw new RuntimeException('Refusing to clean a directory not owned by the capacity fixture');
    }
    capacityDelete($fixtureRoot);
}
