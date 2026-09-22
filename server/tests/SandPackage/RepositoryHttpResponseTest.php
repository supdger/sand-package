<?php

declare(strict_types=1);

// Public controller + real HTTP response encoding; deterministic timer/transport,
// no listener, authentication store, database or external network.
namespace Workerman {
    final class Timer {
        public static array $tasks = [];
        public static function add(float $interval, callable $callback, array $args = [], bool $persistent = true): int {
            self::$tasks[] = fn() => $callback(...$args);
            return count(self::$tasks);
        }
    }
}
namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/RepositoryClient.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/RepositoryLogic.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/controller/InstallController.php';
    $repositoryHttpRoot = sys_get_temp_dir() . '/repository-http-' . bin2hex(random_bytes(6));
    mkdir($repositoryHttpRoot . '/runtime/sandpackage', 0755, true);
    mkdir($repositoryHttpRoot . '/server/plugin', 0755, true);
    function runtime_path(string $path = ''): string {
        global $repositoryHttpRoot;
        return $repositoryHttpRoot . '/runtime' . ($path === '' ? '' : '/' . $path);
    }
    function base_path(string $path = ''): string {
        global $repositoryHttpRoot;
        return $repositoryHttpRoot . '/server' . ($path === '' ? '' : '/' . $path);
    }
    function config(string $key, mixed $default = null): mixed {
        return [
            'plugin.sandadmin.app.version' => '6.0.11',
            'plugin.sandpackage.app.version' => '0.1.0',
        ][$key] ?? $default;
    }
    function json(mixed $data, int $options = 0): \support\Response {
        return new \support\Response(200, ['Content-Type' => 'application/json'], json_encode($data, $options | JSON_THROW_ON_ERROR));
    }
}
namespace plugin\sandpackage\app\service {
    final class GithubRepositoryClient implements RepositoryClient {
        public static bool $fail = false;
        public static int $calls = 0;
        public static string $catalog = '{"schema":1,"plugins":[]}';
        public static string $zip = '';
        public static function shared(): self { return new self(); }
        public function get(string $url, int $maxBytes, callable $complete): void {
            self::$calls++;
            $complete(
                self::$fail ? null : (str_contains($url, 'raw.githubusercontent.com') ? self::$catalog : self::$zip),
                self::$fail ? new \plugin\sandadmin\exception\ApiException('仓库读取失败') : null,
            );
        }
    }
}
namespace {
    final class FixtureController extends \plugin\sandpackage\app\controller\InstallController {
        public static int $fixtureAdmin = 1;
        protected function init(): void { $this->adminId = self::$fixtureAdmin; }
    }
    final class RecordingConnection extends \Workerman\Connection\TcpConnection {
        public array $sent = [];
        public bool $closed = false;
        public int $fixtureStatus = self::STATUS_ESTABLISHED;
        public function __construct() {}
        public function __destruct() {}
        public function close(mixed $data = null, bool $raw = false): void { if ($data !== null) $this->send($data, $raw); $this->closed = true; }
        public function getStatus(bool $rawOutput = true): int|string { return $this->fixtureStatus; }
        public function send(mixed $sendBuffer, bool $raw = false): bool|null { $this->sent[] = (string) $sendBuffer; return true; }
    }
    function check(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
        echo "[PASS] $message\n";
    }
    function requestFixture(): \support\Request {
        $request = new \support\Request("GET /tool/install/repository/catalog HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->connection = new RecordingConnection();
        return $request;
    }
    FixtureController::$fixtureAdmin = 2;
    try { new FixtureController(); throw new RuntimeException('non-admin accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getMessage() === '仅超级管理员能够操作', 'repository controller retains super-admin guard'); }
    FixtureController::$fixtureAdmin = 1;
    $controller = new FixtureController();
    $request = requestFixture();
    $response = $controller->repositoryCatalog($request);
    check($response instanceof \support\Response && $response->getHeader('Transfer-Encoding') === 'chunked', 'middleware receives a real streaming JSON Response');
    check($request->connection->sent === [] && \plugin\sandpackage\app\service\GithubRepositoryClient::$calls === 0, 'headers returned before network callback can send body');
    $head = (string) $response;
    check(str_contains($head, 'Transfer-Encoding: chunked') && !str_contains($head, 'Content-Length:'), 'headers do not terminate with a premature empty response');
    (array_shift(\Workerman\Timer::$tasks))();
    $chunks = $request->connection->sent;
    check(count($chunks) === 2 && $chunks[1] === "0\r\n\r\n", 'exactly one JSON chunk and terminating chunk sent');
    [$size, $body] = explode("\r\n", $chunks[0], 2);
    $data = json_decode(substr($body, 0, hexdec($size)), true, 32, JSON_THROW_ON_ERROR);
    check($data['code'] === 200 && $data['data']['plugins'] === [], 'browser receives ordinary successful API JSON');
    \plugin\sandpackage\app\service\GithubRepositoryClient::$fail = true;
    $request = requestFixture();
    $controller->repositoryCatalog($request);
    (array_shift(\Workerman\Timer::$tasks))();
    [$size, $body] = explode("\r\n", $request->connection->sent[0], 2);
    $errorData = json_decode(substr($body, 0, hexdec($size)), true, 32, JSON_THROW_ON_ERROR);
    check($errorData['code'] === 400 && $errorData['message'] === '仓库读取失败' && count($request->connection->sent) === 2, 'async errors complete with standard error JSON');
    $closeRequest = new \support\Request("GET /tool/install/repository/catalog HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $closeRequest->connection = new RecordingConnection();
    $closeResponse = $controller->repositoryCatalog($closeRequest);
    check(!$closeRequest->connection->closed && $closeResponse->getHeader('Connection') === 'close', 'close request remains open until async JSON completes');
    (array_shift(\Workerman\Timer::$tasks))();
    check($closeRequest->connection->closed && $closeRequest->connection->sent[1] === "0\r\n\r\n", 'close request closes only after terminating JSON chunk');
    $oldRequest = new \support\Request("GET /tool/install/repository/catalog HTTP/1.0\r\nHost: localhost\r\n\r\n");
    $oldRequest->connection = new RecordingConnection();
    $oldResponse = $controller->repositoryCatalog($oldRequest);
    check($oldResponse->getStatusCode() === 505 && \Workerman\Timer::$tasks === [], 'HTTP 1.0 rejected before async operation with ordinary response');
    $request = requestFixture();
    $controller->repositoryCatalog($request);
    $request->connection->fixtureStatus = RecordingConnection::STATUS_CLOSED;
    $before = \plugin\sandpackage\app\service\GithubRepositoryClient::$calls;
    (array_shift(\Workerman\Timer::$tasks))();
    check($request->connection->sent === [] && \plugin\sandpackage\app\service\GithubRepositoryClient::$calls === $before, 'closed connection before dispatch starts no network operation');
    try { $controller->repositoryDownload(requestFixture()); throw new RuntimeException('missing payload accepted'); }
    catch (\plugin\sandadmin\exception\ApiException) { check(\Workerman\Timer::$tasks === [], 'invalid download selection rejected before dispatch'); }

    \plugin\sandpackage\app\service\GithubRepositoryClient::$fail = false;
    $documentFile = tempnam(sys_get_temp_dir(), 'repository-http-document-');
    $documentZip = new ZipArchive();
    $documentZip->open($documentFile, ZipArchive::OVERWRITE);
    $documentZip->addFromString('info.ini', "app = doc-sample\nversion = 1.0.0\n");
    $documentZip->addFromString('README.md', "# HTTP document\n");
    $documentZip->close();
    $documentBytes = (string) file_get_contents($documentFile);
    unlink($documentFile);
    $documentSha = hash('sha256', $documentBytes);
    \plugin\sandpackage\app\service\GithubRepositoryClient::$zip = $documentBytes;
    \plugin\sandpackage\app\service\GithubRepositoryClient::$catalog = json_encode([
        'schema' => 1,
        'plugins' => [[
            'app' => 'doc-sample', 'title' => 'Doc', 'about' => 'Fixture', 'author' => 'Test',
            'versions' => [[
                'version' => '1.0.0', 'tag' => 'doc-sample-v1.0.0', 'asset' => 'doc-sample-1.0.0.zip',
                'sha256' => $documentSha, 'host_min' => '6.0.0', 'notes' => '',
            ]],
        ]],
    ], JSON_THROW_ON_ERROR);
    $documentRequest = new \support\Request("GET /tool/install/repository/document?app=doc-sample&version=1.0.0&sha256={$documentSha} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $documentRequest->connection = new RecordingConnection();
    $controller->repositoryDocument($documentRequest);
    (array_shift(\Workerman\Timer::$tasks))();
    [$size, $body] = explode("\r\n", $documentRequest->connection->sent[0], 2);
    $documentData = json_decode(substr($body, 0, hexdec($size)), true, 32, JSON_THROW_ON_ERROR);
    check($documentData['data'] === ['app' => 'doc-sample', 'version' => '1.0.0', 'markdown' => "# HTTP document\n"], 'GET document validates identity and returns markdown through async JSON');
    $invalidDocument = new \support\Request("GET /tool/install/repository/document?app=../escape&version=1.0.0&sha256={$documentSha} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $invalidDocument->connection = new RecordingConnection();
    try { $controller->repositoryDocument($invalidDocument); throw new RuntimeException('invalid document selection accepted'); }
    catch (\plugin\sandadmin\exception\ApiException) { check(\Workerman\Timer::$tasks === [], 'document rejects arbitrary app paths before dispatch'); }

    $pendingDirectory = runtime_path('sandpackage/index-pending');
    mkdir($pendingDirectory, 0755, true);
    file_put_contents($pendingDirectory . '/info.ini', "app = \"index-pending\"\nversion = \"1.0.0\"\nstate = 2\nlifecycle_driver = \"saipackage-pg-v1\"\n");
    $indexResponse = $controller->index(requestFixture());
    $indexData = json_decode($indexResponse->rawBody(), true, 32, JSON_THROW_ON_ERROR);
    check($indexData['data']['data'][0]['state'] === 2
        && $indexData['data']['data'][0]['ordinary_actions_blocked'] === false, 'installed-plugin index keeps a healthy uploaded state 2 candidate actionable');
    unlink($pendingDirectory . '/info.ini');
    rmdir($pendingDirectory);
    $orphan = base_path('plugin/orphan-plugin');
    mkdir($orphan);
    file_put_contents($orphan . '/info.ini', "app=\"orphan-plugin\"\nversion=\"1.0.0\"\ntitle=\"Orphan\"\nstate=1\n");
    $beforeFiles = scandir(runtime_path('sandpackage'));
    $inventory = json_decode($controller->index(requestFixture())->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    $rows = $inventory['data']['data'];
    $orphanRow = array_values(array_filter($rows, static fn ($row) => is_array($row) && ($row['app'] ?? '') === 'orphan-plugin'))[0] ?? [];
    check(($orphanRow['state'] ?? null) === 6 && ($orphanRow['ordinary_actions_blocked'] ?? false) === true, 'real controller discovers unregistered runtime plugin and blocks ordinary writes');
    check(($orphanRow['installed_version'] ?? null) === null && scandir(runtime_path('sandpackage')) === $beforeFiles, 'directory metadata never claims installed version or creates registration');
    file_put_contents($orphan . '/info.ini', 'app="wrong-identity"');
    $inventory = json_decode($controller->index(requestFixture())->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    $rows = $inventory['data']['data'];
    $orphanRow = array_values(array_filter($rows, static fn ($row) => is_array($row) && ($row['app'] ?? '') === 'orphan-plugin'))[0] ?? [];
    check(($orphanRow['state'] ?? null) === 99, 'real controller preserves malformed plugin diagnosis');
    unlink($orphan . '/info.ini'); rmdir($orphan);
    rmdir(runtime_path('sandpackage'));
    rmdir(runtime_path());
    rmdir(base_path('plugin'));
    rmdir(base_path());
    rmdir($repositoryHttpRoot);
}
