<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

interface RepositoryClient
{
    /**
     * @param callable(?string, ?\Throwable): void $complete
     */
    public function get(string $url, int $maxBytes, callable $complete): void;
}
