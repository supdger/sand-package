<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace {
    use plugin\sandadmin\exception\ApiException;
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\logic\LegacyInstallLogic;
    use plugin\sandpackage\app\service\PluginStorage;
    use Saithink\Saipackage\service\Filesystem;

    $fixture = sys_get_temp_dir() . '/sandpackage-range-entry-' . bin2hex(random_bytes(8));
    $hostVersion = null;
    function base_path(): string { global $fixture; return $fixture . '/server'; }
    function runtime_path(): string { global $fixture; return $fixture . '/runtime'; }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key): mixed { global $hostVersion; return $key === 'plugin.sandadmin.app.version' ? $hostVersion : null; }
    $server = dirname(__DIR__, 2);
    spl_autoload_register(static function (string $class) use ($server): void {
        foreach (['plugin\\sandpackage\\' => '/plugin/sandpackage/', 'Saithink\\Saipackage\\' => '/compat/Saithink/Saipackage/'] as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $file = $server . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) require $file;
            }
        }
    });
    final class ConnectionBoundary extends \RuntimeException {}
    final class CompatibilityInstallFixture extends InstallLogic {
        public int $connectionCalls = 0;
        protected function recoveryConnection(): object {
            $this->connectionCalls++;
            throw new ConnectionBoundary('Stopped before database connection');
        }
    }
    function compatibilityExpect(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }
    $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/host-version-compatibility.json'), true, 512, JSON_THROW_ON_ERROR);
    $legacy = (new \ReflectionClass(LegacyInstallLogic::class))->newInstanceWithoutConstructor();
    $legacyCheck = new \ReflectionMethod(LegacyInstallLogic::class, 'assertHostSupportsUpgrade');
    $rejects = 0;
    try {
        mkdir(base_path(), 0700, true);
        $directory = (new PluginStorage())->root() . '/neutral-range';
        foreach (['/plugin/neutral-range', '/sandadmin-artd/src/views/plugin/neutral-range'] as $path) mkdir($directory . $path, 0700, true);
        foreach (['install', 'update', 'uninstall'] as $action) file_put_contents($directory . '/' . $action . '.sql', '-- never executed');
        file_put_contents($directory . '/config.json', '{}');
        foreach ($cases as $case) {
            $hostVersion = $case['host'];
            $info = 'app="neutral-range"' . "\n" . 'version="1.0.0"' . "\n" . 'title="Neutral"' . "\n" . 'about="Fixture"' . "\n" . 'author="Test"' . "\n" . 'state=2' . "\n" . 'lifecycle_driver="saipackage-pg-v1"' . "\n";
            if (is_string($case['support'])) $info .= 'support="' . addcslashes($case['support'], "\\\"") . '"' . "\n";
            file_put_contents($directory . '/info.ini', $info);
            $before = [hash_file('sha256', $directory . '/info.ini'), hash_file('sha256', $directory . '/config.json')];
            $logic = new CompatibilityInstallFixture('neutral-range');
            try {
                $logic->install(false);
                throw new \RuntimeException('Install unexpectedly returned');
            } catch (ApiException $error) {
                compatibilityExpect(!$case['expected'] && $error->getCode() === 400, 'Unexpected install rejection: ' . $case['name']);
                compatibilityExpect($logic->connectionCalls === 0, 'Rejected install reached database boundary');
                $rejects++;
            } catch (ConnectionBoundary) {
                compatibilityExpect($case['expected'] && $logic->connectionCalls === 1, 'Install did not reach expected boundary: ' . $case['name']);
            }
            compatibilityExpect($before === [hash_file('sha256', $directory . '/info.ini'), hash_file('sha256', $directory . '/config.json')], 'Preflight changed candidate files');
            compatibilityExpect(!is_dir(base_path() . '/plugin/neutral-range'), 'Preflight deployed runtime files');
            $accepted = true;
            try { $legacyCheck->invoke($legacy, ['support' => $case['support']]); }
            catch (ApiException) { $accepted = false; }
            compatibilityExpect($accepted === $case['expected'], 'Legacy upgrade disagrees: ' . $case['name']);
        }
    } finally {
        Filesystem::delDir($fixture);
    }
    echo 'Host compatibility install preflight: ' . count($cases) . '/' . count($cases) . ' passed; ' . $rejects . " rejected before database/deployment; legacy parity passed\n";
}
