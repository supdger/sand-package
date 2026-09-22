<?php

declare(strict_types=1);

namespace Tinywan\Jwt {
    final class JwtToken
    {
        public static array $claims = [];
        public static function verify(int $scene, string $token): array { return self::$claims; }
    }
}

namespace {
    $terminalRunnerRoot = sys_get_temp_dir() . '/sandpackage-terminal-runner-' . bin2hex(random_bytes(6));
    $terminalRunnerServer = $terminalRunnerRoot . '/server';
    $terminalRunnerFrontend = $terminalRunnerRoot . '/sandadmin-artd';
    mkdir($terminalRunnerServer, 0755, true);
    mkdir($terminalRunnerFrontend, 0755, true);

    function base_path(): string
    {
        global $terminalRunnerServer;
        return $terminalRunnerServer;
    }

    function env(string $key, mixed $default = null): mixed
    {
        return $key === 'FRONTEND_DIR' ? 'sandadmin-artd' : $default;
    }

    final class TerminalRunnerRequest
    {
        public function __construct(private string $authorization) {}
        public function header(string $name, string $default = ''): string { return $name === 'authorization' ? $this->authorization : $default; }
    }

    $terminalRunnerRequest = new TerminalRunnerRequest('');
    function request(): TerminalRunnerRequest
    {
        global $terminalRunnerRequest;
        return $terminalRunnerRequest;
    }

    function terminalRunnerAssert(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    function config(string $key, mixed $default = null): mixed
    {
        if ($key !== 'plugin.sandpackage.terminal.commands') return $default;
        if (isset($GLOBALS['terminalRunnerCommands'])) return $GLOBALS['terminalRunnerCommands'];
        return [
            'web-install' => [
                'npm' => ['cwd' => $GLOBALS['terminalRunnerFrontend'], 'command' => 'npm install'],
                'pnpm' => ['cwd' => $GLOBALS['terminalRunnerFrontend'], 'command' => 'pnpm install'],
            ],
            'composer' => [
                'update' => ['cwd' => $GLOBALS['terminalRunnerServer'], 'command' => 'composer update --no-interaction'],
            ],
        ];
    }

    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/TerminalRunner.php';

    use plugin\sandpackage\app\service\TerminalRunner;
    use Tinywan\Jwt\JwtToken;

    $web = TerminalRunner::allowedCommand('web-install.pnpm');
    terminalRunnerAssert($web === ['argv' => ['pnpm', 'install'], 'cwd' => $terminalRunnerFrontend, 'callback' => 'npm'], 'configured web-install command was not converted to fixed argv');
    terminalRunnerAssert(TerminalRunner::allowedCommand('web-install.pnpm; id') === null, 'command-key injection was accepted');
    terminalRunnerAssert(TerminalRunner::allowedCommand('version.npm') === null, 'non-dependency command was accepted');
    terminalRunnerAssert(TerminalRunner::allowedCommand('composer.update') === ['argv' => ['composer', 'update', '--no-interaction'], 'cwd' => $terminalRunnerServer, 'callback' => 'composer'], 'composer command is not fixed to the declared argv');
    $GLOBALS['terminalRunnerCommands'] = [
        'web-install' => ['pnpm' => ['cwd' => $terminalRunnerFrontend . '/../sandadmin-artd', 'command' => 'pnpm install']],
    ];
    terminalRunnerAssert(TerminalRunner::allowedCommand('web-install.pnpm') === null, 'working directory traversal was accepted');
    $link = $terminalRunnerRoot . '/frontend-link';
    symlink($terminalRunnerFrontend, $link);
    $GLOBALS['terminalRunnerCommands'] = [
        'web-install' => ['pnpm' => ['cwd' => $link, 'command' => 'pnpm install']],
    ];
    terminalRunnerAssert(TerminalRunner::allowedCommand('web-install.pnpm') === null, 'working-directory symlink was accepted');
    unset($GLOBALS['terminalRunnerCommands']);

    $isSuperAdmin = (new ReflectionClass(TerminalRunner::class))->getMethod('isSuperAdmin');
    $isSuperAdmin->setAccessible(true);
    $GLOBALS['terminalRunnerRequest'] = new TerminalRunnerRequest('Bearer contract-token');
    JwtToken::$claims = ['extend' => ['id' => 1, 'plat' => 'other']];
    terminalRunnerAssert($isSuperAdmin->invoke(new TerminalRunner()) === false, 'non-SandAdmin token was accepted');
    JwtToken::$claims = ['extend' => ['id' => 2, 'plat' => 'sandadmin']];
    terminalRunnerAssert($isSuperAdmin->invoke(new TerminalRunner()) === false, 'non-administrator token was accepted');
    JwtToken::$claims = ['extend' => ['id' => 1, 'plat' => 'sandadmin']];
    terminalRunnerAssert($isSuperAdmin->invoke(new TerminalRunner()) === true, 'SandAdmin administrator token was rejected');

    $output = TerminalRunner::safeOutput("Bearer private-value token=private-value uuid: 123e4567-e89b-12d3-a456-426614174000 {\"secret\":\"private\"} /Users/test/secret\n\033[31mhello\033[0m");
    terminalRunnerAssert(!str_contains($output, 'private-value') && !str_contains($output, '123e4567') && !str_contains($output, '/Users/test/secret') && str_contains($output, 'hello'), 'terminal output was not redacted safely');
    $authorizationOutput = TerminalRunner::safeOutput("Authorization: Basic dXNlcjpwYXNz\nauthorization=ApiKey top-secret\n{'authorization':'custom private'}");
    terminalRunnerAssert(!str_contains($authorizationOutput, 'dXNlcjpwYXNz') && !str_contains($authorizationOutput, 'top-secret') && !str_contains($authorizationOutput, 'custom private'), 'authorization values were not fully redacted');
    terminalRunnerAssert(mb_strlen(TerminalRunner::safeOutput(str_repeat('x', 5000)), 'UTF-8') === 5000, 'chunk redaction must not pretend to be the session output quota');

    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/TerminalRunner.php');
    terminalRunnerAssert(str_contains($source, "proc_open(\$this->groupLauncherCommand(\$command['argv'])")
        && str_contains($source, "return array_merge([PHP_BINARY, '-r', self::CHILD_LAUNCHER, '--'], \$argv);"), 'terminal runner is not using the fixed argv process-group launcher');
    terminalRunnerAssert(substr_count($source, 'shell_exec(') === 1
        && str_contains($source, "shell_exec('/bin/ps -axo pid=,pgid= 2>/dev/null')")
        && !str_contains($source, 'system(') && !str_contains($source, 'passthru('), 'terminal runner contains an unbounded shell execution API');
    terminalRunnerAssert(str_contains($source, 'beginDependencyCommand') && str_contains($source, 'dependencyCommandFailed') && str_contains($source, 'releaseDependencyCommand'), 'terminal runner does not lock and compensate dependency tasks');
    terminalRunnerAssert(str_contains($source, "request()->header('authorization'") && str_contains($source, "'plat'"), 'terminal runner does not require the SandAdmin administrator claim');
    terminalRunnerAssert(!str_contains($source, "input('token'") && str_contains($source, 'MAX_OUTPUT_BYTES'), 'terminal runner still accepts token URLs or lacks an output quota');
    $terminalView = (string) file_get_contents(dirname(__DIR__, 3) . '/sandadmin-artd/src/views/plugin/sandpackage/install/terminal.vue');
    terminalRunnerAssert(!str_contains($terminalView, 'v-html') && str_contains($terminalView, 'v-text="msg"'), 'terminal output is not rendered as escaped text');
    echo "SandPackage terminal runner contract passed\n";
}
