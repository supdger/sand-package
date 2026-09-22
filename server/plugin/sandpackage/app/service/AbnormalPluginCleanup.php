<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use plugin\sandadmin\exception\ApiException;
use Throwable;

/** Bounded cleanup of an absent deployment. The caller holds host and app locks. */
final class AbnormalPluginCleanup
{
    private const TABLE_NAME = '(?:"[a-z_][a-z0-9_]*"|[a-z_][a-z0-9_]*)(?:\.(?:"[a-z_][a-z0-9_]*"|[a-z_][a-z0-9_]*))?';
    private string $journalPath;

    public function __construct(
        private string $app,
        private string $candidate,
        private array $paths,
        private string $root,
        private object $pdo,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app) || $candidate !== $root . '/' . $app) {
            throw new ApiException('异常清理的插件路径无效');
        }
        $this->journalPath = $root . '/cleanup/' . $app . '.json';
        $this->safe($this->journalPath);
    }

    public static function pending(string $root, string $app): bool
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $app)) return false;
        $path = $root . '/cleanup/' . $app . '.json';
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $current .= '/' . $part;
            if ($part === '' || $part === '.' || $part === '..' || is_link($current)) return true;
        }
        if (!is_file($path)) return false;
        if (filesize($path) > 16777216) return true;
        $record = json_decode((string) @file_get_contents($path), true);
        return !is_array($record) || ($record['app'] ?? null) !== $app || ($record['phase'] ?? '') !== 'cleaned';
    }

    public function hasJournal(): bool { return is_file($this->journalPath); }
    public function hasPending(): bool { return ($this->journal()['phase'] ?? 'cleaned') !== 'cleaned'; }

    public function inspect(): array
    {
        $snapshot = $this->snapshot();
        return $this->display($snapshot);
    }

    public function cleanup(string $fingerprint, string $confirmApp): array
    {
        $snapshot = $this->snapshot();
        if ($confirmApp !== $this->app || !hash_equals(self::hash($snapshot), $fingerprint)) {
            throw new ApiException('插件名称或清理现场已变化，请重新检查后确认');
        }
        $record = $this->journal();
        if ($record !== null && $record['phase'] === 'cleaned' && !is_dir($this->candidate)) return ['app' => $this->app, 'state' => 0, 'archive' => $record['archive'], 'restart_required' => count($record['moves']) > 1];
        if ($record === null || $record['phase'] === 'cleaned') {
            $operation = bin2hex(random_bytes(16));
            $archive = $this->root . '/archives/' . $this->app . '-' . $operation;
            $record = ['format' => 1, 'app' => $this->app, 'operation' => $operation,
                'phase' => 'prepared', 'archive' => $archive, 'snapshot' => $snapshot,
                'before' => $snapshot['database'], 'after' => null, 'moves' => [], 'events' => []];
            foreach ($snapshot['files'] as $path => $hash) {
                if ($hash !== null) $record['moves'][] = ['source' => $path,
                    'destination' => $archive . '/' . ($path === $this->candidate ? 'candidate' : 'deployment-' . count($record['moves'])), 'hash' => $hash];
            }
            $this->save($record);
        }
        if ($snapshot['phase'] === 'ready') {
            if ($record['phase'] === 'prepared') {
                $record['before'] = $snapshot['database'];
                $record['snapshot']['database'] = $snapshot['database'];
                $this->save($record);
            }
            $this->cleanDatabase($record);
        }
        $record = $this->journalRequired();
        $record['phase'] = 'files_pending';
        $this->save($record);
        $this->makeDirectory($record['archive']);
        foreach ($record['moves'] as $move) {
            $this->safe($move['source']);
            $this->safe($move['destination']);
            $source = $this->treeHash($move['source']);
            $destination = $this->treeHash($move['destination']);
            if ($source === null && $destination === $move['hash']) continue;
            if ($source !== $move['hash'] || $destination !== null) throw new ApiException('归档源已变化或目标被占用，请重新核对；数据库不会重复清理');
            if (!@rename($move['source'], $move['destination'])) throw new ApiException('插件文件归档失败，可检查权限后继续完成清理');
            $record['events'][] = ['event' => 'archived', 'path' => $move['source'], 'time' => gmdate('c')];
            $this->save($record);
        }
        $record['phase'] = 'cleaned';
        $record['events'][] = ['event' => 'cleaned', 'time' => gmdate('c')];
        $this->save($record);
        return ['app' => $this->app, 'state' => 0, 'archive' => $record['archive'],
            'restart_required' => count($record['moves']) > 1];
    }

    private function snapshot(): array
    {
        $record = $this->journal();
        if ($record !== null && ($record['phase'] !== 'cleaned' || !is_dir($this->candidate))) {
            $original = $record['snapshot'];
            $files = [];
            foreach ($original['files'] as $path => $hash) {
                $files[$path] = $this->treeHash($path);
                $move = null;
                foreach ($record['moves'] as $item) if ($item['source'] === $path) $move = $item;
                if ($files[$path] === $hash) {
                    if ($move !== null && $this->treeHash($move['destination']) !== null) throw new ApiException('清理归档目标已被占用');
                } elseif ($files[$path] !== null || $move === null || $this->treeHash($move['destination']) !== $hash) {
                    throw new ApiException('清理登记或残留文件已变化，不能继续原清理');
                }
            }
            if ($record['phase'] === 'prepared' && $this->declaration() !== $original['declaration']) throw new ApiException('插件清理声明已变化');
            $database = $this->database($original['declaration']);
            if ($record['phase'] === 'prepared' && $database['identity'] === $record['before']['identity']) $phase = 'ready';
            elseif ($record['after'] !== null && $database === $record['after']) $phase = 'files_pending';
            elseif ($database === $record['before'] && in_array($record['phase'], ['prepared', 'commit_intent'], true)) $phase = 'ready';
            else throw new ApiException('清理提交结果与前后状态均不匹配，请核对数据库变化');
            return array_merge($original, ['phase' => $phase, 'files' => $files, 'database' => $database,
                'operation' => $record['operation'], 'journal_phase' => $record['phase']]);
        }
        $this->safe($this->candidate . '/info.ini');
        $info = @parse_ini_file($this->candidate . '/info.ini', true, INI_SCANNER_TYPED);
        if (!is_array($info) || ($info['app'] ?? '') !== $this->app || !is_string($info['version'] ?? null)) {
            throw new ApiException('异常安装登记无效，无法确定插件版本');
        }
        $files = [];
        foreach ($this->paths as $source => $target) {
            if (!is_string($source) || !str_starts_with($source, $this->candidate . '/') || !is_string($target)) throw new ApiException('清理部署路径无效');
            $files[$target] = $this->treeHash($target);
            if ($files[$target] !== null && stat($target)['dev'] !== stat($this->root)['dev']) throw new ApiException('残留目录与归档根不在同一文件系统，无法原子归档：' . $target);
        }
        $files[$this->candidate] = $this->treeHash($this->candidate);
        $declaration = $this->declaration();
        return ['app' => $this->app, 'version' => $info['version'], 'phase' => 'ready',
            'files' => $files, 'declaration' => $declaration, 'database' => $this->database($declaration)];
    }

    private function display(array $snapshot): array
    {
        $display = ['app' => $this->app, 'version' => $snapshot['version'], 'phase' => $snapshot['phase'],
            'tables' => array_keys($snapshot['database']['tables']),
            'menus' => array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'code' => (string) $row['code']], $snapshot['database']['menus']),
            'paths' => array_keys(array_filter($snapshot['files'], static fn (?string $hash): bool => $hash !== null)),
            'fingerprint' => self::hash($snapshot)];
        if (isset($snapshot['declaration']['cleanup_package_version'])) $display['cleanup_package_version'] = $snapshot['declaration']['cleanup_package_version'];
        return $display;
    }

    /** Parse declarations only. No original lifecycle statement is executed. */
    private function declaration(): array
    {
        $install = $this->readSql($this->candidate . '/install.sql');
        $uninstall = $this->readSql($this->candidate . '/uninstall.sql');
        $declaration = $this->parseDeclaration($install, $uninstall);
        $supplement = $this->supplement($this->candidate . '/.cleanup-package.json', $this->app);
        if ($supplement !== null) {
            $additional = $this->parseDeclaration($supplement['install_sql'], $supplement['uninstall_sql']);
            $declaration['tables'] = array_values(array_unique(array_merge($declaration['tables'], $additional['tables'])));
            sort($declaration['tables']);
            $declaration['unproven_tables'] = array_values(array_diff(array_unique(array_merge($declaration['unproven_tables'], $additional['unproven_tables'])), $declaration['tables']));
            sort($declaration['unproven_tables']);
            $conditions = array_values(array_unique(array_filter([$declaration['menu_condition'], $additional['menu_condition']], static fn (?string $condition): bool => $condition !== null)));
            $declaration['menu_condition'] = $conditions === [] ? null : '(' . implode(') OR (', $conditions) . ')';
            $declaration['cleanup_package_version'] = $supplement['version'];
            $declaration['cleanup_package_sha256'] = $supplement['sha256'];
            $declaration['cleanup_install_hash'] = $additional['install_hash'];
            $declaration['cleanup_uninstall_hash'] = $additional['uninstall_hash'];
        }
        $protected = [];
        foreach (array_merge(glob(base_path() . '/plugin/sandadmin/db/*') ?: [], glob($this->root . '/*/install.sql') ?: []) as $file) {
            if ($file === $this->candidate . '/install.sql' || !is_file($file)) continue;
            $protected = array_merge($protected, self::createdTables($this->readSql($file)));
        }
        foreach (glob($this->root . '/*/.cleanup-package.json') ?: [] as $file) {
            if ($file === $this->candidate . '/.cleanup-package.json') continue;
            $other = $this->supplement($file, basename(dirname($file)));
            if ($other !== null) $protected = array_merge($protected, self::createdTables($other['install_sql']));
        }
        foreach (array_merge($declaration['tables'], $declaration['unproven_tables']) as $table) {
            if (preg_match('/\.(?:sand_system_|sand_tool_|sa_)/', $table) || in_array($table, $protected, true)) {
                throw new ApiException('候选表属于宿主或其他插件，禁止清理：' . $table);
            }
        }
        return $declaration;
    }

    private function supplement(string $path, string $app): ?array
    {
        $this->safe($path);
        if (!file_exists($path)) return null;
        if (!is_file($path) || filesize($path) > 16777216) throw new ApiException('补充清理包元数据无效或超过大小限制');
        $package = json_decode((string) file_get_contents($path), true);
        if (!is_array($package) || ($package['app'] ?? null) !== $app
            || !is_string($package['version'] ?? null) || strlen($package['version']) > 80
            || !preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $package['version'])
            || !is_string($package['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/iD', $package['sha256'])
            || !is_string($package['install_sql'] ?? null) || !is_string($package['uninstall_sql'] ?? null)
            || trim($package['install_sql']) === '' || trim($package['uninstall_sql']) === '') {
            throw new ApiException('补充清理包必须包含相同插件标识、有效版本、包摘要和生命周期声明');
        }
        return $package;
    }

    private function parseDeclaration(string $install, string $uninstall): array
    {
        $created = self::createdTables($install);
        $dropped = [];
        $altered = [];
        $menuCondition = null;
        $roleCondition = null;
        $transaction = 0;
        $finished = false;
        foreach (PostgresLifecycleSqlExecutor::split($uninstall) as $statement) {
            $sql = self::leading($statement);
            if (preg_match('/^BEGIN(?:\s+WORK|\s+TRANSACTION)?$/iD', $sql)) {
                if ($transaction !== 0 || $finished) throw new ApiException('清理只支持一个完整卸载事务');
                $transaction = 1;
            } elseif (preg_match('/^COMMIT(?:\s+WORK|\s+TRANSACTION)?$/iD', $sql)) {
                if ($transaction !== 1) throw new ApiException('卸载事务声明不完整');
                $transaction = 0; $finished = true;
            } elseif ($finished) {
                throw new ApiException('卸载事务结束后仍存在 SQL，不能自动清理');
            } elseif (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(' . self::TABLE_NAME . ')(?:\s+RESTRICT)?$/iD', $sql, $match)) {
                $dropped[] = self::table($match[1]);
            } elseif (preg_match('/^ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(' . self::TABLE_NAME . ')\s+DROP\s+CONSTRAINT\s+(?:IF\s+EXISTS\s+)?[a-z_][a-z0-9_]*$/iD', $sql, $match)) {
                $altered[] = self::table($match[1]);
            } elseif (preg_match('/^DELETE\s+FROM\s+(?:' . self::fixedIdentifier('public') . '\.)?' . self::fixedIdentifier('sand_system_menu') . '\s+WHERE\s+(.+)$/isD', $sql, $match) && $menuCondition === null) {
                $menuCondition = $this->condition($match[1]);
            } elseif (preg_match('/^DELETE\s+FROM\s+(?:' . self::fixedIdentifier('public') . '\.)?' . self::fixedIdentifier('sand_system_role_menu') . '\s+WHERE\s+' . self::fixedIdentifier('menu_id') . '\s+IN\s*\(\s*SELECT\s+' . self::fixedIdentifier('id') . '\s+FROM\s+(?:' . self::fixedIdentifier('public') . '\.)?' . self::fixedIdentifier('sand_system_menu') . '\s+WHERE\s+(.+)\s*\)$/isD', $sql, $match) && $roleCondition === null) {
                $roleCondition = $this->condition(trim($match[1]));
            } else {
                throw new ApiException('卸载脚本包含未支持的清理语句；仅支持 owned 表 DROP/约束声明与限定菜单删除');
            }
        }
        if ($transaction !== 0) throw new ApiException('卸载事务未闭合');
        $dropped = array_values(array_unique($dropped)); sort($dropped);
        if (array_diff($created, $dropped) !== [] || array_diff($altered, $dropped) !== []) throw new ApiException('安装与卸载表声明不一致，不能证明完整清理范围');
        if ($menuCondition !== $roleCondition) throw new ApiException('菜单与角色菜单清理条件不一致');
        return ['tables' => $created, 'unproven_tables' => array_values(array_diff($dropped, $created)), 'menu_condition' => $menuCondition,
            'install_hash' => hash('sha256', $install), 'uninstall_hash' => hash('sha256', $uninstall)];
    }

    /** Unquoted identifiers fold to lowercase; quoted identifiers must already be exact. */
    private static function fixedIdentifier(string $name): string
    {
        return '(?:' . preg_quote($name, '/') . '(?![a-z0-9_$])|(?-i:"' . preg_quote($name, '/') . '"))';
    }

    private function condition(string $sql): string
    {
        $literal = "'(?:[^']|'')*'";
        $term = self::fixedIdentifier('code') . "\\s*(?:=\\s*($literal)|LIKE\\s+($literal)(?:\\s+ESCAPE\\s+($literal))?)";
        $ownedTerm = "(" . implode('|', array_map(self::fixedIdentifier(...), ['slug', 'component', 'path'])) . ")\\s*(=|LIKE)\\s*($literal)";
        $parts = [];
        while ($sql !== '') {
            $value = static fn (string $text): string => str_replace("''", "'", substr($text, 1, -1));
            if (preg_match('/\A\s*' . $ownedTerm . '/i', $sql, $match)) {
                $field = strtolower(trim($match[1], '"'));
                $operator = strtoupper($match[2]);
                $pattern = $value($match[3]);
                $expected = match ($field . ' ' . $operator) {
                    'slug LIKE' => $this->app . ':%',
                    'component LIKE' => '/plugin/' . $this->app . '/%',
                    'path =' => '/' . $this->app,
                    'path LIKE' => '/' . $this->app . '/%',
                    default => null,
                };
                if ($expected === null || $pattern !== $expected || strpbrk($this->app, '_%\\') !== false) {
                    throw new ApiException('菜单 slug、component、path 条件只能使用该插件的精确路径或权限前缀');
                }
                $parts[] = $field . ' ' . $operator . ' ' . $this->pdo->quote($pattern);
            } elseif (!preg_match('/\A\s*' . $term . '/i', $sql, $match)) {
                throw new ApiException('菜单删除条件不是受支持的插件归属限定条件');
            } elseif (($match[1] ?? '') !== '') {
                if ($value($match[1]) === '') throw new ApiException('空 code 不能证明菜单归属');
                $parts[] = 'code = ' . $this->pdo->quote($value($match[1]));
            } else {
                if ($value($match[2]) === '') throw new ApiException('空 code 不能证明菜单归属');
                $part = 'code LIKE ' . $this->pdo->quote($value($match[2]));
                if (($match[3] ?? '') !== '') {
                    $escape = $value($match[3]);
                    if (strlen($escape) !== 1) throw new ApiException('菜单匹配转义字符无效');
                    $part .= ' ESCAPE ' . $this->pdo->quote($escape);
                }
                $parts[] = $part;
            }
            $sql = trim(substr($sql, strlen($match[0])));
            if ($sql === '') break;
            if (!preg_match('/\AOR\s+/i', $sql, $separator)) throw new ApiException('菜单删除条件包含未支持的运算');
            $sql = substr($sql, strlen($separator[0]));
            if (trim($sql) === '') throw new ApiException('菜单删除条件不完整');
        }
        if ($parts === []) throw new ApiException('菜单删除范围为空');
        $parts = array_values(array_unique($parts));
        sort($parts, SORT_STRING);
        return implode(' OR ', $parts);
    }

    private function database(array $declaration): array
    {
        $identity = $this->rows("SELECT current_database() AS database, (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid, current_user AS username, inet_server_addr()::text AS address, inet_server_port() AS port");
        $tables = [];
        foreach ($declaration['unproven_tables'] as $name) {
            [$schema, $table] = explode('.', $name);
            if ($this->rows('SELECT c.oid::text AS oid, c.relkind AS kind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=' . $this->pdo->quote($schema) . ' AND c.relname=' . $this->pdo->quote($table)) !== []) throw new ApiException('卸载脚本还声明了缺少安装归属证据的现存表：' . $name);
        }
        foreach ($declaration['tables'] as $name) {
            [$schema, $table] = explode('.', $name);
            $relations = $this->rows('SELECT c.oid::text AS oid, c.relkind AS kind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=' . $this->pdo->quote($schema) . ' AND c.relname=' . $this->pdo->quote($table));
            if ($relations === []) continue;
            if (count($relations) !== 1 || $relations[0]['kind'] !== 'r') throw new ApiException('清理仅支持普通表：' . $name);
            $oid = (int) $relations[0]['oid'];
            $definition = $this->rows('SELECT a.attname, a.atttypid::text, a.atttypmod, a.attnotnull, a.attidentity, a.attgenerated, pg_get_expr(d.adbin,d.adrelid) AS default_expression FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=' . $oid . ' AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum');
            $constraints = $this->rows('SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=' . $oid . ' ORDER BY conname');
            $rows = $this->rows('SELECT to_jsonb(t)::text AS row_value FROM ' . self::qualified($name) . ' t');
            $hashes = array_map(static fn (array $row): string => hash('sha256', $row['row_value']), $rows); sort($hashes);
            $tables[$name] = ['oid' => $oid, 'definition' => self::hash([$definition, $constraints]), 'rows' => self::hash($hashes)];
        }
        if ($tables !== []) {
            $oids = implode(',', array_column($tables, 'oid'));
            $dependencies = $this->rows("SELECT DISTINCT n.nspname || '.' || c.relname AS dependent_table FROM pg_constraint f JOIN pg_class c ON c.oid=f.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE f.contype='f' AND f.confrelid IN (" . $oids . ') AND f.conrelid NOT IN (' . $oids . ') ORDER BY dependent_table');
            if ($dependencies !== []) throw new ApiException('清理范围不完整，以下表仍依赖插件：' . implode('、', array_column($dependencies, 'dependent_table')) . '。请补充对应版本的插件包后重新检查');
        }
        $menus = []; $roles = [];
        $all = $this->rows('SELECT id::text, parent_id::text, name, code, path, component, to_jsonb(m)::text AS row_value FROM public.sand_system_menu m ORDER BY id');
        $selected = $declaration['menu_condition'] === null ? [] : $this->rows('SELECT id::text FROM public.sand_system_menu WHERE ' . $declaration['menu_condition'] . ' ORDER BY id');
        $ids = array_column($selected, 'id');
        $byId = [];
        foreach ($all as $row) $byId[(string) $row['id']] = $row;
        foreach ($all as $row) {
            $id = (string) $row['id']; $parent = (string) $row['parent_id'];
            if (!in_array($id, $ids, true)) {
                $component = ltrim((string) $row['component'], '/');
                $path = (string) $row['path'];
                if (str_starts_with($component, 'plugin/' . $this->app . '/') || $path === '/' . $this->app || str_starts_with($path, '/' . $this->app . '/')) throw new ApiException('卸载声明未覆盖该插件现存菜单：' . $row['code']);
                if (in_array($parent, $ids, true)) throw new ApiException('插件菜单下存在不属于卸载声明的子菜单：' . $row['code']);
                continue;
            }
            $current = $row; $visited = []; $owned = false;
            while (true) {
                $currentId = (string) $current['id'];
                if (isset($visited[$currentId])) throw new ApiException('插件菜单存在循环父子关系');
                $visited[$currentId] = true;
                $component = ltrim((string) $current['component'], '/');
                $path = (string) $current['path'];
                if ($component !== '' && !str_starts_with($component, 'plugin/' . $this->app . '/')) throw new ApiException('卸载菜单条件包含其他插件组件：' . $current['code']);
                if (str_starts_with($path, '/') && $path !== '/' . $this->app && !str_starts_with($path, '/' . $this->app . '/')) throw new ApiException('卸载菜单条件包含其他应用路径：' . $current['code']);
                if ($component !== '' || $path === '/' . $this->app || str_starts_with($path, '/' . $this->app . '/')) $owned = true;
                $parentId = (string) $current['parent_id'];
                if (!in_array($parentId, $ids, true) || !isset($byId[$parentId])) break;
                $current = $byId[$parentId];
            }
            if (!$owned) throw new ApiException('菜单缺少该插件路径或组件归属证据：' . $row['code']);
            $menus[] = $row;
        }
        if ($ids !== []) $roles = $this->rows('SELECT to_jsonb(r)::text AS row_value FROM public.sand_system_role_menu r WHERE menu_id IN (' . self::ids($ids) . ') ORDER BY role_id, menu_id');
        return ['identity' => self::hash($identity), 'tables' => $tables, 'menus' => $menus, 'roles' => $roles];
    }

    private function cleanDatabase(array $record): void
    {
        if ($this->pdo->inTransaction()) throw new ApiException('清理不能接管已有事务');
        if (!$this->pdo->beginTransaction()) throw new ApiException('无法开始插件清理事务');
        try {
            if ($this->rows("SELECT evtname FROM pg_event_trigger WHERE evtenabled <> 'D'") !== []) throw new ApiException('存在数据库事件触发器，无法保证清理边界');
            $tables = array_keys($record['before']['tables']);
            $locks = array_map(self::qualified(...), $tables);
            $locks[] = '"public"."sand_system_menu"'; $locks[] = '"public"."sand_system_role_menu"';
            if ($this->rows("SELECT conname FROM pg_constraint WHERE contype='f' AND confrelid IN ('public.sand_system_menu'::regclass, 'public.sand_system_role_menu'::regclass) AND conrelid NOT IN ('public.sand_system_menu'::regclass, 'public.sand_system_role_menu'::regclass) AND confdeltype IN ('c','n','d')") !== []) throw new ApiException('共享菜单表存在影响外部表的级联约束，无法保证清理边界');
            if ($this->rows("SELECT tgname FROM pg_trigger WHERE tgrelid IN ('public.sand_system_menu'::regclass, 'public.sand_system_role_menu'::regclass) AND NOT tgisinternal AND tgenabled <> 'D'") !== []) throw new ApiException('共享菜单表存在自定义触发器，无法保证清理边界');
            if ($locks !== []) $this->execute('LOCK TABLE ' . implode(', ', $locks) . ' IN ACCESS EXCLUSIVE MODE NOWAIT');
            if ($this->database($record['snapshot']['declaration']) !== $record['before']) throw new ApiException('数据库已变化，请重新检查后清理');
            $ids = array_column($record['before']['menus'], 'id');
            if ($ids !== []) {
                $this->execute('DELETE FROM public.sand_system_role_menu WHERE menu_id IN (' . self::ids($ids) . ')');
                $this->execute('DELETE FROM public.sand_system_menu WHERE id IN (' . self::ids($ids) . ')');
            }
            if ($tables !== []) $this->execute('DROP TABLE ' . implode(', ', array_map(self::qualified(...), $tables)) . ' RESTRICT');
            $after = $this->database($record['snapshot']['declaration']);
            if ($after['identity'] !== $record['before']['identity'] || $after['tables'] !== [] || $after['menus'] !== [] || $after['roles'] !== []) throw new ApiException('数据库清理后置状态不匹配');
            $record['after'] = $after; $record['phase'] = 'commit_intent';
            $this->save($record);
            if (!$this->pdo->commit()) throw new ApiException('清理提交结果未知，请重新检查');
            $record['phase'] = 'files_pending';
            $this->save($record);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof ApiException) throw $error;
            $state = (string) $error->getCode();
            $message = match ($state) {
                '2BP01' => '存在其他对象依赖该插件表，本次清理已回滚；请先解除依赖后重新检查',
                '55P03' => '插件或菜单数据正在被使用，本次未清理；请稍后重新检查',
                default => '数据库清理未能完成，请重新检查现场后重试；提交结果未知时系统只按核对后的状态继续',
            };
            throw new ApiException($message, 0, $error);
        }
    }

    private static function createdTables(string $sql): array
    {
        $tables = [];
        foreach (PostgresLifecycleSqlExecutor::split($sql) as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(' . self::TABLE_NAME . ')\s*\(/i', self::leading($statement), $match)) $tables[] = self::table($match[1]);
        }
        $tables = array_values(array_unique($tables)); sort($tables); return $tables;
    }

    private static function leading(string $sql): string
    {
        $sql = trim($sql);
        while (str_starts_with($sql, '--') || str_starts_with($sql, '/*')) {
            if (str_starts_with($sql, '--')) { $end = strpos($sql, "\n"); $sql = $end === false ? '' : ltrim(substr($sql, $end + 1)); continue; }
            $depth = 1; $offset = 2;
            while ($depth > 0 && $offset < strlen($sql)) {
                $pair = substr($sql, $offset, 2);
                if ($pair === '/*') { $depth++; $offset += 2; }
                elseif ($pair === '*/') { $depth--; $offset += 2; }
                else $offset++;
            }
            if ($depth !== 0) throw new ApiException('SQL 注释未闭合');
            $sql = ltrim(substr($sql, $offset));
        }
        return trim($sql);
    }

    private function readSql(string $path): string
    {
        $this->safe($path);
        $sql = @file_get_contents($path);
        if (!is_string($sql)) throw new ApiException('无法读取插件生命周期声明：' . basename($path));
        return $sql;
    }
    private static function table(string $name): string
    {
        if (preg_match('/"[^"]*[A-Z][^"]*"/', $name)) throw new ApiException('自动清理不支持区分大小写的引用表名');
        $name = str_replace('"', '', $name);
        return str_contains($name, '.') ? strtolower($name) : 'public.' . strtolower($name);
    }
    private static function qualified(string $name): string { return '"' . str_replace('.', '"."', $name) . '"'; }
    private static function ids(array $ids): string
    {
        foreach ($ids as $id) if (!preg_match('/^[0-9]+$/D', (string) $id)) throw new ApiException('菜单 ID 无效');
        return implode(',', $ids);
    }
    private static function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private function treeHash(string $path): ?string { $tree = FreshInstallRecovery::tree($path); return $tree === null ? null : self::hash($tree); }
    private function safe(string $path): void
    {
        if (!str_starts_with($path, '/')) throw new ApiException('清理路径必须为绝对路径');
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new ApiException('清理路径无效');
            $current .= '/' . $part; clearstatcache(true, $current);
            if (is_link($current)) throw new ApiException('清理路径不能经过符号链接');
        }
    }
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) throw new ApiException('无法读取插件清理现场');
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    private function execute(string $sql): void { if ($this->pdo->exec($sql) === false) throw new ApiException('插件清理 SQL 失败'); }
    private function journal(): ?array
    {
        $this->safe($this->journalPath);
        if (!is_file($this->journalPath)) return null;
        if (filesize($this->journalPath) > 16777216) throw new ApiException('异常清理日志超过大小限制');
        $record = json_decode((string) file_get_contents($this->journalPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['format'] ?? null) !== 1 || ($record['app'] ?? null) !== $this->app
            || !preg_match('/^[a-f0-9]{32}$/D', $record['operation'] ?? '')
            || ($record['archive'] ?? '') !== $this->root . '/archives/' . $this->app . '-' . $record['operation']
            || !in_array($record['phase'] ?? '', ['prepared', 'commit_intent', 'files_pending', 'cleaned'], true)) throw new ApiException('异常清理日志无效');
        if (!is_array($record['snapshot'] ?? null) || !is_array($record['snapshot']['files'] ?? null)
            || !is_array($record['snapshot']['declaration'] ?? null) || !is_array($record['before'] ?? null)
            || !array_key_exists('after', $record) || !is_array($record['moves'] ?? null)) throw new ApiException('异常清理日志缺少现场记录');
        $expectedPaths = array_values($this->paths); $expectedPaths[] = $this->candidate;
        $recordedPaths = array_keys($record['snapshot']['files']); sort($expectedPaths); sort($recordedPaths);
        if ($expectedPaths !== $recordedPaths) throw new ApiException('异常清理日志的部署范围不匹配');
        $seen = [];
        foreach ($record['moves'] as $index => $move) {
            $source = $move['source'] ?? '';
            $destination = $record['archive'] . '/' . ($source === $this->candidate ? 'candidate' : 'deployment-' . $index);
            if (!in_array($source, $expectedPaths, true) || isset($seen[$source]) || ($move['destination'] ?? null) !== $destination
                || !is_string($move['hash'] ?? null) || $move['hash'] !== $record['snapshot']['files'][$source]) throw new ApiException('异常清理日志的归档范围不匹配');
            $seen[$source] = true;
        }
        foreach ($record['snapshot']['files'] as $source => $hash) if ($hash !== null && !isset($seen[$source])) throw new ApiException('异常清理日志遗漏归档源');
        return $record;
    }
    private function journalRequired(): array { return $this->journal() ?? throw new ApiException('异常清理日志不存在'); }
    private function makeDirectory(string $path): void
    {
        $this->safe($path);
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new ApiException('无法创建清理归档目录');
    }
    private function save(array $record): void
    {
        $this->safe($this->journalPath); $this->makeDirectory(dirname($this->journalPath));
        $temporary = $this->journalPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $body = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new ApiException('无法保存异常清理日志');
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) throw new ApiException('异常清理日志未持久化');
        } finally { fclose($handle); }
        if (!rename($temporary, $this->journalPath)) throw new ApiException('无法替换异常清理日志');
    }
}
