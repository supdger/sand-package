<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use support\Log;
use Throwable;

/**
 * Structured failure diagnostics for the PostgreSQL lifecycle driver.
 *
 * The driver previously reported failures through error_log() only. Under
 * daemon mode that stream is not delivered to the Webman log channel, so the
 * real SQL cause of a failed install, attach or uninstall was unrecoverable
 * from the host logs. Records go to the plugin's own rotating channel when it
 * is registered and otherwise to the host default channel; the public API
 * response keeps carrying nothing but the diagnostic id.
 */
final class LifecycleFailureLog
{
    private const CHANNEL = 'plugin.sandpackage.default';

    public static function newDiagnosticId(): string
    {
        return 'SP-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    }

    public static function failureCode(string $stage): string
    {
        return match ($stage) {
            'database_install' => 'SANDPACKAGE_DATABASE_INSTALL_FAILED',
            'database_uninstall' => 'SANDPACKAGE_DATABASE_UNINSTALL_FAILED',
            'database_update' => 'SANDPACKAGE_DATABASE_UPDATE_FAILED',
            'existing_schema_attach' => 'SANDPACKAGE_EXISTING_SCHEMA_ATTACH_FAILED',
            'existing_schema_attach_recovery' => 'SANDPACKAGE_EXISTING_SCHEMA_ATTACH_RECOVERY_FAILED',
            'recovery_checkpoint' => 'SANDPACKAGE_RECOVERY_CHECKPOINT_UNAVAILABLE',
            default => 'SANDPACKAGE_OPERATION_FAILED',
        };
    }

    /**
     * @param array<string,scalar|null> $context Extra non-sensitive identifiers.
     */
    public static function record(
        string $diagnosticId,
        string $stage,
        string $app,
        Throwable $error,
        array $context = [],
    ): void {
        try {
            Log::channel(self::channel())->error('SandPackage lifecycle failed', $context + [
                'diagnostic_id' => $diagnosticId,
                'app' => $app,
                'stage' => $stage,
                'failure_code' => self::failureCode($stage),
                'lifecycle_driver' => 'saipackage-pg-v1',
            ] + self::describe($error, 'exception') + self::previous($error));
        } catch (Throwable) {
            // Diagnostics must never replace the original lifecycle failure.
            try {
                error_log('SandPackage lifecycle failed ' . $diagnosticId . ' ' . $stage . ' ' . $app
                    . ' ' . $error::class . '(' . $error->getCode() . '): ' . $error->getMessage());
            } catch (Throwable) {
                // Nothing further can be reported from this failure path.
            }
        }
    }

    /** @return array<string,string|int|null> */
    private static function describe(Throwable $error, string $prefix): array
    {
        $code = (string) $error->getCode();
        return [
            $prefix . '_class' => $error::class,
            $prefix . '_code' => $code,
            // PDO/think-orm report the authoritative SQL cause through SQLSTATE.
            $prefix . '_sqlstate' => preg_match('/^[0-9A-Z]{5}$/D', strtoupper($code)) === 1 ? strtoupper($code) : null,
            $prefix . '_message' => $error->getMessage(),
            $prefix . '_file' => $error->getFile(),
            $prefix . '_line' => $error->getLine(),
        ];
    }

    /** @return array<string,string|int|null> */
    private static function previous(Throwable $error): array
    {
        $previous = $error->getPrevious();
        return $previous === null ? [] : self::describe($previous, 'previous');
    }

    /** Prefer the plugin's rotating channel; fall back to the host default. */
    private static function channel(): string
    {
        return is_array(config('log', [])[self::CHANNEL] ?? null) ? self::CHANNEL : 'default';
    }
}
