<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['autoload']['psr-4']['SandAdmin\\Package\\'] ?? null) !== 'server/'
    || ($composer['autoload']['psr-4']['Saithink\\Saipackage\\'] ?? null) !== 'server/compat/Saithink/Saipackage/'
) {
    throw new RuntimeException('Sand Package namespace mappings are incomplete');
}

require $root . '/server/Install.php';
if (!defined(\SandAdmin\Package\Install::class . '::WEBMAN_PLUGIN')) {
    throw new RuntimeException('Sand Package Webman plugin marker is missing');
}

spl_autoload_register(static function (string $class) use ($root): void {
    $mappings = [
        'plugin\\sandpackage\\' => $root . '/server/plugin/sandpackage/',
        'Saithink\\Saipackage\\' => $root . '/server/compat/Saithink/Saipackage/',
    ];
    foreach ($mappings as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

if (!class_exists(\Saithink\Saipackage\service\Version::class)
    || !class_exists(\plugin\sandpackage\app\service\PluginStorage::class)
) {
    throw new RuntimeException('Sand Package runtime namespaces are not loadable');
}
foreach (['route.php', 'process.php', 'autoload.php'] as $config) {
    if (!is_file($root . '/server/plugin/sandpackage/config/' . $config)) {
        throw new RuntimeException("Sand Package config payload is missing: {$config}");
    }
}

fwrite(STDOUT, "sand-package package probe passed\n");
