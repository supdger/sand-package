<?php

declare(strict_types=1);

use plugin\sandpackage\app\service\HostVersionCompatibility;

require dirname(__DIR__, 2) . '/plugin/sandpackage/app/service/HostVersionCompatibility.php';
$cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/host-version-compatibility.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    if (HostVersionCompatibility::matches($case['support'], $case['host']) !== $case['expected']) {
        throw new RuntimeException('Host compatibility case failed: ' . $case['name']);
    }
}
echo 'HostVersionCompatibility: ' . count($cases) . '/' . count($cases) . " passed\n";
