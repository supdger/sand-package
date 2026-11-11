# AGENTS.md — Sand Package

## 本仓是什么

PHP 库 `supdger/sand-package`：SandAdmin 的 PostgreSQL 插件包管理/安装器，负责插件 ZIP 的安装、升级、恢复语义与管理端页面源码发布，经 Composer 安装进已有宿主。

主要目录：

- `composer.json`：autoload `SandAdmin\Package\` → `server/`、`plugin\sandpackage\` → `server/plugin/sandpackage/`、`Saithink\Saipackage\` → `server/compat/`（上游兼容层）；无 scripts。
- `server/`：`FrontendPublisher.php`、`Install.php`、`PublishExclusions.php`，及 `plugin/sandpackage/`（`app/`、`command/`、`config/`、`tools/`）、`compat/Saithink/Saipackage`、`scripts/build-plugin-package.php`。
- `sandadmin-artd/src/views/`：插件管理页源码（少量 .vue/.ts，无构建配置，由宿主前端工程承载）。
- `tools/`：断言脚本 `assert-*.php` 与 `fixtures/`。
- 本仓无 SQL 文件、无 `tests/` 目录；业务插件的迁移 SQL 由各插件包自带。

## 命名与注释规范

PHP ≥8.2（Webman/Workerman，PSR-12/PSR-4）。完整规范见 `/Users/supdger/agent-work/grokbot/devin-queue/命名与注释规范.md`，要点：

**数据库（PostgreSQL，小写蛇形）**

- 本仓不直接建表；插件包自带迁移时业务表前缀 `sand_<域>_*`，主键 `id`；列名主体 ≤3 词、≤30 字符。
- 布尔 `is_`/`has_`/`can_`/`must_` 或形容词；时间点 `*_time`、日期 `*_date`，固定三列 `create_time`/`update_time`/`delete_time`；数量带单位。
- `status` 表示启停（1 启用 / 2 停用），`state` 表示流转，两者分开；索引约束 `uk_`/`ix_`/`fk_`/`ck_` + 完整表名。

**接口与契约**

- 路径全小写连字符、资源用复数；JSON/查询参数小写蛇形、与列同名。
- 枚举小写蛇形（任务状态 `queued`/`running`/`succeeded`/`failed`/`cancelled`）；错误码大写蛇形；权限码 `域:资源:动作`。
- 迁移文件 `<序号>_<动词>_<对象>`，已发布不改名、不改内容。

**代码**

- 类/组件/类型 PascalCase，函数/变量/属性 camelCase，常量大写蛇形；与列、接口字段一一对应的键保持蛇形原样。
- 函数/方法 ≤4 词且动词开头；不用 Manager/Helper/Util/Processor/Wrapper/Info/Data 收尾；不用 usr/pwd/cnt/msg/btn/tbl/mgr/svc/cfg/val/ret 等自造缩写；变量名不带类型后缀；布尔以 is/has/can/should 开头。
- 前端组件 `PascalCase.tsx`（一文件一主组件，文件名同组件名），hook `useXxx.ts`，测试 `同名.test.ts(x)`。

**注释**

- 只写代码看不出的原因、约束、单位、取值含义；禁逐行翻译、禁「此函数用于……」、禁流水账、禁横幅分隔线、禁表情、禁改动记录、禁注释掉的旧代码。
- 新文件注释用简体中文；TODO 写成 `TODO(负责人或#编号): 原因`。

**统一叫法**（同一概念全仓一个词）：`dept`、`file`、`create_time`/`update_time`/`delete_time`、`status`=启停、`state`=流转、`announcement`、`task`/`task_run`（后台任务）、`schedule`/`schedule_run`（定时任务）、`dict_type`、`audit_log`、`visibility`、`cancelled`（双写 l）、`client`（OAuth 接入方）。

交付前自查：`python3 /Users/supdger/agent-work/grokbot/devin-queue/naming_check.py sql|code|notes|terms ...`（用法见脚本开头）。

## 提交规矩

- 只 `git add` 具体文件，禁止 `git add -A` / `git add .`。
- 不提交 `.env`、密钥、token、`glm.key` 等敏感文件。
- 不 force push、不 `reset --hard`、不用 `--no-verify`；不合并自己的 PR。

## 测试和检查命令

- `php tools/assert-fd-isolation.php`、`php tools/assert-full-restart-semantics.php`、`php tools/assert-publish-exclusions.php`（直接执行）。
- `php server/scripts/build-plugin-package.php`（插件包构建；无参数说明见脚本头注释）。
- `php -l <file>` 语法检查；无 phpunit、无 tests/、无 CI。
- `sandadmin-artd/` 仅插件视图源码，无独立构建；由宿主前端工程承载。

## 许可证

MIT（根 LICENSE，含 saithink 双版权行；NOTICE 声明含衍生自 saipackage 与 saiadmin6.x 前端视图的代码）。
