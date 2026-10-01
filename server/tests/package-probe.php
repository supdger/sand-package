<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['autoload']['psr-4']['SandAdmin\\Package\\'] ?? null) !== 'server/'
    || ($composer['autoload']['psr-4']['Saithink\\Saipackage\\'] ?? null) !== 'server/compat/Saithink/Saipackage/'
) {
    throw new RuntimeException('Sand Package namespace mappings are incomplete');
}

$packageAutoload = getenv('SANDPACKAGE_PACKAGE_AUTOLOAD');
if (is_string($packageAutoload) && $packageAutoload !== '') require $packageAutoload;
require_once $root . '/server/Install.php';
if (!defined(\SandAdmin\Package\Install::class . '::WEBMAN_PLUGIN')) {
    throw new RuntimeException('Sand Package Webman plugin marker is missing');
}

if (!is_string($packageAutoload) || $packageAutoload === '') spl_autoload_register(static function (string $class) use ($root, $composer): void {
    $mappings = $composer['autoload']['psr-4'];
    foreach ($mappings as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $root . '/' . $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

if (!class_exists(\Saithink\Saipackage\service\Version::class)
    || !class_exists(\plugin\sandpackage\app\service\PluginStorage::class)
    || !class_exists(\SandAdmin\Package\FrontendPublisher::class)
    || !class_exists(\plugin\sandpackage\app\service\HostPayloadManifest::class)
    || !class_exists(\plugin\sandpackage\app\service\ExistingSchemaManifest::class)
) {
    throw new RuntimeException('Sand Package runtime namespaces are not loadable');
}
if (is_string($packageAutoload) && $packageAutoload !== '') {
    foreach ([\SandAdmin\Package\FrontendPublisher::class,
        \plugin\sandpackage\app\service\HostPayloadManifest::class,
        \plugin\sandpackage\app\service\ExistingSchemaManifest::class] as $class) {
        $source = (new ReflectionClass($class))->getFileName();
        if (!is_string($source) || !str_starts_with((string) realpath($source), (string) realpath($root) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Composer loaded a class outside the candidate package: ' . $class);
        }
    }
}
foreach (['route.php', 'process.php', 'autoload.php'] as $config) {
    if (!is_file($root . '/server/plugin/sandpackage/config/' . $config)) {
        throw new RuntimeException("Sand Package config payload is missing: {$config}");
    }
}

fwrite(STDOUT, "sand-package package probe passed\n");
