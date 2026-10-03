<?php

declare(strict_types=1);

// Copy to the host server/config/sand_system_update.php and set actual absolute paths.
// PHP CLI must have proc_open, pcntl and posix on Linux/macOS. This file is local trusted configuration.
// Every command is an argv list passed directly to proc_open; shell strings, && and pipes are unsupported.
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
