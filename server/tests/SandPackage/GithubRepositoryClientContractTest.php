<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\sandadmin\exception\ApiException;
use plugin\sandpackage\app\service\GithubRepositoryClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function githubTransportExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
}

function githubTransportRejects(string $url, string $message): void
{
    $calls = 0;
    $body = 'not-called';
    $error = null;
    (new GithubRepositoryClient())->get(
        $url,
        1024,
        static function (?string $result, ?\Throwable $failure) use (&$calls, &$body, &$error): void {
            $calls++;
            $body = $result;
            $error = $failure;
        },
    );
    githubTransportExpect(
        $calls === 1 && $body === null && $error instanceof ApiException,
        $message,
    );
}

githubTransportRejects('http://raw.githubusercontent.com/org/repo/main/catalog.json', 'rejects non-HTTPS catalogue URLs exactly once');
githubTransportRejects('https://token@raw.githubusercontent.com/org/repo/main/catalog.json', 'rejects URL credentials exactly once');
githubTransportRejects('https://raw.githubusercontent.com:444/org/repo/main/catalog.json', 'rejects non-standard HTTPS ports exactly once');
githubTransportRejects('https://raw.githubusercontent.com/org/repo/main/catalog.json?token=user-input', 'rejects user-supplied query tokens exactly once');
githubTransportRejects('https://example.com/org/repo/releases/download/v1/plugin.zip', 'rejects non-GitHub initial hosts exactly once');

$calls = 0;
(new GithubRepositoryClient())->get(
    'https://github.com/org/repo/releases/download/v1/plugin.zip',
    0,
    static function (?string $body, ?\Throwable $error) use (&$calls): void {
        $calls++;
        githubTransportExpect($body === null && $error instanceof ApiException, 'rejects a missing body limit with ApiException');
    },
);
githubTransportExpect($calls === 1, 'completes invalid size requests exactly once');

$limitedClient = new GithubRepositoryClient();
$limitedClientReflection = new ReflectionClass($limitedClient);
$pendingProperty = $limitedClientReflection->getProperty('pending');
$pending = $pendingProperty->getValue($limitedClient);
for ($index = 0; $index < 8; $index++) {
    $pending->enqueue(new \plugin\sandpackage\app\service\GithubRepositoryRequest(
        'https://github.com/org/repo/releases/download/v1/plugin.zip',
        1024,
        static function (?string $body, ?\Throwable $error): void {
        },
        microtime(true) + 60,
    ));
}
$limitedCalls = 0;
$limitedClient->get(
    'https://github.com/org/repo/releases/download/v1/plugin.zip',
    1024,
    static function (?string $body, ?\Throwable $error) use (&$limitedCalls): void {
        $limitedCalls++;
        githubTransportExpect(
            $body === null
                && $error instanceof ApiException
                && $error->getMessage() === 'GitHub 下载请求过多，请稍后重试',
            'rejects requests beyond the per-Worker total budget',
        );
    },
);
githubTransportExpect($limitedCalls === 1, 'completes an over-capacity request exactly once');
$limitedClient->close();

githubTransportExpect(
    GithubRepositoryClient::shared() === GithubRepositoryClient::shared(),
    'uses one production scheduler and concurrency budget per Worker',
);
GithubRepositoryClient::shared()->close();

$source = file_get_contents(dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/GithubRepositoryClient.php');
githubTransportExpect(is_string($source), 'loads the transport source for security contract checks');
foreach ([
    'CURLOPT_FOLLOWLOCATION => false' => 'manual redirect handling stays enabled',
    'CURLOPT_SSL_VERIFYPEER => true' => 'TLS peer verification stays enabled',
    'CURLOPT_SSL_VERIFYHOST => 2' => 'TLS hostname verification stays enabled',
    'private const MAX_REDIRECTS = 3' => 'redirects stay bounded to three',
    'private const MAX_CONCURRENCY = 2' => 'per-process concurrency stays bounded to two',
    'private const MAX_TOTAL_REQUESTS = 8' => 'active and pending requests stay bounded to eight',
    'CURL_VERSION_ASYNCHDNS' => 'asynchronous DNS capability remains mandatory',
] as $needle => $message) {
    githubTransportExpect(str_contains($source, $needle), $message);
}
githubTransportExpect(!str_contains($source, 'Authorization:'), 'does not add an authorization token');

echo "SandPackage GitHub repository transport contract passed\n";
