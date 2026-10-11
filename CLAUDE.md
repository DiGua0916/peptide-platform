# Claude 后端开发入口

先读取 AGENTS.md，并遵守共同开发约定。你负责本项目的 WordPress 后端业务插件；Codex 负责主题、页面和交互。

首次接手时完整阅读 docs/CLAUDE_HANDOFF.md。它说明项目背景、当前状态、双方分工、D 盘目录和 BE-01 的交付要求。

## 每次任务必须更新项目聊天室

本项目新增 docs/CHATROOM.md 中的协作约定。开始工作前读取主仓库的最新消息；每次完成项目工作后，在最终回复用户之前，以 Claude 署名追加进度，写明完成内容、修改文件、实际验证、剩余问题和下一步，并重新读取确认写入成功。

唯一记录：D:/Codex/Projects/peptide-platform/docs/chat/ROOM.md。使用主仓库 tools/chatroom/room.py 的 tail / post 命令；不要在 backend 工作目录另建聊天记录。网页只是 Markdown 的本地展示，不会自动触发回复。这项约定仅在多肽项目中生效。

用户于 2026-10-11 另外授权 CRK1 口令接入其他项目群聊。用户明确给出口令并绑定当前会话时，改用 D:/Codex/Projects/collaboration-chatrooms/room.py，所有 tail / post 显式带 --room <口令>，只写该群的独立文档，不再重复写入默认多肽群。其他未接入的项目不自动汇报。详见 docs/CHATROOM.md。

## 工作范围

- 工作分支：claude/backend。
- 主要代码：wp-content/plugins/peptide-core/。
- 交接记录：docs/handoffs/backend.md。
- 共享依据：docs/REQUIREMENTS.md、docs/CONTRACT.md、docs/TASKS.md。
- 技术文档和项目沟通使用中文；面向海外访客的页面文案按最终需求确定。

## 首个任务

以下内容可以作为 Claude Code 的首条任务：

> 请读取 AGENTS.md、CLAUDE.md、docs/CLAUDE_HANDOFF.md 和其中列出的项目文档。首先检查当前目录、分支和文件状态，完成 BE-01：在 docs/handoffs/backend-plan-v1.md 中提出后端架构、产品与批次及检测报告的数据组织方案、WooCommerce 的取舍、接口草案和分阶段计划，并更新 docs/handoffs/backend.md。区分已确定、假设和待确认内容。当前先完成方案，不安装依赖、不接入支付、不发布网站、不修改前端主题。按交接说明中的格式报告文件改动、实际检查和提交标识；无法访问本地目录时说明限制并输出方案正文。

## 后续实现原则

将业务逻辑保留在插件内，使用 WordPress 的用户、权限和媒体机制。涉及写操作、查询权限或报告访问时，在服务端做相应验证。不要把管理员凭据或服务端密钥发给浏览器。

不要自行更改公共字段、路由或返回格式。将变更建议写入交接记录，再由集成流程更新共同契约。

Claude Code 需要单独安装、登录并获得本地项目访问权限。本文件不是已经建立连接或自动启动任务的证明。

## 成员移出或群解散的汇报例外

读取时在 tail 中带 --author 自己的身份。若返回 membership=removed、room_not_member、status=dissolved 或相应停止提示，解除本会话绑定并停止向该群汇报；此例外优先于本项目的默认汇报要求。保留原已授权的项目工作，不改绑其他群、不重建群，不因发言失败就自动加入。用户重新邀请后才执行 join --code <完整口令> --author <自己的身份>。省略 --author 的 join 只读，不登记入群。
