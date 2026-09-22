<?php

declare(strict_types=1);

use plugin\sandpackage\app\service\AbnormalPluginCleanup;

$fixtureRoot = realpath(sys_get_temp_dir()) . '/sandpackage-abnormal-' . bin2hex(random_bytes(6));
function base_path(string $path = ''): string { global $fixtureRoot; return $fixtureRoot . '/server' . ($path === '' ? '' : '/' . $path); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/FreshInstallRecovery.php';
require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/AbnormalPluginCleanup.php';

final class CleanupPdo
{
    public array $tables = ['public.probe_one' => ['{"id":1}'], 'public.probe_two' => []];
    public array $menus = [];
    public array $roles = [];
    public array $executed = [];
    public array $dependencies = [];
    public bool $loseCommit = false;
    public bool $failDrop = false;
    public bool $events = false;
    public mixed $afterCommit = null;
    private ?array $before = null;
    public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
    public function inTransaction(): bool { return $this->before !== null; }
    public function beginTransaction(): bool { $this->before = [$this->tables, $this->menus, $this->roles]; $this->executed[] = 'BEGIN'; return true; }
    public function rollBack(): bool { [$this->tables, $this->menus, $this->roles] = $this->before; $this->before = null; $this->executed[] = 'ROLLBACK'; return true; }
    public function commit(): bool {
        $this->before = null; $this->executed[] = 'COMMIT';
        if ($this->afterCommit !== null) ($this->afterCommit)();
        if ($this->loseCommit) { $this->loseCommit = false; throw new RuntimeException('commit response lost'); }
        return true;
    }
    public function query(string $sql): object {
        $rows = [];
        if (str_contains($sql, 'current_database()')) $rows = [['database' => 'fixture', 'oid' => '42', 'username' => 'fixture', 'address' => null, 'port' => null]];
        elseif (str_contains($sql, 'FROM pg_event_trigger')) $rows = $this->events ? [['evtname' => 'unexpected']] : [];
        elseif (str_contains($sql, 'FROM pg_trigger')) $rows = [];
        elseif (str_contains($sql, 'FROM pg_class') && preg_match("/n.nspname='([^']+)' AND c.relname='([^']+)'/", $sql, $match)) {
            if (isset($this->tables[$match[1] . '.' . $match[2]])) $rows = [['oid' => (string) (100 + crc32($match[2]) % 1000), 'kind' => 'r']];
        } elseif (str_contains($sql, 'FROM pg_attribute')) $rows = [['attname' => 'id']];
        elseif (str_starts_with($sql, 'SELECT DISTINCT n.nspname')) {
            preg_match('/f.confrelid IN \(([^)]+)\)/', $sql, $match); $oids = explode(',', $match[1]);
            foreach ($this->dependencies as $source => $target) {
                $sourceOid = (string) (100 + crc32(substr($source, strpos($source, '.') + 1)) % 1000);
                $targetOid = (string) (100 + crc32(substr($target, strpos($target, '.') + 1)) % 1000);
                if (isset($this->tables[$source], $this->tables[$target]) && in_array($targetOid, $oids, true) && !in_array($sourceOid, $oids, true)) $rows[] = ['dependent_table' => $source];
            }
        }
        elseif (str_contains($sql, 'FROM pg_constraint')) $rows = [];
        elseif (str_starts_with($sql, 'SELECT to_jsonb(t)') && preg_match('/FROM "([^"]+)"\."([^"]+)"/', $sql, $match)) {
            $rows = array_map(static fn (string $row): array => ['row_value' => $row], $this->tables[$match[1] . '.' . $match[2]]);
        } elseif (str_starts_with($sql, 'SELECT id::text, parent_id::text')) {
            $rows = array_map(static fn (array $row): array => $row + ['row_value' => json_encode($row)], array_values($this->menus));
        } elseif (str_starts_with($sql, 'SELECT id::text FROM public.sand_system_menu WHERE')) {
            preg_match_all("/(code|slug|component|path)\\s*(=|LIKE)\\s*'((?:[^']|'')*)'/i", $sql, $terms, PREG_SET_ORDER);
            foreach ($this->menus as $row) {
                foreach ($terms as $term) {
                    $value = (string) ($row[strtolower($term[1])] ?? '');
                    $pattern = str_replace("''", "'", $term[3]);
                    $matches = strtoupper($term[2]) === '=' ? $value === $pattern
                        : preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/sD', $value) === 1;
                    if ($matches) { $rows[] = ['id' => $row['id']]; break; }
                }
            }
        } elseif (str_starts_with($sql, 'SELECT to_jsonb(r)')) {
            preg_match('/IN \(([^)]+)\)/', $sql, $match); $ids = explode(',', $match[1]);
            foreach ($this->roles as $row) if (in_array((string) $row['menu_id'], $ids, true)) $rows[] = ['row_value' => json_encode($row)];
        } else throw new RuntimeException('Unexpected query: ' . $sql);
        return new class($rows) { public function __construct(private array $rows) {} public function fetchAll(int $mode): array { return $this->rows; } };
    }
    public function exec(string $sql): int {
        $this->executed[] = $sql;
        if (str_starts_with($sql, 'LOCK TABLE')) return 0;
        if (str_starts_with($sql, 'DROP TABLE')) {
            if ($this->failDrop) throw new RuntimeException('external dependency prevents DROP');
            if (!str_ends_with($sql, ' RESTRICT')) throw new RuntimeException('unsafe DROP');
            preg_match_all('/"([^"]+)"\."([^"]+)"/', $sql, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) unset($this->tables[$match[1] . '.' . $match[2]]);
            return 1;
        }
        if (preg_match('/^DELETE FROM public\.sand_system_(role_menu|menu) WHERE (?:menu_id|id) IN \(([^)]+)\)$/', $sql, $match)) {
            $ids = explode(',', $match[2]);
            if ($match[1] === 'role_menu') $this->roles = array_values(array_filter($this->roles, static fn (array $row): bool => !in_array((string) $row['menu_id'], $ids, true)));
            else $this->menus = array_filter($this->menus, static fn (array $row): bool => !in_array((string) $row['id'], $ids, true));
            return 1;
        }
        throw new RuntimeException('Unexpected mutation: ' . $sql);
    }
}

function check(bool $passed, string $message): void { if (!$passed) throw new RuntimeException($message); echo "[PASS] $message\n"; }
function rejects(callable $call, string $message): void { try { $call(); } catch (Throwable) { check(true, $message); return; } throw new RuntimeException('Not rejected: ' . $message); }
function setup(): array {
    global $fixtureRoot;
    $root = $fixtureRoot . '/case-' . bin2hex(random_bytes(4)); $candidate = $root . '/probe-package';
    mkdir($candidate, 0700, true);
    file_put_contents($candidate . '/info.ini', "app=probe-package\nversion=1.0.0\nstate=1\n");
    file_put_contents($candidate . '/install.sql', "-- CREATE TABLE unrelated (id int);\nCREATE TABLE probe_one (id int); CREATE TABLE probe_two (id int);");
    file_put_contents($candidate . '/uninstall.sql', "BEGIN; ALTER TABLE IF EXISTS probe_one DROP CONSTRAINT IF EXISTS internal_fk; DROP TABLE IF EXISTS probe_one; DROP TABLE IF EXISTS probe_two; DELETE FROM sand_system_role_menu WHERE menu_id IN (SELECT id FROM sand_system_menu WHERE code LIKE 'Probe%'); DELETE FROM sand_system_menu WHERE code LIKE 'Probe%'; COMMIT;");
    $pdo = new CleanupPdo();
    $pdo->menus = [
        '1' => ['id' => '1', 'parent_id' => '0', 'name' => 'Probe', 'code' => 'Probe', 'path' => '/probe-package', 'component' => ''],
        '2' => ['id' => '2', 'parent_id' => '1', 'name' => 'Permission', 'code' => 'ProbeRead', 'path' => '', 'component' => ''],
        '9' => ['id' => '9', 'parent_id' => '0', 'name' => 'Other', 'code' => 'Other', 'path' => '/other', 'component' => ''],
    ];
    $pdo->roles = [['role_id' => 1, 'menu_id' => 1], ['role_id' => 1, 'menu_id' => 2], ['role_id' => 1, 'menu_id' => 9]];
    $frontend = $root . '/frontend/probe-package'; mkdir($frontend, 0700, true); file_put_contents($frontend . '/user-change.vue', 'preserve user changes');
    $paths = [$candidate . '/plugin/probe-package' => $root . '/backend/probe-package', $candidate . '/frontend/probe-package' => $frontend];
    return [new AbnormalPluginCleanup('probe-package', $candidate, $paths, $root, $pdo), $pdo, $root, $candidate, $frontend];
}

[$service, $pdo, $root, $candidate, $frontend] = setup();
$preview = $service->inspect();
check($preview['tables'] === ['public.probe_one', 'public.probe_two'] && count($preview['menus']) === 2 && $pdo->executed === [] && !is_dir($root . '/cleanup'), 'inspection is read-only and excludes comment-only CREATE and unrelated menus');
rejects(fn () => $service->cleanup($preview['fingerprint'], 'wrong'), 'wrong plugin confirmation is rejected without a journal');
$pdo->tables['public.probe_one'][] = '{"id":2}';
rejects(fn () => $service->cleanup($preview['fingerprint'], 'probe-package'), 'stale data fingerprint rejects destructive work');
$preview = $service->inspect(); $result = $service->cleanup($preview['fingerprint'], 'probe-package');
check($pdo->tables === [] && count($pdo->menus) === 1 && count($pdo->roles) === 1 && isset($pdo->menus[9]), 'cleanup removes owned tables and fixed menu IDs, retaining unrelated data');
check(!is_dir($candidate) && !is_dir($frontend) && file_get_contents($result['archive'] . '/deployment-0/user-change.vue') === 'preserve user changes', 'candidate and user-modified residual files are archived intact');
check(!AbnormalPluginCleanup::pending($root, 'probe-package') && $service->inspect()['phase'] === 'files_pending', 'completed journal allows read-only final-state inspection without candidate');
$again = $service->inspect(); $count = count($pdo->executed); $service->cleanup($again['fingerprint'], 'probe-package');
check(count($pdo->executed) === $count, 'completed cleanup retry never repeats SQL');

[$service, $pdo, $root] = setup(); $pdo->loseCommit = true;
rejects(fn () => $service->cleanup($service->inspect()['fingerprint'], 'probe-package'), 'lost COMMIT response leaves a resumable journal');
check($pdo->tables === [] && AbnormalPluginCleanup::pending($root, 'probe-package') && $service->inspect()['phase'] === 'files_pending', 'post-commit database state resolves ambiguous COMMIT to files_pending');
$count = count($pdo->executed); $service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check(count($pdo->executed) === $count, 'commit-unknown retry archives only');

[$service, $pdo] = setup(); $pdo->failDrop = true;
rejects(fn () => $service->cleanup($service->inspect()['fingerprint'], 'probe-package'), 'external foreign-key failure rolls back cleanup');
check(count($pdo->menus) === 3 && count($pdo->tables) === 2 && $service->inspect()['phase'] === 'ready', 'rollback preserves menus and permits a fresh confirmation');
$pdo->tables['public.probe_one'][] = '{"id":3}';
check($service->inspect()['phase'] === 'ready', 'rolled-back prepared cleanup allows a fresh preview after legitimate data changes');
$pdo->failDrop = false; $service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check($pdo->tables === [], 'rolled-back SQL failure can be retried');

[$service, $pdo, $root] = setup();
$pdo->afterCommit = static function () use ($root): void { $record = json_decode(file_get_contents($root . '/cleanup/probe-package.json'), true); mkdir(dirname($record['archive']), 0700, true); file_put_contents($record['archive'], 'injected archive obstruction'); };
rejects(fn () => $service->cleanup($service->inspect()['fingerprint'], 'probe-package'), 'archive filesystem failure preserves pending cleanup');
$record = json_decode(file_get_contents($root . '/cleanup/probe-package.json'), true); unlink($record['archive']); $pdo->afterCommit = null;
$count = count($pdo->executed); $service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check(count($pdo->executed) === $count, 'filesystem failure retries without repeating database mutations');

[$service, $pdo] = setup(); $pdo->menus['9']['parent_id'] = '1';
rejects(fn () => $service->inspect(), 'foreign child below plugin menu is never swept up');
[$service, $pdo] = setup(); $pdo->menus['9']['code'] = 'ProbeOther';
rejects(fn () => $service->inspect(), 'broad LIKE match does not authorize foreign menu paths');
[$service, $pdo, $root, $candidate] = setup(); file_put_contents($candidate . '/uninstall.sql', "DROP TABLE probe_one CASCADE;");
rejects(fn () => $service->inspect(), 'CASCADE and unsupported uninstall statements are rejected');
[$service, $pdo, $root, $candidate] = setup(); mkdir($root . '/other-package'); file_put_contents($root . '/other-package/install.sql', 'CREATE TABLE probe_one (id int);');
rejects(fn () => $service->inspect(), 'another package table declaration prevents cross-plugin cleanup');
[$service, $pdo] = setup(); $pdo->events = true;
rejects(fn () => $service->cleanup($service->inspect()['fingerprint'], 'probe-package'), 'enabled database event triggers prevent cleanup');
check(count($pdo->tables) === 2, 'event-trigger refusal leaves business tables intact');
[$service, $pdo, $root, $candidate, $frontend] = setup(); symlink($candidate . '/info.ini', $frontend . '/unsafe-link');
rejects(fn () => $service->inspect(), 'residual deployment symlinks are rejected');
[$service, $pdo] = setup(); $pdo->tables = [];
$service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check(count($pdo->menus) === 1, 'menu-only residue is cleaned without requiring a DROP TABLE');
[$service, $pdo] = setup(); $pdo->tables = []; $pdo->menus = []; $pdo->roles = [];
$service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check($pdo->tables === [] && !$service->hasPending(), 'empty database residue can still archive the old registration');

[$service, $pdo, $root, $candidate] = setup();
file_put_contents($candidate . '/install.sql', 'CREATE TABLE "public"."probe_one" (id int); CREATE TABLE "probe_two" (id int);');
file_put_contents($candidate . '/uninstall.sql', str_replace('DROP TABLE IF EXISTS probe_one', 'DROP TABLE IF EXISTS "public"."probe_one"', file_get_contents($candidate . '/uninstall.sql')));
check(count($service->inspect()['tables']) === 2, 'quoted lowercase PostgreSQL declarations retain exact table ownership');
[$service, $pdo, $root] = setup(); $pdo->loseCommit = true;
$pdo->afterCommit = static function () use ($root): void {
    $record = json_decode(file_get_contents($root . '/cleanup/probe-package.json'), true);
    mkdir($record['archive'], 0700, true);
    foreach ($record['moves'] as $move) rename($move['source'], $move['destination']);
};
rejects(fn () => $service->cleanup($service->inspect()['fingerprint'], 'probe-package'), 'interruption after filesystem moves preserves durable identities');
$pdo->afterCommit = null; $count = count($pdo->executed);
$service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check(count($pdo->executed) === $count && !$service->hasPending(), 'already archived candidate can complete without repeating SQL or renames');
[$service, $pdo, $root] = setup(); $pdo->failDrop = true;
rejects(fn () => $service->cleanup($service->inspect()['fingerprint'], 'probe-package'), 'prepare a failed operation for journal range validation');
$journal = $root . '/cleanup/probe-package.json'; $record = json_decode(file_get_contents($journal), true);
$record['moves'][0]['source'] = '/tmp/foreign-source'; file_put_contents($journal, json_encode($record));
rejects(fn () => $service->inspect(), 'journal cannot expand archive source paths');
[$service, $pdo, $root, $candidate] = setup(); symlink($candidate, $root . '/cleanup');
check(AbnormalPluginCleanup::pending($root, 'probe-package'), 'pending detection does not follow a symlinked journal parent');

[$service, $pdo, $root, $candidate] = setup();
file_put_contents($candidate . '/uninstall.sql', 'BEGIN; DROP TABLE probe_one; DROP TABLE probe_two; COMMIT;');
rejects(fn () => $service->inspect(), 'DROP-only uninstall cannot silently leave plugin menus behind');
check($pdo->executed === [] && !is_dir($root . '/cleanup'), 'missing menu declaration blocks before any mutation');
$pdo->menus = ['9' => $pdo->menus['9']]; $pdo->roles = [['role_id' => 1, 'menu_id' => 9]];
$service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check(count($pdo->menus) === 1 && $pdo->tables === [], 'table-only plugin cleanup works when no plugin menu exists');

[$service, $pdo, $root, $candidate] = setup();
$pdo->tables['public.probe_three'] = ['{"id":3}']; $pdo->dependencies['public.probe_three'] = 'public.probe_one';
try { $service->inspect(); throw new RuntimeException('Expected missing dependency error'); }
catch (\plugin\sandadmin\exception\ApiException $error) { check(str_contains($error->getMessage(), 'public.probe_three') && str_contains($error->getMessage(), '补充对应版本'), 'external FK appears in preview with a concrete supplementary-package remedy'); }
$originalInstall = file_get_contents($candidate . '/install.sql'); $originalUninstall = file_get_contents($candidate . '/uninstall.sql');
$package = ['app' => 'probe-package', 'version' => '1.1.0', 'sha256' => str_repeat('a', 64),
    'install_sql' => $originalInstall . ' CREATE TABLE probe_three (id int);',
    'uninstall_sql' => str_replace('COMMIT;', 'DROP TABLE probe_three; COMMIT;', $originalUninstall)];
file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
$preview = $service->inspect();
check(count($preview['tables']) === 3 && $preview['cleanup_package_version'] === '1.1.0', 'supplementary same-plugin package adds owned tables and exposes its version');
check(file_get_contents($candidate . '/install.sql') === $originalInstall && file_get_contents($candidate . '/uninstall.sql') === $originalUninstall, 'supplementary declarations do not replace the original lifecycle evidence');
$changed = $package; $changed['sha256'] = str_repeat('b', 64); file_put_contents($candidate . '/.cleanup-package.json', json_encode($changed));
rejects(fn () => $service->cleanup($preview['fingerprint'], 'probe-package'), 'changing supplementary package invalidates the prior confirmation');
$service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check($pdo->tables === [] && count($pdo->menus) === 1, 'supplemented scope clears the full internal FK graph and plugin menus');

[$service, $pdo, $root, $candidate] = setup();
$package['app'] = 'another-plugin'; file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
rejects(fn () => $service->inspect(), 'supplementary package for another plugin is rejected');
$package['app'] = 'probe-package'; $package['version'] = 'not-a-version'; file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
rejects(fn () => $service->inspect(), 'invalid supplementary version is rejected');
$package['version'] = '1.1.0'; $package['sha256'] = 'invalid'; file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
rejects(fn () => $service->inspect(), 'invalid supplementary ZIP hash is rejected');
$package['sha256'] = str_repeat('a', 64); $package['install_sql'] = 'CREATE TABLE sand_system_foreign (id int);'; $package['uninstall_sql'] = 'DROP TABLE sand_system_foreign;';
file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
rejects(fn () => $service->inspect(), 'supplement cannot claim a protected host table');
$package['install_sql'] = 'CREATE TABLE probe_three (id int);'; $package['uninstall_sql'] = 'DROP TABLE probe_three CASCADE;';
file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
rejects(fn () => $service->inspect(), 'supplement cannot bypass the restricted uninstall grammar');
$package['uninstall_sql'] = 'DROP TABLE probe_three;'; file_put_contents($candidate . '/.cleanup-package.json', json_encode($package));
mkdir($root . '/other-package'); file_put_contents($root . '/other-package/install.sql', 'CREATE TABLE probe_three (id int);');
rejects(fn () => $service->inspect(), 'supplement cannot claim another registered plugin table');

function menuDeclaration(string $candidate, string $menu, ?string $role = null): void {
    $role ??= $menu;
    file_put_contents($candidate . '/uninstall.sql', "BEGIN; DROP TABLE probe_one; DROP TABLE probe_two; DELETE FROM sand_system_role_menu WHERE menu_id IN (SELECT id FROM sand_system_menu WHERE $role); DELETE FROM sand_system_menu WHERE $menu; COMMIT;");
}

[$service, $pdo, $root, $candidate] = setup();
$pdo->menus['1']['code'] = ''; $pdo->menus['2']['code'] = ''; $pdo->menus['2']['slug'] = 'probe-package:read';
$pdo->menus['3'] = ['id' => '3', 'parent_id' => '1', 'name' => 'Page', 'code' => '', 'slug' => '', 'path' => 'page', 'component' => '/plugin/probe-package/page/index'];
$pdo->roles[] = ['role_id' => 1, 'menu_id' => 3];
$pdo->menus['4'] = ['id' => '4', 'parent_id' => '1', 'name' => 'Deep page', 'code' => '', 'slug' => '', 'path' => '/probe-package/history', 'component' => ''];
$pdo->roles[] = ['role_id' => 1, 'menu_id' => 4];
$pdo->menus['9']['code'] = ''; $pdo->menus['9']['slug'] = 'probe-package-other:read';
$pdo->menus['9']['path'] = '/probe-package-other'; $pdo->menus['9']['component'] = '/plugin/probe-package-other/page';
$condition = "path = '/probe-package' OR slug LIKE 'probe-package:%' OR component LIKE '/plugin/probe-package/%' OR path LIKE '/probe-package/%'";
$reverse = "PATH like '/probe-package/%' OR COMPONENT like '/plugin/probe-package/%' OR SLUG like 'probe-package:%' OR PATH = '/probe-package'";
menuDeclaration($candidate, $condition, $reverse);
$preview = $service->inspect();
check(count($preview['menus']) === 4, 'blank-code permissions are selected by exact plugin slug and owned page paths');
$service->cleanup($preview['fingerprint'], 'probe-package');
check(count($pdo->menus) === 1 && isset($pdo->menus[9]) && count($pdo->roles) === 1, 'normalized reordered menu predicates delete fixed owned IDs while preserving foreign blank-code menus');

$unsafeConditions = [
    "code = ''",
    "name = 'Probe'",
    "slug LIKE 'other-package:%'",
    "slug LIKE 'probe-package%'",
    "slug LIKE 'probe-package:_%'",
    "slug = 'probe-package:read'",
    "component LIKE '/plugin/other-package/%'",
    "component LIKE '/plugin/probe-package%'",
    "component LIKE 'plugin/probe-package/%'",
    "path = '/other-package'",
    "path LIKE '/probe-package%'",
    "path LIKE '/probe-package/_%'",
    "slug LIKE 'probe-package:%' ESCAPE 'x'",
    "component LIKE '/plugin/probe-package/%' OR 1=1",
];
foreach ($unsafeConditions as $condition) {
    [$service, $pdo, $root, $candidate] = setup(); menuDeclaration($candidate, $condition);
    rejects(fn () => $service->inspect(), 'reject unsafe menu condition: ' . $condition);
    check($pdo->executed === [], 'unsafe condition performs no database mutation');
}
[$service, $pdo, $root, $candidate] = setup();
menuDeclaration($candidate, "path = '/probe-package' OR slug LIKE 'probe-package:%'");
$pdo->menus['2']['slug'] = 'other-package:read';
rejects(fn () => $service->inspect(), 'owned root does not authorize deletion of an unselected child');
$pdo->menus['2']['slug'] = 'probe-package:read'; $pdo->menus['2']['component'] = '/plugin/other-package/page';
rejects(fn () => $service->inspect(), 'matching plugin slug never overrides foreign component ownership');
[$service, $pdo, $root, $candidate] = setup();
menuDeclaration($candidate, "path = '/probe-package' OR slug LIKE 'probe-package:%'", "path = '/probe-package'");
rejects(fn () => $service->inspect(), 'different role-menu and menu scopes remain rejected');
rejects(fn () => new AbnormalPluginCleanup('probe_package', $root . '/probe_package', [], $root, $pdo), 'underscore app cannot expand LIKE ownership scope');

[$service, $pdo, $root, $candidate] = setup();
$quotedSql = <<<'SQL'
BEGIN;
DELETE FROM "public"."sand_system_role_menu" WHERE "menu_id" IN (
    SELECT "id" FROM "public"."sand_system_menu"
    WHERE "code" = 'Probe"Quoted' OR "code" LIKE 'Probe%'
       OR "slug" LIKE 'probe-package:%'
       OR "component" LIKE '/plugin/probe-package/%'
       OR "path" = '/probe-package' OR "path" LIKE '/probe-package/%'
);
DELETE FROM "public"."sand_system_menu"
WHERE "code" = 'Probe"Quoted' OR "code" LIKE 'Probe%'
   OR "slug" LIKE 'probe-package:%'
   OR "component" LIKE '/plugin/probe-package/%'
   OR "path" = '/probe-package' OR "path" LIKE '/probe-package/%';
DROP TABLE IF EXISTS "probe_one";
DROP TABLE IF EXISTS "probe_two";
COMMIT;
SQL;
file_put_contents($candidate . '/uninstall.sql', $quotedSql);
$pdo->menus['1']['code'] = 'Probe"Quoted';
check(count($service->inspect()['menus']) === 2, 'quoted PostgreSQL table and column identifiers support the real Workflow uninstall shape');
$method = new ReflectionMethod($service, 'parseDeclaration');
$parsed = $method->invoke($service, file_get_contents($candidate . '/install.sql'), $quotedSql);
check(str_contains($parsed['menu_condition'], "'Probe\"Quoted'"), 'quoted identifier support preserves double quotes inside string literals');
$service->cleanup($service->inspect()['fingerprint'], 'probe-package');
check(count($pdo->menus) === 1 && count($pdo->roles) === 1, 'quoted predicates still delete only fixed owned menu IDs');
foreach ([
    ['"sand_system_menu"', '"SAND_SYSTEM_MENU"'],
    ['"public"', '"Public"'],
    ['"menu_id"', '"MENU_ID"'],
    ['"id"', '"ID"'],
    ['"slug"', '"Slug"'],
    ['"code"', '"CODE"'],
    ['"component"', '"unknown_field"'],
    ['"path"', '"name"'],
    ['"sand_system_menu"', '"sand_system_menu'],
    ['"slug"', 'slug"'],
] as [$from, $to]) {
    [$service, $pdo, $root, $candidate] = setup();
    file_put_contents($candidate . '/uninstall.sql', str_replace($from, $to, $quotedSql));
    rejects(fn () => $service->inspect(), 'quoted identifier rejection: ' . $from . ' -> ' . $to);
}
foreach (["slugLIKE 'probe-package:%'", "codeLIKE 'Probe%'"] as $condition) {
    [$service, $pdo, $root, $candidate] = setup(); menuDeclaration($candidate, $condition);
    rejects(fn () => $service->inspect(), 'merged identifier is not a field/operator pair: ' . $condition);
}
[$service, $pdo, $root, $candidate] = setup();
menuDeclaration($candidate, "CODE LIKE 'Probe%' OR SLUG LIKE 'probe-package:%' OR PATH = '/probe-package'");
check(count($service->inspect()['menus']) === 2, 'uppercase unquoted identifiers retain PostgreSQL lowercase folding');
menuDeclaration($candidate, '"slug"LIKE \'probe-package:%\' OR "code"LIKE \'Probe%\'');
check(count($service->inspect()['menus']) === 2, 'quoted identifiers may directly precede LIKE without becoming merged tokens');
echo "AbnormalPluginCleanup behavior checks passed.\n";
