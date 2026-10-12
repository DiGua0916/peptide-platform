# 多肽独立站 · 协作仓库

面向省级技能大赛的海外独立站项目。平台确定采用自托管 WordPress；Codex 负责前端主题与交互，Claude 负责后端业务插件。

主仓库现在位于 `D:\Codex\Projects\peptide-platform`。前后端独立工作目录位于 `D:\Codex\Projects\peptide-platform-worktrees` 下。

## 当前状态

- 已准备 Git 仓库、分工约定、需求基线和交接文档。
- 初始化后使用 main 作为集成分支，codex/frontend、claude/backend 分别开发。
- 品牌 VialCove，采用用户购买的 Lunira 视觉；WordPress/WooCommerce 与 peptide-core 0.3.0 已在 LocalWP 运行，主题已联调。
- 本地预览：http://vialcovepeptidescom.local/；商品、批次、报告仍为演示数据。仅美国的测试付款/配送已联调，真实收款、运费和正式部署待办。
- 有项目 Markdown 聊天室；显式提及可经独立助手会话回复（见 docs/CHATROOM.md），不代表自动接管双方正在开发的桌面任务。
- 当前仍是本地 Git；尚未配置 GitHub 远程仓库。

## 目录

| 目录或文件 | 用途 |
| --- | --- |
| AGENTS.md | 共同开发约定及 Codex 工作边界 |
| CLAUDE.md | Claude 后端工作入口 |
| docs/CLAUDE_HANDOFF.md | 给 Claude 的完整项目背景与首次任务 |
| docs/REQUIREMENTS.md | 已确认需求与待确认事项 |
| docs/CONTRACT.md | 当前实现的数据与接口契约 |
| docs/WORKFLOW.md | 独立工作目录、提交和联调流程 |
| docs/TASKS.md | 当前任务和验收条件 |
| docs/CHATROOM.md | 项目聊天室使用方法和每次任务后的进度规则 |
| tools/chatroom/ | Markdown 聊天室服务、界面和追加工具 |
| docs/handoffs/ | 双方分别维护的交接记录 |
| wp-content/themes/peptide-site/ | Codex 负责的 VialCove 主题源码 |
| wp-content/plugins/peptide-core/ | Claude 负责的业务插件源码 |

主题和插件通过目录联接挂到开发站点。数据库与运行文件不进入 Git，环境说明见 docs/WORKFLOW.md。

## 从哪里开始

1. 先读 docs/REQUIREMENTS.md、docs/CONTRACT.md。
2. 按 docs/WORKFLOW.md 在自己的工作目录工作。
3. 只修改当前任务负责的文件；接口变更先记录再同步。
4. 每个任务完成后，先向主仓库 docs/chat/ROOM.md 追加进度并确认保存，再回复用户。操作见 docs/CHATROOM.md。

双击主仓库“打开项目聊天室.cmd”可打开本项目的聊天页面，默认地址 http://127.0.0.1:17832。记录固定保存到主仓库的 docs/chat/ROOM.md，两个开发目录共用这一份文件。

Claude 首次任务已完成；后续读取 docs/TASKS.md、当前 CONTRACT 和最新角色交接，不再重复初始化。

## 开发环境与数据

使用 D 盘 LocalWP，双方已协调共用当前开发站点。WordPress 核心、数据库、上传文件、真实客户数据和凭据不纳入 Git。演示数据明确标注；实验室、工厂和美国仓库具体资料以用户提供的信息为准。

## 模板与许可

用户已购买并提供 Lunira 源码；商业 ZIP 保存在 Git 外。主题复用素材及外部依赖详见主题 README，正式使用前核对购买授权范围。Genesis Lab 是早期参考。项目尚未指定开源许可证。

## Git

首次提交由 Codex 使用临时提交身份完成，不更改用户的全局 Git 身份。后续人工提交可单独设置本仓库的 user.name 和 user.email。
