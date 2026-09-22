<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\service;

use CurlHandle;
use CurlMultiHandle;
use plugin\sandadmin\exception\ApiException;
use RuntimeException;
use SplQueue;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Public GitHub transport for repository catalogues and release assets.
 *
 * One instance is intended to be shared by one Worker process. All request
 * data stays in GithubRepositoryRequest objects and is released on completion.
 */
final class GithubRepositoryClient implements RepositoryClient
{
    private const CONNECT_TIMEOUT_MS = 5000;
    private const TOTAL_TIMEOUT_SECONDS = 60.0;
    private const MAX_REDIRECTS = 3;
    private const MAX_CONCURRENCY = 2;
    private const MAX_TOTAL_REQUESTS = 8;
    private const POLL_INTERVAL_SECONDS = 0.01;

    private const INITIAL_HOSTS = [
        'github.com' => true,
        'raw.githubusercontent.com' => true,
    ];

    private const REDIRECT_HOSTS = [
        'github.com' => true,
        'raw.githubusercontent.com' => true,
        'objects.githubusercontent.com' => true,
        'objects-origin.githubusercontent.com' => true,
        'release-assets.githubusercontent.com' => true,
        'github-releases.githubusercontent.com' => true,
    ];

    private static ?self $processClient = null;

    /** @var SplQueue<GithubRepositoryRequest> */
    private SplQueue $pending;

    /** @var array<int,GithubRepositoryRequest> */
    private array $active = [];

    private ?CurlMultiHandle $multi = null;
    private ?int $timerId = null;
    private bool $closed = false;

    public function __construct(private bool $synchronousCli = false)
    {
        $this->pending = new SplQueue();
    }

    /**
     * Production entrypoint: one scheduler and one concurrency budget per Worker.
     */
    public static function shared(): self
    {
        return self::$processClient ??= new self();
    }

    public function get(string $url, int $maxBytes, callable $complete): void
    {
        if ($this->closed) {
            $complete(null, new ApiException('GitHub 下载客户端已经关闭'));
            return;
        }
        if (count($this->active) + $this->pending->count() >= self::MAX_TOTAL_REQUESTS) {
            $complete(null, new ApiException('GitHub 下载请求过多，请稍后重试'));
            return;
        }

        try {
            $this->assertRuntimeAvailable();
            if ($maxBytes < 1) {
                throw new ApiException('GitHub 下载大小限制必须大于零');
            }
            $validatedUrl = $this->validateUrl($url, true);
        } catch (Throwable $error) {
            $complete(null, $this->apiError($error, 'GitHub 下载地址无效'));
            return;
        }

        $request = new GithubRepositoryRequest(
            $validatedUrl,
            $maxBytes,
            $complete,
            microtime(true) + self::TOTAL_TIMEOUT_SECONDS,
        );
        $this->pending->enqueue($request);

        if ($this->synchronousCli) {
            try {
                $this->driveSynchronously();
            } catch (Throwable $error) {
                $this->failAll($this->apiError($error, 'GitHub 下载请求失败'));
            }
            return;
        }

        try {
            $this->pumpQueue();
            $this->ensureTimer();
        } catch (Throwable $error) {
            $this->failAll($this->apiError($error, 'GitHub 下载请求无法启动'));
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $callbackError = null;
        try {
            $this->failAll(new ApiException('GitHub 下载客户端已经关闭'));
        } catch (Throwable $error) {
            $callbackError = $error;
        } finally {
            $this->stopTimer();
            if ($this->multi !== null) {
                curl_multi_close($this->multi);
                $this->multi = null;
            }
            if (self::$processClient === $this) {
                self::$processClient = null;
            }
        }
        if ($callbackError !== null) {
            throw $callbackError;
        }
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (Throwable) {
            // Destructors cannot safely propagate application callbacks.
        }
    }

    private function assertRuntimeAvailable(): void
    {
        if (!function_exists('curl_multi_init') || !defined('CURL_VERSION_ASYNCHDNS')) {
            throw new ApiException('当前环境缺少 GitHub 异步下载能力');
        }
        $version = curl_version();
        if (((int) ($version['features'] ?? 0) & CURL_VERSION_ASYNCHDNS) === 0) {
            throw new ApiException('当前 cURL 不支持异步 DNS，已拒绝在 Worker 中下载');
        }
        if ($this->synchronousCli
            && (PHP_SAPI !== 'cli' || (class_exists(Worker::class) && Worker::getAllWorkers() !== []))) {
            throw new ApiException('同步下载驱动只允许用于独立 CLI 测试');
        }
    }

    private function ensureTimer(): void
    {
        if ($this->timerId !== null || ($this->active === [] && $this->pending->isEmpty())) {
            return;
        }
        $this->timerId = Timer::add(self::POLL_INTERVAL_SECONDS, function (): void {
            try {
                $this->tick();
            } catch (Throwable $error) {
                $this->failAll($this->apiError($error, 'GitHub 下载请求失败'));
            }
        });
    }

    private function stopTimer(): void
    {
        if ($this->timerId === null) {
            return;
        }
        Timer::del($this->timerId);
        $this->timerId = null;
    }

    private function tick(): void
    {
        $this->executeMulti();
        $this->collectCompleted();
        $this->pumpQueue();
        if ($this->active === [] && $this->pending->isEmpty()) {
            $this->stopTimer();
        }
    }

    private function driveSynchronously(): void
    {
        $this->pumpQueue();
        while ($this->active !== [] || !$this->pending->isEmpty()) {
            $this->executeMulti();
            $this->collectCompleted();
            $this->pumpQueue();
            if ($this->active !== [] && $this->multi !== null) {
                $selected = curl_multi_select($this->multi, 0.1);
                if ($selected === -1) {
                    usleep(1000);
                }
            }
        }
    }

    private function executeMulti(): void
    {
        if ($this->multi === null || $this->active === []) {
            return;
        }
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($status !== CURLM_OK) {
            throw new RuntimeException('cURL multi 执行失败');
        }
    }

    private function collectCompleted(): void
    {
        if ($this->multi === null) {
            return;
        }
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'] ?? null;
            if (!$handle instanceof CurlHandle) {
                continue;
            }
            $id = spl_object_id($handle);
            $request = $this->active[$id] ?? null;
            if ($request === null) {
                curl_multi_remove_handle($this->multi, $handle);
                curl_close($handle);
                continue;
            }

            unset($this->active[$id]);
            $request->handle = null;
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($this->multi, $handle);
            curl_close($handle);

            if ($request->overflow) {
                $this->finish($request, null, new ApiException('GitHub 下载内容超过大小限制'));
                continue;
            }
            if (($info['result'] ?? CURLE_FAILED_INIT) !== CURLE_OK) {
                $this->finish($request, null, new ApiException('GitHub 下载请求失败'));
                continue;
            }
            if ($this->isRedirect($statusCode)) {
                $this->continueRedirect($request);
                continue;
            }
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->finish($request, null, new ApiException("GitHub 返回异常状态（HTTP {$statusCode}）"));
                continue;
            }
            $this->finish($request, $request->responseBody, null);
        }
    }

    private function pumpQueue(): void
    {
        while (count($this->active) < self::MAX_CONCURRENCY && !$this->pending->isEmpty()) {
            $request = $this->pending->dequeue();
            if ($request->completed) {
                continue;
            }
            try {
                $this->start($request);
            } catch (Throwable $error) {
                $this->finish($request, null, $this->apiError($error, 'GitHub 下载请求无法启动'));
            }
        }
    }

    private function start(GithubRepositoryRequest $request): void
    {
        $remainingMs = (int) floor(($request->deadline - microtime(true)) * 1000);
        if ($remainingMs < 1) {
            throw new ApiException('GitHub 下载请求已超时');
        }
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new ApiException('无法创建 GitHub 下载连接');
        }

        $request->responseBody = '';
        $request->location = null;
        $request->handle = $handle;
        try {
            $options = [
                CURLOPT_URL => $request->url,
                CURLOPT_HTTPGET => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT_MS => min(self::CONNECT_TIMEOUT_MS, $remainingMs),
                CURLOPT_TIMEOUT_MS => $remainingMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'SandPackage-GitHub-Client/1.0',
                CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $unused, string $chunk) use ($request): int {
                    $length = strlen($chunk);
                    $request->receivedBytes += $length;
                    if ($request->receivedBytes > $request->maxBytes) {
                        $request->overflow = true;
                        return 0;
                    }
                    $request->responseBody .= $chunk;
                    return $length;
                },
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $unused, string $header) use ($request): int {
                    if (str_starts_with($header, 'HTTP/')) {
                        $request->location = null;
                    } elseif (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                        $request->location = strlen($location) <= 8192 ? $location : null;
                    }
                    return strlen($header);
                },
            ];
            if (defined('CURLOPT_NETRC')) {
                $options[CURLOPT_NETRC] = CURL_NETRC_IGNORED;
            }
            if (!curl_setopt_array($handle, $options)) {
                throw new ApiException('无法配置 GitHub 下载连接');
            }

            $this->multi ??= curl_multi_init();
            $result = curl_multi_add_handle($this->multi, $handle);
            if ($result !== CURLM_OK) {
                throw new ApiException('无法启动 GitHub 下载连接');
            }
            $this->active[spl_object_id($handle)] = $request;
        } catch (Throwable $error) {
            if ($this->multi !== null) {
                curl_multi_remove_handle($this->multi, $handle);
            }
            curl_close($handle);
            $request->handle = null;
            throw $error;
        }
    }

    private function continueRedirect(GithubRepositoryRequest $request): void
    {
        if ($request->location === null) {
            $this->finish($request, null, new ApiException('GitHub 下载重定向缺少有效地址'));
            return;
        }
        if ($request->redirects >= self::MAX_REDIRECTS) {
            $this->finish($request, null, new ApiException('GitHub 下载重定向次数过多'));
            return;
        }
        try {
            $request->url = $this->validateUrl(
                $this->resolveRedirect($request->url, $request->location),
                false,
            );
        } catch (Throwable $error) {
            $this->finish($request, null, $this->apiError($error, 'GitHub 下载重定向地址无效'));
            return;
        }
        $request->redirects++;
        $this->pending->enqueue($request);
    }

    private function validateUrl(string $url, bool $initial): string
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ApiException('GitHub 下载地址格式无效');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || ($initial && isset($parts['query']))
            || isset($parts['fragment'])) {
            throw new ApiException('GitHub 下载地址必须使用无凭据的标准 HTTPS');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $allowed = $initial ? self::INITIAL_HOSTS : self::REDIRECT_HOSTS;
        if (!isset($allowed[$host])) {
            throw new ApiException('GitHub 下载地址域名不在允许范围');
        }
        return $url;
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if ($location === '' || preg_match('/[\x00-\x1f\x7f]/', $location) === 1) {
            throw new ApiException('GitHub 下载重定向地址无效');
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['host'])) {
            throw new ApiException('GitHub 下载重定向基地址无效');
        }
        $origin = 'https://' . $base['host'];
        if (isset($base['port'])) {
            $origin .= ':' . $base['port'];
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . ($base['path'] ?? '/') . $location;
        }
        $path = $base['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $origin . $this->normalizePath($directory . $location);
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    private function isRedirect(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    private function finish(
        GithubRepositoryRequest $request,
        ?string $body,
        ?Throwable $error,
    ): void {
        if ($request->completed) {
            return;
        }
        $request->completed = true;
        $complete = $request->complete;
        $request->complete = null;
        $request->responseBody = '';
        $request->location = null;
        $complete($body, $error);
    }

    private function failAll(ApiException $error): void
    {
        $requests = [];
        foreach ($this->active as $id => $request) {
            if ($request->handle !== null && $this->multi !== null) {
                curl_multi_remove_handle($this->multi, $request->handle);
                curl_close($request->handle);
                $request->handle = null;
            }
            unset($this->active[$id]);
            $requests[] = $request;
        }
        while (!$this->pending->isEmpty()) {
            $requests[] = $this->pending->dequeue();
        }
        $this->stopTimer();
        $callbackError = null;
        foreach ($requests as $request) {
            try {
                $this->finish($request, null, $error);
            } catch (Throwable $thrown) {
                $callbackError ??= $thrown;
            }
        }
        if ($callbackError !== null) {
            throw $callbackError;
        }
    }

    private function apiError(Throwable $error, string $fallback): ApiException
    {
        return $error instanceof ApiException ? $error : new ApiException($fallback);
    }
}

/**
 * Mutable state owned by exactly one in-flight request.
 *
 * @internal
 */
final class GithubRepositoryRequest
{
    public int $redirects = 0;
    public int $receivedBytes = 0;
    public string $responseBody = '';
    public ?string $location = null;
    public bool $overflow = false;
    public bool $completed = false;
    public ?CurlHandle $handle = null;
    public mixed $complete;

    public function __construct(
        public string $url,
        public int $maxBytes,
        callable $complete,
        public float $deadline,
    ) {
        $this->complete = $complete;
    }
}
