<?php

return [
    // Public repositories only. Ref may be a branch, tag or immutable commit.
    'repository' => env('SANDADMIN_PLUGIN_REPOSITORY', 'supdger/sandadmin'),
    'ref' => env('SANDADMIN_PLUGIN_REF', 'main'),
];
