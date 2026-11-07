<?php

declare(strict_types=1);

/**
 * Detached official full restart for plugin process registration.
 * Usage: php full-restart-worker.php <payload.json>
 * Payload: server, pid_file, master, expected[], php, registry?, clear_pending?
 *
 * Never patches workerman/webman. On success may clear process_registration_pending
 * in the plugin info.ini registry path when provided.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

if (!function_exists('posix_setsid') || !function_exists('pcntl_fork')) {
    fwrite(STDERR, "posix/pcntl required\n");
    exit(2);
}

$payloadFile = $argv[1] ?? '';
if ($payloadFile === '' || !is_file($payloadFile)) {
    fwrite(STDERR, "payload missing\n");
    exit(2);
}

$pid = pcntl_fork();
if ($pid < 0) {
    exit(2);
}
if ($pid > 0) {
    // Parent of the double-fork launcher exits immediately so InstallLogic can continue.
    exit(0);
}
if (posix_setsid() < 0) {
    exit(2);
}
$pid = pcntl_fork();
if ($pid < 0) {
    exit(2);
}
if ($pid > 0) {
    exit(0);
}

usleep(500000);

try {
    $raw = file_get_contents($payloadFile);
    if (!is_string($raw)) {
        throw new RuntimeException('payload unreadable');
    }
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    @unlink($payloadFile);
    require_once __DIR__ . '/system-update-worker.php';
    $server = $payload['server'];
    $pidFile = $payload['pid_file'];
    $master = $payload['master'];
    $php = $payload['php'] ?? PHP_BINARY;
    $expected = $payload['expected'] ?? [];
    $next = SandSystemUpdateRuntime::fullRestartUnixHost($server, $pidFile, $master, $php);
    if (is_array($expected) && $expected !== []) {
        SandSystemUpdateRuntime::assertUnixCustomProcessesRunning($server, $next, $expected);
    }
    $registry = $payload['registry'] ?? null;
    if (is_string($registry) && is_file($registry) && ($payload['clear_pending'] ?? false) === true) {
        clearProcessPending($registry);
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

function clearProcessPending(string $registry): void
{
    $info = parse_ini_file($registry, true, INI_SCANNER_TYPED);
    if (!is_array($info)) {
        throw new RuntimeException('registry unreadable');
    }
    $info['process_registration_pending'] = 0;
    $info['dependency_restart_pending'] = 0;
    $info['dependency_restart_status'] = 'completed';
    $lines = [];
    foreach ($info as $key => $val) {
        if (is_array($val)) {
            $lines[] = "[$key]";
            foreach ($val as $ikey => $ival) {
                $lines[] = "$ikey = $ival";
            }
        } else {
            $lines[] = "$key = $val";
        }
    }
    $body = implode("\n", $lines) . "\n";
    $temporary = $registry . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('registry temp failed');
    }
    try {
        if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('registry write failed');
        }
        if (!rename($temporary, $registry)) {
            throw new RuntimeException('registry replace failed');
        }
    } finally {
        fclose($handle);
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
