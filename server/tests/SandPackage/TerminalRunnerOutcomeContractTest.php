<?php

declare(strict_types=1);

namespace Saithink\Saipackage\service {
    final class Server {}
}

namespace Tinywan\Jwt {
    final class JwtToken
    {
        public static function verify(int $scene, string $token): array { return ['extend' => ['id' => 1, 'plat' => 'sandadmin']]; }
    }
}

namespace plugin\sandpackage\app\logic {
    final class InstallLogic
    {
        public static int $failed = 0;
        public static int $completed = 0;
        public function __construct(string $app) {}
        public function beginDependencyCommand(string $type): string { return 'contract-nonce'; }
        public function acquireDependencyExecutionLock(string $type, string $nonce): void {}
        public function recordDependencyProcessStarted(string $type, string $nonce, int $pid, int $pgid, array $descendants, int $startedAt): void {}
        public function updateDependencyProcessJournal(string $type, string $nonce, array $descendants, string $event, ?int $failedAt = null): void {}
        public function confirmDependencyProcessReaped(string $type, string $nonce): void {}
        public function dependentInstallComplete(string $type, ?string $nonce = null, bool $restart = false): array { self::$completed++; return ['advanced' => true, 'completed' => true]; }
        public function dependencyCommandFailed(string $type, ?string $nonce = null): bool { self::$failed++; return true; }
        public function releaseDependencyExecutionLock(): void {}
        public function releaseDependencyCommand(): void {}
    }
}

namespace plugin\sandpackage\app\service {
    function proc_open(array $command, array $descriptor, array &$pipes, string $cwd, array $environment): mixed
    {
        $pipes = [
            1 => fopen('php://temp', 'w+'),
            2 => fopen('php://temp', 'w+'),
            3 => fopen('php://temp', 'w+'),
            4 => fopen('php://temp', 'w+'),
        ];
        fwrite($pipes[4], "{\"event\":\"ready\",\"launcher_pid\":1234,\"pgid\":1234,\"descendant_pids\":[],\"time\":1}\n");
        rewind($pipes[4]);
        return fopen('php://temp', 'w+');
    }
    function proc_get_status(mixed $process): array { return ['running' => false, 'exitcode' => 0, 'pid' => 1234]; }
    function proc_close(mixed $process): int { return 0; }
    function proc_terminate(mixed $process): bool { return true; }
    function posix_getpgid(int $pid): int|false { return $pid; }
    function posix_kill(int $pid, int $signal): bool { return $signal !== 0; }
}

namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\service\TerminalRunner;

    $outcomeRoot = sys_get_temp_dir() . '/sandpackage-terminal-outcome-' . bin2hex(random_bytes(6));
    $outcomeServer = $outcomeRoot . '/server';
    $outcomeFrontend = $outcomeRoot . '/sandadmin-artd';
    mkdir($outcomeServer, 0755, true);
    mkdir($outcomeFrontend, 0755, true);

    function base_path(): string { global $outcomeServer; return $outcomeServer; }
    function env(string $key, mixed $default = null): mixed { return $key === 'FRONTEND_DIR' ? 'sandadmin-artd' : $default; }
    function config(string $key, mixed $default = null): mixed {
        global $outcomeFrontend, $outcomeServer;
        return $key === 'plugin.sandpackage.terminal.commands' ? [
            'web-install' => ['npm' => ['cwd' => $outcomeFrontend, 'command' => 'npm install']],
            'composer' => ['update' => ['cwd' => $outcomeServer, 'command' => 'composer update --no-interaction']],
        ] : $default;
    }
    final class OutcomeRequest
    {
        public object $connection;
        public function __construct() { $this->connection = (object) []; }
        public function input(string $key, string $default = ''): string { return ['command' => 'web-install.npm', 'extend' => 'module-install:outcome-plugin'][$key] ?? $default; }
        public function header(string $key, string $default = ''): string { return $key === 'authorization' ? 'Bearer contract' : $default; }
    }
    $outcomeRequest = new OutcomeRequest();
    function request(): OutcomeRequest { global $outcomeRequest; return $outcomeRequest; }
    function outcomeAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/TerminalRunner.php';

    $completeRunner = new TerminalRunner();
    foreach ($completeRunner->exec() as $_) {}
    outcomeAssert($completeRunner->isTerminalCompleted(), 'fully consumed generator did not report terminal completion');
    $completeRunner->abort();
    outcomeAssert(InstallLogic::$completed === 1 && InstallLogic::$failed === 0, 'successful complete consumption triggered compensation');

    $interruptedRunner = new TerminalRunner();
    $generator = $interruptedRunner->exec();
    $generator->current();
    unset($generator);
    gc_collect_cycles();
    outcomeAssert(InstallLogic::$failed === 1, 'destroying an unfinished generator did not compensate the dependency task');
    echo "SandPackage terminal runner outcome contract passed\n";
}
