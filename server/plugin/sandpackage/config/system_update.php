<?php
/** Host-local deployment policy survives package upgrades in server/config. */
return array_replace([
    'php' => PHP_BINARY,
    'frontend' => dirname(base_path()) . '/sandadmin-artd',
    'composer' => ['composer'],
    'pnpm' => ['pnpm'],
    // Empty deployment options use the framework host adapter; overrides are optional.
    'static' => '',
    'reload' => [],
    'health' => [],
], config('sand_system_update', []));
