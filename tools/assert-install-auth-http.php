#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 安装路由鉴权 HTTP 集成断言：php tools/assert-install-auth-http.php [--keep]
 *
 * 自举临时 webman 宿主（webman 骨架 + sand-core + 本仓 path 软链）与独立
 * 临时 PostgreSQL，用实例 JWT 配置签发测试令牌（只写临时 curl 配置文件，
 * 不回显明文）；断言未登录 code:401、普通管理员（id≠1）真实 HTTP 403，
 * 并抽查超管放行、伪造令牌与方法限定。结束即停进程、删临时目录，--keep
 * 保留现场。前置：pdo_pgsql、composer、curl、initdb/pg_ctl/psql/createdb。
 */

$repoRoot = dirname(__DIR__);
$keepWork = in_array('--keep', $argv ?? [], true);
$assertions = 0;

$fail = static function (string $message): never {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
};

// 命令一律用数组形式传给 proc_open，不经 shell；令牌不进命令行。
$run = static function (array $command, string $cwd, string $logFile) use ($fail): void {
    $log = fopen($logFile, 'ab');
    if ($log === false) {
        $fail('无法写日志 ' . $logFile);
    }
    fwrite($log, '$ ' . implode(' ', $command) . PHP_EOL);
    $proc = proc_open($command, [0 => ['pipe', 'rb'], 1 => $log, 2 => $log], $pipes, $cwd);
    if (!is_resource($proc)) {
        fclose($log);
        $fail('无法启动命令 ' . $command[0]);
    }
    fclose($pipes[0]);
    $code = proc_close($proc);
    fclose($log);
    if ($code !== 0) {
        $tail = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $lines = array_slice(explode(PHP_EOL, trim($tail)), -20);
        $fail('命令失败（' . $code . '）' . $command[0] . PHP_EOL . implode(PHP_EOL, $lines));
    }
};

$capture = static function (array $command, string $cwd) use ($fail): string {
    $proc = proc_open($command, [0 => ['pipe', 'rb'], 1 => ['pipe', 'wb'], 2 => ['pipe', 'wb']], $pipes, $cwd);
    if (!is_resource($proc)) {
        $fail('无法启动命令 ' . $command[0]);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        $fail('命令失败（' . $code . '）' . $command[0] . '：' . trim((string) $err));
    }
    return trim((string) $out);
};

$freePort = static function () use ($fail): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    if ($sock === false) {
        $fail('无法获取空闲端口');
    }
    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, strrpos($name, ':') + 1);
};

$waitPort = static function (int $port, int $seconds) use ($fail): void {
    $deadline = microtime(true) + $seconds;
    do {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if (is_resource($conn)) {
            fclose($conn);
            return;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    $fail("端口 {$port} 在 {$seconds}s 内未开始监听");
};

$removeTree = static function (string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        $item->isDir() && !is_link($path) ? @rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
};

// curl -K 配置文件装 Authorization 头，令牌不出现在进程命令行。
$request = static function (string $method, string $base, string $path, ?string $credFile) use ($capture): array {
    $command = ['curl', '-sS', '-X', $method, '-w', "\n__HTTP_CODE__%{http_code}"];
    if ($credFile !== null) {
        $command[] = '-K';
        $command[] = $credFile;
    }
    $command[] = $base . $path;
    $raw = $capture($command, sys_get_temp_dir());
    $pos = strrpos($raw, "\n__HTTP_CODE__");
    if ($pos === false) {
        return ['status' => 0, 'body' => $raw];
    }
    return ['status' => (int) substr($raw, $pos + 14), 'body' => substr($raw, 0, $pos)];
};

$json = static function (string $body): array {
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
};

$paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
foreach (['composer', 'curl', 'initdb', 'pg_ctl', 'psql', 'createdb'] as $bin) {
    $found = false;
    foreach ($paths as $dir) {
        if (is_executable($dir . '/' . $bin)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $fail('缺少命令 ' . $bin);
    }
}
if (!extension_loaded('pdo_pgsql')) {
    $fail('PHP 缺少 pdo_pgsql 扩展');
}

$workDir = sys_get_temp_dir() . '/sandpkg-auth-' . bin2hex(random_bytes(4));
$hostDir = $workDir . '/host';
$pgDir = $workDir . '/pgdata';
$logDir = $workDir . '/logs';
$httpPort = $freePort();
$pgPort = $freePort();
while ($pgPort === $httpPort) {
    $pgPort = $freePort();
}
mkdir($hostDir, 0700, true);
mkdir($logDir, 0700, true);
fwrite(STDOUT, "工作目录 {$workDir}（结束自动删除）；http={$httpPort} pgsql={$pgPort}" . PHP_EOL);

$webmanStarted = false;
try {
    // 临时 PostgreSQL
    fwrite(STDOUT, '[1/6] 初始化临时 PostgreSQL' . PHP_EOL);
    $run(['initdb', '-D', $pgDir, '-U', 'postgres', '--auth=trust', '-E', 'UTF8', '-N'], $workDir, $logDir . '/pg.log');
    $run([
        'pg_ctl', '-D', $pgDir, '-w', '-t', '30',
        '-o', "-p {$pgPort} -k {$workDir} -c listen_addresses=127.0.0.1",
        '-l', $logDir . '/pg-server.log', 'start',
    ], $workDir, $logDir . '/pg.log');
    $waitPort($pgPort, 15);
    $dbName = 'sandpkg_auth_test';
    $psql = static fn(array $extra): array => array_merge(
        ['psql', '-X', '-h', '127.0.0.1', '-p', (string) $pgPort, '-U', 'postgres', '-d', $dbName],
        $extra,
    );
    $run(['createdb', '-h', '127.0.0.1', '-p', (string) $pgPort, '-U', 'postgres', $dbName], $workDir, $logDir . '/pg.log');

    // webman 宿主骨架
    fwrite(STDOUT, '[2/6] composer 安装宿主与依赖（首次需下载，可能耗时）' . PHP_EOL);
    rmdir($hostDir);
    $run(['composer', 'create-project', 'workerman/webman', $hostDir, '--no-interaction', '--no-scripts', '--no-progress'], $workDir, $logDir . '/composer.log');
    $repoSpec = json_encode([
        'type' => 'path',
        'url' => $repoRoot,
        'options' => ['symlink' => true],
    ], JSON_UNESCAPED_SLASHES);
    $run(['composer', 'config', '--json', 'repositories.sand-package', $repoSpec], $hostDir, $logDir . '/composer.log');
    $run([
        'composer', 'require', '--no-interaction', '--no-progress',
        'supdger/sand-package:*', 'supdger/sand-core:^0.2.9',
        'phpoption/phpoption:*', 'vlucas/phpdotenv:*',
    ], $hostDir, $logDir . '/composer.log');

    // 插件载荷应由 composer 钩子复制；缺失时兜底手动复制，保证测的是本仓代码。
    $payload = [
        'sandadmin' => $hostDir . '/vendor/supdger/sand-core/server/plugin/sandadmin',
        'sandpackage' => $repoRoot . '/server/plugin/sandpackage',
    ];
    foreach ($payload as $name => $source) {
        if (is_dir($hostDir . '/plugin/' . $name)) {
            continue;
        }
        $run(['cp', '-R', $source, $hostDir . '/plugin/' . $name], $workDir, $logDir . '/composer.log');
    }

    // 宿主配置
    fwrite(STDOUT, '[3/6] 写宿主配置（端口、数据库、关闭无关进程）' . PHP_EOL);
    $processFile = $hostDir . '/config/process.php';
    $processConf = (string) file_get_contents($processFile);
    $processConf = preg_replace(
        "/'listen'\s*=>\s*'[^']*'/",
        "'listen' => 'http://127.0.0.1:{$httpPort}'",
        $processConf,
        1,
    );
    $processConf = preg_replace("/'count'\s*=>[^,]+/", "'count' => 1", $processConf, 1);
    file_put_contents($processFile, $processConf);
    file_put_contents($hostDir . '/config/plugin/webman/channel/app.php', "<?php\nreturn ['enable' => false];\n");

    $dbConf = function (string $port) {
        return <<<PHP
        <?php

        return [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'type' => 'pgsql',
                    'hostname' => '127.0.0.1',
                    'database' => 'sandpkg_auth_test',
                    'username' => 'postgres',
                    'password' => '',
                    'hostport' => {$port},
                    'params' => [\\PDO::ATTR_TIMEOUT => 3],
                    'charset' => 'utf8',
                    'prefix' => '',
                    'break_reconnect' => true,
                ],
            ],
        ];

        PHP;
    };
    file_put_contents($hostDir . '/config/think-orm.php', $dbConf((string) $pgPort));
    $illConf = <<<PHP
    <?php

    return [
        'default' => 'pgsql',
        'connections' => [
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => '{$pgPort}',
                'database' => 'sandpkg_auth_test',
                'username' => 'postgres',
                'password' => '',
                'charset' => 'utf8',
                'prefix' => '',
                'options' => [\\PDO::ATTR_TIMEOUT => 3],
            ],
        ],
    ];

    PHP;
    file_put_contents($hostDir . '/config/database.php', $illConf);

    // JWT 密钥：安装器一般会写入随机值；为空、占位或过短时换成随机密钥，
    // 只写文件不回显，满足运行时密钥守门。
    $jwtFile = $hostDir . '/config/plugin/tinywan/jwt/app.php';
    $jwtConf = is_file($jwtFile) ? (array) include $jwtFile : [];
    $jwt = (array) ($jwtConf['jwt'] ?? []);
    $weakKey = static function (mixed $key): bool {
        return !is_string($key) || strlen($key) < 32 || str_contains($key, '__');
    };
    if ($weakKey($jwt['access_secret_key'] ?? null) || $weakKey($jwt['refresh_secret_key'] ?? null)) {
        $jwt['access_secret_key'] = bin2hex(random_bytes(32));
        $jwt['refresh_secret_key'] = bin2hex(random_bytes(32));
        $jwtConf['jwt'] = $jwt;
        file_put_contents($jwtFile, '<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($jwtConf, true) . ';' . PHP_EOL);
    }

    // schema 与测试令牌
    fwrite(STDOUT, '[4/6] 载入核心 schema 并签发测试令牌（明文不回显）' . PHP_EOL);
    $coreDb = $hostDir . '/vendor/supdger/sand-core/server/plugin/sandadmin/db';
    $schemaFile = $coreDb . '/sandadmin-6.0.pgsql';
    if (!is_file($schemaFile)) {
        $fail('未找到核心 schema ' . $schemaFile);
    }
    $run($psql(['-v', 'ON_ERROR_STOP=1', '-f', $schemaFile]), $workDir, $logDir . '/pg.log');
    $ormDir = $hostDir . '/vendor/topthink/think-orm/src/db/connector';
    $pgVer = (int) $capture($psql(['-tAc', 'show server_version_num']), $workDir);
    $metaFile = $ormDir . ($pgVer >= 120000 ? '/pgsql12.sql' : '/pgsql.sql');
    $run($psql(['-v', 'ON_ERROR_STOP=1', '-f', $metaFile]), $workDir, $logDir . '/pg.log');
    // id=1 超管的强制改密标志置 0，否则它只能访问改密与用户信息两个接口。
    $run($psql(['-c', 'update sand_system_user set must_change_password=0 where id in (1,2)']), $workDir, $logDir . '/pg.log');

    file_put_contents($hostDir . '/mint-token.php', <<<'PHP'
    <?php
    // 测试令牌签发：用本实例 JWT 配置给指定用户 id 签 access token。
    chdir(__DIR__);
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/vendor/workerman/webman-framework/src/support/helpers.php';
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', __DIR__);
    }
    \Webman\Config::load(config_path());
    $id = (int)($argv[1] ?? 0);
    $username = (string)($argv[2] ?? 'admin');
    $token = \Tinywan\Jwt\JwtToken::generateToken(['id' => $id, 'username' => $username, 'plat' => 'sandadmin']);
    fwrite(STDOUT, $token['access_token'] . "\n");
    PHP);

    $credDir = $workDir . '/cred';
    mkdir($credDir, 0700);
    $writeCred = static function (string $user, string $name) use ($capture, $hostDir, $credDir): string {
        $idArg = $user === 'admin' ? '1' : '2';
        $token = $capture(['php', 'mint-token.php', $idArg, $user], $hostDir);
        if ($token === '') {
            throw new RuntimeException('令牌签发为空');
        }
        $file = $credDir . '/' . $name . '.cfg';
        file_put_contents($file, 'header = "Authorization: Bearer ' . $token . '"' . PHP_EOL);
        chmod($file, 0600);
        return $file;
    };
    $adminCred = $writeCred('admin', 'admin');
    $userCred = $writeCred('martin', 'user');
    fwrite(STDOUT, '测试令牌已签发（id=1 超管 / id=2 普通管理员，明文不回显）' . PHP_EOL);

    // 启动 webman
    fwrite(STDOUT, '[5/6] 启动临时 webman 实例' . PHP_EOL);
    putenv('SANDADMIN_CRONTAB_ENABLED=false');
    $run(['php', 'start.php', 'start', '-d'], $hostDir, $logDir . '/webman.log');
    $webmanStarted = true;
    $waitPort($httpPort, 20);
    $base = "http://127.0.0.1:{$httpPort}";

    // HTTP 断言
    fwrite(STDOUT, '[6/6] 发送断言请求' . PHP_EOL);
    $check = static function (string $label, array $resp, int $http, ?int $code) use (&$assertions, $json, $fail): void {
        $assertions++;
        $data = $json($resp['body']);
        $got = $data['code'] ?? null;
        $ok = $resp['status'] === $http && ($code === null || $got === $code);
        fwrite(STDOUT, sprintf(
            "  %-52s HTTP %d code %s → %s\n",
            $label,
            $resp['status'],
            var_export($got, true),
            $ok ? 'PASS' : 'FAIL',
        ));
        if (!$ok) {
            $fail("{$label}：期望 HTTP {$http} code " . var_export($code, true));
        }
    };

    $guest = null;
    foreach ([
        ['GET', '/app/sandpackage/install/index'],
        ['POST', '/app/sandpackage/install/install'],
        ['POST', '/app/sandpackage/install/upload'],
        ['GET', '/app/sandpackage/systemUpdate/status'],
        ['POST', '/app/sandpackage/systemUpdate/start'],
    ] as [$method, $path]) {
        $check("未登录 {$method} {$path} → code 401", $request($method, $base, $path, $guest), 200, 401);
    }
    foreach ([
        ['GET', '/app/sandpackage/install/index'],
        ['POST', '/app/sandpackage/install/install'],
        ['GET', '/app/sandpackage/install/limits'],
        ['GET', '/app/sandpackage/systemUpdate/status'],
        ['POST', '/app/sandpackage/systemUpdate/start'],
    ] as [$method, $path]) {
        $check("普通管理员(id=2) {$method} {$path} → 真实 HTTP 403", $request($method, $base, $path, $userCred), 403, 403);
    }
    $check('超管(id=1) GET /app/sandpackage/install/index → 放行', $request('GET', $base, '/app/sandpackage/install/index', $adminCred), 200, 200);
    $check('超管(id=1) GET /app/sandpackage/install/limits → 放行', $request('GET', $base, '/app/sandpackage/install/limits', $adminCred), 200, 200);

    $badCred = $credDir . '/bad.cfg';
    file_put_contents($badCred, 'header = "Authorization: Bearer abc.def.ghi"' . PHP_EOL);
    $check('伪造令牌 GET /app/sandpackage/install/index → code 401', $request('GET', $base, '/app/sandpackage/install/index', $badCred), 200, 401);
    $check('错误方法 PUT /app/sandpackage/install/index → code 404', $request('PUT', $base, '/app/sandpackage/install/index', $guest), 200, 404);

    fwrite(STDOUT, "install auth http: 未登录 code:401 / 普通管理员真实 403 全部成立（{$assertions} 项断言）" . PHP_EOL);
} finally {
    if ($webmanStarted) {
        $stop = proc_open(['php', 'start.php', 'stop'], [1 => ['pipe', 'wb'], 2 => ['pipe', 'wb']], $pipes, $hostDir);
        if (is_resource($stop)) {
            proc_close($stop);
        }
    }
    if (is_dir($pgDir)) {
        $stopPg = proc_open(['pg_ctl', '-D', $pgDir, '-m', 'fast', '-w', 'stop'], [1 => ['pipe', 'wb'], 2 => ['pipe', 'wb']], $pipes, $workDir);
        if (is_resource($stopPg)) {
            proc_close($stopPg);
        }
    }
    if ($keepWork) {
        fwrite(STDOUT, '--keep：现场保留在 ' . $workDir . PHP_EOL);
    } else {
        $removeTree($workDir);
    }
}
