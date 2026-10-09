<?php
declare(strict_types=1);

// Real processes only; no host bootstrap, credentials, database or dependency install.
require_once dirname(__DIR__, 2) . '/plugin/sandpackage/tools/system-update-worker.php';
$autoload = getenv('SAND_WORKERMAN_AUTOLOAD') ?: '';
if (!is_file($autoload)) throw new RuntimeException('Set SAND_WORKERMAN_AUTOLOAD to an existing Composer autoload containing Workerman.');
$python = getenv('SAND_TEST_PYTHON') ?: '/usr/bin/python3';
$root = realpath(sys_get_temp_dir()) . '/sand-recover-restart-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$process = null;
$master = null;
$count = 0;
function checkRestart(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $count++;
    echo 'PASS: ' . $message . PHP_EOL;
}
function rejectsRestart(callable $call, string $message): void {
    try { $call(); } catch (RuntimeException $error) { checkRestart(true, $message . ' [' . $error->getMessage() . ']'); return; }
    throw new RuntimeException('FAIL: ' . $message);
}
function restartFacts(string $url): array {
    $context = stream_context_create(['http' => ['timeout' => 2, 'proxy' => null]]);
    $text = @file_get_contents($url, false, $context);
    if ($text === false) throw new RuntimeException('Own fixture HTTP not ready.');
    return json_decode($text, true, 16, JSON_THROW_ON_ERROR);
}
try {
    $server = $root . '/server';
    mkdir($server . '/runtime', 0700, true);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $listen = 'http://' . $address;
    $url = $listen . '/';
    file_put_contents($server . '/version', 'before');
    file_put_contents($server . '/start.php', <<<'WORKER'
<?php
require getenv('SAND_WORKERMAN_AUTOLOAD');
use Workerman\Worker;
use Workerman\Protocols\Http\Response;
Worker::$pidFile = __DIR__ . '/runtime/webman.pid';
Worker::$logFile = __DIR__ . '/runtime/workerman.log';
$worker = new Worker(getenv('SAND_FIXTURE_LISTEN'));
$worker->name = 'webman';
$worker->count = 1;
$worker->reloadable = getenv('SAND_FIXTURE_NO_RELOAD') !== '1';
$worker->onWorkerStart = function ($worker) {
    $version = file_get_contents(__DIR__ . '/version');
    $worker->onMessage = function ($connection) use ($version) {
        $connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode(['pid' => getmypid(), 'version' => $version])));
    };
};
Worker::runAll();
WORKER);
    file_put_contents($root . '/reload.php', <<<'CLI'
<?php
namespace support { final class Log { public static function error(string $text): void { fwrite(STDERR, $text . "\n"); } } }
namespace {
    function base_path(): string { return getenv('SAND_FIXTURE_SERVER'); }
    function config(string $key, mixed $default = null): mixed {
        return ['server.pid_file' => base_path() . '/runtime/webman.pid', 'process.webman.listen' => getenv('SAND_FIXTURE_LISTEN')][$key] ?? $default;
    }
    require getenv('SAND_WORKERMAN_AUTOLOAD');
    require getenv('SAND_RESTART_SOURCE');
    try {
        if (\Saithink\Saipackage\service\Server::restart() !== true) exit(1);
        echo "CLI reload successful\n";
    } catch (\Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
CLI);
    file_put_contents($root . '/caller.py', <<<'PYTHON'
import os
import subprocess
result = subprocess.run([os.environ["SAND_TEST_PHP"], os.environ["SAND_FIXTURE_CLI"]])
print("Python caller survived; CLI exit=" + str(result.returncode), flush=True)
raise SystemExit(result.returncode)
PYTHON);
    $environment = getenv();
    $environment['SAND_WORKERMAN_AUTOLOAD'] = $autoload;
    $environment['SAND_FIXTURE_SERVER'] = $server;
    $environment['SAND_FIXTURE_LISTEN'] = $listen;
    $environment['SAND_RESTART_SOURCE'] = getenv('SAND_RESTART_SOURCE') ?: dirname(__DIR__, 2) . '/compat/Saithink/Saipackage/service/Server.php';
    $environment['SAND_TEST_PHP'] = PHP_BINARY;
    $environment['SAND_FIXTURE_CLI'] = $root . '/reload.php';
    echo 'Own fixture: ' . $server . '; listener=' . $listen . PHP_EOL;
    $process = proc_open([PHP_BINARY, $server . '/start.php', 'start'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/master.log', 'a'], 2 => ['file', $root . '/master.log', 'a']], $pipes, $server, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch own master.');
    $before = null;
    for ($i = 0; $i < 60; $i++) {
        try { $before = restartFacts($url); break; } catch (RuntimeException) { usleep(100000); }
    }
    if ($before === null) throw new RuntimeException((string)file_get_contents($root . '/master.log'));
    $pidFile = $server . '/runtime/webman.pid';
    $master = SandSystemUpdateRuntime::unixHostMaster($server, $pidFile);
    echo 'Own master PID=' . $master['pid'] . '; worker PID=' . $before['pid'] . PHP_EOL;
    checkRestart($master['pid'] !== $before['pid'], 'PID file resolves the real own Workerman master');
    rejectsRestart(fn () => SandSystemUpdateRuntime::unixHostMaster($server, $pidFile, getmypid()), 'HTTP parent constraint remains enforced');
    file_put_contents($root . '/stale.pid', '99999999');
    rejectsRestart(fn () => SandSystemUpdateRuntime::unixHostMaster($server, $root . '/stale.pid'), 'stale PID is rejected');
    rejectsRestart(fn () => SandSystemUpdateRuntime::unixHostMaster($server, $root . '/absent.pid'), 'missing PID file is rejected');
    file_put_contents($root . '/caller.pid', (string)getmypid());
    rejectsRestart(fn () => SandSystemUpdateRuntime::unixHostMaster($server, $root . '/caller.pid'), 'a live caller is not accepted as master');
    mkdir($root . '/other-server');
    file_put_contents($root . '/other-server/start.php', '<?php');
    rejectsRestart(fn () => SandSystemUpdateRuntime::unixHostMaster($root . '/other-server', $pidFile), 'another host cannot claim this master');
    $changed = $master;
    $changed['identity'] = 'a different process start time ' . $master['identity'];
    rejectsRestart(fn () => SandSystemUpdateRuntime::reloadUnixHost($server, $pidFile, $changed), 'changed process start identity prevents signalling');
    checkRestart(restartFacts($url)['pid'] === $before['pid'], 'rejected identities leave the own HTTP worker untouched');
    file_put_contents($server . '/version', 'after');
    $caller = proc_open([$python, $root . '/caller.py'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $server, $environment, ['bypass_shell' => true]);
    if (!is_resource($caller)) throw new RuntimeException('Cannot launch Python caller.');
    $exit = proc_close($caller);
    echo 'Python supervisor exit=' . $exit . PHP_EOL;
    checkRestart($exit === 0, 'Python caller survives and CLI returns success after verified reload');
    $after = restartFacts($url);
    checkRestart($after['pid'] !== $before['pid'] && $after['version'] === 'after', 'real HTTP worker rotates and loads the new source');
    checkRestart(SandSystemUpdateRuntime::unixHostMaster($server, $pidFile) === $master, 'reload preserves the bound master');
    unlink($pidFile);
    $caller = proc_open([$python, $root . '/caller.py'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $server, $environment, ['bypass_shell' => true]);
    if (!is_resource($caller)) throw new RuntimeException('Cannot launch failure caller.');
    checkRestart(proc_close($caller) === 1, 'missing master produces an explicit nonzero CLI result without killing Python');
    checkRestart(restartFacts($url)['pid'] === $after['pid'], 'missing master never falls back to the caller or another process');
    file_put_contents($pidFile, (string)$master['pid']);
    posix_kill($master['pid'], SIGINT);
    proc_close($process);
    $process = null;
    checkRestart(!posix_kill($master['pid'], 0), 'first own master stopped before the no-reload fixture');
    $master = null;
    $environment['SAND_FIXTURE_NO_RELOAD'] = '1';
    echo 'Starting own non-reloadable fixture at ' . $listen . PHP_EOL;
    $process = proc_open([PHP_BINARY, $server . '/start.php', 'start'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/master.log', 'a'], 2 => ['file', $root . '/master.log', 'a']], $pipes, $server, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch own no-reload master.');
    $held = null;
    for ($i = 0; $i < 60; $i++) {
        try { $held = restartFacts($url); break; } catch (RuntimeException) { usleep(100000); }
    }
    if ($held === null) throw new RuntimeException('Own no-reload HTTP not ready.');
    $master = SandSystemUpdateRuntime::unixHostMaster($server, $pidFile);
    $caller = proc_open([$python, $root . '/caller.py'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $server, $environment, ['bypass_shell' => true]);
    if (!is_resource($caller)) throw new RuntimeException('Cannot launch no-reload caller.');
    checkRestart(proc_close($caller) === 1, 'successful signal delivery without worker rotation returns nonzero');
    checkRestart(restartFacts($url)['pid'] === $held['pid'], 'no-reload failure preserves the existing own worker and Python caller');
} finally {
    if (is_resource($process)) {
        if ($master !== null && posix_kill($master['pid'], 0)) {
            // This PID came only from the own fixture and is revalidated before stop.
            $pidFile = $root . '/server/runtime/webman.pid';
            if (!is_file($pidFile)) file_put_contents($pidFile, (string)$master['pid']);
            if (SandSystemUpdateRuntime::unixHostMaster($root . '/server', $pidFile) === $master) posix_kill($master['pid'], SIGINT);
        }
        proc_close($process);
    }
    if ($master !== null) checkRestart(!posix_kill($master['pid'], 0), 'own master and its workers stopped');
    SandSystemUpdateRuntime::remove($root);
    checkRestart(!file_exists($root), 'own temporary fixture removed');
}
echo 'SandPackage CLI native reload: ' . $count . ' checks passed.' . PHP_EOL;
