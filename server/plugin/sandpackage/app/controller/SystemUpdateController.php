<?php
declare(strict_types=1);
namespace plugin\sandpackage\app\controller;

use plugin\sandadmin\app\middleware\CheckLogin;
use plugin\sandadmin\app\middleware\SystemLog;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandpackage\app\service\SystemUpdate;
use support\annotation\Middleware;
use support\Request;
use support\Response;
use Throwable;

#[Middleware(CheckLogin::class, SystemLog::class)]
final class SystemUpdateController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        if ($this->adminId !== 1) throw new ApiException('仅超级管理员能够执行系统更新', 400);
    }
    private function respond(callable $action): Response
    {
        try { return $this->success($action()); }
        catch (ApiException $error) { throw $error; }
        catch (Throwable $error) { throw new ApiException(\SandSystemUpdateRuntime::redact($error->getMessage()), 400); }
    }
    public function status(Request $request): Response { return $this->respond(static fn (): array => (new SystemUpdate())->status()); }
    public function inspect(Request $request): Response { return $this->respond(static fn (): array => (new SystemUpdate())->inspect($request->post('targets'))); }
    public function start(Request $request): Response { return $this->respond(static fn (): array => (new SystemUpdate())->start($request->post('confirmation'))); }
    public function task(Request $request): Response { return $this->respond(static fn (): array => (new SystemUpdate())->task($request->get('id'))); }
    public function recover(Request $request): Response { return $this->respond(static fn (): array => (new SystemUpdate())->recover($request->post('id'))); }
}
