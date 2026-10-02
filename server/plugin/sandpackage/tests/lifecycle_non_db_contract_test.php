<?php
// Legacy recovery regression only; normal lifecycle is covered by UpstreamPostgresLifecycleTest.php.
declare(strict_types=1);

namespace {
    $contractRoot = sys_get_temp_dir() . '/sandpackage-lifecycle-contract-' . bin2hex(random_bytes(8));
    mkdir($contractRoot, 0755, true);
    $contractConfig = [];
    $contractRequest = new class {
        public object $connection;
        public function __construct()
        {
            $this->connection = (object) ['status' => 0];
        }
        public function input(string $key, mixed $default = null): mixed { return $default; }
        public function header(string $key, mixed $default = null): mixed { return $default; }
    };

    function runtime_path(): string
    {
        global $contractRoot;
        return $contractRoot . '/runtime';
    }

    function base_path(): string
    {
        global $contractRoot;
        return $contractRoot . '/server';
    }

    function env(string $name, mixed $default = null): mixed
    {
        return $default;
    }

    function config(string $key, mixed $default = null): mixed
    {
        global $contractConfig;
        return $contractConfig[$key] ?? $default;
    }

    function request(): object
    {
        global $contractRequest;
        return $contractRequest;
    }
}

namespace Tinywan\Jwt {
    final class JwtToken
    {
        public static function verify(int $scene, string $token): array
        {
            return ['extend' => ['id' => 1, 'plat' => 'sandadmin']];
        }
    }
}

namespace plugin\sandpackage\app\logic {
    final class ContractHostPersistenceFault
    {
        public static ?string $action = null;
        public static ?string $pathNeedle = null;
        public static int $remaining = 0;

        public static function fails(string $action, string $path): bool
        {
            if (self::$action !== $action || self::$remaining < 1 || !str_contains($path, '/sandpackage/locks')
                || (self::$pathNeedle !== null && !str_contains($path, self::$pathNeedle))) {
                return false;
            }
            self::$remaining--;
            return true;
        }
    }

    function file_put_contents(string $filename, mixed $data, int $flags = 0, mixed $context = null): int|false
    {
        if (ContractHostPersistenceFault::fails('write', $filename)) {
            return false;
        }
        return $context === null ? \file_put_contents($filename, $data, $flags) : \file_put_contents($filename, $data, $flags, $context);
    }

    function rename(string $from, string $to): bool
    {
        return (ContractHostPersistenceFault::fails('rename', $from) || ContractHostPersistenceFault::fails('rename', $to)) ? false : \rename($from, $to);
    }

    function unlink(string $filename): bool
    {
        return ContractHostPersistenceFault::fails('unlink', $filename) ? false : \unlink($filename);
    }

    function fopen(string $filename, string $mode, bool $useIncludePath = false, mixed $context = null): mixed
    {
        if (ContractHostPersistenceFault::fails('fopen', $filename)) {
            return false;
        }
        return $context === null ? \fopen($filename, $mode, $useIncludePath) : \fopen($filename, $mode, $useIncludePath, $context);
    }

    function mkdir(string $directory, int $permissions = 0777, bool $recursive = false, mixed $context = null): bool
    {
        if (ContractHostPersistenceFault::fails('mkdir', $directory)) {
            return false;
        }
        return $context === null ? \mkdir($directory, $permissions, $recursive) : \mkdir($directory, $permissions, $recursive, $context);
    }

    function fwrite($stream, string $data, ?int $length = null): int|false
    {
        $meta = is_resource($stream) ? stream_get_meta_data($stream) : [];
        if (ContractHostPersistenceFault::fails('short_write', (string) ($meta['uri'] ?? ''))) {
            return 0;
        }
        return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
    }

    function chmod(string $filename, int $permissions): bool
    {
        return ContractHostPersistenceFault::fails('chmod', $filename) ? false : \chmod($filename, $permissions);
    }
}

namespace Saithink\Saipackage\service {
    final class Server
    {
        /** @var array<string,array<string,mixed>> */
        public static array $info = [];
        public static int $importCalls = 0;
        /** @var list<string> */
        public static array $importPaths = [];
        public static int $restartCalls = 0;
        public static bool $throwImport = false;
        public static bool $importResult = true;
        public static int $setIniFailCount = 0;
        /** @var array<string,array<string,array<string,mixed>>> */
        public static array $config = [];
        /** @var array<string,list<array<string,mixed>>> */
        public static array $infoWrites = [];

        /** @return array<string,mixed> */
        public static function getIni(string $path): array
        {
            if (isset(self::$info[$path])) {
                return self::$info[$path];
            }
            $file = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'info.ini';
            return is_file($file) ? (parse_ini_file($file, true, INI_SCANNER_TYPED) ?: []) : [];
        }

        /** @param array<string,mixed> $info */
        public static function setIni(string $path, array $info): bool
        {
            if (self::$setIniFailCount > 0) {
                self::$setIniFailCount--;
                return false;
            }
            self::$info[$path] = $info;
            self::$infoWrites[$path][] = $info;
            return true;
        }

        public static function importSql(string $path): bool
        {
            self::$importCalls++;
            self::$importPaths[] = $path;
            if (self::$throwImport) {
                throw new \RuntimeException('fixture import exception');
            }
            return self::$importResult;
        }

        public static function restart(): bool
        {
            self::$restartCalls++;
            return true;
        }

        public static function getConfig(string $path, string $key): array
        {
            return self::$config[$path][$key] ?? [];
        }

        public static function getDepend(string $path): array
        {
            return [];
        }
    }

    final class Version
    {
        public static function compare(string $left, string $right): bool
        {
            return version_compare($left, $right, '<');
        }
    }

    final class Filesystem
    {
        public static int $deleteCalls = 0;
        public static int $zipCalls = 0;
        public static bool $mutateArchiveDuringUnzip = false;
        public static bool $addExtraDuringUnzip = false;

        public static function delDir(string $path): void
        {
            self::$deleteCalls++;
            if (!is_dir($path)) {
                return;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($path);
        }

        public static function dirIsEmpty(string $path): bool
        {
            if (!is_dir($path)) {
                return true;
            }
            return !(new \FilesystemIterator($path))->valid();
        }

        public static function unzip(string $file, string $directory = ''): string
        {
            if (self::$mutateArchiveDuringUnzip) {
                chmod($file, 0600);
                file_put_contents($file, 'mutation', FILE_APPEND);
                self::$mutateArchiveDuringUnzip = false;
            }
            $zip = new \ZipArchive();
            if ($zip->open($file) !== true) {
                throw new \RuntimeException('fixture zip open failed');
            }
            $directory = $directory ?: substr($file, 0, -4);
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('fixture zip directory failed');
            }
            if (!$zip->extractTo($directory)) {
                throw new \RuntimeException('fixture zip extract failed');
            }
            if (self::$addExtraDuringUnzip) {
                file_put_contents($directory . '/unexpected.php', '<?php');
                self::$addExtraDuringUnzip = false;
            }
            $zip->close();
            return $directory;
        }

        /** @param array<string,string> $files */
        public static function zipDir(array $files, string $zip): void
        {
            self::$zipCalls++;
        }
    }

    final class Depends
    {
        public function __construct(string $path, string $type = '')
        {
        }
    }
}

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException
    {
    }
}

namespace plugin\sandadmin\app\cache {
    final class UserMenuCache
    {
        public static int $clearCalls = 0;

        public static function clearMenuCache(): void
        {
            self::$clearCalls++;
        }
    }
}

namespace think\facade {
    final class Db
    {
        public static function connect(string $name): object
        {
            if ($name !== 'pgsql') throw new \RuntimeException('fixture requires pgsql');
            return new class {
                public function connect(): object
                {
                    return new class {
                        public function exec(string $sql): int|false
                        {
                            $trimmed = trim($sql);
                            if (in_array(strtoupper($trimmed), ['BEGIN', 'COMMIT', 'ROLLBACK'], true)) return 1;
                            \Saithink\Saipackage\service\Server::$importCalls++;
                            $kind = str_contains($trimmed, 'update.sql') ? 'update.sql'
                                : (str_contains($trimmed, 'uninstall.sql') ? 'uninstall.sql' : 'install.sql');
                            \Saithink\Saipackage\service\Server::$importPaths[] = \runtime_path() . '/sandpackage/test-plugin/' . $kind;
                            if (\Saithink\Saipackage\service\Server::$throwImport) throw new \RuntimeException('fixture import exception');
                            return \Saithink\Saipackage\service\Server::$importResult ? 1 : false;
                        }
                    };
                }
                public function getPdo(): false { return false; }
            };
        }
    }
}

namespace {
    use Saithink\Saipackage\service\Filesystem;
    use Saithink\Saipackage\service\Server;
    use plugin\sandpackage\app\logic\ContractHostPersistenceFault;
    use plugin\sandadmin\app\cache\UserMenuCache;
    use plugin\sandpackage\app\logic\LegacyInstallLogic as InstallLogic;
    use plugin\sandpackage\app\service\TerminalRunner;

    require dirname(__DIR__) . '/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__) . '/app/service/PluginStorage.php';
    require dirname(__DIR__) . '/app/logic/LegacyInstallLogic.php';
    // Keep this historical terminal/lease fixture bound to its legacy backend.
    class_alias(InstallLogic::class, 'plugin\\sandpackage\\app\\logic\\InstallLogic');
    require dirname(__DIR__) . '/app/service/TerminalRunner.php';

    /** @param mixed $condition */
    function expect($condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function mkdirFor(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException("cannot create fixture path {$path}");
        }
    }

    function writeFixture(string $path, string $contents): void
    {
        mkdirFor(dirname($path));
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("cannot write fixture {$path}");
        }
    }

    function removeFixtureDirectory(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    /** @return array{app_dir:string,runtime_backend:string,runtime_frontend:string} */
    function fixture(string $name, int $state, bool $registrationCandidate = false, string $app = 'test-plugin'): array
    {
        global $contractRoot;
        $appDir = runtime_path() . '/sandpackage/' . $app . '/';
        $runtimeBackend = base_path() . '/plugin/' . $app;
        $runtimeFrontend = dirname(base_path()) . '/sandadmin-artd/src/views/plugin/' . $app;
        removeFixtureDirectory(rtrim($appDir, '/'));
        removeFixtureDirectory($runtimeBackend);
        removeFixtureDirectory($runtimeFrontend);
        mkdirFor($appDir);
        foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $lifecycle) {
            writeFixture($appDir . $lifecycle, "SELECT '" . $lifecycle . "';" . PHP_EOL);
        }
        foreach ([$appDir . 'plugin/' . $app, $runtimeBackend] as $backend) {
            writeFixture($backend . '/info.ini', "app = test-plugin\nversion = 1.2.3\n");
            writeFixture($backend . '/config/app.php', "<?php return ['version' => '1.2.3'];\n");
            writeFixture($backend . '/payload.php', "<?php // {$name}\n");
        }
        foreach ([$appDir . 'sandadmin-artd/src/views/plugin/' . $app, $runtimeFrontend] as $frontend) {
            writeFixture($frontend . '/index.vue', "<template><div>{$name}</div></template>\n");
        }
        Server::$info[rtrim($appDir, '/')] = [];
        Server::$info[$appDir] = [
                'app' => $app,
                'title' => 'Fixture',
                'about' => 'Fixture',
                'author' => 'Fixture',
                'version' => '1.2.3',
                'state' => $state,
                'registration_candidate' => $registrationCandidate ? 1 : 0,
            ];
        Server::$info[$appDir . 'plugin/' . $app . '/'] = ['app' => $app, 'version' => '1.2.3'];
        Server::$info[$runtimeBackend . '/'] = ['app' => $app, 'version' => '1.2.3'];
        return ['app_dir' => $appDir, 'runtime_backend' => $runtimeBackend, 'runtime_frontend' => $runtimeFrontend];
    }

    function resetCounters(): void
    {
        Server::$importCalls = 0;
        Server::$importPaths = [];
        Server::$restartCalls = 0;
        Server::$importResult = true;
        Server::$throwImport = false;
        Filesystem::$deleteCalls = 0;
        Filesystem::$zipCalls = 0;
        UserMenuCache::$clearCalls = 0;
        Server::$config = [];
        Server::$infoWrites = [];
        Server::$setIniFailCount = 0;
        ContractHostPersistenceFault::$action = null;
        ContractHostPersistenceFault::$pathNeedle = null;
        ContractHostPersistenceFault::$remaining = 0;
    }

    function trustedManifest(string $app = 'test-plugin'): string
    {
        $method = new ReflectionMethod(InstallLogic::class, 'verifyDeploymentMatchesPackage');
        return (string) $method->invoke(new InstallLogic($app));
    }

    /** @param array<string,string> $entries */
    function packageZip(string $name, array $entries = []): string
    {
        global $contractRoot;
        $file = $contractRoot . '/' . $name . '.zip';
        $zip = new ZipArchive();
        expect($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, "{$name}: cannot create ZIP fixture");
        $defaults = [
            'info.ini' => "app = test-plugin\ntitle = Fixture\nabout = Fixture\nauthor = Fixture\nversion = 1.2.3\n",
            'install.sql' => '-- install' . PHP_EOL,
            'update.sql' => '-- update' . PHP_EOL,
            'uninstall.sql' => '-- uninstall' . PHP_EOL,
            'plugin/test-plugin/info.ini' => "app = test-plugin\nversion = 1.2.3\n",
            'plugin/test-plugin/config/app.php' => "<?php return ['version' => '1.2.3'];\n",
            'sandadmin-artd/src/views/plugin/test-plugin/index.vue' => '<template><div>fixture</div></template>' . PHP_EOL,
        ];
        foreach (array_merge($defaults, $entries) as $entry => $content) {
            expect($zip->addFromString($entry, $content), "{$name}: cannot add {$entry}");
        }
        $zip->close();
        return $file;
    }

    final class UploadFixture
    {
        public function __construct(private string $path)
        {
        }

        public function getPathname(): string
        {
            return $this->path;
        }

        public function move(string $destination): self
        {
            if (!rename($this->path, $destination)) {
                throw new RuntimeException('fixture upload move failed');
            }
            return new self($destination);
        }
    }

    final class TerminalRequestFixture
    {
        public object $connection;

        /** @param array<string,string> $input */
        public function __construct(private array $input)
        {
            $this->connection = (object) ['status' => 0];
        }

        public function input(string $key, mixed $default = null): mixed
        {
            return $this->input[$key] ?? $default;
        }

        public function header(string $key, mixed $default = null): mixed
        {
            return $key === 'authorization' ? 'Bearer fixture' : $default;
        }
    }

    /** @param callable(string):void $breakLifecycle */
    function assertInstallLifecycleRejected(string $name, callable $breakLifecycle): void
    {
        $paths = fixture($name, InstallLogic::WAIT_INSTALL);
        resetCounters();
        $breakLifecycle($paths['app_dir']);
        try {
            (new InstallLogic('test-plugin'))->install(false);
            throw new RuntimeException("{$name}: install unexpectedly succeeded");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(Server::$importCalls === 0, "{$name}: importSql was called");
        expect(Filesystem::$deleteCalls === 0, "{$name}: files were deleted");
        expect(UserMenuCache::$clearCalls === 0, "{$name}: cache was cleared");
        expect(Server::$restartCalls === 0, "{$name}: service was restarted");
        expect((Server::$info[$paths['app_dir']]['state'] ?? null) !== InstallLogic::INSTALLED, "{$name}: package was marked installed");
    }

    assertInstallLifecycleRejected('missing', static function (string $root): void {
        unlink($root . 'update.sql');
    });
    assertInstallLifecycleRejected('empty', static function (string $root): void {
        file_put_contents($root . 'update.sql', '');
    });
    assertInstallLifecycleRejected('symlink', static function (string $root): void {
        $target = $root . 'lifecycle-target.sql';
        writeFixture($target, '-- target' . PHP_EOL);
        unlink($root . 'update.sql');
        expect(symlink($target, $root . 'update.sql'), 'symlink fixture is unavailable');
    });

    foreach (['install' => InstallLogic::WAIT_INSTALL, 'update' => InstallLogic::WAIT_INSTALL] as $kind => $state) {
        foreach (['false', 'exception'] as $mode) {
            $paths = fixture("{$kind}-import-{$mode}", $state);
            if ($kind === 'update') {
                Server::$info[$paths['app_dir']]['update'] = 1;
            }
            resetCounters();
            Server::$importResult = false;
            Server::$throwImport = $mode === 'exception';
            $failureMessage = '';
            try {
                (new InstallLogic('test-plugin'))->install(false);
                throw new RuntimeException("{$kind} {$mode}: unexpectedly succeeded");
            } catch (\plugin\sandadmin\exception\ApiException $exception) {
                $failureMessage = $exception->getMessage();
            }
            expect(Server::$importCalls === 1, "{$kind} {$mode}: importer was not called exactly once ({$failureMessage}; stage=" . (Server::$info[$paths['app_dir']]['failed_stage'] ?? 'none') . ')');
            expect((Server::$importPaths[0] ?? '') === $paths['app_dir'] . ($kind === 'update' ? 'update.sql' : 'install.sql'), "{$kind} {$mode}: wrong canonical lifecycle SQL input");
            expect((Server::$info[$paths['app_dir']]['failed_stage'] ?? null) === ($kind === 'update' ? 'database_update' : 'database_install'), "{$kind} {$mode}: failure stage is wrong");
            expect(Filesystem::$deleteCalls === 0 && Server::$restartCalls === 0, "{$kind} {$mode}: importer failure advanced deployment");
        }
    }

    $unreadablePaths = fixture('unreadable', InstallLogic::WAIT_INSTALL);
    chmod($unreadablePaths['app_dir'] . 'update.sql', 0000);
    if (!is_readable($unreadablePaths['app_dir'] . 'update.sql')) {
        resetCounters();
        try {
            (new InstallLogic('test-plugin'))->install(false);
            throw new RuntimeException('unreadable: install unexpectedly succeeded');
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(Server::$importCalls === 0, 'unreadable: importSql was called');
        expect(Filesystem::$deleteCalls === 0, 'unreadable: files were deleted');
    }
    chmod($unreadablePaths['app_dir'] . 'update.sql', 0644);

    $registrationPaths = fixture('registration-missing', InstallLogic::WAIT_INSTALL, true);
    unlink($registrationPaths['app_dir'] . 'uninstall.sql');
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
        throw new RuntimeException('registration missing lifecycle unexpectedly succeeded');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(Server::$importCalls === 0, 'registration missing lifecycle: importSql was called');
    expect(Filesystem::$deleteCalls === 0, 'registration missing lifecycle: files were deleted');
    expect(UserMenuCache::$clearCalls === 0, 'registration missing lifecycle: cache was cleared');
    expect((Server::$info[$registrationPaths['app_dir']]['state'] ?? null) !== InstallLogic::INSTALLED, 'registration missing lifecycle: package was marked installed');

    foreach (['false', 'exception'] as $mode) {
        $uninstallPaths = fixture('uninstall-' . $mode, InstallLogic::INSTALLED);
        resetCounters();
        Server::$importResult = false;
        Server::$throwImport = $mode === 'exception';
        try {
            (new InstallLogic('test-plugin'))->uninstall();
            throw new RuntimeException("uninstall {$mode}: unexpectedly succeeded");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(Server::$importCalls === 1, "uninstall {$mode}: importSql call count is wrong");
        expect(Filesystem::$deleteCalls === 0, "uninstall {$mode}: files were deleted");
        expect(Filesystem::$zipCalls === 0, "uninstall {$mode}: backup was created");
        expect(UserMenuCache::$clearCalls === 0, "uninstall {$mode}: cache was cleared");
        expect(Server::$restartCalls === 0, "uninstall {$mode}: service was restarted");
        expect(is_dir($uninstallPaths['app_dir']), "uninstall {$mode}: registry directory was removed");
        expect((Server::$info[$uninstallPaths['app_dir']]['failed_stage'] ?? null) === 'database_uninstall', "uninstall {$mode}: failure stage is wrong");
        expect((Server::$info[$uninstallPaths['app_dir']]['state'] ?? null) === InstallLogic::INSTALLED, "uninstall {$mode}: prior installed state was not retained");

        Server::$importResult = true;
        Server::$throwImport = false;
        (new InstallLogic('test-plugin'))->uninstall();
        expect(Server::$importCalls === 2, "uninstall {$mode}: retry did not rerun uninstall.sql");
    }

    foreach ([InstallLogic::WAIT_INSTALL, InstallLogic::CONFLICT_PENDING, InstallLogic::DEPENDENT_WAIT_INSTALL] as $state) {
        $paths = fixture('uninstall-in-progress-' . $state, $state);
        resetCounters();
        try {
            (new InstallLogic('test-plugin'))->uninstall();
            throw new RuntimeException("uninstall {$state}: in-progress state was deleted");
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            expect(str_contains($exception->getMessage(), '正在执行'), "uninstall {$state}: wrong in-progress error");
        }
        expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0, "uninstall {$state}: in-progress state mutated lifecycle");
        expect(is_dir($paths['app_dir']), "uninstall {$state}: in-progress registry was removed");
    }

    $operationRetryPaths = fixture('uninstall-wait-retry', InstallLogic::WAIT_INSTALL);
    resetCounters();
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            (new InstallLogic('test-plugin'))->uninstall();
            throw new RuntimeException("wait attempt {$attempt}: unexpectedly started uninstall");
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            expect(str_contains($exception->getMessage(), '正在执行'), "wait attempt {$attempt}: did not preserve OPERATION_IN_PROGRESS");
        }
    }
    expect((Server::$info[$operationRetryPaths['app_dir']]['state'] ?? null) === InstallLogic::WAIT_INSTALL, 'wait retry was normalized into a recovery state');
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0, 'wait retry invoked SQL or deletion');

    foreach (['failed' => InstallLogic::FAILED, 'legacy' => InstallLogic::DIRECTORY_OCCUPIED] as $kind => $state) {
        $paths = fixture('uninstall-' . $kind, $state);
        if ($kind === 'legacy') {
            unset(Server::$info[$paths['app_dir']]['state']);
        } else {
            Server::$info[$paths['app_dir']]['last_stable_state'] = InstallLogic::INSTALLED;
            Server::$info[$paths['app_dir']]['failed_stage'] = 'dependency_install';
        }
        Server::$info[$paths['app_dir']]['registration_manifest'] = trustedManifest();
        resetCounters();
        Server::$importResult = false;
        try {
            (new InstallLogic('test-plugin'))->uninstall();
            throw new RuntimeException("{$kind}: recovery uninstall unexpectedly succeeded");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(Server::$importCalls === 1 && (Server::$importPaths[0] ?? '') === $paths['app_dir'] . 'uninstall.sql', "{$kind}: recovery uninstall did not use canonical SQL");
        expect(Filesystem::$deleteCalls === 0 && is_dir($paths['app_dir']), "{$kind}: recovery uninstall deleted files after SQL failure");
        expect((Server::$info[$paths['app_dir']]['state'] ?? null) === InstallLogic::FAILED, "{$kind}: recovery failure was not retryable");
        Server::$importResult = true;
        (new InstallLogic('test-plugin'))->uninstall();
        expect(Server::$importCalls === 2, "{$kind}: recovery retry did not re-import canonical uninstall SQL");
    }

    $untrustedFailedPaths = fixture('uninstall-failed-without-manifest', InstallLogic::FAILED);
    Server::$info[$untrustedFailedPaths['app_dir']]['last_stable_state'] = InstallLogic::INSTALLED;
    Server::$info[$untrustedFailedPaths['app_dir']]['failed_stage'] = 'dependency_install';
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->uninstall();
        throw new RuntimeException('FAILED registry without trusted manifest ran uninstall');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0, 'FAILED registry without manifest mutated SQL or files');

    $driftFailedPaths = fixture('uninstall-failed-drift', InstallLogic::FAILED);
    Server::$info[$driftFailedPaths['app_dir']]['last_stable_state'] = InstallLogic::INSTALLED;
    Server::$info[$driftFailedPaths['app_dir']]['failed_stage'] = 'dependency_install';
    Server::$info[$driftFailedPaths['app_dir']]['registration_manifest'] = trustedManifest();
    writeFixture($driftFailedPaths['runtime_backend'] . '/payload.php', '<?php // drift');
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->uninstall();
        throw new RuntimeException('FAILED registry with runtime drift ran uninstall');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0, 'FAILED registry drift mutated SQL or files');

    $residuePaths = fixture('uninstall-residue', InstallLogic::UNINSTALLED);
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->uninstall();
        throw new RuntimeException('uninstalled registry residue was deleted');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0 && is_dir($residuePaths['app_dir']), 'uninstalled registry residue advanced lifecycle');

    foreach ([InstallLogic::DIRECTORY_OCCUPIED, InstallLogic::DEPLOYMENT_MISSING, 99] as $state) {
        $paths = fixture('uninstall-unknown-' . $state, $state);
        if ($state === InstallLogic::DEPLOYMENT_MISSING) {
            removeFixtureDirectory($paths['runtime_backend']);
            removeFixtureDirectory($paths['runtime_frontend']);
        }
        resetCounters();
        try {
            (new InstallLogic('test-plugin'))->uninstall();
            throw new RuntimeException("uninstall {$state}: unknown registry state was deleted");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0 && is_dir($paths['app_dir']), "uninstall {$state}: unknown state mutated host");
    }

    $unsafeRetryPaths = fixture('uninstall-directory-occupied-retry', InstallLogic::DIRECTORY_OCCUPIED);
    resetCounters();
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            (new InstallLogic('test-plugin'))->uninstall();
            throw new RuntimeException("directory occupied attempt {$attempt}: unexpectedly started uninstall");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect((Server::$info[$unsafeRetryPaths['app_dir']]['uninstall_recovery_required'] ?? 0) == 1, "directory occupied attempt {$attempt}: recovery marker was lost");
        expect((Server::$info[$unsafeRetryPaths['app_dir']]['state'] ?? null) === InstallLogic::DIRECTORY_OCCUPIED, "directory occupied attempt {$attempt}: state was normalized to FAILED");
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0 && is_dir($unsafeRetryPaths['app_dir']), 'directory occupied retry invoked SQL or deletion');

    $missingLifecyclePaths = fixture('uninstall-failed-missing-lifecycle', InstallLogic::FAILED);
    unlink($missingLifecyclePaths['app_dir'] . 'uninstall.sql');
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->uninstall();
        throw new RuntimeException('failed package without lifecycle was uninstalled');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0 && is_dir($missingLifecyclePaths['app_dir']), 'failed package without lifecycle did not fail closed');

    $absentPaths = fixture('uninstall-absent', InstallLogic::UNINSTALLED);
    removeFixtureDirectory(rtrim($absentPaths['app_dir'], '/'));
    removeFixtureDirectory($absentPaths['runtime_backend']);
    removeFixtureDirectory($absentPaths['runtime_frontend']);
    resetCounters();
    (new InstallLogic('test-plugin'))->uninstall();
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0, 'absent registry/dirs was not idempotent');

    $runtimeOnlyPaths = fixture('uninstall-runtime-only', InstallLogic::UNINSTALLED);
    removeFixtureDirectory(rtrim($runtimeOnlyPaths['app_dir'], '/'));
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->uninstall();
        throw new RuntimeException('runtime-only residue was deleted without a registry');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0 && is_dir($runtimeOnlyPaths['runtime_backend']), 'runtime-only residue mutated host');

    $lockedPaths = fixture('operation-lock', InstallLogic::INSTALLED);
    resetCounters();
    $firstOperation = new InstallLogic('test-plugin');
    $acquireOperationLock = new ReflectionMethod(InstallLogic::class, 'acquireOperationLock');
    $releaseOperationLock = new ReflectionMethod(InstallLogic::class, 'releaseOperationLock');
    $acquireOperationLock->invoke($firstOperation);
    $acquireOperationLock->invoke($firstOperation);
    $releaseOperationLock->invoke($firstOperation);
    $lockPath = runtime_path() . '/sandpackage/locks/test-plugin-operation.lock';
    $child = proc_open([
        PHP_BINARY,
        '-r',
        '$lock = fopen($argv[1], "c"); $acquired = flock($lock, LOCK_EX | LOCK_NB); if ($acquired) { flock($lock, LOCK_UN); fclose($lock); exit(1); } fclose($lock); exit(0);',
        $lockPath,
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($child), 'cross-process operation-lock fixture could not start');
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    expect(proc_close($child) === 0, 'cross-process operation lock was not held');
    try {
        (new InstallLogic('test-plugin'))->uninstall();
        throw new RuntimeException('concurrent uninstall unexpectedly acquired the app operation lock');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'concurrent operation failed for the wrong reason');
    }
    expect(Server::$importCalls === 0, 'operation lock allowed uninstall SQL import');
    expect(Filesystem::$deleteCalls === 0, 'operation lock allowed file deletion');
    try {
        (new InstallLogic('test-plugin'))->setInfo(['stage' => 'unexpected']);
        throw new RuntimeException('concurrent registry write unexpectedly acquired the app operation lock');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'concurrent registry write failed for the wrong reason');
    }
    expect((Server::$info[$lockedPaths['app_dir']]['stage'] ?? '') !== 'unexpected', 'operation lock allowed registry write');
    $releaseOperationLock->invoke($firstOperation);

    $updateLockPaths = fixture('operation-lock-update', InstallLogic::WAIT_INSTALL);
    Server::$info[$updateLockPaths['app_dir']]['update'] = 1;
    resetCounters();
    $acquireOperationLock->invoke($firstOperation);
    try {
        (new InstallLogic('test-plugin'))->install(false);
        throw new RuntimeException('concurrent update unexpectedly acquired the app operation lock');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'concurrent update failed for the wrong reason');
    }
    expect(Server::$importCalls === 0, 'operation lock allowed update SQL import');
    $releaseOperationLock->invoke($firstOperation);

    $recoveryLockPaths = fixture('operation-lock-recovery', InstallLogic::DEPENDENT_WAIT_INSTALL);
    resetCounters();
    $acquireOperationLock->invoke($firstOperation);
    try {
        (new InstallLogic('test-plugin'))->dependencyCommandFailed('npm', 'fixture-nonce');
        throw new RuntimeException('concurrent recovery unexpectedly acquired the app operation lock');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'concurrent recovery failed for the wrong reason');
    }
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0, 'operation lock allowed recovery mutation');
    $releaseOperationLock->invoke($firstOperation);

    $dependencyPaths = fixture('dependency-command', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$dependencyPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    resetCounters();
    $dependency = new InstallLogic('test-plugin');
    $nonce = $dependency->beginDependencyCommand('npm');
    $dependency->releaseDependencyCommand();
    expect(is_string($nonce) && $nonce !== '', 'dependency command did not persist nonce');
    expect((Server::$info[$dependencyPaths['app_dir']]['dependency_command_lease_until'] ?? 0) > time(), 'dependency command did not persist lease');
    try {
        (new InstallLogic('test-plugin'))->beginDependencyCommand('npm');
        throw new RuntimeException('second dependency command ignored persistent nonce lock');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    foreach (['install', 'uninstall', 'register'] as $operation) {
        try {
            if ($operation === 'install') {
                (new InstallLogic('test-plugin'))->install(false);
            } elseif ($operation === 'uninstall') {
                (new InstallLogic('test-plugin'))->uninstall();
            } else {
                (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
            }
            throw new RuntimeException("dependency command did not block {$operation}");
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            expect(str_contains($exception->getMessage(), '依赖安装任务'), "dependency command blocked {$operation} with wrong error");
        }
    }
    try {
        (new InstallLogic('test-plugin'))->dependencyCommandFailed('npm', 'wrong-nonce');
        throw new RuntimeException('wrong dependency nonce unlocked the command');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect((Server::$info[$dependencyPaths['app_dir']]['dependency_command_nonce'] ?? '') === $nonce, 'wrong dependency nonce changed persistent state');
    $dependency->acquireDependencyExecutionLock('npm', $nonce);
    $result = $dependency->dependentInstallComplete('npm', $nonce, false);
    $dependency->releaseDependencyExecutionLock();
    expect(($result['completed'] ?? false) === true && (Server::$info[$dependencyPaths['app_dir']]['state'] ?? null) === InstallLogic::INSTALLED, 'dependency completion did not finalize stable state');
    expect(!isset(Server::$info[$dependencyPaths['app_dir']]['dependency_command_nonce']), 'dependency completion did not release nonce');
    foreach (Server::$infoWrites[$dependencyPaths['app_dir']] ?? [] as $write) {
        expect(isset($write['dependency_command_nonce']) || ($write['state'] ?? null) === InstallLogic::INSTALLED, 'dependency completion persisted a transient unlocked non-stable state');
    }

    $failedCompletionPaths = fixture('dependency-complete-failure', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$failedCompletionPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $failedDependency = new InstallLogic('test-plugin');
    $failedNonce = $failedDependency->beginDependencyCommand('npm');
    $failedDependency->releaseDependencyCommand();
    Server::$config[$failedCompletionPaths['app_dir']]['sand_platform'] = ['service_catalog' => []];
    try {
        $failedDependency->acquireDependencyExecutionLock('npm', $failedNonce);
        $failedDependency->dependentInstallComplete('npm', $failedNonce, false);
        throw new RuntimeException('dependency completion failure unexpectedly succeeded');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    $failedDependency->releaseDependencyExecutionLock();
    expect((Server::$info[$failedCompletionPaths['app_dir']]['state'] ?? null) === InstallLogic::FAILED, 'dependency completion failure did not restore failure state');
    expect(!isset(Server::$info[$failedCompletionPaths['app_dir']]['dependency_command_nonce']), 'dependency completion failure did not release nonce');
    expect((Server::$info[$failedCompletionPaths['app_dir']]['dependency_recovery_state'] ?? '') === 'restored', 'dependency completion failure did not record recovery');

    $expiredDependencyPaths = fixture('dependency-expired', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$expiredDependencyPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $expiredOriginal = new InstallLogic('test-plugin');
    $expiredNonce = $expiredOriginal->beginDependencyCommand('npm');
    $expiredOriginal->acquireDependencyExecutionLock('npm', $expiredNonce);
    Server::$info[$expiredDependencyPaths['app_dir']]['dependency_command_lease_until'] = time() - 1;
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->beginDependencyCommand('npm');
        throw new RuntimeException('expired command replaced while its execution lock was held');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect((Server::$info[$expiredDependencyPaths['app_dir']]['dependency_command_nonce'] ?? '') === $expiredNonce, 'held expired execution lock changed old nonce');
    $expiredOriginal->releaseDependencyExecutionLock();
    $replacementDependency = new InstallLogic('test-plugin');
    $replacementNonce = $replacementDependency->beginDependencyCommand('npm');
    expect($replacementNonce !== $expiredNonce, 'expired dependency command did not receive a fresh nonce');
    foreach (['complete', 'failure'] as $callback) {
        try {
            if ($callback === 'complete') {
                (new InstallLogic('test-plugin'))->dependentInstallComplete('npm', $expiredNonce, false);
            } else {
                (new InstallLogic('test-plugin'))->dependencyCommandFailed('npm', $expiredNonce);
            }
            throw new RuntimeException("stale expired {$callback} callback unlocked the replacement command");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect((Server::$info[$expiredDependencyPaths['app_dir']]['dependency_command_nonce'] ?? '') === $replacementNonce, "stale expired {$callback} callback changed replacement nonce");
    }
    $replacementDependency->acquireDependencyExecutionLock('npm', $replacementNonce);
    $replacementDependency->dependencyCommandFailed('npm', $replacementNonce);
    $replacementDependency->releaseDependencyExecutionLock();

    $beginRollbackPaths = fixture('dependency-begin-setinfo-failure', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$beginRollbackPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    resetCounters();
    Server::$setIniFailCount = 1;
    try {
        (new InstallLogic('test-plugin'))->beginDependencyCommand('npm');
        throw new RuntimeException('dependency begin succeeded after setInfo failure');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), 'setInfo failure left an ownerless host lease');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), 'setInfo failure left an unrecoverable journal');
    expect((Server::$info[$beginRollbackPaths['app_dir']]['dependency_command_nonce'] ?? null) === null, 'setInfo failure left an app nonce');

    foreach (['short_write', 'rename', 'chmod'] as $faultAction) {
        $leaseWritePaths = fixture('dependency-lease-' . $faultAction . '-failure', InstallLogic::DEPENDENT_WAIT_INSTALL);
        Server::$info[$leaseWritePaths['app_dir']]['npm_dependent_wait_install'] = 1;
        resetCounters();
        ContractHostPersistenceFault::$action = $faultAction;
        ContractHostPersistenceFault::$pathNeedle = $faultAction === 'chmod' ? '.lease.json' : '.lease.json.';
        ContractHostPersistenceFault::$remaining = 1;
        try {
            (new InstallLogic('test-plugin'))->beginDependencyCommand('npm');
            throw new RuntimeException("host lease {$faultAction} failure allowed a command");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json') && !is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), "host lease {$faultAction} failure left ownerless records");
        expect((Server::$info[$leaseWritePaths['app_dir']]['dependency_command_nonce'] ?? null) === null, "host lease {$faultAction} failure did not restore app snapshot");
        resetCounters();
    }

    foreach (['mkdir', 'fopen'] as $faultAction) {
        removeFixtureDirectory(runtime_path() . '/sandpackage/locks');
        $lockSetupPaths = fixture('dependency-lock-' . $faultAction . '-failure', InstallLogic::DEPENDENT_WAIT_INSTALL);
        Server::$info[$lockSetupPaths['app_dir']]['npm_dependent_wait_install'] = 1;
        resetCounters();
        ContractHostPersistenceFault::$action = $faultAction;
        ContractHostPersistenceFault::$pathNeedle = '/sandpackage/locks';
        ContractHostPersistenceFault::$remaining = 1;
        try {
            (new InstallLogic('test-plugin'))->beginDependencyCommand('npm');
            throw new RuntimeException("host lock {$faultAction} failure allowed a command");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json') && !is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), "host lock {$faultAction} failure left owner records");
        expect((Server::$info[$lockSetupPaths['app_dir']]['dependency_command_nonce'] ?? null) === null, "host lock {$faultAction} failure changed app snapshot");
        resetCounters();
    }

    $noRenewPaths = fixture('dependency-lease-no-renew', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$noRenewPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $noRenew = new InstallLogic('test-plugin');
    $noRenewNonce = $noRenew->beginDependencyCommand('npm');
    $leaseBeforeRenewAttempt = file_get_contents(runtime_path() . '/sandpackage/locks/host-npm.lease.json');
    try {
        (new InstallLogic('test-plugin'))->beginDependencyCommand('npm');
        throw new RuntimeException('existing host lease was silently renewed');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(file_get_contents(runtime_path() . '/sandpackage/locks/host-npm.lease.json') === $leaseBeforeRenewAttempt, 'existing host lease was changed by rejected renewal');
    $noRenew->acquireDependencyExecutionLock('npm', $noRenewNonce);
    $noRenew->dependencyCommandFailed('npm', $noRenewNonce);
    $noRenew->releaseDependencyExecutionLock();

    $orphanLeasePaths = fixture('dependency-expired-host-lease', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$orphanLeasePaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $orphanLeaseOwner = new InstallLogic('test-plugin');
    $orphanLeaseNonce = $orphanLeaseOwner->beginDependencyCommand('npm');
    $orphanLeasePath = runtime_path() . '/sandpackage/locks/host-npm.lease.json';
    $orphanLease = json_decode((string) file_get_contents($orphanLeasePath), true);
    $orphanLease['expires_at'] = time() - 1;
    file_put_contents($orphanLeasePath, json_encode($orphanLease));
    $orphanOtherPaths = fixture('dependency-expired-host-lease-other', InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
    Server::$info[$orphanOtherPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $orphanOther = new InstallLogic('other-plugin');
    $orphanOtherNonce = $orphanOther->beginDependencyCommand('npm');
    expect(!isset(Server::$info[$orphanLeasePaths['app_dir']]['dependency_command_nonce']) && (Server::$info[$orphanLeasePaths['app_dir']]['dependency_recovery_state'] ?? '') === 'lease_expired', 'expired no-journal host lease did not recover owner app');
    $orphanOther->acquireDependencyExecutionLock('npm', $orphanOtherNonce);
    $orphanOther->dependencyCommandFailed('npm', $orphanOtherNonce);
    $orphanOther->releaseDependencyExecutionLock();

    foreach (['lease-release', 'journal-cleanup'] as $failureStage) {
        $releaseFaultPaths = fixture('dependency-' . $failureStage . '-failure', InstallLogic::DEPENDENT_WAIT_INSTALL);
        Server::$info[$releaseFaultPaths['app_dir']]['npm_dependent_wait_install'] = 1;
        $releaseFault = new InstallLogic('test-plugin');
        $releaseFaultNonce = $releaseFault->beginDependencyCommand('npm');
        $releaseFault->acquireDependencyExecutionLock('npm', $releaseFaultNonce);
        ContractHostPersistenceFault::$action = 'unlink';
        ContractHostPersistenceFault::$pathNeedle = $failureStage === 'lease-release' ? '.lease.json' : '.journal.json';
        ContractHostPersistenceFault::$remaining = 1;
        try {
            $releaseFault->dependencyCommandFailed('npm', $releaseFaultNonce);
            throw new RuntimeException("{$failureStage} unlink failure allowed final callback");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        resetCounters();
        $releaseFault->releaseDependencyExecutionLock();
        expect(is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), "{$failureStage} failure did not retain recovery journal");
        $releaseOtherPaths = fixture('dependency-' . $failureStage . '-other', InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
        Server::$info[$releaseOtherPaths['app_dir']]['npm_dependent_wait_install'] = 1;
        $releaseOther = new InstallLogic('other-plugin');
        $releaseOtherNonce = $releaseOther->beginDependencyCommand('npm');
        $replacementLease = json_decode((string) file_get_contents(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), true);
        expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json') && ($replacementLease['app'] ?? null) === 'other-plugin', "{$failureStage} recovery left stale host records");
        $releaseOther->acquireDependencyExecutionLock('npm', $releaseOtherNonce);
        $releaseOther->dependencyCommandFailed('npm', $releaseOtherNonce);
        $releaseOther->releaseDependencyExecutionLock();
        expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), "{$failureStage} replacement cleanup left host lease");
    }

    $writeJournal = new ReflectionMethod(InstallLogic::class, 'writeHostDependencyJournal');
    $recoverJournal = new ReflectionMethod(InstallLogic::class, 'recoverHostDependencyJournal');
    $acquireHostLease = new ReflectionMethod(InstallLogic::class, 'acquireHostDependencyLease');
    foreach (['PREPARED', 'APP_PREPARED', 'HOST_WRITTEN', 'APP_ACTIVE'] as $phase) {
        $phasePaths = fixture('journal-' . strtolower($phase), InstallLogic::DEPENDENT_WAIT_INSTALL);
        Server::$info[$phasePaths['app_dir']]['npm_dependent_wait_install'] = 1;
        $phasePrevious = Server::$info[$phasePaths['app_dir']];
        $phaseLogic = new InstallLogic('test-plugin');
        $phaseNonce = 'phase-' . strtolower($phase);
        if (in_array($phase, ['HOST_WRITTEN', 'APP_ACTIVE'], true)) {
            $acquireHostLease->invoke($phaseLogic, 'npm', $phaseNonce);
        }
        if ($phase === 'APP_ACTIVE') {
            Server::$info[$phasePaths['app_dir']]['dependency_command_nonce'] = $phaseNonce;
            Server::$info[$phasePaths['app_dir']]['dependency_command_type'] = 'npm';
            Server::$info[$phasePaths['app_dir']]['dependency_host_lease_state'] = 'active';
        }
        $writeJournal->invoke($phaseLogic, 'npm', $phaseNonce, $phase, $phasePrevious);
        $recoverJournal->invoke($phaseLogic, 'npm');
        expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json') && !is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), "journal {$phase} recovery left host records");
        expect((Server::$info[$phasePaths['app_dir']]['dependency_command_nonce'] ?? null) === null, "journal {$phase} recovery did not restore previous app snapshot");
    }

    $crossOwnerPaths = fixture('journal-cross-owner', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$crossOwnerPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $crossPrevious = Server::$info[$crossOwnerPaths['app_dir']];
    $crossOwner = new InstallLogic('test-plugin');
    $writeJournal->invoke($crossOwner, 'npm', 'cross-owner-nonce', 'PREPARED', $crossPrevious);
    $crossOtherPaths = fixture('journal-cross-owner-other', InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
    Server::$info[$crossOtherPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $crossOther = new InstallLogic('other-plugin');
    $crossOtherNonce = $crossOther->beginDependencyCommand('npm');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), 'cross-owner journal entry was not recovered');
    $crossOther->acquireDependencyExecutionLock('npm', $crossOtherNonce);
    $crossOther->dependencyCommandFailed('npm', $crossOtherNonce);
    $crossOther->releaseDependencyExecutionLock();

    $heldJournalPaths = fixture('journal-held-process', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$heldJournalPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $heldPrevious = Server::$info[$heldJournalPaths['app_dir']];
    $heldOwner = new InstallLogic('test-plugin');
    $heldNonce = $heldOwner->beginDependencyCommand('npm');
    $heldOwner->acquireDependencyExecutionLock('npm', $heldNonce);
    $writeJournal->invoke($heldOwner, 'npm', $heldNonce, 'APP_ACTIVE', $heldPrevious);
    try {
        (new InstallLogic('other-plugin'))->beginDependencyCommand('npm');
        throw new RuntimeException('held execution flock permitted journal recovery');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    $heldOwner->releaseDependencyExecutionLock();
    $recoverJournal->invoke($heldOwner, 'npm');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), 'held journal recovery left host lease after process exit');

    $hostFirstPaths = fixture('host-lease-first', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$hostFirstPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $hostFirst = new InstallLogic('test-plugin');
    $hostFirstNonce = $hostFirst->beginDependencyCommand('npm');
    $hostFirst->acquireDependencyExecutionLock('npm', $hostFirstNonce);

    $hostSecondPaths = fixture('host-lease-second', InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
    Server::$info[$hostSecondPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    Server::$info[$hostSecondPaths['app_dir']]['composer_dependent_wait_install'] = 1;
    resetCounters();
    try {
        (new InstallLogic('other-plugin'))->beginDependencyCommand('npm');
        throw new RuntimeException('second app acquired active host npm lease');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '宿主依赖命令'), 'second app npm lock had wrong error');
    }
    try {
        (new InstallLogic('other-plugin'))->install(false);
        throw new RuntimeException('second app lifecycle ignored active host lease');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '宿主依赖命令'), 'second app lifecycle lock had wrong error');
    }
    expect(Server::$importCalls === 0, 'cross-plugin lifecycle invoked SQL while host lease was active');
    $hostSecond = new InstallLogic('other-plugin');
    $composerNonce = $hostSecond->beginDependencyCommand('composer');
    $hostSecond->acquireDependencyExecutionLock('composer', $composerNonce);
    $hostSecond->dependencyCommandFailed('composer', $composerNonce);
    $hostSecond->releaseDependencyExecutionLock();

    $hostFirst->dependencyCommandFailed('npm', $hostFirstNonce);
    $hostFirst->releaseDependencyExecutionLock();
    $hostSecondPaths = fixture('host-lease-second-replacement', InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
    Server::$info[$hostSecondPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $hostSecondNpm = new InstallLogic('other-plugin');
    $hostSecondNpmNonce = $hostSecondNpm->beginDependencyCommand('npm');
    try {
        $hostFirst->dependencyCommandFailed('npm', $hostFirstNonce);
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    $hostLeaseFile = runtime_path() . '/sandpackage/locks/host-npm.lease.json';
    $hostLease = json_decode((string) file_get_contents($hostLeaseFile), true);
    expect(($hostLease['app'] ?? null) === 'other-plugin' && ($hostLease['nonce'] ?? null) === $hostSecondNpmNonce, 'stale callback released replacement host lease');
    $hostSecondNpm->acquireDependencyExecutionLock('npm', $hostSecondNpmNonce);
    $hostSecondNpm->dependencyCommandFailed('npm', $hostSecondNpmNonce);
    $hostSecondNpm->releaseDependencyExecutionLock();

    $uploadLockPaths = fixture('upload-lock', InstallLogic::WAIT_INSTALL);
    $uploadSource = packageZip('upload-from-path-lock');
    resetCounters();
    $acquireOperationLock->invoke($firstOperation);
    try {
        (new InstallLogic())->uploadFromPath($uploadSource);
        throw new RuntimeException('uploadFromPath acquired an app lock after static preflight');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'uploadFromPath conflict had wrong error');
    }
    expect(is_file($uploadSource), 'uploadFromPath conflict removed caller ZIP');
    expect(glob(runtime_path() . '/sandpackage/upload-*') === [], 'uploadFromPath conflict extracted a temporary package');
    $releaseOperationLock->invoke($firstOperation);

    $uploadFileSource = packageZip('upload-file-lock');
    resetCounters();
    $acquireOperationLock->invoke($firstOperation);
    try {
        (new InstallLogic())->upload(new UploadFixture($uploadFileSource));
        throw new RuntimeException('upload acquired an app lock after static preflight');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'upload conflict had wrong error');
    }
    expect(is_file($uploadFileSource), 'upload conflict moved caller ZIP');
    expect(glob(runtime_path() . '/sandpackage/upload-*') === [], 'upload conflict extracted or copied a temporary package');
    $releaseOperationLock->invoke($firstOperation);

    $unsafeUpload = packageZip('upload-traversal', ['../escape.php' => '<?php']);
    resetCounters();
    try {
        (new InstallLogic())->uploadFromPath($unsafeUpload);
        throw new RuntimeException('traversal ZIP passed static preflight');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(is_file($unsafeUpload), 'traversal ZIP was removed during static preflight');
    expect(glob(runtime_path() . '/sandpackage/upload-*') === [], 'traversal ZIP created extracted temporary resources');

    $symlinkUpload = packageZip('upload-symlink', ['linked.php' => 'target']);
    $symlinkZip = new ZipArchive();
    expect($symlinkZip->open($symlinkUpload) === true, 'symlink ZIP fixture could not reopen');
    expect($symlinkZip->setExternalAttributesName('linked.php', ZipArchive::OPSYS_UNIX, 0120777 << 16), 'symlink ZIP fixture could not set Unix mode');
    $symlinkZip->close();
    try {
        (new InstallLogic())->uploadFromPath($symlinkUpload);
        throw new RuntimeException('Unix-mode symlink ZIP passed static preflight');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(is_file($symlinkUpload), 'symlink ZIP mutation removed caller input');
    expect(glob(runtime_path() . '/sandpackage/uploads/*.zip') === [], 'symlink ZIP left private candidate');

    $mutableUpload = packageZip('upload-candidate-mutation', [
        'info.ini' => "app = mutation-plugin\ntitle = Fixture\nabout = Fixture\nauthor = Fixture\nversion = 1.2.3\n",
        'plugin/mutation-plugin/info.ini' => "app = mutation-plugin\nversion = 1.2.3\n",
    ]);
    resetCounters();
    Filesystem::$mutateArchiveDuringUnzip = true;
    try {
        (new InstallLogic())->uploadFromPath($mutableUpload);
        throw new RuntimeException('mutated private ZIP reached deployment');
    } catch (\plugin\sandadmin\exception\ApiException|RuntimeException) {
    }
    expect(is_file($mutableUpload), 'candidate mutation removed caller ZIP');
    expect(!is_dir(runtime_path() . '/sandpackage/mutation-plugin'), 'candidate mutation deployed a package');
    expect(glob(runtime_path() . '/sandpackage/upload-*') === [], 'candidate mutation left extracted package');
    expect(glob(runtime_path() . '/sandpackage/uploads/*.zip') === [], 'candidate mutation left private ZIP');

    $extraUpload = packageZip('upload-extract-extra', [
        'info.ini' => "app = extra-plugin\ntitle = Fixture\nabout = Fixture\nauthor = Fixture\nversion = 1.2.3\n",
        'plugin/extra-plugin/info.ini' => "app = extra-plugin\nversion = 1.2.3\n",
    ]);
    Filesystem::$addExtraDuringUnzip = true;
    try {
        (new InstallLogic())->uploadFromPath($extraUpload);
        throw new RuntimeException('post-extract extra file reached deployment');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect(is_file($extraUpload) && !is_dir(runtime_path() . '/sandpackage/extra-plugin'), 'post-extract manifest mismatch mutated input or deployed');
    expect(glob(runtime_path() . '/sandpackage/upload-*') === [] && glob(runtime_path() . '/sandpackage/uploads/*.zip') === [], 'post-extract manifest mismatch left controlled resources');

    $terminalDir = $contractRoot . '/sandadmin-artd';
    $terminalBin = $contractRoot . '/terminal-bin';
    mkdirFor($terminalDir);
    mkdirFor($terminalBin);
    $terminalCommand = $terminalBin . '/npm';
    writeFixture($terminalCommand, "#!/bin/sh\necho fixture-npm\nexit 0\n");
    chmod($terminalCommand, 0700);
    $contractConfig['plugin.sandpackage.terminal.commands'] = [
        'web-install' => ['npm' => ['cwd' => $terminalDir, 'command' => 'npm install']],
    ];
    $originalPath = getenv('PATH');
    putenv('PATH=' . $terminalBin . ':' . $originalPath);
    $terminalPaths = fixture('terminal-runner-success', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$terminalPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $contractRequest = new TerminalRequestFixture(['command' => 'web-install.npm', 'extend' => 'module-install:test-plugin']);
    resetCounters();
    $terminalRunner = new TerminalRunner();
    $terminalFrames = iterator_to_array($terminalRunner->exec());
    expect(implode("\n", $terminalFrames) !== '' && str_contains(implode("\n", $terminalFrames), 'exec-success'), 'TerminalRunner fake subprocess did not complete successfully');
    expect((Server::$info[$terminalPaths['app_dir']]['state'] ?? null) === InstallLogic::INSTALLED, 'TerminalRunner fake subprocess did not finalize plugin state');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), 'TerminalRunner fake subprocess left host lease');

    writeFixture($terminalCommand, "#!/bin/sh\necho fixture-npm-failure\nexit 1\n");
    chmod($terminalCommand, 0700);
    $terminalFailurePaths = fixture('terminal-runner-failure', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$terminalFailurePaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $contractRequest = new TerminalRequestFixture(['command' => 'web-install.npm', 'extend' => 'module-install:test-plugin']);
    $terminalFailureRunner = new TerminalRunner();
    $terminalFailureFrames = iterator_to_array($terminalFailureRunner->exec());
    expect(str_contains(implode("\n", $terminalFailureFrames), 'exec-error'), 'TerminalRunner failed subprocess did not report terminal failure');
    expect((Server::$info[$terminalFailurePaths['app_dir']]['state'] ?? null) === InstallLogic::FAILED, 'TerminalRunner failed subprocess did not finalize failure state');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json'), 'TerminalRunner failed subprocess left host lease');

    writeFixture($terminalCommand, "#!/bin/sh\nsleep 5\necho fixture-npm-slow\nexit 0\n");
    chmod($terminalCommand, 0700);
    $terminalAbortPaths = fixture('terminal-runner-abort', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$terminalAbortPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $contractRequest = new TerminalRequestFixture(['command' => 'web-install.npm', 'extend' => 'module-install:test-plugin']);
    $terminalAbortRunner = new TerminalRunner();
    $terminalAbortGenerator = $terminalAbortRunner->exec();
    $terminalAbortGenerator->rewind();
    expect(str_contains((string) $terminalAbortGenerator->current(), 'connection-success'), 'TerminalRunner abort fixture did not start subprocess');
    $executionLock = runtime_path() . '/sandpackage/locks/host-npm.execution.lock';
    $lockProbe = proc_open([PHP_BINARY, '-r', '$f=fopen($argv[1],"c"); exit(flock($f, LOCK_EX|LOCK_NB) ? 1 : 0);', $executionLock], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $lockPipes);
    expect(is_resource($lockProbe), 'TerminalRunner abort lock probe could not start');
    foreach ($lockPipes as $pipe) { fclose($pipe); }
    expect(proc_close($lockProbe) === 0, 'TerminalRunner did not hold execution flock while subprocess lived');
    $terminalAbortRunner->abort();
    while ($terminalAbortGenerator->valid()) { $terminalAbortGenerator->next(); }
    expect((Server::$info[$terminalAbortPaths['app_dir']]['state'] ?? null) === InstallLogic::FAILED, 'TerminalRunner abort did not record failure state');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json') && !is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), 'TerminalRunner abort left host lease or journal');

    writeFixture($terminalCommand, "#!/bin/sh\nhead -c 40000 /dev/zero | tr '\\000' x\nexit 0\n");
    chmod($terminalCommand, 0700);
    $terminalTimeoutPaths = fixture('terminal-runner-output-timeout', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$terminalTimeoutPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $contractRequest = new TerminalRequestFixture(['command' => 'web-install.npm', 'extend' => 'module-install:test-plugin']);
    $terminalTimeoutRunner = new TerminalRunner();
    $terminalTimeoutFrames = iterator_to_array($terminalTimeoutRunner->exec());
    expect(str_contains(implode("\n", $terminalTimeoutFrames), 'exec-error'), 'TerminalRunner output-limit timeout did not report failure');
    expect((Server::$info[$terminalTimeoutPaths['app_dir']]['state'] ?? null) === InstallLogic::FAILED, 'TerminalRunner output-limit timeout did not record failure');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json') && !is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), 'TerminalRunner output-limit timeout left host lease or journal');

    writeFixture($terminalCommand, "#!/bin/sh\nsleep 2\nexit 0\n");
    chmod($terminalCommand, 0700);
    $contractConfig['plugin.sandpackage.terminal.max_runtime_seconds'] = 1;
    $terminalWallTimeoutPaths = fixture('terminal-runner-wall-timeout', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$terminalWallTimeoutPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $contractRequest = new TerminalRequestFixture(['command' => 'web-install.npm', 'extend' => 'module-install:test-plugin']);
    $terminalWallTimeoutRunner = new TerminalRunner();
    $terminalWallTimeoutFrames = iterator_to_array($terminalWallTimeoutRunner->exec());
    expect(str_contains(implode("\n", $terminalWallTimeoutFrames), 'exec-error'), 'TerminalRunner wall-clock timeout did not report failure');
    expect((Server::$info[$terminalWallTimeoutPaths['app_dir']]['state'] ?? null) === InstallLogic::FAILED, 'TerminalRunner wall-clock timeout did not record failure');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.lease.json') && !is_file(runtime_path() . '/sandpackage/locks/host-npm.journal.json'), 'TerminalRunner wall-clock timeout left host lease or journal');
    unset($contractConfig['plugin.sandpackage.terminal.max_runtime_seconds']);
    putenv('PATH=' . $originalPath);

    $positivePaths = fixture('register-positive', InstallLogic::WAIT_INSTALL, true);
    resetCounters();
    $firstRegistration = new InstallLogic('test-plugin');
    $acquireOperationLock->invoke($firstRegistration);
    try {
        (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
        throw new RuntimeException('concurrent registration unexpectedly acquired the app lock');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        expect(str_contains($exception->getMessage(), '正在执行'), 'concurrent registration failed for the wrong reason');
    }
    $releaseOperationLock->invoke($firstRegistration);
    $registered = (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
    expect(($registered['state'] ?? null) === InstallLogic::INSTALLED, 'positive registration did not mark installed');
    expect(($registered['stage'] ?? null) === 'registered', 'positive registration stage is wrong');
    expect(UserMenuCache::$clearCalls === 1, 'positive registration did not clear cache once');
    $idempotent = (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
    expect(($idempotent['stage'] ?? null) === 'registered', 'idempotent registration changed stage');
    expect(UserMenuCache::$clearCalls === 1, 'idempotent registration cleared cache');

    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@9.9.9');
        throw new RuntimeException('invalid confirmation unexpectedly succeeded');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect((Server::$info[$positivePaths['app_dir']]['state'] ?? null) === InstallLogic::INSTALLED, 'invalid confirmation changed the installed state');
    expect((Server::$info[$positivePaths['app_dir']]['failed_stage'] ?? null) === 'registration_confirmation', 'invalid confirmation stage is wrong');
    expect((Server::$info[$positivePaths['app_dir']]['last_error'] ?? '') !== '', 'invalid confirmation did not record an error');

    $missingCandidatePaths = fixture('registration-missing-candidate', InstallLogic::INSTALLED);
    Server::$info[$missingCandidatePaths['app_dir']]['stage'] = 'registered';
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
        throw new RuntimeException('missing registration candidate unexpectedly succeeded');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect((Server::$info[$missingCandidatePaths['app_dir']]['state'] ?? null) === InstallLogic::INSTALLED, 'missing registration candidate changed the installed state');
    expect((Server::$info[$missingCandidatePaths['app_dir']]['failed_stage'] ?? null) === 'registration_confirmation', 'missing registration candidate stage is wrong');
    expect((Server::$info[$missingCandidatePaths['app_dir']]['last_error'] ?? '') !== '', 'missing registration candidate did not record an error');

    $revalidationPaths = fixture('register-revalidation', InstallLogic::WAIT_INSTALL, true);
    (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
    writeFixture($revalidationPaths['runtime_backend'] . '/payload.php', "<?php // changed after registration\n");
    resetCounters();
    try {
        (new InstallLogic('test-plugin'))->registerExisting('REGISTER test-plugin@1.2.3');
        throw new RuntimeException('changed registered deployment unexpectedly passed revalidation');
    } catch (\plugin\sandadmin\exception\ApiException) {
    }
    expect((Server::$info[$revalidationPaths['app_dir']]['state'] ?? null) === InstallLogic::INSTALLED, 'revalidation failure changed the installed state');
    expect((Server::$info[$revalidationPaths['app_dir']]['failed_stage'] ?? null) === 'registration_revalidation', 'revalidation failure stage is wrong');
    expect(Server::$importCalls === 0 && Filesystem::$deleteCalls === 0 && Server::$restartCalls === 0, 'revalidation failure mutated host lifecycle');

    // A future command from a different plugin recovers only a dead process
    // journal, under the same host-type execution flock, before it can begin.
    $processRecoveryOwnerPaths = fixture('process-recovery-owner', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$processRecoveryOwnerPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    Server::$info[$processRecoveryOwnerPaths['app_dir']]['process_recovery_required'] = 1;
    Server::$info[$processRecoveryOwnerPaths['app_dir']]['dependency_process_journal'] = json_encode(['nonce' => 'dead-process-nonce', 'type' => 'npm', 'launcher_pid' => 99999991, 'pgid' => 99999991, 'descendant_pids' => [99999992], 'start_time' => time() - 10, 'failure_time' => time() - 5, 'phase' => 'RECOVERY_REQUIRED'], JSON_THROW_ON_ERROR);
    writeFixture(runtime_path() . '/sandpackage/locks/host-npm.process.journal.json', json_encode(['app' => 'test-plugin', 'nonce' => 'dead-process-nonce', 'type' => 'npm', 'launcher_pid' => 99999991, 'pgid' => 99999991, 'descendant_pids' => [99999992], 'start_time' => time() - 10, 'failure_time' => time() - 5, 'phase' => 'RECOVERY_REQUIRED', 'created_at' => time() - 10, 'updated_at' => time() - 5], JSON_THROW_ON_ERROR));
    $processRecoveryOtherPaths = fixture('process-recovery-other', InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
    Server::$info[$processRecoveryOtherPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $processRecoveryOther = new InstallLogic('other-plugin');
    $processRecoveryOtherNonce = $processRecoveryOther->beginDependencyCommand('npm');
    expect(!is_file(runtime_path() . '/sandpackage/locks/host-npm.process.journal.json') && (Server::$info[$processRecoveryOwnerPaths['app_dir']]['process_recovery_required'] ?? 0) != 1, 'cross-plugin start did not recover a fully dead process journal');
    $processRecoveryOther->acquireDependencyExecutionLock('npm', $processRecoveryOtherNonce);
    $processRecoveryOther->dependencyCommandFailed('npm', $processRecoveryOtherNonce);
    $processRecoveryOther->releaseDependencyExecutionLock();

    // Process supervision is host-type scoped. A live/ambiguous npm process
    // record rejects only npm; composer can acquire its independent lock and
    // must not rewrite the npm record (and the converse is also required).
    foreach (['npm' => 'composer', 'composer' => 'npm'] as $blockedType => $allowedType) {
        $typeOwnerPaths = fixture('process-type-owner-' . $blockedType, InstallLogic::DEPENDENT_WAIT_INSTALL);
        Server::$info[$typeOwnerPaths['app_dir']][$blockedType === 'npm' ? 'npm_dependent_wait_install' : 'composer_dependent_wait_install'] = 1;
        $activeJournal = ['app' => 'test-plugin', 'nonce' => 'active-' . $blockedType . '-nonce', 'type' => $blockedType,
            'launcher_pid' => getmypid(), 'pgid' => posix_getpgrp(), 'descendant_pids' => [], 'start_time' => time(),
            'failure_time' => null, 'phase' => 'LAUNCHER_READY', 'created_at' => time(), 'updated_at' => time()];
        Server::$info[$typeOwnerPaths['app_dir']]['process_recovery_required'] = 1;
        Server::$info[$typeOwnerPaths['app_dir']]['dependency_process_journal'] = json_encode([
            'nonce' => $activeJournal['nonce'], 'type' => $blockedType, 'launcher_pid' => $activeJournal['launcher_pid'],
            'pgid' => $activeJournal['pgid'], 'descendant_pids' => [], 'start_time' => $activeJournal['start_time'],
            'failure_time' => null, 'phase' => 'LAUNCHER_READY',
        ], JSON_THROW_ON_ERROR);
        $activeJournalPath = runtime_path() . '/sandpackage/locks/host-' . $blockedType . '.process.journal.json';
        writeFixture($activeJournalPath, json_encode($activeJournal, JSON_THROW_ON_ERROR));
        $activeJournalBefore = file_get_contents($activeJournalPath);

        $otherTypePaths = fixture('process-type-other-' . $blockedType, InstallLogic::DEPENDENT_WAIT_INSTALL, false, 'other-plugin');
        Server::$info[$otherTypePaths['app_dir']][$allowedType === 'npm' ? 'npm_dependent_wait_install' : 'composer_dependent_wait_install'] = 1;
        $otherType = new InstallLogic('other-plugin');
        $otherTypeNonce = $otherType->beginDependencyCommand($allowedType);
        expect(file_get_contents($activeJournalPath) === $activeJournalBefore, "{$blockedType} process journal was rewritten by {$allowedType}");
        $otherType->acquireDependencyExecutionLock($allowedType, $otherTypeNonce);
        $otherType->dependencyCommandFailed($allowedType, $otherTypeNonce);
        $otherType->releaseDependencyExecutionLock();
        try {
            (new InstallLogic('test-plugin'))->beginDependencyCommand($blockedType);
            throw new RuntimeException("active {$blockedType} process journal permitted the same type");
        } catch (\plugin\sandadmin\exception\ApiException) {
        }
        unlink($activeJournalPath);
        unset(Server::$info[$typeOwnerPaths['app_dir']]['process_recovery_required'], Server::$info[$typeOwnerPaths['app_dir']]['dependency_process_journal']);
    }

    // A TERM-resistant package manager can leave a descendant that the OS has
    // not reaped yet. It must be killed with its isolated PGID and retain the
    // dual recovery record until a future locked recovery can prove absence.
    $terminalChildPidFile = $contractRoot . '/term-ignoring-child.pid';
    putenv('PATH=' . $terminalBin . ':' . $originalPath);
    writeFixture($terminalCommand, "#!/bin/sh\ntrap '' TERM\n( trap '' TERM; while :; do sleep 1; done ) &\necho \$! > " . escapeshellarg($terminalChildPidFile) . "\nwhile :; do sleep 1; done\n");
    chmod($terminalCommand, 0700);
    $terminalTermIgnoringPaths = fixture('terminal-runner-term-ignoring', InstallLogic::DEPENDENT_WAIT_INSTALL);
    Server::$info[$terminalTermIgnoringPaths['app_dir']]['npm_dependent_wait_install'] = 1;
    $contractRequest = new TerminalRequestFixture(['command' => 'web-install.npm', 'extend' => 'module-install:test-plugin']);
    $terminalTermIgnoringRunner = new TerminalRunner();
    $terminalTermIgnoringGenerator = $terminalTermIgnoringRunner->exec();
    $terminalFixtureGroupId = null;
    try {
        $terminalTermIgnoringGenerator->rewind();
        $terminalFixtureGroupId = (new \ReflectionProperty(TerminalRunner::class, 'processGroupId'))->getValue($terminalTermIgnoringRunner);
        expect(is_int($terminalFixtureGroupId) && $terminalFixtureGroupId > 0 && $terminalFixtureGroupId !== posix_getpgrp(), 'dependency fixture group was not isolated');
        $childDeadline = microtime(true) + 2;
        while (!is_file($terminalChildPidFile) && microtime(true) < $childDeadline) { usleep(10000); }
        expect(is_file($terminalChildPidFile), 'TERM-ignoring dependency fixture did not spawn a child');
        $terminalChildPid = (int) trim((string) file_get_contents($terminalChildPidFile));
        expect($terminalChildPid > 0 && posix_kill($terminalChildPid, 0), 'TERM-ignoring dependency child was not live');
        $terminalTermIgnoringRunner->abort();
        while ($terminalTermIgnoringGenerator->valid()) { $terminalTermIgnoringGenerator->next(); }
        $terminalChildState = trim((string) shell_exec('/bin/ps -o stat= -p ' . $terminalChildPid . ' 2>/dev/null'));
        expect($terminalChildState === '' || str_starts_with($terminalChildState, 'Z'), 'TerminalRunner left TERM-ignoring descendant running after PGID KILL');
        // Some hosts promptly reap orphaned children; only unresolved processes
        // require a durable recovery marker after termination.
        if (posix_kill(-$terminalFixtureGroupId, 0) || posix_kill($terminalChildPid, 0)) {
            expect((Server::$info[$terminalTermIgnoringPaths['app_dir']]['process_recovery_required'] ?? 0) == 1 && is_file(runtime_path() . '/sandpackage/locks/host-npm.process.journal.json'), 'unreaped process state did not retain a durable recovery marker');
        }
    } finally {
        if (is_int($terminalFixtureGroupId) && $terminalFixtureGroupId > 0 && $terminalFixtureGroupId !== posix_getpgrp()) {
            @posix_kill(-$terminalFixtureGroupId, SIGKILL);
        }
        putenv('PATH=' . $originalPath);
    }

    echo "PASS lifecycle non-DB contract\n";
}
