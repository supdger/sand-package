<?php

declare(strict_types=1);

/**
 * Behavioral regression for the 0.2.24 full_restart fix:
 * a helper spawned from a process that holds a listening socket must NOT keep
 * the listen fd — after the "master" releases its socket the port rebinds.
 *
 * Run: php tools/assert-fd-isolation.php
 * Uses only an idle 28xxx port; kills only children it spawned itself.
 */

if (PHP_OS_FAMILY === 'Windows') {
    fwrite(STDOUT, "SKIP: POSIX-only assertion\n");
    exit(0);
}
if (!function_exists('proc_open') || !function_exists('posix_kill')) {
    fwrite(STDERR, "FAIL: proc_open/posix required\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/server/plugin/sandpackage/tools/system-update-worker.php';

// Minimal copy_dir stub matching the vendor helper's overwrite semantics so the
// Saithink compat Server can be exercised outside webman.
if (!function_exists('copy_dir')) {
    function copy_dir(string $source, string $dest, bool $overwrite = false): void
    {
        if (is_file($source)) {
            copy($source, $dest);
            return;
        }
        if (!is_dir($dest)) mkdir($dest, 0777, true);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0777, true);
            } elseif ($overwrite || !file_exists($target)) {
                copy($item->getPathname(), $target);
            }
        }
    }
}
require_once $root . '/server/compat/Saithink/Saipackage/service/Server.php';

function assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: $message\n");
}

function pickPort(): int
{
    for ($port = 28100; $port < 28200; $port++) {
        if (lsofLines(['-nP', '-iTCP:' . $port, '-sTCP:LISTEN']) !== []) {
            continue;
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if (is_resource($probe)) {
            fclose($probe);
            return $port;
        }
    }
    fwrite(STDERR, "FAIL: no free 28xxx port\n");
    exit(1);
}

/** Spawn a child, return [proc, pid]; descriptors detached like production. */
function spawnDetached(array $argv): array
{
    $pipes = [];
    $proc = proc_open($argv, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['file', '/dev/null', 'a'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        fwrite(STDERR, "FAIL: spawn failed\n");
        exit(1);
    }
    $status = proc_get_status($proc);
    return [$proc, (int) $status['pid']];
}

/** Run lsof through the fd scrub so the probing shell itself never holds the socket. */
function lsofLines(array $args): array
{
    $argv = SandSystemUpdateRuntime::unixFdScrubCommand(array_merge(['lsof'], $args));
    $pipes = [];
    $proc = proc_open($argv, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'a'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return [];
    }
    $out = (string) stream_get_contents($pipes[1]);
    proc_close($proc);
    $out = trim($out);
    return $out === '' ? [] : (preg_split('/\R/', $out) ?: []);
}

function childHoldsPort(int $pid, int $port): bool
{
    foreach (lsofLines(['-nP', '-p', (string) $pid]) as $line) {
        if (str_contains($line, ':' . $port) && str_contains($line, 'LISTEN')) {
            return true;
        }
    }
    return false;
}

function killTree(int $pid): void
{
    if ($pid > 1) {
        @posix_kill($pid, SIGKILL);
    }
}

$fixture = $root . '/tools/fixtures/fd-scrub-child.php';
$marker = tempnam(sys_get_temp_dir(), 'fdscrub-');
$children = [];
$socket = null;
$port = pickPort();
echo "# test port: $port\n";

try {
    // ---------- case A: unscrubbed spawn reproduces the inherited-fd bug ----------
    $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
    assert_true(is_resource($socket), 'fixture: parent holds listen socket');
    [$procA, $pidA] = spawnDetached([PHP_BINARY, $fixture, '20', $marker]);
    $children[] = $pidA;
    usleep(600000);
    assert_true(childHoldsPort($pidA, $port), 'unscrubbed child inherited the listen fd (bug reproduced)');
    fclose($socket);
    $socket = null;
    $rebind = @stream_socket_server('tcp://127.0.0.1:' . $port, $e2, $s2);
    assert_true($rebind === false, 'port stays bound while unscrubbed child lives (Address already in use)');
    killTree($pidA);
    proc_close($procA);
    $deadline = microtime(true) + 5;
    do {
        $rebind = @stream_socket_server('tcp://127.0.0.1:' . $port, $e2, $s2);
        if (is_resource($rebind)) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    assert_true(is_resource($rebind), 'port released after unscrubbed child exits');
    fclose($rebind);

    // ---------- case B: scrub command prevents inheritance ----------
    @unlink($marker);
    $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
    assert_true(is_resource($socket), 'fixture: parent re-holds listen socket');
    $argv = SandSystemUpdateRuntime::unixFdScrubCommand([PHP_BINARY, $fixture, '20', $marker]);
    [$procB, $pidB] = spawnDetached($argv);
    $children[] = $pidB;
    usleep(600000);
    assert_true(!childHoldsPort($pidB, $port), 'scrubbed child did not inherit the listen fd');
    fclose($socket);
    $socket = null;
    $rebind = @stream_socket_server('tcp://127.0.0.1:' . $port, $e2, $s2);
    assert_true(is_resource($rebind), 'port rebinds while scrubbed child is still alive');
    fclose($rebind);
    killTree($pidB);
    proc_close($procB);
    assert_true(is_file($marker) && trim((string) file_get_contents($marker)) === 'scrubbed', 'scrub marks SAND_FD_SCRUBBED=1');

    // ---------- case C: worker self-scrub (no scrub wrapper, pcntl_exec path) ----------
    // Launch the real full-restart-worker with a dummy payload; it must scrub
    // itself, so the listen fd never reaches the detached grandchild either.
    $dir = sys_get_temp_dir() . '/fr-worker-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    file_put_contents($dir . '/payload.json', json_encode([
        'server' => $dir,
        'pid_file' => $dir . '/missing.pid',
        'master' => ['pid' => 2, 'identity' => 'none'],
        'expected' => [],
        'php' => PHP_BINARY,
        'listeners' => [],
    ]));
    $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
    assert_true(is_resource($socket), 'fixture: parent holds listen socket again');
    [$procC, $pidC] = spawnDetached([
        PHP_BINARY,
        $root . '/server/plugin/sandpackage/tools/full-restart-worker.php',
        $dir . '/payload.json',
    ]);
    $children[] = $pidC;
    // The worker self-scrubs via pcntl_exec (same pid), then double-forks into a
    // detached grandchild. During that window only THIS process may hold the port.
    usleep(800000);
    $myPid = getmypid();
    $holders = lsofLines(['-nP', '-iTCP:' . $port, '-sTCP:LISTEN']);
    $others = array_values(array_filter($holders, static fn (string $line): bool =>
        preg_match('/^\S+\s+(\d+)/', $line, $m) === 1 && (int) $m[1] !== $myPid));
    if ($others !== []) {
        fwrite(STDERR, "unexpected holders:\n" . implode("\n", $holders) . "\n");
    }
    assert_true($others === [], 'full-restart-worker self-scrub: detached worker chain holds no listen fd');
    fclose($socket);
    $socket = null;
    $rebind = @stream_socket_server('tcp://127.0.0.1:' . $port, $e2, $s2);
    assert_true(is_resource($rebind), 'port rebinds after worker self-scrub');
    fclose($rebind);

    // ---------- unit: listener parsing + bounded release wait ----------
    $targets = SandSystemUpdateRuntime::unixTcpListeners([
        'http://0.0.0.0:8787',
        'tcp://127.0.0.1:2206',
        'unix:///tmp/x.sock',
        'websocket://0.0.0.0:8787',
        'not-a-listen',
    ]);
    assert_true(count($targets) === 2, 'unixTcpListeners dedupes same host:port across schemes');
    $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $e2, $s2);
    $start = microtime(true);
    $threw = false;
    try {
        SandSystemUpdateRuntime::waitUnixListenersReleased(['tcp://127.0.0.1:' . $port], 1.0);
    } catch (RuntimeException) {
        $threw = true;
    }
    $elapsed = microtime(true) - $start;
    assert_true($threw && $elapsed < 5, 'waitUnixListenersReleased is bounded while port held');
    fclose($socket);
    $socket = null;
    SandSystemUpdateRuntime::waitUnixListenersReleased(['tcp://127.0.0.1:' . $port], 3.0);
    assert_true(true, 'waitUnixListenersReleased returns once port free');

    // ---------- unit: installByRelation preserves operator .env ----------
    $src = $dir . '/src-pkg';
    $dst = $dir . '/dst-plugin';
    mkdir($src . '/config', 0700, true);
    mkdir($dst . '/config', 0700, true);
    file_put_contents($src . '/.env', "APP_PORT=8787\n");
    file_put_contents($src . '/config/.env.local', "KEEP_DEFAULT=1\n");
    file_put_contents($src . '/config/app.php', "<?php return ['version' => '9.9.9'];\n");
    file_put_contents($src . '/.env.example', "APP_PORT=8787\n");
    file_put_contents($dst . '/.env', "APP_PORT=25879\n");
    file_put_contents($dst . '/config/.env.local', "KEEP_MINE=1\n");
    file_put_contents($dst . '/.env.example', "APP_PORT=0\n");
    \Saithink\Saipackage\service\Server::installByRelation([$src => $dst]);
    assert_true(trim((string) file_get_contents($dst . '/.env')) === 'APP_PORT=25879', 'installByRelation preserves existing .env');
    assert_true(trim((string) file_get_contents($dst . '/config/.env.local')) === 'KEEP_MINE=1', 'installByRelation preserves .env.<name>');
    assert_true(trim((string) file_get_contents($dst . '/.env.example')) === 'APP_PORT=8787', '.env.example stays managed payload');
    assert_true(str_contains((string) file_get_contents($dst . '/config/app.php'), '9.9.9'), 'ordinary payload files still update');
    assert_true(glob($dst . '/*.sandkeep-*') === [] && glob($dst . '/config/*.sandkeep-*') === [], 'no parked env leftovers');

    // ---------- unit: isRuntimeEnvName ----------
    assert_true(\Saithink\Saipackage\service\Server::isRuntimeEnvName('.env'), '.env is runtime env');
    assert_true(\Saithink\Saipackage\service\Server::isRuntimeEnvName('.env.local'), '.env.local is runtime env');
    assert_true(!\Saithink\Saipackage\service\Server::isRuntimeEnvName('.env.example'), '.env.example stays managed');
    assert_true(!\Saithink\Saipackage\service\Server::isRuntimeEnvName('env.php'), 'env.php is not runtime env');

    foreach ($children as $pid) {
        killTree($pid);
    }
    exec('rm -rf ' . escapeshellarg($dir));
    @unlink($marker);

    fwrite(STDOUT, "All fd-isolation assertions passed.\n");
} finally {
    if (is_resource($socket)) {
        fclose($socket);
    }
    foreach ($children as $pid) {
        killTree($pid);
    }
}
