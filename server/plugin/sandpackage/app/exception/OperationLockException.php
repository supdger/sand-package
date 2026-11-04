<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\exception;

use plugin\sandadmin\exception\ApiException;

/**
 * 安装操作锁被在途事务持有（flock 非阻塞冲突）。
 *
 * 与守卫类业务拒绝（恢复检查点、宿主依赖记录）区分：锁冲突是瞬态的，
 * 在途安装/卸载/恢复事务提交并释放锁之后即可重试。依赖命令入口
 * （TerminalRunner）据此把失败归类为【锁冲突】，前端编排器在预算内
 * 重试入队而不是直接判依赖链失败。宿主仍按 ApiException 统一渲染，
 * 不改变对外响应壳语义。
 */
final class OperationLockException extends ApiException
{
}
