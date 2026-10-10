<?php

use plugin\sandpackage\app\service\PackageArchivePolicy;

return [
    'type' => ['zip'],
    'size' => PackageArchivePolicy::MAX_BYTES,
];
