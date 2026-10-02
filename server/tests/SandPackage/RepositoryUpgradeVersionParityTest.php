<?php

declare(strict_types=1);

// Real catalog action and ordinary ZIP upload preflight. Only the installed
// record is simulated. Intentionally incomplete ZIPs stop before candidate
// staging, dependency handling, database work or installation.
namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\logic\LegacyInstallLogic;
    use plugin\sandpackage\app\logic\RepositoryLogic;
    use plugin\sandpackage\app\service\PluginVersion;

    $source = dirname(__DIR__, 2) . '/plugin/sandpackage/app/';
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'plugin\\sandpackage\\app\\';
        if (str_starts_with($class, $prefix)) {
            require $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    $root = sys_get_temp_dir() . '/sandpackage-version-parity-' . bin2hex(random_bytes(6));
    function base_path(string $path = ''): string {
        global $root;
        return $root . '/server' . ($path === '' ? '' : '/' . $path);
    }
    function runtime_path(string $path = ''): string {
        global $root;
        return $root . '/runtime' . ($path === '' ? '' : '/' . $path);
    }
    function env(string $key, mixed $default = null): mixed { return $default; }
    final class InstalledVersionFixture extends InstallLogic {
        public static string $installedVersion = '0.7.6-preview.1';
        public function getInfo(): array {
            return ['app' => 'neutral-parity', 'version' => self::$installedVersion, 'state' => self::INSTALLED];
        }
        public function getInstallState(): int { return self::INSTALLED; }
    }
    function cleanFixture(string $path): void {
        if (is_dir($path)) {
            foreach (new FilesystemIterator($path) as $entry) cleanFixture($entry->getPathname());
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    function verify(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo '[PASS] ' . $message . PHP_EOL;
    }
    mkdir(base_path('plugin/neutral-parity/config'), 0700, true);
    mkdir(runtime_path(), 0700, true);
    $cases = [
        ['0.7.6-preview.1', '0.7.6-zeta.1', 'upgrade'],
        ['0.7.6-preview.2', '0.7.6-preview.10', 'upgrade'],
        ['0.7.6-preview.10', '0.7.6', 'upgrade'],
        ['0.7.6', '0.7.6', 'installed'],
        ['0.7.6', '0.7.6-preview.10', 'downgrade'],
        ['0.7.10', '0.7.6', 'downgrade'],
    ];
    try {
        $logic = (new ReflectionClass(RepositoryLogic::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($logic, 'hostVersion'))->setValue($logic, '0.1.2');
        $actionMethod = new ReflectionMethod($logic, 'releaseAction');
        $legacyCompare = new ReflectionMethod(LegacyInstallLogic::class, 'compareSemver');
        foreach ($cases as [$from, $to, $expectedAction]) {
            InstalledVersionFixture::$installedVersion = $from;
            file_put_contents(base_path('plugin/neutral-parity/config/app.php'), "<?php return ['version'=>'$from'];");
            $release = ['version' => $to, 'tag' => 'v' . $to, 'asset' => 'plugin.zip', 'sha256' => str_repeat('a', 64), 'host_min' => '0.1.0'];
            $catalog = RepositoryLogic::parseCatalog(json_encode(['schema' => 1, 'plugins' => [[
                'app' => 'neutral-parity', 'title' => 'Fixture', 'about' => 'Fixture', 'author' => 'Fixture', 'versions' => [$release],
            ]]], JSON_THROW_ON_ERROR));
            $action = $actionMethod->invoke($logic, ['state' => 1, 'installed_version' => $from, 'blocked' => false, 'reason' => ''], $catalog['plugins'][0]['versions'][0]);
            verify($action[0] === $expectedAction, "$from -> $to repository action is $expectedAction");
            $zipPath = $root . '/' . bin2hex(random_bytes(5)) . '.zip';
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('info.ini', "app=neutral-parity\nversion=$to\n");
            $zip->close();
            $rejection = null;
            try { (new InstalledVersionFixture('neutral-parity'))->uploadFromPath($zipPath); }
            catch (\plugin\sandadmin\exception\ApiException $error) { $rejection = $error->getMessage(); }
            $expected = $expectedAction === 'upgrade' ? '该插件的基础配置信息不完善' : '升级包版本必须高于已安装版本';
            verify($rejection === $expected, "$from -> $to real upload agrees with repository before staging");
            verify(($legacyCompare->invoke(null, $to, $from) <=> 0) === (PluginVersion::compare($to, $from) <=> 0), "$from -> $to legacy ordering agrees");
        }
    } finally {
        cleanFixture($root);
    }
}
