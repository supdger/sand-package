# Sand Package

SandAdmin 的 Composer 插件安装器，包含 PostgreSQL 插件生命周期、仓库获取、恢复能力和对应管理端页面源码。

## 源码与安装

本仓提供插件安装器及管理端页面源码。已有 SandAdmin 宿主通过 Composer 安装本包；[开发源码](https://github.com/supdger/sand-package/tree/main)与 GitHub 的 Source code 压缩包供开发使用。业务插件的完整 ZIP 由本安装器管理，不能把安装器源码压缩包当作业务插件上传。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](CHANGELOG.md)保留历史记录。下载见 [最新稳定版](https://github.com/supdger/sand-package/releases/latest)；预发布及全部版本见 [公开发行](https://github.com/supdger/sand-package/releases)。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

先完成 [Sand Core](https://github.com/supdger/sand-core) 的前端源码发布，再安装本包；包依赖下载不等于前端基线已发布。

```bash
composer require supdger/sand-package:^0.2
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

Composer 安装或更新时，Webman 插件安装钩子会在 Sand Core 前端基线上自动发布
SandPackage 管理页面源码，不需要额外执行工具脚本，并拒绝覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandpackage/`，前端目录应有 `.sand-package-source-manifest.json` 和 `src/views/plugin/sandpackage/`。这只确认源码发布；服务启动、登录及 ZIP 插件安装另按[宿主首次安装指南](https://github.com/supdger/sandadmin/wiki/getting-started)和[仓库插件分发](https://github.com/supdger/sandadmin/wiki/repository-plugin-distribution)操作。管理端运行条件沿用 [Sand Core](https://github.com/supdger/sand-core) 的 Node.js/pnpm 要求和宿主锁文件。

## 文档与反馈

详细配置和操作见 [Wiki](https://github.com/supdger/sandadmin/wiki/Home)。[Sand Package：应用载荷、schema 重接与兼容声明](https://github.com/supdger/sandadmin/wiki/package-reference)记录对应能力及限制；安装器与宿主使用分别按所选版本核对。

问题与建议请提交到 [Issues](https://github.com/supdger/sand-package/issues)。

## 系统更新（0.2.0）

超级管理员从后台「插件管理」进入「系统更新」，查看 SandAdmin 核心和插件管理器的已安装版本、可用版本及更新说明。选择目标版本后，先点击「检查所选升级」，处理未通过的检查项，再确认升级。系统更新与普通插件操作互斥；本地文件修改、插件兼容性或执行环境不满足时不能继续。

任务进度、错误及恢复入口保存在服务端，页面断线或刷新后可以重新查询；提交结果不明时先重新连接查询，不要重复提交。仅在后端任务报告升级成功并完成健康检查后显示升级完成；失败时按任务提供的恢复入口处理。


启用前由宿主管理员按 [`tools/system-update.config.example.php`](tools/system-update.config.example.php) 配置 `server/config/sand_system_update.php`，填写实际 PHP、前端和专用静态目录，以及固定的 Composer/pnpm、服务重载和真实运行健康检查命令。命令使用参数数组，不执行 shell 字符串。当前独立执行器要求 Linux/macOS 的 PHP CLI 支持 `proc_open`、`pcntl` 和 `posix`；Windows 后台执行暂不支持。

确认专用静态目录已部署当前构建产物后，在宿主 `server/` 目录建立基线（将目录替换为实际绝对路径）：

```bash
php vendor/supdger/sand-package/tools/prepare-system-update.php --frontend=/srv/sandadmin/sandadmin-artd --static=/srv/sandadmin/server/public/admin
```

成功会显示已核对的文件数量并写入静态目录清单；不一致会说明额外、缺失或变化文件数量，不会覆盖部署文件。静态目录须与前端源码、宿主 `app/config/vendor/plugin/runtime` 目录分离，更新后仍需供 Web 服务器读取。仅带有官方有效更新契约且明确无数据库和宿主骨架变更的稳定发行可选择；未提供有效契约的版本及非稳定发行不会列入可选版本，不能强制跳过。

任务中断或外部改动导致自动恢复拒绝时，保留 `server/runtime/system-update/jobs/<任务ID>` 中的记录、备份与固定 `worker.php`，先确认原任务子进程和外部写入者已停止。在宿主 `server/` 运行 `php runtime/system-update/jobs/<任务ID>/worker.php <任务目录绝对路径> manual-inspect` 查看将覆盖的范围、变化文件、`confirmation` 和 `fingerprint`；核对后将命令末尾 `manual-inspect` 替换为 `manual-recover <confirmation> <fingerprint>`，使用本次返回的原值，其余参数保持相同。恢复会先保存当前现场，再覆盖为备份版本并重载、健康检查；确认过期或现场变化会拒绝执行。真实恢复及服务重载须在宿主管理员的维护授权内执行。

公开包、隔离流程与界面验证不能替代真实宿主服务重载及业务验收。详细使用与恢复操作见 [系统更新（Wiki）](https://github.com/supdger/sandadmin/wiki/system-update)。
