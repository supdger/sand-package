<?php
declare(strict_types=1);

// Repository-to-installer filesystem integration. Network is a deterministic fixture;
// SQL uses a recording connection. No real database, HTTP service or restart.
namespace think\facade {
    final class Db
    {
        public static array $sql = [];
        public static bool $fail = false;
        public static function connect(string $name = ''): object
        {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected database connection');
            return new class {
                public function connect(): object { return $this; }
                public function inTransaction(): bool { return false; }
                public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
                public function query(string $sql): object {
                    return new class($sql) {
                        public function __construct(private string $sql) {}
                        public function fetchAll(int $mode): array {
                            return str_contains($this->sql, 'current_database()') ? [['database' => 'recording-fixture', 'oid' => '1', 'username' => 'fixture', 'address' => null, 'port' => null, 'started' => 'fixed']] : [];
                        }
                    };
                }
                public function exec(string $sql): int {
                    Db::$sql[] = trim($sql);
                    if (Db::$fail && str_contains($sql, 'BROKEN SQL')) throw new \RuntimeException('isolated simulated SQL error');
                    return 1;
                }
            };
        }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache { public static function clearMenuCache(): void {} }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use Saithink\Saipackage\service\Server;
    use think\facade\Db;

    $root = realpath(sys_get_temp_dir()) . '/sandpackage-repository-' . bin2hex(random_bytes(6));
    mkdir($root . '/server/plugin', 0755, true);
    mkdir($root . '/sandadmin-artd', 0755, true);
    ini_set('error_log', $root . '/expected-errors.log');
    function base_path($path = ''): string { global $root; return $root . '/server' . ($path ? '/' . $path : ''); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path ? '/' . $path : ''); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/InstallLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';

    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo "[PASS] $message\n";
    }
    function rejected(callable $operation, string $message): void {
        try { $operation(); } catch (\plugin\sandadmin\exception\ApiException) { echo "[PASS] $message\n"; return; }
        throw new RuntimeException($message);
    }
    function package(string $app, string $version, array $config = [], array $extra = []): string {
        global $root;
        $path = $root . '/' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('info.ini', "app = $app\ntitle = Neutral\nabout = Fixture\nauthor = Test\nversion = $version\nstate = 0\n");
        $zip->addFromString('config.json', json_encode($config, JSON_THROW_ON_ERROR));
        $zip->addFromString('install.sql', "CREATE TABLE neutral_sample (id bigint);\n");
        $zip->addFromString('update.sql', "ALTER TABLE neutral_sample ADD COLUMN label text;\n");
        $zip->addFromString('uninstall.sql', "DROP TABLE neutral_sample;\n");
        $zip->addFromString("plugin/$app/config/app.php", "<?php return ['version' => '$version'];\n");
        $zip->addFromString("sandadmin-artd/src/views/plugin/$app/index.vue", '<template><div>Neutral ' . $version . '</div></template>');
        foreach ($extra as $name => $body) $zip->addFromString($name, $body);
        $zip->close();
        return $path;
    }

    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/RepositoryClient.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/RepositoryLogic.php';

    final class FixtureRepositoryClient implements \plugin\sandpackage\app\service\RepositoryClient
    {
        public array $requests = [];
        public function __construct(public string $catalog, public string $zip, public ?Throwable $error = null) {}
        public function get(string $url, int $maxBytes, callable $complete): void
        {
            $this->requests[] = [$url, $maxBytes];
            $complete($this->error === null ? (str_contains($url, 'raw.githubusercontent.com') ? $this->catalog : $this->zip) : null, $this->error);
        }
    }
    function manifest(string $zip, string $version = '1.0.0', string $app = 'neutral-sample'): array {
        return ['schema' => 1, 'plugins' => [['app' => $app, 'repository' => 'supdger/' . $app, 'title' => 'Neutral', 'about' => 'Fixture', 'author' => 'Test',
            'versions' => [['version' => $version, 'tag' => $app . '-v' . $version, 'asset' => $app . '-' . $version . '.zip',
                'sha256' => hash('sha256', $zip), 'host_min' => '6.0.0', 'host_max' => '6.9.9', 'notes' => 'Test release']]]]];
    }
    function downloadFixture(FixtureRepositoryClient $client, string $version = '1.0.0', ?string $sha = null, string $host = '6.0.11'): array {
        $logic = new \plugin\sandpackage\app\logic\RepositoryLogic($client, 'supdger/sandadmin', 'main', $host);
        $calls = 0;
        $result = null;
        $failure = null;
        $logic->download('neutral-sample', $version, $sha ?? hash('sha256', $client->zip), function (?array $data, ?Throwable $error) use (&$calls, &$result, &$failure): void {
            $calls++;
            $result = $data;
            $failure = $error;
        });
        check($calls === 1, 'download completes exactly once');
        if ($failure !== null) throw $failure;
        return $result;
    }
    function clientFor(string $zip, string $version = '1.0.0'): FixtureRepositoryClient {
        return new FixtureRepositoryClient(json_encode(manifest($zip, $version), JSON_THROW_ON_ERROR), $zip);
    }
    function catalogFixture(FixtureRepositoryClient $client, string $host = '6.0.11'): array {
        $logic = new \plugin\sandpackage\app\logic\RepositoryLogic($client, 'supdger/sandadmin', 'main', $host);
        $calls = 0; $result = null; $failure = null;
        $logic->catalog(function (?array $data, ?Throwable $error) use (&$calls, &$result, &$failure): void {
            $calls++; $result = $data; $failure = $error;
        });
        check($calls === 1, 'catalog completes exactly once');
        if ($failure !== null) throw $failure;
        return $result;
    }
    function documentFixture(FixtureRepositoryClient $client, string $app, string $version, string $sha): array {
        $logic = new \plugin\sandpackage\app\logic\RepositoryLogic($client, 'supdger/sandadmin', 'main', '6.0.11');
        $calls = 0; $result = null; $failure = null;
        $logic->document($app, $version, $sha, function (?array $data, ?Throwable $error) use (&$calls, &$result, &$failure): void {
            $calls++; $result = $data; $failure = $error;
        });
        check($calls === 1, 'document completes exactly once');
        if ($failure !== null) throw $failure;
        return $result;
    }
    function writeInstallRecord(string $app, string $version, int $state, string $driver = 'saipackage-pg-v1'): void {
        $directory = runtime_path('sandpackage/' . $app);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('record fixture directory failed');
        file_put_contents($directory . '/info.ini', "app = \"$app\"\nversion = \"$version\"\nstate = $state\nlifecycle_driver = \"$driver\"\n");
    }
    function addCatalogVersion(array $plugin, string $version): array {
        $entry = $plugin['versions'][0];
        $entry['version'] = $version;
        $plugin['versions'][] = $entry;
        return $plugin;
    }

    try {
        file_put_contents(base_path('composer.json'), '{"name":"test/host","require":{}}');
        file_put_contents($root . '/sandadmin-artd/package.json', '{"name":"test-host","dependencies":{}}');
        $zip = file_get_contents(package('neutral-sample', '1.0.0'));
        $client = clientFor($zip);
        $repository = new \plugin\sandpackage\app\logic\RepositoryLogic($client, 'supdger/sandadmin', 'main', '6.0.11');
        $repository->catalog(function (?array $result, ?Throwable $error): void {
            check($error === null && $result['repository'] === 'supdger/sandadmin' && $result['plugins'][0]['versions'][0]['version'] === '1.0.0', 'repository catalog exposes versioned package without store account');
            check($result['plugins'][0]['local'] === [
                'state' => 0, 'version' => null, 'installed_version' => null, 'blocked' => false, 'reason' => '',
            ] && $result['plugins'][0]['versions'][0]['action'] === 'install', 'state 0 catalog entry is directly installable');
        });
        $empty = \plugin\sandpackage\app\logic\RepositoryLogic::parseCatalog('{"schema":1,"plugins":[]}');
        check($empty['plugins'] === [], 'empty repository stays empty');
        foreach (['not json', '{"schema":2,"plugins":[]}', '{"schema":1,"plugins":{}}'] as $bad) {
            rejected(fn() => \plugin\sandpackage\app\logic\RepositoryLogic::parseCatalog($bad), 'invalid manifest is rejected');
        }
        $duplicate = manifest($zip);
        $duplicate['plugins'][] = $duplicate['plugins'][0];
        rejected(fn() => \plugin\sandpackage\app\logic\RepositoryLogic::parseCatalog(json_encode($duplicate)), 'duplicate plugin rejected');
        $malicious = manifest($zip);
        $malicious['plugins'][0]['versions'][0]['asset'] = '../evil.zip';
        rejected(fn() => \plugin\sandpackage\app\logic\RepositoryLogic::parseCatalog(json_encode($malicious)), 'asset path traversal rejected');
        $maliciousRepository = manifest($zip);
        $maliciousRepository['plugins'][0]['repository'] = 'https://github.com/evil/repository';
        rejected(fn() => \plugin\sandpackage\app\logic\RepositoryLogic::parseCatalog(json_encode($maliciousRepository)), 'arbitrary plugin repository URL rejected');
        $legacyRepository = manifest($zip);
        unset($legacyRepository['plugins'][0]['repository']);
        check(
            \plugin\sandpackage\app\logic\RepositoryLogic::parseCatalog(json_encode($legacyRepository), 'supdger/catalog')['plugins'][0]['repository'] === 'supdger/catalog',
            'legacy catalog entry falls back to configured catalog repository'
        );
        rejected(fn() => new \plugin\sandpackage\app\logic\RepositoryLogic($client, '../evil', 'main', '6.0.11'), 'untrusted repository configuration rejected');
        check(catalogFixture(clientFor($zip), '5.0.0')['plugins'][0]['versions'][0]['action'] === 'incompatible', 'catalog marks versions outside the host range as incompatible');
        rejected(fn() => downloadFixture(clientFor($zip), '1.0.0', null, '5.0.0'), 'incompatible host rejected before download');
        rejected(fn() => downloadFixture(clientFor($zip), '1.0.0', str_repeat('a', 64)), 'changed catalog digest rejected');
        $wrong = clientFor($zip); $wrong->zip = 'tampered';
        rejected(fn() => downloadFixture($wrong, '1.0.0', hash('sha256', $zip)), 'wrong ZIP checksum rejected');
        $identity = file_get_contents(package('other-sample', '1.0.0'));
        rejected(fn() => downloadFixture(clientFor($identity)), 'package app mismatch rejected');
        $wrongVersion = file_get_contents(package('neutral-sample', '2.0.0'));
        rejected(fn() => downloadFixture(clientFor($wrongVersion)), 'package version mismatch rejected');
        $legacy = file_get_contents(package('neutral-sample', '1.0.0', ['sand_platform' => ['required_plugins' => ['iam']]]));
        rejected(fn() => downloadFixture(clientFor($legacy)), 'old platform extension remains rejected');
        $traversal = file_get_contents(package('neutral-sample', '1.0.0', [], ['../escape' => 'no']));
        rejected(fn() => downloadFixture(clientFor($traversal)), 'existing ZIP path protections retained');
        check(!is_dir(runtime_path('sandpackage/neutral-sample')), 'rejected downloads do not create an installation candidate');
        check(Db::$sql === [], 'rejected downloads execute no SQL');

        $docBytes = file_get_contents(package('doc-sample', '1.0.0', [], [
            'README.md' => "# Documentation\n<script>alert('raw')</script>\n",
        ]));
        writeInstallRecord('doc-sample', '0.9.0', InstallLogic::INSTALLED);
        $docClient = new FixtureRepositoryClient(json_encode(manifest($docBytes, '1.0.0', 'doc-sample'), JSON_THROW_ON_ERROR), $docBytes);
        $document = documentFixture($docClient, 'doc-sample', '1.0.0', hash('sha256', $docBytes));
        check($document === [
            'app' => 'doc-sample',
            'version' => '1.0.0',
            'markdown' => "# Documentation\n<script>alert('raw')</script>\n",
        ], 'document returns UTF-8 markdown including malicious HTML as unchanged plain text');
        check(iterator_count(new FilesystemIterator(runtime_path('sandpackage/doc-sample'))) === 1
            && !is_dir(base_path('plugin/doc-sample')), 'document lookup ignores stale local state and neither stages nor deploys a plugin');
        check(Db::$sql === [], 'document lookup executes no SQL');
        $noReadme = file_get_contents(package('doc-sample', '1.0.0'));
        $noReadmeClient = new FixtureRepositoryClient(json_encode(manifest($noReadme, '1.0.0', 'doc-sample'), JSON_THROW_ON_ERROR), $noReadme);
        rejected(fn() => documentFixture($noReadmeClient, 'doc-sample', '1.0.0', hash('sha256', $noReadme)), 'document rejects a ZIP without root README');
        $largeReadme = file_get_contents(package('doc-sample', '1.0.0', [], ['README.md' => str_repeat('x', 262145)]));
        $largeReadmeClient = new FixtureRepositoryClient(json_encode(manifest($largeReadme, '1.0.0', 'doc-sample'), JSON_THROW_ON_ERROR), $largeReadme);
        rejected(fn() => documentFixture($largeReadmeClient, 'doc-sample', '1.0.0', hash('sha256', $largeReadme)), 'document rejects README larger than 256 KiB');
        $caseReadme = file_get_contents(package('doc-sample', '1.0.0', [], ['readme.md' => "case-compatible\n"]));
        $caseReadmeClient = new FixtureRepositoryClient(json_encode(manifest($caseReadme, '1.0.0', 'doc-sample'), JSON_THROW_ON_ERROR), $caseReadme);
        check(documentFixture($caseReadmeClient, 'doc-sample', '1.0.0', hash('sha256', $caseReadme))['markdown'] === "case-compatible\n", 'document accepts an explicitly allowed README case variant');
        $badDocumentDigest = clientFor($docBytes, '1.0.0');
        $badDocumentDigest->zip = 'tampered archive bytes';
        rejected(fn() => documentFixture($badDocumentDigest, 'neutral-sample', '1.0.0', hash('sha256', $docBytes)), 'document rejects downloaded bytes with a mismatched digest');

        $statusPlugins = [];
        $statusZip = file_get_contents(package('fresh-status', '1.0.0'));
        $fresh = manifest($statusZip, '1.0.0', 'fresh-status')['plugins'][0];
        $statusPlugins[] = $fresh;
        writeInstallRecord('healthy-status', '1.0.0', InstallLogic::INSTALLED);
        mkdir(base_path('plugin/healthy-status/config'), 0755, true);
        file_put_contents(base_path('plugin/healthy-status/config/app.php'), "<?php return ['version' => '1.0.0'];\n");
        $healthy = manifest($statusZip, '1.0.0', 'healthy-status')['plugins'][0];
        $healthy = addCatalogVersion($healthy, '0.9.0');
        $healthy = addCatalogVersion($healthy, '1.1.0');
        $statusPlugins[] = $healthy;
        writeInstallRecord('stale-status', '1.0.0', InstallLogic::INSTALLED);
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'stale-status')['plugins'][0];
        writeInstallRecord('legacy-status', '1.0.0', InstallLogic::INSTALLED, 'legacy-driver');
        mkdir(base_path('plugin/legacy-status/config'), 0755, true);
        file_put_contents(base_path('plugin/legacy-status/config/app.php'), "<?php return ['version' => '1.0.0'];\n");
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'legacy-status')['plugins'][0];
        writeInstallRecord('pending-status', '1.0.0', InstallLogic::WAIT_INSTALL);
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'pending-status')['plugins'][0];
        mkdir(runtime_path('sandpackage/occupied-status'), 0755, true);
        file_put_contents(runtime_path('sandpackage/occupied-status/junk.txt'), 'occupied');
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'occupied-status')['plugins'][0];
        mkdir(base_path('plugin/orphan-status/config'), 0755, true);
        file_put_contents(base_path('plugin/orphan-status/config/app.php'), "<?php return ['version' => '1.0.0'];\n");
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'orphan-status')['plugins'][0];
        mkdir(runtime_path('broken-status-target'), 0755, true);
        symlink(runtime_path('broken-status-target'), runtime_path('sandpackage/broken-status'));
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'broken-status')['plugins'][0];
        if (!is_dir(runtime_path('sandpackage/locks'))) mkdir(runtime_path('sandpackage/locks'), 0755, true);
        file_put_contents(runtime_path('sandpackage/locks/journal-status-old.json'), '{}');
        $statusPlugins[] = manifest($statusZip, '1.0.0', 'journal-status')['plugins'][0];
        $statusClient = new FixtureRepositoryClient(json_encode(['schema' => 1, 'plugins' => $statusPlugins], JSON_THROW_ON_ERROR), $statusZip);
        $statusCatalog = catalogFixture($statusClient);
        $byApp = array_column($statusCatalog['plugins'], null, 'app');
        check($byApp['fresh-status']['local']['state'] === 0 && $byApp['fresh-status']['versions'][0]['action'] === 'install', 'missing local record remains installable');
        $healthyActions = array_column($byApp['healthy-status']['versions'], 'action', 'version');
        check($byApp['healthy-status']['local']['installed_version'] === '1.0.0'
            && $healthyActions === ['1.0.0' => 'installed', '0.9.0' => 'downgrade', '1.1.0' => 'upgrade'], 'healthy installed plugin distinguishes same, downgrade and upgrade versions');
        check($byApp['stale-status']['local']['state'] === 7
            && $byApp['stale-status']['local']['blocked']
            && str_contains($byApp['stale-status']['local']['reason'], '运行目录缺失')
            && $byApp['stale-status']['versions'][0]['action'] === 'manage', 'stale registry reports actual state 7 and requires management');
        foreach (['legacy-status', 'journal-status'] as $managedApp) {
            check($byApp[$managedApp]['local']['blocked'] && $byApp[$managedApp]['versions'][0]['action'] === 'manage', $managedApp . ' remains fail-closed in repository catalog');
        }
        check(!$byApp['pending-status']['local']['blocked']
            && $byApp['pending-status']['versions'][0]['action'] === 'manage', 'healthy state 2 remains available to the installed-plugin workflow');
        check($byApp['occupied-status']['local']['state'] === 5
            && str_contains($byApp['occupied-status']['local']['reason'], '目录已被占用'), 'state 5 has a specific occupied-directory reason');
        check($byApp['orphan-status']['local']['state'] === 6
            && str_contains($byApp['orphan-status']['local']['reason'], '缺少安装登记'), 'state 6 has a specific unregistered-runtime reason');
        check($byApp['broken-status']['local']['state'] === 99
            && $byApp['broken-status']['versions'][0]['action'] === 'manage', 'one unreadable local record fails closed without breaking the catalog');
        unlink(runtime_path('sandpackage/locks/journal-status-old.json'));

        $info = downloadFixture($client);
        check($info['state'] === 2 && !$info['ordinary_actions_blocked']
            && !is_dir(base_path('plugin/neutral-sample')), 'valid download stages an actionable state 2 candidate without deployment');
        $uploadedStatus = (new InstallLogic('neutral-sample'))->ordinaryStatus();
        check($uploadedStatus['state'] === 2 && !$uploadedStatus['blocked'], 'fresh state 2 candidate remains installable in the local index preflight');
        check(Db::$sql === [], 'successful download executes no SQL');
        check($client->requests[count($client->requests)-1][0] === 'https://github.com/supdger/neutral-sample/releases/download/neutral-sample-v1.0.0/neutral-sample-1.0.0.zip', 'download URL derived only from validated plugin repository and manifest');
        rejected(fn() => downloadFixture(clientFor($zip)), 'duplicate candidate cannot overwrite pending install');
        $installed = (new InstallLogic('neutral-sample'))->install(false);
        check($installed['state'] === 1 && is_file(base_path('plugin/neutral-sample/config/app.php')), 'downloaded candidate uses existing real file deployment');
        check(Db::$sql === ['BEGIN', 'CREATE TABLE neutral_sample (id bigint)', 'COMMIT'], 'existing install lifecycle receives only install SQL through recording connection');
        $upgrade = file_get_contents(package('neutral-sample', '1.1.0'));
        $info = downloadFixture(clientFor($upgrade, '1.1.0'), '1.1.0');
        check($info['update'] === 1 && $info['upgrade_from_version'] === '1.0.0', 'repository version becomes upgrade candidate');
        Db::$sql = [];
        rejected(fn() => (new InstallLogic('neutral-sample'))->install(false, 'incorrect'), 'repository upgrade still needs existing confirmation');
        check(Db::$sql === [], 'failed confirmation does not execute SQL');
        (new InstallLogic('neutral-sample'))->install(false, 'UPGRADE neutral-sample@1.0.0->1.1.0');
        check(Db::$sql === ['BEGIN', 'ALTER TABLE neutral_sample ADD COLUMN label text', 'COMMIT'], 'downloaded upgrade executes update SQL only');
        rejected(fn() => downloadFixture(clientFor($zip)), 'older repository package cannot downgrade installed plugin');
        (new InstallLogic('neutral-sample'))->setInfo(['state' => 8]);
        $next = file_get_contents(package('neutral-sample', '1.2.0'));
        rejected(fn() => downloadFixture(clientFor($next, '1.2.0'), '1.2.0'), 'recovery state blocks repository staging');
        $network = clientFor($zip); $network->error = new \plugin\sandadmin\exception\ApiException('仓库不可达');
        rejected(fn() => downloadFixture($network), 'network failure propagated without candidate writes');
        $cleanupApp = 'cleanup-repository';
        $cleanupRoot = (new \plugin\sandpackage\app\service\PluginStorage())->root();
        $cleanupDir = $cleanupRoot . '/' . $cleanupApp;
        mkdir($cleanupDir, 0700);
        $oldInfo = "app=cleanup-repository\nversion=0.5.0\nstate=1\n";
        file_put_contents($cleanupDir . '/info.ini', $oldInfo);
        file_put_contents($cleanupDir . '/install.sql', 'CREATE TABLE cleanup_old (id bigint);');
        $cleanupZip = file_get_contents(package($cleanupApp, '2.0.0'));
        $prepare = static function (string $body, ?string $sha = null) use ($cleanupApp): array {
            $client = new FixtureRepositoryClient(json_encode(manifest($body, '2.0.0', $cleanupApp)), $body);
            $logic = new \plugin\sandpackage\app\logic\RepositoryLogic($client, 'supdger/sandadmin', 'main', '6.0.11');
            $result = null; $error = null; $calls = 0;
            $logic->cleanupPackage($cleanupApp, '2.0.0', $sha ?? hash('sha256', $body), static function (?array $data, ?Throwable $failure) use (&$result, &$error, &$calls): void { $result = $data; $error = $failure; $calls++; });
            check($calls === 1, 'cleanup package completes once');
            if ($error !== null) throw $error;
            return $result;
        };
        $sqlBefore = Db::$sql;
        rejected(fn() => $prepare($cleanupZip, str_repeat('0', 64)), 'cleanup package rejects changed catalog digest');
        $prepared = $prepare($cleanupZip);
        $supplement = file_get_contents($cleanupDir . '/.cleanup-package.json');
        check($prepared === ['app' => $cleanupApp, 'version' => '2.0.0'] && json_decode($supplement, true)['sha256'] === hash('sha256', $cleanupZip), 'verified same-app release stores only supplemental declarations');
        check(file_get_contents($cleanupDir . '/info.ini') === $oldInfo && file_get_contents($cleanupDir . '/install.sql') === 'CREATE TABLE cleanup_old (id bigint);', 'cleanup supplement never replaces old version or lifecycle scripts');
        check(Db::$sql === $sqlBefore && !is_dir(base_path('plugin/' . $cleanupApp)), 'cleanup package neither executes SQL nor deploys runtime');
        $wrongApp = file_get_contents(package('different-plugin', '2.0.0'));
        rejected(fn() => $prepare($wrongApp), 'cleanup package rejects different app identity');
        $unsafe = file_get_contents(package($cleanupApp, '2.0.0', [], ['../escape.sql' => 'unsafe']));
        rejected(fn() => $prepare($unsafe), 'cleanup package rejects unsafe ZIP path without extraction');
        check(file_get_contents($cleanupDir . '/.cleanup-package.json') === $supplement, 'rejected cleanup package preserves prior supplement');
        if (!is_dir($cleanupRoot . '/cleanup')) mkdir($cleanupRoot . '/cleanup', 0700);
        file_put_contents($cleanupRoot . '/cleanup/' . $cleanupApp . '.json', json_encode(['app' => $cleanupApp, 'phase' => 'prepared']));
        rejected(fn() => $prepare($cleanupZip), 'active cleanup cannot change its supplemental package');
        echo "Repository distribution fixture passed (no real database/network/restart).\n";
    } finally {
        \Saithink\Saipackage\service\Filesystem::delDir($root);
    }
}
