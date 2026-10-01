<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use InvalidArgumentException;

/** Validates a plugin's fixed service/action declaration before installation. */
final class PluginServiceCatalogPolicy
{
    /**
     * @param array<string,mixed> $config
     * @return array{service:array{code:string,name:string},actions:array<string,string>}|null
     */
    public static function declaration(array $config, string $app): ?array
    {
        if (!array_key_exists('service_catalog', $config)) return null;
        $raw = $config['service_catalog'];
        if (!is_array($raw) || array_keys($raw) !== ['service', 'actions']
            || !is_array($raw['service']) || array_keys($raw['service']) !== ['code', 'name']
            || !is_string($raw['service']['code'])
            || $raw['service']['code'] !== str_replace('-', '_', $app)
            || !is_string($raw['service']['name']) || trim($raw['service']['name']) === ''
            || mb_strlen($raw['service']['name']) > 128
            || !is_array($raw['actions']) || $raw['actions'] === []
            || array_is_list($raw['actions']) || count($raw['actions']) > 256) {
            throw new InvalidArgumentException('插件服务目录声明格式错误');
        }
        foreach ($raw['actions'] as $code => $name) {
            if (!is_string($code) || preg_match('/\A[a-z0-9][a-z0-9._-]{1,95}\z/D', $code) !== 1
                || !str_starts_with($code, $raw['service']['code'] . '.')
                || !is_string($name) || trim($name) === '' || mb_strlen($name) > 128) {
                throw new InvalidArgumentException('插件服务目录动作声明格式错误');
            }
        }
        return $raw;
    }
}
