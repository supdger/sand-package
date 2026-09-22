<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php build-neutral-package.php /absolute/existing/output-directory\n");
    exit(2);
}

$requestedDirectory = $argv[1];
if (!str_starts_with($requestedDirectory, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Output directory must be an absolute path\n");
    exit(2);
}

$outputDirectory = realpath($requestedDirectory);
if ($outputDirectory === false || !is_dir($outputDirectory)) {
    fwrite(STDERR, "Output directory must already exist\n");
    exit(2);
}
if (!is_writable($outputDirectory)) {
    fwrite(STDERR, "Output directory is not writable\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP ZipArchive extension is required\n");
    exit(2);
}

/**
 * @return array<string, string>
 */
function packageFiles(string $version, bool $broken): array
{
    $tableColumns = $version === '1.1.0'
        ? "id bigint PRIMARY KEY,\n    note text NOT NULL,\n    label text"
        : "id bigint PRIMARY KEY,\n    note text NOT NULL";
    $insertColumns = $version === '1.1.0' ? 'id, note, label' : 'id, note';
    $insertValues = $version === '1.1.0'
        ? "1, 'installed by neutral-probe', 'fresh 1.1.0'"
        : "1, 'installed by neutral-probe'";

    $installSql = $broken
        ? "CREATE TABL sand_package_probe (id bigint PRIMARY KEY);\n"
        : <<<SQL
CREATE TABLE sand_package_probe (
    {$tableColumns}
);
INSERT INTO sand_package_probe ({$insertColumns})
VALUES ({$insertValues});
INSERT INTO sand_system_menu (parent_id, name, code, type, path, component, status)
VALUES (0, 'Neutral probe', 'NeutralProbe', 2, '/neutral-probe', '/plugin/neutral-probe/index', 1);
SQL;

    $updateSql = <<<'SQL'
ALTER TABLE sand_package_probe ADD COLUMN label text;
UPDATE sand_package_probe SET label = 'upgraded to 1.1.0' WHERE id = 1;
SQL;

    $uninstallSql = <<<'SQL'
DELETE FROM sand_system_role_menu
WHERE menu_id IN (SELECT id FROM sand_system_menu WHERE code = 'NeutralProbe');
DELETE FROM sand_system_menu WHERE code = 'NeutralProbe';
DROP TABLE IF EXISTS sand_package_probe;
SQL;

    $info = <<<INI
app = neutral-probe
title = Neutral probe
about = Neutral PostgreSQL package lifecycle acceptance fixture
author = SandAdmin
website = https://github.com/SandAdmin
version = {$version}
support = 0.1.x
state = 0
INI;

    $backendConfig = <<<PHP
<?php

return ['version' => '{$version}'];
PHP;

    $frontend = "<template><div>Neutral probe {$version}</div></template>\n";

    return [
        'info.ini' => $info . "\n",
        'config.json' => "{}\n",
        'install.sql' => $installSql . "\n",
        'update.sql' => $updateSql . "\n",
        'uninstall.sql' => $uninstallSql . "\n",
        'plugin/neutral-probe/config/app.php' => $backendConfig . "\n",
        'sandadmin-artd/src/views/plugin/neutral-probe/index.vue' => $frontend,
    ];
}

function buildPackage(string $target, string $version, bool $broken = false): void
{
    $archive = new ZipArchive();
    $opened = $archive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException("Unable to create package: {$target}");
    }

    foreach (packageFiles($version, $broken) as $name => $contents) {
        if (!$archive->addFromString($name, $contents)) {
            $archive->close();
            throw new RuntimeException("Unable to add {$name} to {$target}");
        }
    }
    if (!$archive->close()) {
        throw new RuntimeException("Unable to finish package: {$target}");
    }
}

$packages = [
    'neutral-probe-1.0.0.zip' => ['1.0.0', false],
    'neutral-probe-1.1.0.zip' => ['1.1.0', false],
    'neutral-probe-broken.zip' => ['1.0.0', true],
];

try {
    foreach ($packages as $filename => [$version, $broken]) {
        $target = $outputDirectory . DIRECTORY_SEPARATOR . $filename;
        buildPackage($target, $version, $broken);
        fwrite(STDOUT, $target . "\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
