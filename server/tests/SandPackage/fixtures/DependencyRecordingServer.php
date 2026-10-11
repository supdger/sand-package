<?php

namespace Saithink\Saipackage\service;

/** The inherited methods are loaded from the current public compatibility Server. */
final class Server extends RecordingServerBase
{
    public static bool $failRestart = false;
    public static function restart(): bool
    {
        $info = (new \plugin\sandpackage\app\logic\InstallLogic('neutral-host'))->getInfo();
        if (($info['state'] ?? null) !== 1 || isset($info['dependency_command_nonce'])
            || isset($info['composer_dependent_wait_install']) || isset($info['npm_dependent_wait_install'])) {
            throw new \RuntimeException('Reload requested before the successful dependency registration');
        }
        file_put_contents(base_path('restart-called'), "requested\n", FILE_APPEND);
        return !self::$failRestart;
    }
}
