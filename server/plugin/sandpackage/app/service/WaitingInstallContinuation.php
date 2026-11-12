<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

/**
 * 安装中断或等待依赖时，页面续做只看登记和已经装上的包。
 * 浏览器不能跑依赖命令：包已经在磁盘上就允许收尾，否则只回报还缺的名字和要复制的命令。
 */
final class WaitingInstallContinuation
{
    /**
     * @param array<string, mixed> $info
     */
    public static function describeContinueRefusal(array $info, int $state): ?string
    {
        $interrupted = self::isInterrupted($info);
        if ($state !== WaitingInstallWithdrawal::STATE && !$interrupted) {
            return '只有停在等待依赖或安装中断的插件可以续做';
        }
        if (!empty($info['update']) || !empty($info['upgrade_from_version']) || !empty($info['failed_upgrade'])) {
            return '这次不是可以续做的全新安装';
        }
        if (($info['lifecycle_driver'] ?? '') !== 'saipackage-pg-v1') {
            return '安装记录不是当前安装流程，不能从这里续做';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $declared 插件 config.json 里的依赖声明
     * @param array<string, true> $present 宿主磁盘上已经能读到的包名
     * @return list<array{source: string, name: string}>
     */
    public static function missingPackages(array $declared, array $present): array
    {
        $missing = [];
        foreach (['require' => 'composer', 'require-dev' => 'composer', 'dependencies' => 'npm', 'devDependencies' => 'npm'] as $key => $source) {
            $items = $declared[$key] ?? null;
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $name => $constraint) {
                if (!is_string($name) || self::isPlatformPackage($name) || !self::isPackageName($name) || isset($present[$name])) {
                    continue;
                }
                $missing[] = ['source' => $source, 'name' => $name];
            }
        }
        return $missing;
    }

    /**
     * @param list<array{source: string, name: string}> $missing
     */
    public static function missingText(array $missing): string
    {
        $composer = [];
        $npm = [];
        foreach ($missing as $item) {
            if (($item['source'] ?? '') === 'npm') {
                $npm[] = $item['name'];
            } else {
                $composer[] = $item['name'];
            }
        }
        $parts = [];
        if ($composer !== []) {
            $parts[] = '后台依赖 ' . implode('、', $composer);
        }
        if ($npm !== []) {
            $parts[] = '页面依赖 ' . implode('、', $npm);
        }
        if ($parts === []) {
            return '还差依赖没装完。请把下面的命令复制到服务器的 server 目录执行，装好后回到这一页点「刷新」。';
        }
        return '还差' . implode('，', $parts) . '。请把下面的命令复制到服务器的 server 目录执行，装好后回到这一页点「刷新」。';
    }

    /**
     * @param list<array{source: string, name: string}> $missing
     * @return list<array{label: string, text: string}>
     */
    public static function commandLines(string $app, array $missing): array
    {
        $hasComposer = false;
        $hasNpm = false;
        foreach ($missing as $item) {
            if (($item['source'] ?? '') === 'npm') {
                $hasNpm = true;
            } else {
                $hasComposer = true;
            }
        }
        $lines = [];
        if ($hasComposer) {
            $lines[] = ['label' => '装后台依赖', 'text' => 'php webman sandpackage:dependency run ' . $app . ' composer'];
        }
        if ($hasNpm) {
            $lines[] = ['label' => '装页面依赖', 'text' => 'php webman sandpackage:dependency run ' . $app . ' npm'];
        }
        return $lines;
    }

    /** @param array<string, mixed> $info */
    public static function isInterrupted(array $info): bool
    {
        return !empty($info['dependency_command_nonce'])
            || !empty($info['dependency_failed_nonce'])
            || !empty($info['process_recovery_required']);
    }

    private static function isPackageName(string $name): bool
    {
        return strlen($name) <= 80 && preg_match('/^[A-Za-z0-9_.@\/-]+$/D', $name) === 1;
    }

    /** php 和扩展是运行环境，不是还要下载的包。 */
    private static function isPlatformPackage(string $name): bool
    {
        return $name === 'php'
            || $name === 'composer-plugin-api'
            || $name === 'composer-runtime-api'
            || str_starts_with($name, 'ext-')
            || str_starts_with($name, 'lib-');
    }
}
