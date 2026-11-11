<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

/**
 * 等待依赖安装时能否撤回。只看登记字段，不读磁盘、不连数据库。
 * 已写入宿主文件归属的包不走这条，避免半截删掉别的插件要用的文件。
 */
final class WaitingInstallWithdrawal
{
    public const STATE = 4;

    /**
     * @param array<string, mixed> $info
     */
    public static function describeRefusal(
        array $info,
        int $state,
        bool $hasDependent,
        bool $hasUnverifiable,
        bool $hasHostPayload,
    ): ?string {
        if ($state !== self::STATE) {
            return '只有停在等待依赖安装的插件可以撤回安装';
        }
        if (!empty($info['dependency_command_nonce']) || !empty($info['dependency_command_type'])) {
            return '依赖命令还在执行，先等它结束后再撤回';
        }
        if (!empty($info['update']) || !empty($info['upgrade_from_version']) || !empty($info['existing_schema_attached'])) {
            return '这次不是可以撤回的全新安装';
        }
        if (($info['lifecycle_driver'] ?? '') !== 'saipackage-pg-v1') {
            return '安装记录不是当前安装流程，不能从这里撤回';
        }
        if ($hasDependent || $hasUnverifiable) {
            return '还有其他插件可能依赖它，不能撤回';
        }
        if ($hasHostPayload) {
            return '这个插件已经改过宿主文件，不能从这里撤回';
        }
        return null;
    }
}
