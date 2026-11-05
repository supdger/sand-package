# Sand Package

SandAdmin 的 Composer 插件安装器，包含 PostgreSQL 插件生命周期、仓库获取、恢复能力和对应管理端页面源码。

## 源码与安装

本仓提供插件安装器及管理端页面源码。已有 SandAdmin 宿主通过 Composer 安装本包；[开发源码](https://github.com/supdger/sand-package/tree/main)与 GitHub 的 Source code 压缩包供开发使用。业务插件的完整 ZIP 由本安装器管理，不能把安装器源码压缩包当作业务插件上传。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](CHANGELOG.md)保留历史记录。下载见 [最新稳定版](https://github.com/supdger/sand-package/releases/latest)；预发布及全部版本见 [公开发行](https://github.com/supdger/sand-package/releases)。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

本版本要求 [Sand Core 0.2.4](https://github.com/supdger/sand-core)。建议先完成其前端源码发布再安装本包；core 前端基线未就绪时，本包只安装后端载荷并给出告警，不中止 Composer，可在基线就绪后补发前端。包依赖下载不等于前端基线已发布。

```bash
composer require 'supdger/sand-package:>=0.2.14'
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

Composer 安装或更新时，Webman 插件安装钩子先安装后端载荷，再在 Sand Core 前端基线上发布 SandPackage 管理页面源码，不需要额外执行工具脚本。前端发布的任何失败（core 基线未就绪、文件冲突、写盘失败）都降级为告警，不中止 Composer、不回滚已安装的后端载荷；告警带固定前缀 `[sandadmin]` 和恢复命令 `composer run-script sand:publish`（由宿主 Sand Core 提供）。

前端目标目录已存在同名文件时按内容判定：与发布源逐字节相同视为已发布并补写清单；内容不同才拒绝，并列出具体文件。已登记且被本地修改的文件在更新时跳过覆盖并汇总报告，新版本不再提供的文件按原清单哈希校验未改动后才删除。

| 环境变量 | 取值 | 作用 |
| --- | --- | --- |
| `SAND_FRONTEND_PUBLISH` | 未设置 | 默认行为，按清单发布 |
| `SAND_FRONTEND_PUBLISH` | `skip` | 跳过前端发布，只安装后端载荷 |
| `SAND_FRONTEND_PUBLISH` | `force` | 跳过修改校验、强制重发布并重建清单（丢弃本地前端改动） |

前端目标目录优先取宿主 `composer.json` 的 `extra.sandadmin.frontend-path`；未声明时回退“宿主目录名为 `server` 则取同级 `sandadmin-artd`”的启发式。目标必须位于宿主项目父级目录之内且路径不含符号链接，否则拒绝发布并提示声明该配置。

后端载荷 `plugin/sandpackage/config/` 仅首次安装写入，后续更新不覆盖宿主定制。卸载只移除安装记录登记且内容未变的文件及因此变空的目录，未登记或已修改的文件一律保留并在告警中列出。

发布成功后，宿主应有 `server/plugin/sandpackage/`，前端目录应有 `.sand-package-source-manifest.json` 和 `src/views/plugin/sandpackage/`。这只确认源码发布；服务启动、登录及 ZIP 插件安装另按[宿主首次安装指南](https://github.com/supdger/sandadmin/wiki/getting-started)和[仓库插件分发](https://github.com/supdger/sandadmin/wiki/repository-plugin-distribution)操作。管理端运行条件沿用 [Sand Core](https://github.com/supdger/sand-core) 的 Node.js/pnpm 要求和宿主锁文件。

## 插件 ZIP 容量

上传与仓库下载使用同一压缩 ZIP 上限：16MiB（16,777,216 bytes）。宿主 `plugin.sandpackage.upload.size` 可设置为更小的正整数 bytes；配置无效或超过 16MiB 时明确拒绝，不回退默认值。构包工具同样限制为 16MiB，无需增加参数。

HTTP 请求大小与 ZIP 容量分别受限。宿主 HTTP 请求包络需容纳 ZIP 与 multipart 开销（例如 20MiB）；本包不会修改宿主配置。超过限制时显示实际接收的 `actual` 与生效的 `max` bytes，下载中断不把已接收长度视为完整文件。解压后仍限制 64MiB、2048 条目，并校验路径、CRC、摘要与声明载荷。

## 插件依赖版本

插件依赖声明中的 `version` 是已安装插件的最低版本；有效的相同或更高版本直接复用，不降级或重装。随包 ZIP 的声明版本、SHA 和元数据身份仍须精确匹配，未安装的待安装候选也须与随包版本和 SHA 一致。版本低于下限、格式无效或候选身份不符时，在当前插件 SQL 之前拒绝。

安装返回“等待依赖安装”时，服务重载延后到最后一个依赖任务成功登记之后；安装时选择不重载会保留该选择，依赖命令的完成顺序不会改变它。依赖失败保持等待状态。

## 系统更新

超级管理员可从「插件管理 → 系统更新」查看宿主核心与插件管理器的官方版本，检查所选升级并确认执行。任务保存进度和恢复入口，刷新或断线后可继续查询；通过健康检查才显示成功，本地修改、插件不兼容或环境未就绪时会阻止升级。标准宿主的服务重载、运行版本健康检查和专用静态发布基线由框架识别和准备，不要求管理员编写 `reload` / `health` 脚本。

已有静态文件只有与当前 `dist` 逐路径、逐摘要一致时才自动建立基线；本地修改、未知文件或进程归属不明会保留现场并给出具体原因，不自动覆盖。

服务动作：`reload`（SIGUSR1）只轮换可重载的 HTTP worker，**不会**重读 `config/process.php`；含自定义进程的安装/升级走 `full_restart`（`php start.php restart` 语义）。旧接口名 `Server::restart()` 实际是 reload，请改用 `Server::reload()` / `Server::fullRestart()`。

运行要求：Linux/macOS 的 PHP CLI 需支持 `proc_open`、`pcntl` 和 `posix`，重载只针对当前 HTTP 服务所属的 Workerman 主进程；Windows 需要 Windows PowerShell 5.1、PHP CLI ≥8.2 与 `proc_open`，使用本地盘符目录，不支持 UNC、设备路径或 reparse point。标准适配无法确认的自定义进程监督器或外部静态托管不会被当作安全可用。

## 恢复入口

安装、升级或依赖任务中断时，先在宿主 `server/` 目录用 `inspect` 命令检查现场，再按输出的 `actions` 和本次 `fingerprint` 执行对应恢复命令。确认文本绑定本次检查结果；现场变化、断线或重新检查后旧确认失效，不能跨检查点重用。

| 场景 | 检查命令 | 恢复动作与确认文本 |
| --- | --- | --- |
| 已安装插件的升级包已上传、尚未开始生命周期 | `php webman sandpackage:recover inspect-candidate <插件标识>` | `discard-candidate`，`DISCARD <插件标识> <fingerprint>` |
| 首次安装的候选已上传、尚未开始生命周期 | `php webman sandpackage:recover inspect-fresh-candidate <插件标识>` | `discard-fresh-candidate`，`DISCARD-FRESH <插件标识> <fingerprint>` |
| 宿主应用载荷升级在 SQL 开始前因文件冲突失败 | `php webman sandpackage:recover inspect-host-upgrade <插件标识>` | `restore-host-upgrade`，`RESTORE <插件标识> <fingerprint>` |
| 依赖任务进程中断、失去回执 | `php webman sandpackage:recover inspect-dependency <插件标识>` | `recover-dependency`，`RECOVER DEPENDENCY <插件标识> <fingerprint>`；仅请求重载时另加 `--restart` |

只执行本次 `actions` 中出现的动作。收尾类动作（`finish-journal`、`finish-dependency-cleanup`）只完成已有记录，`restart-only` 按账本意图请求重载或完整重启（`dependency_service_action` / `process_registration_pending`），`refresh-provider-binding` 只更新已验证提供方的执行绑定；它们都不改变已恢复的状态。

对应的超级管理员 API 为 `POST /app/sandpackage/install/` 下的 `inspectCandidate`、`discardCandidate`、`inspectFreshCandidate`、`discardFreshCandidate`、`inspectHostUpgradeRecovery`、`inspectDependencyRecovery`、`recoverDependency`，均传 `appName`，恢复另传完整 `confirmation`，重载动作另传 `restart=1`。

各入口的边界：

- 撤回候选只恢复当前插件的旧安装包与登记（`version` 回到升级前版本、`state=1`），保留原候选和备份，不执行生命周期 SQL、不修改宿主载荷、运行目录、依赖或外部文件，也不重载服务、不回滚其他依赖插件。首次安装候选使用上表的 fresh-candidate 入口。数据库身份与结构只绑定本次检查和执行，不证明上传前数据库未变化。
- `sql_not_started` 只允许回滚旧候选，不允许继续升级，不执行升级 SQL，也不解除文件冲突；消费方须按自身权限处理冲突后再重试升级。已提交 SQL 的失败使用依赖恢复入口，不重跑已提交 SQL。
- 依赖恢复只在原进程确已结束时归档占用并回到依赖等待状态，不证明原命令成功，不撤销 Composer 的部分效果，不重跑依赖、插件安装或 SQL，也不恢复或改写 Composer 清单、锁文件及已安装依赖。`signal_requested` 只表示已发出重载请求，不代替 worker 轮换和实际运行验收。
- Unix 进程检查与 CLI 重载需要 POSIX 扩展、`/usr/bin/pgrep` 与 `/bin/ps`；无法证明原进程退出的平台会拒绝恢复，应改用宿主正式服务控制入口。未知进程、活进程、身份可能复用、权限不明、记录损坏及载荷或依赖漂移同样拒绝，不能手动删除标记或更改登记。

## 依赖命令

生产环境不提供 HTTP 在线终端，也不提供浏览器里的依赖安装按钮。`/app/sandpackage/index/terminal` 与 `POST /app/sandpackage/install/inspectDependencyStart` 均未注册，访问结果为 404。页面不再发起这些请求。

依赖安装在宿主 `server/` 目录、由独立 CLI 进程执行。命令键只有 `composer.update`、`web-install.npm`、`web-install.yarn`、`web-install.pnpm`，不能传入任意 shell：

```bash
php webman sandpackage:dependency run <插件标识> composer
php webman sandpackage:dependency run <插件标识> npm
php webman sandpackage:dependency run <插件标识> npm --manager yarn
php webman sandpackage:dependency inspect composer.update
```

`npm` 任务默认使用 pnpm。`inspect` 只打印当前 CLI 进程的函数与配置提示，不创建锁、任务或子进程。中断后的现场检查与恢复仍使用上一节的 `php webman sandpackage:recover inspect-dependency` / `recover-dependency`，该恢复命令不重跑 composer 或 npm。

## 依赖任务诊断

依赖命令报错时，公开反馈包含固定错误阶段和独立记录号。管理员可用记录号在宿主现有 Webman 日志中查找 `SandPackage dependency command failed`；日志只记录阶段、异常类型、包内源码位置、进程与耗时，不记录异常原文、命令参数、环境、nonce 或带参数的调用栈。记录号不是恢复确认或任务凭据。

`php webman sandpackage:dependency inspect` 返回的 `provider_identity` 只读比较当前 CLI 进程的 Composer 运行元数据、磁盘锁定/安装记录与宿主要求。`status=unavailable` 或字段为 `null` 表示无法获得可信提示；`identity_matches` 只反映这些身份比较，不证明完整恢复资格、源码字节、候选或锁/写入/启动成功，当前不一致也不能证明过去的异常原因。

## 逐版本变化与验证范围

上述能力分别在不同版本引入或修复。逐版本的变化、已完成的验证项和升级影响见 [CHANGELOG](CHANGELOG.md) 与[版本更新与升级影响](https://github.com/supdger/sandadmin/wiki/plugin-updates)。

已有宿主需要升级本包才能获得对应修复；发行本身不会升级、重载或以其他方式操作既有部署。隔离环境与本地回归的结果不代替目标宿主的服务重载、登录业务和部署验收，这两类结果分别记录。

## 文档、许可与反馈

详细配置和操作见 [Wiki](https://github.com/supdger/sandadmin/wiki/Home)。[Sand Package：应用载荷、schema 重接与兼容声明](https://github.com/supdger/sandadmin/wiki/package-reference)记录对应能力及限制；安装器与宿主使用分别按所选版本核对。

本包以 [MIT License](LICENSE) 发行。问题与建议请提交到 [Issues](https://github.com/supdger/sand-package/issues)。
