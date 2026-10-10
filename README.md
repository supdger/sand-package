# Sand Package

SandAdmin 的 Composer 插件安装器，包含 PostgreSQL 插件生命周期、仓库获取、恢复能力和对应管理端页面源码。

## 源码与安装

本仓提供插件安装器及管理端页面源码。已有 SandAdmin 宿主通过 Composer 安装本包；[开发源码](https://github.com/supdger/sand-package/tree/main)与 GitHub 的 Source code 压缩包供开发使用。业务插件的完整 ZIP 由本安装器管理，不能把安装器源码压缩包当作业务插件上传。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](CHANGELOG.md)保留历史记录。下载见 [最新稳定版](https://github.com/supdger/sand-package/releases/latest)；预发布及全部版本见 [公开发行](https://github.com/supdger/sand-package/releases)。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

本版本要求 [Sand Core 0.2.4](https://github.com/supdger/sand-core)；先完成其前端源码发布，再安装本包；包依赖下载不等于前端基线已发布。

```bash
composer require supdger/sand-package:^0.2.7
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

Composer 安装或更新时，Webman 插件安装钩子会在 Sand Core 前端基线上自动发布
SandPackage 管理页面源码，不需要额外执行工具脚本，并拒绝覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandpackage/`，前端目录应有 `.sand-package-source-manifest.json` 和 `src/views/plugin/sandpackage/`。这只确认源码发布；服务启动、登录及 ZIP 插件安装另按[宿主首次安装指南](https://github.com/supdger/sandadmin/wiki/getting-started)和[仓库插件分发](https://github.com/supdger/sandadmin/wiki/repository-plugin-distribution)操作。管理端运行条件沿用 [Sand Core](https://github.com/supdger/sand-core) 的 Node.js/pnpm 要求和宿主锁文件。

## 文档与反馈

详细配置和操作见 [Wiki](https://github.com/supdger/sandadmin/wiki/Home)。[Sand Package：应用载荷、schema 重接与兼容声明](https://github.com/supdger/sandadmin/wiki/package-reference)记录对应能力及限制；安装器与宿主使用分别按所选版本核对。

问题与建议请提交到 [Issues](https://github.com/supdger/sand-package/issues)。

## 插件 ZIP 容量

上传与仓库下载使用同一压缩 ZIP 上限：16MiB（16,777,216 bytes）。宿主 `plugin.sandpackage.upload.size` 可设置为更小的正整数 bytes；配置无效或超过 16MiB 时明确拒绝，不回退默认值。构包工具同样限制为 16MiB，无需增加参数。

HTTP 请求大小与 ZIP 容量分别受限。宿主 HTTP 请求包络需容纳 ZIP 与 multipart 开销（例如 20MiB）；本包不会修改宿主配置。超过限制时显示实际接收的 `actual` 与生效的 `max` bytes，下载中断不把已接收长度视为完整文件。解压后仍限制 64MiB、2048 条目，并校验路径、CRC、摘要与声明载荷。

## 自动依赖与恢复 CLI（0.2.6）

自动安装依赖后，安装器按 Webman 的配置与 autoload 文件声明在当前 worker 激活依赖，再查询服务目录。加载或目录预检失败时保留原候选，不执行当前插件 SQL；修复依赖后可继续原安装。

Unix 恢复 CLI 重载需要 POSIX 扩展、`/usr/bin/pgrep` 与 `/bin/ps`。恢复 CLI 的 `--restart` 仍须明确授权。它核对当前宿主的 Workerman master 与 HTTP worker，并等待 worker 轮换，不向调用者父进程发送信号。无法定位或重载失败时命令非零退出，先通过 `inspect` 核对恢复现场，不重跑已提交 SQL。缺少 POSIX 的平台会拒绝 CLI 重载，应使用宿主正式服务控制入口。已有宿主需要升级本包才能获得这些修复；无需数据库迁移。

## 依赖命令执行修复（0.2.5）

插件安装中的 Composer / npm / pnpm / yarn 命令继承宿主进程的 HOME、缓存、代理及其他工具配置，并正确传递命令参数。仍由超级管理员执行固定安装任务，不从请求接收环境值。已有宿主需要升级本包才能获得修复；无需数据库迁移，配套 Sand Core 0.2.4 的要求不变。

## 插件安装后的页面恢复（0.2.4）

配套 Sand Core 0.2.4、发布并构建匹配的管理端源码后，插件安装引发短暂服务重载时，仓库只读请求会有限自动恢复，页面完成导航后退出加载遮罩。权限拒绝、业务错误和安装写请求不会自动重放；持续故障可使用页面的“重新加载”。

## 系统更新（0.2.3）

安装本版本后，超级管理员可从「插件管理 → 系统更新」查看宿主核心与插件管理器的官方版本，检查所选升级并确认执行。任务保存进度和恢复入口，刷新或断线后可继续查询；通过健康检查才显示成功，本地修改、插件不兼容或环境未就绪时会阻止升级。

本版本将升级准备纳入框架：用户选择版本后点击「升级所选版本」，核对确认内容后执行。标准宿主的服务重载、运行版本健康检查及专用静态发布基线由框架识别和准备，不要求普通管理员编辑 `reload` / `health` 或执行基线工具。升级到 0.2.3 并完成 Composer 源码发布后生效，部署行为以实际安装版本为准。

框架从宿主实际 HTTP 监听、公共目录及当前主进程识别运行环境；默认使用公共目录下的独立 `admin/`，构建时匹配该静态地址，并通过 HTTP 核对入口页面、JavaScript / CSS 与运行版本。预检只准备目录与清单，实际服务重载发生在确认执行后的任务阶段。已有静态文件只有与当前 `dist` 逐路径、逐摘要一致时才自动建立基线；本地修改、未知文件或进程归属不明会保留现场并给出具体原因，不会自动覆盖。

Linux/macOS 的 PHP CLI 需支持 `proc_open`、`pcntl` 和 `posix`，重载只针对当前 HTTP 服务所属的 Workerman 主进程。Windows 需要 Windows PowerShell 5.1、PHP CLI ≥8.2 与 `proc_open`；框架识别标准 `windows.php` 监督器祖先进程，并解析已安装 Composer PHAR、pnpm / Corepack CLI 为原生 PHP / Node 参数，不执行 `.bat/.cmd`。使用本地盘符目录，不支持 UNC、设备路径或 reparse point。

自定义部署仍可用 [`tools/system-update.config.example.php`](tools/system-update.config.example.php) 提供可信宿主配置，覆盖标准适配；这属于部署扩展，不是后台升级用户的操作步骤。`prepare-system-update.php` 保留为外部部署的只读核对与基线维护工具。标准适配无法确认的自定义进程监督器或外部静态托管不会被猜测为安全可用。

本版本在 macOS 完成自动准备、真实隔离 HTTP、Workerman 主进程归属、原生重载、新运行版本和静态入口资源回归。Windows 10 / PHP 8.2.12 / PowerShell 5.1 的隔离实机验证覆盖独立执行器 61 项、标准监督器自动重载与健康恢复 9 项，以及实际 Composer PHAR / pnpm 原生命令解析与执行；长参数通过临时私有载荷传递，避免 Windows 启动命令长度限制。上述结果来自隔离环境，不代表既有宿主已完成升级。仅允许带有效更新契约、明确无数据库或宿主骨架变更的稳定发行。

升级影响见 [版本更新与升级影响](https://github.com/supdger/sandadmin/wiki/plugin-updates)。既有真实宿主的服务重载、登录业务和故障恢复尚需部署验收；本地验证不能代替这些结果。
