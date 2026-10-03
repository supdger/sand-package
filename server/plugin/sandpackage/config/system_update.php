<?php
/** Host-local deployment policy survives package upgrades in server/config. */
return array_replace([
    'php' => PHP_BINARY,
    'frontend' => dirname(base_path()) . '/sandadmin-artd',
    'composer' => ['composer'],
    'pnpm' => ['pnpm'],
    // Dedicated admin static directory, reload and health commands must be configured once.
    'static' => '',
    'reload' => [],
    'health' => [],
], config('sand_system_update', []));
