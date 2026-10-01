<?php

declare(strict_types=1);

namespace think\facade {
    final class Db {
        public static object $pdo;
        public static function connect(string $name): object {
            if ($name !== 'pgsql') throw new \RuntimeException('Unexpected connection');
            return new class { public function connect(): object { return Db::$pdo; } };
        }
    }
}
namespace plugin\sandadmin\app\cache {
    final class UserMenuCache {
        public static bool $failOnce = false;
        public static function clearMenuCache(): void {
            if (self::$failOnce) { self::$failOnce = false; throw new \RuntimeException('Injected cache failure'); }
        }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\PostgresHostCatalogFingerprint;
    use plugin\sandadmin\app\cache\UserMenuCache;
    use think\facade\Db;

    final class AttachRecordingConnection {
        public string $database = 'existing_fixture';
        public int $writes = 0;
        public array $queries = [];
        public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
        public function exec(string $sql): int { $this->writes++; throw new RuntimeException('Attach must never execute SQL'); }
        public function query(string $sql): object {
            if (!str_starts_with(ltrim($sql), 'SELECT ')) throw new RuntimeException('Only read-only queries allowed');
            $this->queries[] = $sql;
            $rows = [];
            if (str_contains($sql, 'SELECT EXISTS(')) $rows = [['present' => true]];
            elseif (str_contains($sql, 'current_schema()')) $rows = [['database' => $this->database, 'schema_name' => 'public']];
            elseif (str_contains($sql, 'current_database()')) $rows = [['database' => $this->database, 'oid' => '7',
                'username' => 'fixture', 'address' => null, 'port' => null]];
            elseif (str_contains($sql, 'SELECT migration_file')) $rows = [['migration_file' => '001.sql', 'revision' => '1',
                'checksum' => str_repeat('a', 64), 'package_version' => '1.0.0']];
            elseif (str_contains($sql, 'SELECT relname FROM pg_class')) $rows = [
                ['relname' => 'neutral_schema_record'], ['relname' => 'neutral_schema_schema_migration']];
            return new class($rows) {
                public function __construct(private array $rows) {}
                public function fetchAll(int $mode): array { return $this->rows; }
            };
        }
    }
    $fixture = sys_get_temp_dir() . '/sandpackage-attach-recording-' . bin2hex(random_bytes(6));
    $root = $fixture;
    function base_path(string $path = ''): string { global $root; return $root . '/server' . ($path === '' ? '' : '/' . $path); }
    function runtime_path(string $path = ''): string { global $root; return $root . '/runtime' . ($path === '' ? '' : '/' . $path); }
    function env(string $key, mixed $default = null): mixed { return $default; }
    function config(string $key): mixed { return $key === 'plugin.sandadmin.app.version' ? '0.1.0' : null; }
    require getenv('SANDPACKAGE_TEST_VENDOR') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
    $source = dirname(__DIR__, 2) . '/plugin/sandpackage/';
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'plugin\\sandpackage\\';
        if (!str_starts_with($class, $prefix)) return;
        $path = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }, true, true);
    require_once $source . 'app/logic/InstallLogic.php';
    function attachExpect(bool $value, string $message): void {
        if (!$value) throw new RuntimeException($message);
        echo '[PASS] ' . $message . PHP_EOL;
    }
    function attachReject(callable $run, string $needle, string $message): void {
        try { $run(); }
        catch (\plugin\sandadmin\exception\ApiException $error) {
            attachExpect(str_contains($error->getMessage(), $needle), $message);
            return;
        }
        throw new RuntimeException('Expected rejection: ' . $message);
    }
    function attachPackage(string $path, string $version): void {
        $sql = 'CREATE TABLE neutral_schema_record (id bigint);';
        $manifest = [
            'format' => 1, 'app' => 'neutral-schema', 'version' => $version,
            'install_sql_sha256' => hash('sha256', $sql),
            'ledger_table' => 'neutral_schema_schema_migration', 'ledger_rows' => 1,
            'ledger_sha256' => hash('sha256', '001.sql|1|' . str_repeat('a', 64) . "|1.0.0\n"),
            'table_count' => 2,
            'table_names_sha256' => hash('sha256', "neutral_schema_record\nneutral_schema_schema_migration\n"),
            'catalog_schema_sha256' => PostgresHostCatalogFingerprint::capture(Db::$pdo, 'neutral-schema')['schema'],
        ];
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('ZIP creation failed');
        foreach ([
            'info.ini' => "app=neutral-schema\ntitle=Neutral\nabout=Fixture\nauthor=Test\nversion=$version\nsupport=\">=0.1.0\"\n",
            'config.json' => '{}', 'install.sql' => $sql, 'update.sql' => '', 'uninstall.sql' => 'DROP TABLE neutral_schema_record;',
            'existing-schema.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'plugin/neutral-schema/config/app.php' => "<?php return ['version'=>'$version'];",
            'sandadmin-artd/src/views/plugin/neutral-schema/index.vue' => '<template>Neutral</template>',
        ] as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
    }
    try {
        foreach (['success', 'continue'] as $scenario) {
            $root = $fixture . '/' . $scenario;
            mkdir(base_path('plugin'), 0700, true);
            mkdir($root . '/sandadmin-artd', 0700, true);
            file_put_contents(base_path('composer.json'), '{"require":{}}');
            file_put_contents($root . '/sandadmin-artd/package.json', '{"dependencies":{}}');
            ini_set('error_log', $root . '/expected-errors.log');
            Db::$pdo = new AttachRecordingConnection();
            attachPackage($root . '/candidate.zip', '1.0.0');
            $uploader = new InstallLogic();
            $uploader->uploadFromPath($root . '/candidate.zip');
            $logic = new InstallLogic('neutral-schema');
            attachExpect($uploader->getAllowedPath() === $logic->getAllowedPath(),
                "$scenario: upload and reconstructed candidate produce identical source and target paths");
            $proof = $logic->inspectExistingSchemaAttach('existing_fixture');
            attachExpect($proof['ledger_rows'] === 1 && $proof['table_count'] === 2 && Db::$pdo->writes === 0,
                "$scenario: inspect binds package and schema using only SELECT queries");
            Db::$pdo->database = 'different_fixture';
            attachReject(fn () => $logic->install(false, $proof['confirmation'], true, 'existing_fixture'),
                '数据库身份不符', "$scenario: database identity drift rejects attach before deployment");
            attachExpect($logic->getInstallState() === InstallLogic::WAIT_INSTALL
                && !is_dir(base_path('plugin/neutral-schema')) && Db::$pdo->writes === 0,
                "$scenario: rejected identity preserves waiting metadata and untouched runtime");
            Db::$pdo->database = 'existing_fixture';
            $candidate = base_path('storage/sandpackage/neutral-schema/plugin/neutral-schema/config/app.php');
            $contents = file_get_contents($candidate);
            file_put_contents($candidate, $contents . "\n// changed");
            attachReject(fn () => $logic->install(false, $proof['confirmation'], true, 'existing_fixture'),
                '上传时摘要不一致', "$scenario: candidate drift rejects attach");
            file_put_contents($candidate, $contents);
            attachReject(fn () => $logic->install(false, 'wrong-confirmation', true, 'existing_fixture'),
                '确认内容不匹配', "$scenario: stale confirmation rejects attach");
            attachReject(fn () => $logic->install(false), '数据库已有该插件表', "$scenario: ordinary installation rejects existing tables");
            UserMenuCache::$failOnce = $scenario === 'continue';
            if ($scenario === 'continue') {
                attachReject(fn () => $logic->install(false, $proof['confirmation'], true, 'existing_fixture'),
                    '插件安装未完成', 'interrupted attachment remains a failed pending operation');
                $recovery = $logic->inspectExistingSchemaAttachRecovery();
                $logic->continueExistingSchemaAttach($recovery['confirmation']);
            } else {
                $logic->install(false, $proof['confirmation'], true, 'existing_fixture');
            }
            attachExpect($logic->getInstallState() === InstallLogic::INSTALLED
                && !empty($logic->getInfo()['existing_schema_attached'])
                && Db::$pdo->writes === 0, "$scenario: attachment completes without install SQL or database writes");
            attachReject(fn () => $logic->uninstall(false), '普通卸载会删除原有数据', "$scenario: attached existing data forbids ordinary uninstall");
            attachReject(fn () => $logic->inspectCleanup(), '自动清理不得删除原有数据', "$scenario: attached existing data forbids automatic cleanup");
            attachPackage($root . '/upgrade.zip', '2.0.0');
            $upgradeUploader = new InstallLogic();
            $upgradeUploader->uploadFromPath($root . '/upgrade.zip');
            attachExpect($upgradeUploader->getAllowedPath() === (new InstallLogic('neutral-schema'))->getAllowedPath(),
                "$scenario: staged upgrade and reconstructed candidate preserve journal path identity");
            $upgraded = (new InstallLogic('neutral-schema'))->getInfo();
            attachExpect(!empty($upgraded['existing_schema_attached']) && !empty($upgraded['update'])
                && Db::$pdo->writes === 0, "$scenario: staging upgrade preserves the existing-data protection marker");
        }
        echo "Existing schema attach recording passed; no real PostgreSQL connection, SQL execution or service restart\n";
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (!file_exists($path)) return;
            if (is_file($path)) { unlink($path); return; }
            foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname());
            rmdir($path);
        };
        $remove($fixture);
    }
}
