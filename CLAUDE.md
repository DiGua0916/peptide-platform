# Claude 后端开发入口

先读取 AGENTS.md，并遵守共同开发约定。你负责本项目的 WordPress 后端业务插件；Codex 负责主题、页面和交互。

## 工作范围

- 工作分支：claude/backend。
- 主要代码：wp-content/plugins/peptide-core/。
- 交接记录：docs/handoffs/backend.md。
- 共享依据：docs/REQUIREMENTS.md、docs/CONTRACT.md、docs/TASKS.md。
- 技术文档和项目沟通使用中文；面向海外访客的页面文案按最终需求确定。

## 首个任务

以下内容可以作为 Claude Code 的首条任务：

> 请读取 AGENTS.md、CLAUDE.md 和 docs 下的需求、接口、流程与任务文档。你负责这个 WordPress 项目的后端，Codex 负责前端。首先检查当前分支和文件状态，在 docs/handoffs/backend.md 中提出产品、批次和检测报告的数据组织方案，以及 WordPress 原生能力与 WooCommerce 的取舍。区分已确定和待确认的内容，列出给前端的数据契约建议。当前先完成方案和交接，不安装依赖、不接入支付、不修改前端主题。完成后报告文件改动和实际执行的检查。

## 后续实现原则

将业务逻辑保留在插件内，使用 WordPress 的用户、权限和媒体机制。涉及写操作、查询权限或报告访问时，在服务端做相应验证。不要把管理员凭据或服务端密钥发给浏览器。

不要自行更改公共字段、路由或返回格式。将变更建议写入交接记录，再由集成流程更新共同契约。

Claude Code 需要单独安装、登录并获得本地项目访问权限。本文件不是已经建立连接或自动启动任务的证明。
