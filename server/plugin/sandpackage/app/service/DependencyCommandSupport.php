<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use RuntimeException;
use Throwable;

/**
 * 依赖命令的进程环境与失败诊断支撑。
 *
 * 后端 composer 依赖步骤此前直接继承宿主环境：Packagist 元数据为 GitHub 托管包
 * 给出的 dist 地址是 api.github.com/zipball，匿名请求每小时只有 60 次额度，
 * 依赖包稍多即撞限额失败。这里为依赖命令准备独立的 COMPOSER_HOME：默认把
 * packagist 指到自带 dist 文件的 Composer 镜像，使依赖解析与下载完全不请求
 * api.github.com；宿主在项目 composer.json 或全局配置里显式声明过
 * packagist.org 仓库时尊重其选择、不注入镜像，宿主的私有仓库凭证
 * （auth.json）与原全局配置（config.json）一并继承，不丢失。
 */
final class DependencyCommandSupport
{
    /** 默认 Composer 镜像：腾讯云镜像自带 dist 文件，不经 api.github.com。 */
    public const DEFAULT_PACKAGIST_MIRROR = 'https://mirrors.cloud.tencent.com/composer/';

    /** 失败诊断读取的进程输出尾部大小。 */
    public const OUTPUT_TAIL_BYTES = 16384;

    /**
     * 失败详情中的「安装操作锁冲突」分类记号。flock 非阻塞冲突经
     * OperationLockException 携带到终端失败详情里，前端编排器按该记号
     * 判定瞬态并在预算内重试入队，不裸匹配中文错误文案。
     */
    public const LOCK_CONFLICT_TOKEN = '【锁冲突】';

    /**
     * 锁冲突失败详情片段：安装/卸载/恢复事务仍在锁内，解锁后命令即可
     * 重试。消息体沿用原锁提示，统一带分类记号与重试指引。
     */
    public static function lockConflictDetail(string $message): string
    {
        $text = trim($message);
        if ($text === '') {
            $text = '安装操作仍在执行';
        }
        return self::LOCK_CONFLICT_TOKEN . mb_substr($text, 0, 240) . '，请稍后重试';
    }

    /**
     * 准备依赖命令使用的独立 COMPOSER_HOME 并返回其路径。
     *
     * @param string $homeDir               目标目录（每次重写 config.json）
     * @param string $projectComposerJson   宿主 composer.json 路径（不存在也可）
     * @param string|null $realComposerHome 宿主真实 COMPOSER_HOME，用于继承配置与凭证
     * @param string|null $mirror           packagist 镜像地址；空值则不注入镜像
     */
    public static function prepareComposerHome(
        string $homeDir,
        string $projectComposerJson,
        ?string $realComposerHome,
        ?string $mirror
    ): string {
        $config = $realComposerHome !== null
            ? self::readJsonObject($realComposerHome . '/config.json')
            : [];
        $authSource = $realComposerHome !== null && is_file($realComposerHome . '/auth.json')
            ? $realComposerHome . '/auth.json'
            : null;

        if (!isset($config['config']) || !is_array($config['config'])) {
            $config['config'] = [];
        }
        // dist 压缩包优先；禁用 GitHub API 元数据通道（vcs 场景回落 git 协议）。
        $config['config']['preferred-install'] = 'dist';
        $config['config']['use-github-api'] = false;
        $config['config']['github-protocols'] = ['https'];

        $project = self::readJsonObject($projectComposerJson);
        $packagistPinned = array_key_exists('packagist.org', self::repositoriesOf($project))
            || array_key_exists('packagist.org', self::repositoriesOf($config));
        if (is_string($mirror) && $mirror !== '' && !$packagistPinned) {
            if (!isset($config['repositories']) || !is_array($config['repositories'])) {
                $config['repositories'] = [];
            }
            $config['repositories']['packagist.org'] = ['type' => 'composer', 'url' => $mirror];
        }

        if (!is_dir($homeDir) && !mkdir($homeDir, 0700, true) && !is_dir($homeDir)) {
            throw new RuntimeException('依赖命令的 Composer 工作目录无法创建');
        }
        if (!is_dir($homeDir) || is_link($homeDir)) {
            throw new RuntimeException('依赖命令的 Composer 工作目录不安全');
        }
        $body = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        self::writeAtomic($homeDir . '/config.json', $body, 0600);
        if ($authSource !== null) {
            $auth = file_get_contents($authSource);
            if (is_string($auth) && $auth !== '') {
                self::writeAtomic($homeDir . '/auth.json', $auth, 0600);
            }
        }
        return $homeDir;
    }

    /**
     * 定位宿主真实的 Composer 主目录，用于继承其全局配置与凭证。
     *
     * @param array<string,string> $env 宿主进程环境
     */
    public static function realComposerHome(array $env): ?string
    {
        $explicit = $env['COMPOSER_HOME'] ?? null;
        if (is_string($explicit) && $explicit !== '' && is_dir($explicit)) {
            return $explicit;
        }
        $home = $env['HOME'] ?? null;
        if (!is_string($home) || $home === '') {
            return null;
        }
        foreach ([$home . '/.composer', $home . '/.config/composer'] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * 依赖进程环境：继承宿主环境并注入隔离的 COMPOSER_HOME 与非交互标记。
     *
     * @param array<string,string> $baseEnv
     * @return array<string,string>
     */
    public static function composerEnvironment(array $baseEnv, string $homeDir): array
    {
        $env = $baseEnv;
        $env['COMPOSER_HOME'] = $homeDir;
        $env['COMPOSER_NO_INTERACTION'] = '1';
        return $env;
    }

    /**
     * 把依赖命令的原始输出尾部翻译成管理员能直接处理的中文提示。
     * 识别不了时返回 null，由调用方保留通用事件说明。
     */
    public static function failureHint(string $type, string $outputTail): ?string
    {
        if ($outputTail === '') {
            return null;
        }
        $githubTouched = preg_match('/github\.com/i', $outputTail) === 1;
        // 1) GitHub API 限额：优先按输出/响应头推算剩余等待，推算不出不给数字。
        if (preg_match('/rate.?limit|API limit|exceeded/i', $outputTail) === 1 && $githubTouched) {
            $minutes = self::githubRateLimitWaitMinutes($outputTail) ?? self::githubRateLimitResetMinutes();
            $wait = $minutes === null
                ? 'GitHub 访问次数用完（未配置令牌时每小时限 60 次），恢复后可一键重试'
                : 'GitHub 访问次数用完，约 ' . $minutes . ' 分钟后可重试';
            return $wait . '；也可在宿主 Composer 中配置 GitHub 令牌（composer config -g github-oauth.github.com <令牌>）';
        }
        // 2) 磁盘空间 / 目录权限
        if (preg_match('/No space left on device|disk quota exceeded|Permission denied|EACCES|EPERM|read.?only file system|failed to create director|cannot create director|mkdir\(\).{0,40}denied|file_put_contents\(\).{0,60}(denied|failed)/is', $outputTail) === 1) {
            return '磁盘空间不足或依赖目录没有写入权限，请检查宿主磁盘空间与目录属主权限后重试';
        }
        // 3) 依赖版本无法解析（composer 冲突 / npm 版本缺失）
        if ($type === 'composer'
            && preg_match('/your requirements could not be resolved|Can only install one of|Root composer\.json requires|requires? [\w\-\.\/]+ -> |conflicts? with|No matching version|not found in/i', $outputTail) === 1) {
            return '插件声明的 PHP 依赖版本无法满足，请核对插件与宿主版本兼容性';
        }
        if ($type !== 'composer'
            && preg_match('/ERESOLVE|No matching version|notarget|unable to resolve|404/i', $outputTail) === 1) {
            return '前端依赖版本无法解析，请核对插件声明的依赖版本与仓库可用性';
        }
        // 4) Composer secure-http 策略在连接前拒绝 http 协议的仓库/镜像地址
        if (preg_match('/does not allow connections? to https?:\/\/|secure-http/i', $outputTail) === 1) {
            return '依赖仓库或镜像使用了 Composer 安全策略不允许的 http 地址，请改用 https 镜像地址后重试，或检查依赖仓库配置';
        }
        // 5) 网络不可达 / 镜像不可用（curl 6/7/28、域名解析失败、连接被拒、超时等）
        if (self::isNetworkFailure($outputTail)) {
            if ($githubTouched) {
                return '网络连接 GitHub 失败、超时或被拒绝，请检查宿主网络与代理设置后重试';
            }
            if ($type === 'composer') {
                return '无法连接 Composer 依赖仓库或镜像，请检查网络后重试，或为依赖命令配置可用的 Composer 镜像';
            }
            return '网络连接前端依赖仓库失败或超时，请检查网络或 npm/pnpm 镜像源后重试';
        }
        return null;
    }

    /**
     * 依赖命令输出尾部的网络类失败判定：curl 6（域名解析）、7（连接失败）、
     * 28（超时）等传输错误，以及 npm/pnpm 的网络错误码。
     */
    private static function isNetworkFailure(string $outputTail): bool
    {
        return preg_match(
            '/curl error (?:6|7|28|35|52|55|56)\b|Could not resolve host|Failed to connect|Could not connect|connect failed|Connection refused|connect(?:ion)? timed? ?out|temporary failure in name resolution|Failed to open stream|network is unreachable|reset by peer|ECONNREFUSED|ECONNRESET|ETIMEDOUT|ENOTFOUND|EAI_AGAIN|ENETUNREACH|EHOSTUNREACH|403 Forbidden|authentication failed|502 Bad Gateway|503 Service Unavailable/i',
            $outputTail,
        ) === 1;
    }

    /**
     * 未能归类时给管理员的最后一截原始输出摘录（已在采集时脱敏）。
     * 返回 null 表示没有可用输出。
     */
    public static function failureExcerpt(string $outputTail): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $outputTail) ?? '');
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > 200 ? '…' . mb_substr($text, -200) : $text;
    }

    /**
     * 从依赖命令输出尾部推算 GitHub 限额的重置等待分钟数：
     * X-RateLimit-Reset 时间戳、Retry-After 秒数、「N 分钟后」与「某时间点」
     * 四类报文；都推不出时返回 null，由调用方回退到 /rate_limit 探测或省略数字。
     */
    public static function githubRateLimitWaitMinutes(string $outputTail): ?int
    {
        if (preg_match('/rate.?limit.?reset["\'\s:=]+?(\d{9,13})/i', $outputTail, $m) === 1) {
            $ts = (int) $m[1];
            if ($ts > 9999999999) {
                $ts = intdiv($ts, 1000);
            }
            return max(1, (int) ceil(($ts - time()) / 60));
        }
        if (preg_match('/retry.?after["\'\s:=]+?(\d{2,7})\b/i', $outputTail, $m) === 1) {
            return max(1, (int) ceil(((int) $m[1]) / 60));
        }
        if (preg_match('/(?:try again|retry|wait|after)\D{0,20}?(\d{1,4})\s*(?:minutes?|分钟)/i', $outputTail, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (preg_match('/(?:until|at)\D{0,20}?(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?)/i', $outputTail, $m) === 1) {
            $ts = strtotime($m[1]);
            if (is_int($ts) && $ts > time()) {
                return max(1, (int) ceil(($ts - time()) / 60));
            }
        }
        return null;
    }

    /**
     * GitHub 匿名限额的重置剩余分钟数。/rate_limit 端点本身不计入额度，
     * 仅用于给管理员一个可执行的重试时间；探测失败返回 null。
     */
    public static function githubRateLimitResetMinutes(): ?int
    {
        if (!function_exists('file_get_contents') || ini_get('allow_url_fopen') === '') {
            return null;
        }
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'ignore_errors' => true,
                    'header' => "User-Agent: SandPackage-dependency\r\nAccept: application/vnd.github+json\r\n",
                ],
            ]);
            $raw = @file_get_contents('https://api.github.com/rate_limit', false, $context);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $data = json_decode($raw, true, 32);
            $reset = is_array($data) ? ($data['resources']['core']['reset'] ?? null) : null;
            if (!is_int($reset) || $reset < 1) {
                return null;
            }
            return max(1, (int) ceil(($reset - time()) / 60));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private static function readJsonObject(string $path): array
    {
        if ($path === '' || !is_file($path) || is_link($path) || filesize($path) > 8388608) {
            return [];
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true, 64) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private static function repositoriesOf(array $document): array
    {
        $repositories = $document['repositories'] ?? null;
        return is_array($repositories) ? $repositories : [];
    }

    private static function writeAtomic(string $path, string $body, int $mode): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('依赖命令配置暂存文件无法创建');
        }
        try {
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle)) {
                throw new RuntimeException('依赖命令配置暂存文件未持久化');
            }
            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('依赖命令配置暂存文件权限设置失败');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('依赖命令配置无法保存');
        }
    }
}
