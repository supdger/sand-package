# Sand Package

SandAdmin 的 Composer 插件安装器，包含 PostgreSQL 插件生命周期、仓库获取、恢复能力和对应管理端页面源码。

```bash
composer require supdger/sand-package:^0.1
```

Composer 安装只发布运行源码，不创建数据库、不执行插件数据库迁移，也不启动或重启服务。业务插件继续使用 SandPackage ZIP 安装。

Composer 安装或更新时，Webman 插件安装钩子会在 Sand Core 前端基线上自动发布
SandPackage 管理页面源码，不需要额外执行工具脚本，并拒绝覆盖未知修改。

## 0.1.8 修复范围

修复 Windows 路径导致插件仓库错误显示“需要管理”、点击后管理列表为空的问题，并让无本地管理记录的按钮显示状态原因和刷新入口。旧 catalog 中 SandIAM、SandWorkflow 的预发布 ZIP 在 SandAdmin 0.1.2 上仍不可安装；更新 SandPackage 不会改变这些插件包的兼容范围。
