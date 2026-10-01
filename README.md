# Sand Package

SandAdmin 的 Composer 插件安装器，包含 PostgreSQL 插件生命周期、仓库获取、恢复能力和对应管理端页面源码。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

先完成 [Sand Core](https://github.com/supdger/sand-core) 的前端源码发布，再安装本包；包依赖下载不等于前端基线已发布。

```bash
composer require supdger/sand-package:^0.1
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

Composer 安装或更新时，Webman 插件安装钩子会在 Sand Core 前端基线上自动发布
SandPackage 管理页面源码，不需要额外执行工具脚本，并拒绝覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandpackage/`，前端目录应有 `.sand-package-source-manifest.json` 和 `src/views/plugin/sandpackage/`。这只确认源码发布；服务启动、登录及 ZIP 插件安装另按[宿主首次安装指南](https://github.com/supdger/sandadmin/wiki/getting-started)和[仓库插件分发](https://github.com/supdger/sandadmin/wiki/repository-plugin-distribution)操作。管理端运行条件沿用 [Sand Core](https://github.com/supdger/sand-core) 的 Node.js/pnpm 要求和宿主锁文件。

版本变化见 [CHANGELOG](CHANGELOG.md) 和[发布记录](https://github.com/supdger/sand-package/releases)。

## 0.1.10-rc.1 集成验证候选

本预发布版本供 SandAI 集成验证使用，尚未完成真实 PostgreSQL、HTTP 与宿主业务验收。Windows 定向检查使用原生 NTFS，数据库使用录制连接；UNC 未连接真实共享。

如需测试本候选，在已有宿主的 `server/` 目录执行：

```bash
composer require supdger/sand-package:0.1.10-rc.1 --with-all-dependencies
```

依赖更新会沿用上面的 Webman 发布钩子。确认 `composer show supdger/sand-package` 为 `0.1.10-rc.1`，再单独安排目标宿主的安装、升级、恢复和卸载验证；依赖更新不会执行这些数据库动作。

真实宿主验证完成前，稳定用户继续消费已发布的稳定版本。

## 受控宿主应用载荷（集成验证候选）

插件 ZIP 可通过根 `host-payload.json` 精确声明随包交付的 `app/**` 和 `config/sand_ai_*.php` 文件及 SHA-256。安装器校验清单与 ZIP 实际文件一致，检查目标归属和冲突，并在安装、升级、卸载及失败恢复中处理这些文件。没有清单的普通插件继续按原有目录安装；不能从源码拉取或手工复制宿主文件来冒充安装结果。

这项能力目前仅完成源码与录制式生命周期验证，尚未经过真实宿主 PostgreSQL、HTTP、升级及卸载验收。Composer 依赖安装与数据库初始化仍是不同操作；本包不选择或创建 SandAdmin 数据库。

## 已有 PostgreSQL schema 重接

插件可以在 ZIP 根目录附带 `existing-schema.json`，声明对应版本的 `install.sql` 摘要、迁移账本、表集合与 PostgreSQL 结构指纹。普通安装仍走原有路径。已有数据库必须使用专门的重接入口，不能重放 `install.sql`。

1. 上传插件 ZIP，保留待安装候选。
2. 超级管理员调用 `POST /app/sandpackage/install/inspectExistingSchemaAttach`，提交 `appName` 和当前数据库名 `database`。预检核对候选包、数据库身份、迁移账本、表集合和结构指纹，返回绑定这些内容的 `confirmation`。
3. 超级管理员调用 `POST /app/sandpackage/install/attachExistingSchema`，提交相同 `appName`、`database` 和完整 `confirmation`。安装器重新核对后部署文件并登记插件，全程不执行 `install.sql`；完成前再次核对数据库结构。此入口不重启服务，服务重载需在另行授权的宿主操作中完成。

重接登记带有 `existing_schema_attached` 标记。普通卸载和自动异常清理会拒绝删除接管前就存在的数据。重接文件采用逐文件事务，部分复制可在核对摘要后继续，外部改动会拒绝。失败后由超级管理员调用 `POST /app/sandpackage/install/inspectExistingSchemaAttachRecovery`（`appName`），取得绑定当前候选、数据库和文件现场的 `confirmation`，再调用 `POST /app/sandpackage/install/continueExistingSchemaAttach`（`appName`、`confirmation`）；管理页面提供相同操作。此恢复只处理没有额外 `host-payload.json` 的重接包，不执行数据库脚本或服务重载。预检是数据库只读的，不能代替真实宿主上的完整安装与业务验收。

## 插件宿主版本声明

ZIP 根 `info.ini` 的 `support` 使用实际 SandAdmin 宿主版本：`>=0.1.0` 声明包含最低版本，可写 `>=0.1.0 <=0.2.0` 加包含最高版本。上下界使用稳定三段版本；无上界时达到最低版本的未来主版本也满足声明。历史 `6.x`、`0.1.x` 和 `|` 分支并集仍可使用，`6.x` 只匹配 6 主版本。

非法声明、缺失宿主版本或并集中任一非法分支均拒绝。宿主 build 信息不影响判断，`0.1.0-rc.1` 尚未达到 `>=0.1.0`。后端安装在处理插件依赖或数据库脚本前检查声明，管理页面同样检查；插件作者应同时更新发布包与 catalog 的范围。

本地行为检查：`php server/tests/SandPackage/HostVersionCompatibilityTest.php`、`php server/tests/SandPackage/HostCompatibilityInstallPreflightTest.php`。前端使用同一 JSON 数据集核验真实 helper，源码检查不代表用户宿主已更新或完成真实安装。
