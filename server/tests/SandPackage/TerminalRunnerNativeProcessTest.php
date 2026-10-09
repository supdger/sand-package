<?php

declare(strict_types=1);

namespace Tinywan\Jwt {
    final class JwtToken {
        public static function verify(int $scene, string $token): array { return ['extend' => ['id' => 1, 'plat' => 'sandadmin']]; }
    }
}

namespace plugin\sandpackage\app\logic {
    final class InstallLogic {
        public static array $completed = [];
        public static array $failed = [];
        public static int $reaped = 0;
        public static int $released = 0;
        public function __construct(string $app) {}
        public function beginDependencyCommand(string $type): string { return 'native-process-nonce'; }
        public function acquireDependencyExecutionLock(string $type, string $nonce): void {}
        public function recordDependencyProcessStarted(string $type, string $nonce, int $pid, int $pgid, array $descendants, int $startedAt): void {}
        public function updateDependencyProcessJournal(string $type, string $nonce, array $descendants, string $event, ?int $failedAt = null): void {}
        public function confirmDependencyProcessReaped(string $type, string $nonce): void { self::$reaped++; }
        public function dependentInstallComplete(string $type, ?string $nonce = null, bool $restart = false): array {
            self::$completed[] = [$type, $nonce, $restart];
            return ['advanced' => true, 'completed' => true];
        }
        public function dependencyCommandFailed(string $type, ?string $nonce = null): bool { self::$failed[] = [$type, $nonce]; return true; }
        public function releaseDependencyExecutionLock(): void {}
        public function releaseDependencyCommand(): void { self::$released++; }
    }
}

namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\TerminalRunner;

    function nativeAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function base_path(): string { return $GLOBALS['nativeRoot'] . '/server'; }
    function env(string $key, mixed $default = null): mixed { return $key === 'FRONTEND_DIR' ? 'sandadmin-artd' : $default; }
    function config(string $key, mixed $default = null): mixed {
        if ($key !== 'plugin.sandpackage.terminal.commands') return $default;
        $web = [];
        foreach (['npm', 'pnpm', 'yarn'] as $tool) $web[$tool] = ['cwd' => $GLOBALS['nativeRoot'] . '/sandadmin-artd', 'command' => $tool . ' install'];
        return [
            'web-install' => $web,
            'composer' => ['update' => ['cwd' => base_path(), 'command' => 'composer update --no-interaction']],
        ];
    }
    final class NativeRequest {
        public object $connection;
        public function __construct(public string $command) { $this->connection = (object) []; }
        public function input(string $key, string $default = ''): string {
            return ['command' => $this->command, 'extend' => 'module-install:native-plugin', 'HOME' => 'untrusted-request', 'COMPOSER_AUTH' => 'untrusted-request'][$key] ?? $default;
        }
        public function header(string $key, string $default = ''): string { return $key === 'authorization' ? 'Bearer native-test' : $default; }
    }
    function request(): NativeRequest { return $GLOBALS['nativeRequest']; }

    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/TerminalRunner.php';
    nativeAssert(function_exists('pcntl_fork') && function_exists('pcntl_exec') && function_exists('posix_setsid'), 'native supervisor extensions are required');
    $GLOBALS['nativeRoot'] = sys_get_temp_dir() . '/sandpackage-native-process-' . bin2hex(random_bytes(6));
    mkdir($GLOBALS['nativeRoot'], 0700);
    foreach (['server', 'sandadmin-artd', 'bin'] as $directory) mkdir($GLOBALS['nativeRoot'] . '/' . $directory, 0700);
    $originalEnvironment = [];
    $fixtureEnvironment = [
        'PATH' => $GLOBALS['nativeRoot'] . '/bin:' . (string) getenv('PATH'),
        'HOME' => $GLOBALS['nativeRoot'] . '/home',
        'COMPOSER_HOME' => $GLOBALS['nativeRoot'] . '/composer-home',
        'COMPOSER_CACHE_DIR' => $GLOBALS['nativeRoot'] . '/composer-cache',
        'COMPOSER_AUTH' => '{"fixture": "native-test-only"}',
        'HTTPS_PROXY' => 'http://127.0.0.1:9',
        'NO_PROXY' => 'localhost,127.0.0.1',
        'SSL_CERT_FILE' => $GLOBALS['nativeRoot'] . '/fixture-ca',
        'NPM_CONFIG_CACHE' => $GLOBALS['nativeRoot'] . '/npm-cache',
        'SANDPACKAGE_NATIVE_TEST_ENV' => 'host-process-value',
    ];
    foreach ($fixtureEnvironment as $key => $value) { $originalEnvironment[$key] = getenv($key); putenv($key . '=' . $value); }
    $originalEnvironment['SANDPACKAGE_NATIVE_TEST_MISSING'] = getenv('SANDPACKAGE_NATIVE_TEST_MISSING');
    putenv('SANDPACKAGE_NATIVE_TEST_MISSING');
    $originalPhpEnvironment = $_ENV;
    $_ENV['SANDPACKAGE_NATIVE_TEST_ENV'] = 'untrusted-php-env';
    $expectedEnvironment = $fixtureEnvironment + ['SANDPACKAGE_NATIVE_TEST_MISSING' => false];
    file_put_contents($GLOBALS['nativeRoot'] . '/expected-env.json', json_encode($expectedEnvironment, JSON_THROW_ON_ERROR));
    $fixture = '#!' . PHP_BINARY . "\n" . <<<'CHILD'
<?php
$root = dirname(__DIR__);
$expected = json_decode(file_get_contents($root . '/expected-env.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($expected as $key => $value) { if (getenv($key) !== $value) { fwrite(STDERR, "host environment contract failed\n"); exit(19); } }
$tool = basename(__FILE__);
$expectedArgs = $tool === 'composer' ? ['update', '--no-interaction'] : ['install'];
if (array_slice($_SERVER['argv'], 1) !== $expectedArgs) { fwrite(STDERR, "argument contract failed\n"); exit(20); }
if (is_file($root . '/fail')) { fwrite(STDERR, "fixture failure\n"); exit(7); }
echo "native fixture verified\n";
CHILD;
    foreach (['composer', 'npm', 'pnpm', 'yarn'] as $tool) {
        file_put_contents($GLOBALS['nativeRoot'] . '/bin/' . $tool, $fixture);
        chmod($GLOBALS['nativeRoot'] . '/bin/' . $tool, 0700);
    }
    try {
        foreach (['composer.update', 'web-install.npm', 'web-install.pnpm', 'web-install.yarn'] as $key) {
            $GLOBALS['nativeRequest'] = new NativeRequest($key);
            $runner = new TerminalRunner();
            $frames = [];
            foreach ($runner->exec() as $frame) $frames[] = json_decode($frame, true, 512, JSON_THROW_ON_ERROR)['data'];
            nativeAssert(in_array('exec-success', $frames, true) && !in_array('exec-error', $frames, true), $key . ' failed its real process contract: ' . implode(' | ', $frames));
            nativeAssert($runner->isBusinessFinalized() && $runner->isTerminalCompleted(), $key . ' did not finalize');
            $callback = $key === 'composer.update' ? 'composer' : 'npm';
            nativeAssert(end(InstallLogic::$completed) === [$callback, 'native-process-nonce', $callback === 'composer'], $key . ' lost its completion nonce or callback');
            nativeAssert(!str_contains(implode("\n", $frames), 'host-process-value') && !str_contains(implode("\n", $frames), 'native-test-only'), 'environment contents appeared in SSE');
            echo $key . " real launcher/env/argv/callback passed\n";
        }
        file_put_contents($GLOBALS['nativeRoot'] . '/fail', '7');
        $expectedFailures = [];
        foreach (['composer.update', 'web-install.npm', 'web-install.pnpm', 'web-install.yarn'] as $key) {
            $GLOBALS['nativeRequest'] = new NativeRequest($key);
            $failedRunner = new TerminalRunner();
            $frames = [];
            foreach ($failedRunner->exec() as $frame) $frames[] = json_decode($frame, true, 512, JSON_THROW_ON_ERROR)['data'];
            nativeAssert(in_array('exec-error', $frames, true) && !in_array('exec-success', $frames, true), $key . ' nonzero exit reported success');
            $expectedFailures[] = [$key === 'composer.update' ? 'composer' : 'npm', 'native-process-nonce'];
            nativeAssert(count(InstallLogic::$completed) === 4 && InstallLogic::$failed === $expectedFailures, $key . ' nonzero exit advanced installation or lost compensation nonce');
            nativeAssert($failedRunner->isTerminalCompleted() && !$failedRunner->isBusinessFinalized(), $key . ' failure did not finish its terminal response');
            echo $key . " nonzero real launcher failure/compensation passed\n";
        }
        nativeAssert(InstallLogic::$reaped === 8 && InstallLogic::$released === 8, 'native command lifecycle failed to reap/release');
    } finally {
        $_ENV = $originalPhpEnvironment;
        foreach ($originalEnvironment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
        foreach (['composer', 'npm', 'pnpm', 'yarn'] as $tool) unlink($GLOBALS['nativeRoot'] . '/bin/' . $tool);
        foreach (['expected-env.json', 'fail'] as $file) if (is_file($GLOBALS['nativeRoot'] . '/' . $file)) unlink($GLOBALS['nativeRoot'] . '/' . $file);
        foreach (['server', 'sandadmin-artd', 'bin'] as $directory) rmdir($GLOBALS['nativeRoot'] . '/' . $directory);
        rmdir($GLOBALS['nativeRoot']);
    }
    echo "SandPackage terminal runner native process contract passed\n";
}
