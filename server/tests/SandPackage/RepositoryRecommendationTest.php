<?php

declare(strict_types=1);

// Pure catalog behavior: no network, database, filesystem lifecycle or installation.
namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace plugin\sandpackage\app\logic {
    final class InstallLogic {
        public const UNINSTALLED = 0, INSTALLED = 1, WAIT_INSTALL = 2;
        public static array $local = ['state' => 0, 'blocked' => false, 'reason' => '', 'installed_version' => null];
        public function __construct(string $app) {}
        public function ordinaryStatus(): array { return self::$local; }
    }
}
namespace {
    use plugin\sandpackage\app\logic\InstallLogic;
    use plugin\sandpackage\app\logic\RepositoryLogic;
    use plugin\sandpackage\app\service\RepositoryClient;
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/RepositoryClient.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/PluginVersion.php';
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/logic/RepositoryLogic.php';
    final class RecommendationFixture implements RepositoryClient {
        public array $releases = [];
        public function get(string $url, int $maxBytes, callable $complete): void {
            $complete(json_encode(['schema' => 1, 'plugins' => [[
                'app' => 'neutral-test', 'title' => 'Neutral', 'about' => 'Fixture', 'author' => 'Fixture',
                'versions' => $this->releases,
            ]]], JSON_THROW_ON_ERROR), null);
        }
    }
    function release(string $version, string $min = '0.1.0', ?string $max = null): array {
        $row = ['version' => $version, 'tag' => 'v' . $version, 'asset' => 'plugin.zip', 'sha256' => str_repeat('a', 64), 'host_min' => $min];
        if ($max !== null) $row['host_max'] = $max;
        return $row;
    }
    function catalog(RecommendationFixture $client, string $host = '0.1.2'): array {
        $result = null;
        (new RepositoryLogic($client, 'example/catalog', 'main', $host))->catalog(
            function (?array $value, ?Throwable $error) use (&$result): void {
                if ($error !== null) throw $error;
                $result = $value;
            }
        );
        return $result['plugins'][0];
    }
    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo '[PASS] ' . $message . PHP_EOL;
    }
    require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/GithubRepositoryClient.php';
    $headers = new ReflectionMethod(\plugin\sandpackage\app\service\GithubRepositoryClient::class, 'requestHeaders');
    check(in_array('Cache-Control: no-cache', $headers->invoke(null, 'https://raw.githubusercontent.com/example/catalog/main/catalog.json'), true), 'mutable catalog requests force HTTP revalidation');
    check($headers->invoke(null, 'https://github.com/example/plugin/releases/download/v1/plugin.zip') === ['Accept: application/octet-stream'], 'release asset caching is unchanged');
    $client = new RecommendationFixture();
    $client->releases = [release('0.7.3'), release('1.0.0', '0.2.0'), release('0.7.10'), release('0.7.6')];
    $plugin = catalog($client);
    check($plugin['recommended_version'] === '0.7.10', 'unordered catalog recommends highest compatible numeric version');
    check(array_column($plugin['versions'], 'version') === ['1.0.0', '0.7.10', '0.7.6', '0.7.3'], 'details retain all versions in descending order');
    InstallLogic::$local['blocked'] = true;
    check(catalog($client)['recommended_version'] === '0.7.10', 'blocked local state does not change compatibility recommendation');
    check(catalog($client)['versions'][1]['action'] === 'manage', 'blocked install still requires management');
    InstallLogic::$local['blocked'] = false;
    InstallLogic::$local['state'] = 1;
    InstallLogic::$local['installed_version'] = '0.7.3';
    check(catalog($client)['versions'][1]['action'] === 'upgrade', 'older installed version offers manual upgrade');
    $client->releases = [release('0.7.6-preview.2'), release('0.7.6-preview.10'), release('0.7.6')];
    check(catalog($client)['recommended_version'] === '0.7.6', 'stable version ranks above previews');
    InstallLogic::$local['installed_version'] = '0.7.6-preview.2';
    check(catalog($client)['versions'][0]['action'] === 'upgrade', 'preview installation can upgrade to the stable version');
    InstallLogic::$local['installed_version'] = '0.7.6';
    check(catalog($client)['versions'][1]['action'] === 'downgrade', 'stable installation cannot downgrade to a preview');
    array_pop($client->releases);
    check(catalog($client)['recommended_version'] === '0.7.6-preview.10', 'preview numeric ordering is supported');
    $client->releases = [release('2.0.0', '0.2.0'), release('1.0.0', '0.1.0', '0.1.1')];
    check(catalog($client)['recommended_version'] === null, 'no compatible version returns explicit null');
    $client->releases = [];
    check(catalog($client)['recommended_version'] === null, 'empty catalog has no recommendation');
}
