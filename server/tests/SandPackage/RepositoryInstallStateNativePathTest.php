<?php

declare(strict_types=1);

// Run directly with PHP on macOS/Linux and Windows. Files stay under a unique
// temporary directory; no database, HTTP service, plugin install or restart.
namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace Saithink\Saipackage\service {
    class Filesystem {
        public static function dirIsEmpty(string $path): bool {
            return count(scandir($path) ?: []) === 2;
        }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\logic\RepositoryLogic;
    use plugin\sandpackage\app\service\AbnormalPluginCleanup;
    use plugin\sandpackage\app\service\FreshInstallRecovery;
    use plugin\sandpackage\app\service\PluginStorage;
    use plugin\sandpackage\app\service\RepositoryClient;

    $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sandpackage-state-' . bin2hex(random_bytes(6));
    $fixture = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fixture);
    mkdir($fixture . DIRECTORY_SEPARATOR . 'server', 0700, true);
    function base_path(string $path = ''): string {
        global $fixture;
        return $fixture . DIRECTORY_SEPARATOR . 'server' . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
    }
    function runtime_path(string $path = ''): string {
        global $fixture;
        return $fixture . DIRECTORY_SEPARATOR . 'runtime' . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
    }
    function env(string $key, mixed $default = null): mixed { return $default; }
    $source = dirname(__DIR__, 2) . '/plugin/sandpackage/app/';
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'plugin\\sandpackage\\app\\';
        if (!str_starts_with($class, $prefix)) return;
        $path = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    });
    require $source . 'service/PluginStorage.php';
    require $source . 'service/AbnormalPluginCleanup.php';
    require $source . 'service/FreshInstallRecovery.php';
    require $source . 'service/RepositoryClient.php';
    require $source . 'logic/InstallLogic.php';
    require $source . 'logic/RepositoryLogic.php';

    function verify(bool $actual, string $message): void {
        if (!$actual) throw new \RuntimeException($message);
        echo '[PASS] ' . $message . PHP_EOL;
    }
    final class CatalogFixture implements RepositoryClient {
        public function get(string $url, int $maxBytes, callable $complete): void {
            $complete(json_encode(['schema' => 1, 'plugins' => [[
                'app' => 'neutral-test', 'repository' => 'example/neutral-test',
                'title' => 'Neutral', 'about' => 'Fixture', 'author' => 'Fixture',
                'versions' => [[
                    'version' => '1.0.0', 'tag' => '1.0.0', 'asset' => 'neutral-test.zip',
                    'sha256' => str_repeat('a', 64), 'host_min' => '0.1.0',
                ]],
            ]]], JSON_THROW_ON_ERROR), null);
        }
    }
    function statusAndAction(): array {
        $local = (new InstallLogic('neutral-test'))->ordinaryStatus();
        $catalog = null;
        $error = null;
        (new RepositoryLogic(new CatalogFixture(), 'example/catalog', 'main', '0.1.0'))
            ->catalog(function (?array $result, ?\Throwable $failure) use (&$catalog, &$error): void {
                $catalog = $result;
                $error = $failure;
            });
        if ($error !== null) throw $error;
        return [$local, $catalog['plugins'][0]['versions'][0]['action'] ?? null];
    }

    try {
        $storage = new PluginStorage();
        $root = $storage->root();
        [$local, $action] = statusAndAction();
        verify($local['state'] === InstallLogic::UNINSTALLED && !$local['blocked']
            && $action === 'install', 'empty native storage offers repository installation');
        verify($storage->managedRecords() === [], 'empty management list agrees with install action');

        mkdir($root . '/cleanup', 0700, true);
        file_put_contents($root . '/cleanup/neutral-test.json', '{"app":"neutral-test","phase":"prepared"}');
        [$local, $action] = statusAndAction();
        verify($local['state'] === InstallLogic::FAILED && $local['blocked']
            && $action === 'manage', 'real pending cleanup blocks ordinary installation');
        verify(isset($storage->managedRecords()['neutral-test']), 'pending cleanup appears in management');

        file_put_contents($root . '/cleanup/neutral-test.json', '{broken');
        [$local, $action] = statusAndAction();
        verify($local['blocked'] && $action === 'manage', 'malformed cleanup journal fails closed');
        verify(isset($storage->managedRecords()['neutral-test']), 'malformed cleanup remains visible in management');

        $candidate = $root . DIRECTORY_SEPARATOR . 'neutral-test';
        new AbnormalPluginCleanup('neutral-test', $candidate, [], $root, new \stdClass());
        verify(true, 'native candidate and root are accepted by cleanup recovery');
        $safeRecoveryPath = new \ReflectionMethod(FreshInstallRecovery::class, 'safePath');
        $safeRecoveryPath->invoke(null, $root . '/fresh-recovery/neutral-test.json');
        verify(true, 'fresh-install recovery accepts native absolute storage path');
        file_put_contents(base_path() . DIRECTORY_SEPARATOR . 'probe.txt', 'native-path');
        $tree = FreshInstallRecovery::tree(base_path());
        verify(($tree['probe.txt'] ?? null) === hash('sha256', 'native-path'),
            'fresh-install recovery resolves native relative tree names');

        unlink($root . '/cleanup/neutral-test.json');
        verify(!AbnormalPluginCleanup::pending($root . DIRECTORY_SEPARATOR, 'neutral-test'),
            'storage root with a native trailing separator is not falsely pending');
        [$local, $action] = statusAndAction();
        verify($local['state'] === InstallLogic::UNINSTALLED && !$local['blocked']
            && $action === 'install', 'repository installation returns after cleanup journal is removed');
        verify($storage->managedRecords() === [], 'management list is empty after cleanup journal removal');

        if (DIRECTORY_SEPARATOR === '\\') {
            verify(AbnormalPluginCleanup::pending('\\\\?\\fixture-share\\sandpackage', 'neutral-test'),
                'unsafe UNC namespace fails closed without opening a share');
            verify(AbnormalPluginCleanup::pending('C:\\fixture\\\\invalid', 'neutral-test'),
                'malformed drive path fails closed');
            try {
                $safeRecoveryPath->invoke(null, '\\\\?\\fixture-share\\sandpackage\\fresh-recovery\\neutral-test.json');
                throw new \RuntimeException('unsafe UNC recovery namespace was accepted');
            } catch (\plugin\sandadmin\exception\ApiException) {
                verify(true, 'unsafe UNC recovery namespace fails closed');
            }
            try {
                $safeRecoveryPath->invoke(null, 'C:\\fixture\\\\invalid');
                throw new \RuntimeException('malformed Windows recovery path was accepted');
            } catch (\plugin\sandadmin\exception\ApiException) {
                verify(true, 'malformed Windows recovery path fails closed');
            }
        } else {
            verify(AbnormalPluginCleanup::pending('relative/path', 'neutral-test'),
                'relative POSIX path fails closed');
        }
        echo 'Repository native-path state contract passed' . PHP_EOL;
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (!file_exists($path) && !is_link($path)) return;
            if (is_dir($path) && !is_link($path)) {
                foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                    $remove($entry->getPathname());
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($fixture);
    }
}
