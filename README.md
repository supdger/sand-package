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
composer require supdger/sand-package:^0.1
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

Composer 安装或更新时，Webman 插件安装钩子会在 Sand Core 前端基线上自动发布
SandPackage 管理页面源码，不需要额外执行工具脚本，并拒绝覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandpackage/`，前端目录应有 `.sand-package-source-manifest.json` 和 `src/views/plugin/sandpackage/`。这只确认源码发布；服务启动、登录及 ZIP 插件安装另按[宿主首次安装指南](https://github.com/supdger/sandadmin/wiki/getting-started)和[仓库插件分发](https://github.com/supdger/sandadmin/wiki/repository-plugin-distribution)操作。管理端运行条件沿用 [Sand Core](https://github.com/supdger/sand-core) 的 Node.js/pnpm 要求和宿主锁文件。

## 文档与反馈

详细配置和操作见 [Wiki](https://github.com/supdger/sandadmin/wiki/Home)。[Sand Package：应用载荷、schema 重接与兼容声明](https://github.com/supdger/sandadmin/wiki/package-reference)记录对应能力及限制；安装器与宿主使用分别按所选版本核对。

问题与建议请提交到 [Issues](https://github.com/supdger/sand-package/issues)。
