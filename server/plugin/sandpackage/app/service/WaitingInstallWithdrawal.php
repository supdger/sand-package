<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

/**
 * 等待依赖、安装中断、安装失败时能否撤回或重装。只看登记字段。
 * 已写入宿主文件归属的包不走撤回，避免半截删掉别的插件要用的文件。
 */
final class WaitingInstallWithdrawal
{
    public const STATE = 4;
    public const FAILED = 8;

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
        if ($state !== self::STATE && $state !== self::FAILED) {
            return '只有停在等待依赖、安装中断或安装失败的插件可以撤回安装';
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

    /**
     * 安装失败能否从页面重新安装。升级失败和重接失败走各自的恢复，不从这里重放。
     *
     * @param array<string, mixed> $info
     */
    public static function describeRetryRefusal(array $info, int $state): ?string
    {
        if ($state !== self::FAILED) {
            return '只有安装失败的插件可以重新安装';
        }
        if (!empty($info['update']) || !empty($info['upgrade_from_version']) || !empty($info['failed_upgrade'])
            || !empty($info['existing_schema_attach_pending'])) {
            return '这次失败不能从这里重新安装';
        }
        if (($info['lifecycle_driver'] ?? '') !== 'saipackage-pg-v1') {
            return '安装记录不是当前安装流程，不能从这里重新安装';
        }
        return null;
    }

    /** 安装脚本还没提交时才允许重放；已经提交过的继续部署，不再插入菜单和数据表。 */
    public static function shouldReplaySql(string $phase): bool
    {
        return $phase === '' || $phase === 'sql_not_committed';
    }
}
