<?php

/**
 * Allowlisted dependency commands for the CLI runner only.
 *
 * HTTP workers must not execute these. The online terminal, registry
 * mutation, ad-hoc build, and version-probe shell entries are not configured.
 * `php webman sandpackage:dependency` is the only supported launcher.
 */
return [
    'commands' => [
        'web-install' => [
            'npm' => [
                'cwd' => dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd'),
                'command' => 'npm install',
            ],
            'yarn' => [
                'cwd' => dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd'),
                'command' => 'yarn install',
            ],
            'pnpm' => [
                'cwd' => dirname(base_path()) . DIRECTORY_SEPARATOR . env('FRONTEND_DIR', 'sandadmin-artd'),
                'command' => 'pnpm install',
            ],
        ],
        'composer' => [
            'update' => [
                'cwd' => base_path(),
                'command' => 'composer update --no-interaction',
            ],
        ],
    ],
    // composer 步骤的 packagist 镜像。宿主已声明 packagist.org 时不注入；
    // 置空字符串可停用镜像注入。仅 CLI 依赖命令读取此项。
    'composer_mirror' => env('SAND_COMPOSER_MIRROR', 'https://mirrors.cloud.tencent.com/composer/'),
];
