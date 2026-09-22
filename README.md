# Sand Package

SandAdmin 的 Composer 插件安装器，包含 PostgreSQL 插件生命周期、仓库获取、恢复能力和对应管理端页面源码。

```bash
composer config repositories.sand-core vcs https://github.com/supdger/sand-core
composer config repositories.sand-package vcs https://github.com/supdger/sand-package
composer require supdger/sand-package:^0.1
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

在 Sand Core 前端源码发布后，叠加 SandPackage 管理页面源码：

```bash
php vendor/supdger/sand-package/tools/publish-frontend.php ../sandadmin-artd
```
