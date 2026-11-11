<?php

/**
 * This file is part of sand-package, derived from saithink/saipackage (MIT License).
 * Copyright (c) 2024 saithink.
 */

namespace Saithink\Saipackage\service;

/**
 * Historical online terminal.
 *
 * HTTP execution is removed. This class no longer starts a process.
 * Allowlisted dependency installs run only in a separate CLI process:
 * `php webman sandpackage:dependency run <插件标识> composer|npm`.
 */
class Terminal
{
    public function __construct()
    {
    }

    public static function getCommand(string $key): bool
    {
        return false;
    }

    /**
     * @throws \RuntimeException always; the HTTP terminal cannot execute commands
     */
    public function exec($auth = true): void
    {
        throw new \RuntimeException('HTTP terminal execution is disabled');
    }

    public static function getOutputFromProc($commandKey): bool
    {
        return false;
    }
}
