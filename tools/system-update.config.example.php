<?php

declare(strict_types=1);

// Copy to the host server/config/sand_system_update.php and set actual absolute paths.
// Linux/macOS: PHP CLI must have proc_open, pcntl and posix.
// Windows: Windows 8 / Server 2012 or later, Windows PowerShell 5.1 and PHP CLI 8.2+ with proc_open.
// The worker locates Windows PowerShell through SystemRoot; there is no powershell configuration key.
// Windows does not need pcntl/posix. Use local drive paths; UNC/device paths and reparse points are rejected.
// This file is local trusted configuration. Replace every example path with an existing absolute path.
// Every command is an argv list passed directly to proc_open; shell strings, && and pipes are unsupported.
if (PHP_OS_FAMILY === 'Windows') {
    return [
        'php' => 'C:/tools/php/php.exe',
        'frontend' => 'D:/sandadmin/sandadmin-artd',
        // Dedicated static directory; deploy the current dist and establish its baseline first.
        'static' => 'D:/sandadmin/server/public/admin',
        // Windows commands must start with a native .exe. Do not use composer.bat or pnpm.cmd.
        // Locate the actual Composer PHAR and pnpm CLI installed on this host.
        'composer' => ['C:/tools/php/php.exe', 'C:/tools/composer/composer.phar'],
        'pnpm' => ['C:/Program Files/nodejs/node.exe', 'C:/tools/pnpm/bin/pnpm.cjs'],
        // These administrator-maintained scripts must already exist and operate this host's real service.
        // reload: return nonzero on failure; health: verify actual HTTP service and expected version.
        'reload' => ['C:/tools/php/php.exe', 'D:/sandadmin/ops/reload.php'],
        'health' => ['C:/tools/php/php.exe', 'D:/sandadmin/ops/health.php'],
        // For existing PowerShell scripts, use a fixed native executable argv instead:
        // 'reload' => [getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe',
        //     '-NoLogo', '-NoProfile', '-NonInteractive', '-File', 'D:/sandadmin/ops/reload.ps1'],
        // Use the same form for health.ps1; it must exit nonzero when the check fails.
    ];
}

return [
    'php' => '/usr/bin/php',
    'frontend' => '/srv/sandadmin/sandadmin-artd',
    // Dedicated directory only; it must not overlap frontend or host app/config/vendor/plugin/runtime.
    // Web server must be able to read this directory and its files after update.
    // Establish its baseline with prepare-system-update.php first.
    'static' => '/srv/sandadmin/server/public/admin',
    'composer' => ['/usr/local/bin/composer'],
    'pnpm' => ['/usr/local/bin/pnpm'],
    // Administrator-maintained script must reload this host's real service and return nonzero on failure.
    'reload' => ['/usr/bin/php', '/srv/sandadmin/ops/reload.php'],
    // Must verify the actual running HTTP service and expected version; nonzero rejects the update.
    'health' => ['/usr/bin/php', '/srv/sandadmin/ops/health.php'],
];
