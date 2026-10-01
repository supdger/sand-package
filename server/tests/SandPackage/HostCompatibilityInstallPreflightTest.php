<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace plugin\sandpackage\app\service {
    final class PluginDependencyPolicy {
        public static int $calls = 0;
        public static function requirements(array $config, string $app): array {
            self::$calls++;
            throw new \RuntimeException('dependency boundary reached');
        }
    }
    final class PostgresLifecycleSqlExecutor {
        public static int $calls = 0;
        public function executeFile(mixed ...$arguments): void { self::$calls++; }
    }
}
namespace {
    use plugin\sandadmin\exception\ApiException;
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\logic\LegacyInstallLogic;
    use plugin\sandpackage\app\service\PluginDependencyPolicy;
    use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;

    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostVersionCompatibility.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostPayloadPlan.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/LegacyInstallLogic.php';

    function config(string $key): mixed { return $key === 'plugin.sandadmin.app.version' ? $GLOBALS['compatibility_host'] : null; }
    class CompatibilityInstallFixture extends InstallLogic {
        public function __construct(string $directory) { $this->appName = 'neutral-range'; $this->appDir = $directory . '/'; }
        public function getInstallState(): int { return self::WAIT_INSTALL; }
        public function checkPackage(): bool { return true; }
    }
    function compatibilityExpect(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    }
    $directory = sys_get_temp_dir() . '/sandpackage-range-entry-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/config.json', '{}');
    $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/host-version-compatibility.json'), true, 512, JSON_THROW_ON_ERROR);
    $legacy = (new ReflectionClass(LegacyInstallLogic::class))->newInstanceWithoutConstructor();
    $legacyCheck = new ReflectionMethod(LegacyInstallLogic::class, 'assertHostSupportsUpgrade');
    $rejects = 0;
    try {
        foreach ($cases as $case) {
            $GLOBALS['compatibility_host'] = $case['host'];
            $info = 'app="neutral-range"' . "\n" . 'version="1.0.0"' . "\n";
            if (is_string($case['support'])) {
                $info .= 'support="' . addcslashes($case['support'], "\\\"") . '"' . "\n";
            }
            file_put_contents($directory . '/info.ini', $info);
            $before = [hash_file('sha256', $directory . '/info.ini'), hash_file('sha256', $directory . '/config.json')];
            $dependenciesBefore = PluginDependencyPolicy::$calls;
            try {
                (new CompatibilityInstallFixture($directory))->install(false);
                throw new RuntimeException('Install unexpectedly returned');
            } catch (ApiException $error) {
                compatibilityExpect(!$case['expected'], 'Accepted case rejected at install entry: ' . $case['name']);
                compatibilityExpect($error->getCode() === 400, 'Install rejection must be a business error');
                compatibilityExpect(PluginDependencyPolicy::$calls === $dependenciesBefore, 'Rejected install reached dependency processing');
                $rejects++;
            } catch (RuntimeException $error) {
                compatibilityExpect($case['expected'] && $error->getMessage() === 'dependency boundary reached', 'Install did not stop at the expected boundary: ' . $case['name']);
                compatibilityExpect(PluginDependencyPolicy::$calls === $dependenciesBefore + 1, 'Accepted install did not reach dependency processing');
            }
            compatibilityExpect(PostgresLifecycleSqlExecutor::$calls === 0, 'Compatibility preflight reached SQL execution');
            compatibilityExpect($before === [hash_file('sha256', $directory . '/info.ini'), hash_file('sha256', $directory . '/config.json')], 'Preflight changed candidate files');
            $accepted = true;
            try { $legacyCheck->invoke($legacy, ['support' => $case['support']]); }
            catch (ApiException) { $accepted = false; }
            compatibilityExpect($accepted === $case['expected'], 'Legacy upgrade disagrees with shared matcher: ' . $case['name']);
        }
    } finally {
        unlink($directory . '/info.ini');
        unlink($directory . '/config.json');
        rmdir($directory);
    }
    echo 'Host compatibility install preflight: ' . count($cases) . '/' . count($cases) . ' passed; ' . $rejects . " rejected before dependencies/SQL; legacy parity passed\n";
}
