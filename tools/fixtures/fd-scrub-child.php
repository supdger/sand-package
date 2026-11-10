<?php

declare(strict_types=1);

/**
 * Test child for tools/assert-fd-isolation.php.
 * Usage: php fd-scrub-child.php <seconds> <marker-file>
 * Writes the SAND_FD_SCRUBBED env value to the marker file, then sleeps.
 */

$seconds = max(1, min(60, (int) ($argv[1] ?? 20)));
$marker = $argv[2] ?? '';
if ($marker !== '') {
    file_put_contents($marker, getenv('SAND_FD_SCRUBBED') === '1' ? 'scrubbed' : 'raw');
}
sleep($seconds);
