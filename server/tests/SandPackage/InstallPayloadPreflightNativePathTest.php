<?php

declare(strict_types=1);

// Real ZIP staging and installation preflight, on the host filesystem.
// The connection sentinel stops before any database connection, SQL or deployment.
namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\PluginStorage;
    use Saithink\Saipackage\service\Filesystem;

    $fixture = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, sys_get_temp_dir())
        . DIRECTORY_SEPARATOR . 'sandpackage-preflight-' . bin2hex(random_bytes(6));
    $host = $fixture . DIRECTORY_SEPARATOR . 'native';
    function base_path(): string { global $host; return $host . DIRECTORY_SEPARATOR . 'server'; }
    function runtime_path(): string { global $host; return $host . DIRECTORY_SEPARATOR . 'runtime'; }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key): mixed { return $key === 'plugin.sandadmin.app.version' ? '0.1.2' : null; }
    $server = dirname(__DIR__, 2);
    require $server . '/compat/Saithink/Saipackage/service/Server.php';
    require $server . '/compat/Saithink/Saipackage/service/Filesystem.php';
    require $server . '/plugin/sandpackage/app/service/PluginStorage.php';
    require $server . '/plugin/sandpackage/app/service/AbnormalPluginCleanup.php';
    require $server . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
    require $server . '/plugin/sandpackage/app/service/HostVersionCompatibility.php';
    require $server . '/plugin/sandpackage/app/logic/InstallLogic.php';

    final class ConnectionBoundary extends \RuntimeException {}
    final class PreflightInstall extends InstallLogic {
        public int $connectionCalls = 0;
        protected function recoveryConnection(): object {
            $this->connectionCalls++;
            throw new ConnectionBoundary('Stopped before database connection');
        }
    }
    function verify(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
        echo '[PASS] ' . $message . PHP_EOL;
    }
    function neutralPackage(string $path): void {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE) !== true) throw new \RuntimeException('Cannot create fixture ZIP');
        $zip->addFromString('info.ini', "app = neutral-test\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = \"1.0.0\"\nsupport = 0.1.x\nstate = 0\n");
        $zip->addFromString('config.json', '{}');
        foreach (['install', 'update', 'uninstall'] as $action) {
            $zip->addFromString($action . '.sql', '-- never executed');
        }
        $zip->addFromString('plugin/neutral-test/config/app.php', '<?php return ["version" => "1.0.0"];');
        $zip->addFromString('sandadmin-artd/src/views/plugin/neutral-test/index.vue', '<template>Neutral</template>');
        $zip->close();
    }
    function stageAndPreflight(string $zipPath, string $label, bool $missingBackend = false): void {
        global $host;
        mkdir(base_path(), 0700, true);
        $uploader = new InstallLogic();
        $info = $uploader->uploadFromPath($zipPath);
        $app = $info['app'];
        $logic = new PreflightInstall($app);
        verify($logic->getInstallState() === InstallLogic::WAIT_INSTALL, "$label: ZIP staged as a waiting candidate");
        verify($logic->getInfo()['package_sha256'] === hash_file('sha256', $zipPath), "$label: staged candidate records the ZIP checksum");
        $candidate = (new PluginStorage())->root() . DIRECTORY_SEPARATOR . $app;
        $backend = $candidate . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        $frontend = $candidate . DIRECTORY_SEPARATOR . 'sandadmin-artd' . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $app;
        verify(is_dir($backend) && is_dir($frontend), "$label: real backend and frontend payload directories exist");
        $before = file_get_contents($candidate . '/info.ini');
        if ($missingBackend && !rename($backend, $backend . '-held')) throw new \RuntimeException('Cannot hold backend fixture');
        try {
            $logic->install(false);
            throw new \RuntimeException('Preflight unexpectedly completed installation');
        } catch (ConnectionBoundary) {
            verify(!$missingBackend && $logic->connectionCalls === 1, "$label: install passed path preflight and stopped at the database connection boundary");
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            verify($missingBackend && $error->getMessage() === '插件后端目录缺失' && $logic->connectionCalls === 0,
                "$label: missing backend is rejected before the database connection boundary");
        }
        verify(file_get_contents($candidate . '/info.ini') === $before
            && $logic->getInstallState() === InstallLogic::WAIT_INSTALL,
            "$label: preflight preserves the pending candidate and metadata");
        verify(!is_dir(base_path() . '/plugin/' . $app)
            && !is_dir(dirname(base_path()) . '/sandadmin-artd/src/views/plugin/' . $app)
            && !is_dir((new PluginStorage())->root() . '/fresh-recovery'),
            "$label: no payload deployment or fresh-install journal was created");
        if (!$missingBackend) {
            $paths = (new \ReflectionMethod(InstallLogic::class, 'checkedPaths'))->invoke($logic);
            verify(count($paths) === 2 && is_dir(array_keys($paths)[0]) && is_dir(array_keys($paths)[1]),
                "$label: both payload mappings remain available for deployment");
        }
    }
    try {
        mkdir($fixture, 0700, true);
        $zipPath = $fixture . DIRECTORY_SEPARATOR . 'neutral.zip';
        neutralPackage($zipPath);
        stageAndPreflight($zipPath, 'native neutral ZIP');
        $host = $fixture . DIRECTORY_SEPARATOR . 'missing-backend';
        stageAndPreflight($zipPath, 'missing backend', true);
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows accepts slash-based host paths while storage and payload keys
            // use native separators; uploadFromPath also leaves a slash appDir suffix.
            $host = str_replace('\\', '/', $fixture . DIRECTORY_SEPARATOR . 'mixed');
            stageAndPreflight($zipPath, 'mixed-separator Windows host');
        }
        if (isset($argv[1])) {
            if (!isset($argv[2]) || !preg_match('/^[a-f0-9]{64}$/D', $argv[2])
                || !hash_equals($argv[2], (string) hash_file('sha256', $argv[1]))) {
                throw new \RuntimeException('External ZIP checksum does not match the pinned artifact');
            }
            $host = $fixture . DIRECTORY_SEPARATOR . 'public-package';
            stageAndPreflight($argv[1], 'checksum-pinned public ZIP');
            if (DIRECTORY_SEPARATOR === '\\') {
                $host = str_replace('\\', '/', $fixture . DIRECTORY_SEPARATOR . 'public-mixed');
                stageAndPreflight($argv[1], 'mixed-separator public ZIP');
            }
        }
        echo 'Native install payload preflight passed; database installation remains untested.' . PHP_EOL;
    } finally {
        Filesystem::delDir($fixture);
    }
}
