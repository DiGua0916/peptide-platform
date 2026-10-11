# 任务清单

| 编号 | 负责人 | 任务 | 状态 |
| --- | --- | --- | --- |
| SETUP-01 | Codex | 初始化本地仓库、协作规则、角色分支与工作目录 | 已完成（验收项如下） |
| HANDOFF-01 | Codex | 完整项目交接说明和给 Claude 的首次任务消息 | 已完成 |
| ROOM-01 | Codex | 项目专用 Markdown 聊天室、追加工具和任务进度规则 | 已完成；读写测试与浏览器检查通过 |
| SPEC-01 | 用户 + Codex | 确认比赛、产品用途、客户、资源和模板 | 待确认 |
| BE-01 | Claude | 提出产品/批次/报告存储及接口方案 | 后端分支已提交方案，待评审；未实现 |
| FE-01 | Codex | 根据已确认业务确定页面结构和设计方向 | 待业务资料 |
| ENV-01 | 集成负责人 | 选择并运行本地 WordPress 环境 | 待安排 |
| INT-01 | 双方 | 基于确认的契约完成首条端到端流程 | 待前置任务 |

## SETUP-01 验收

- main 有初始化提交。
- codex/frontend 和 claude/backend 有各自的工作目录。
- git status 显示三个工作目录干净。
- git remote -v 无输出，表示尚未创建远程仓库。
- 凭据、数据库和上传文件被 .gitignore 排除。

## BE-01 验收

完整要求见 docs/CLAUDE_HANDOFF.md。后端方案写入 docs/handoffs/backend-plan-v1.md，并在 docs/handoffs/backend.md 记录交接；内容应包含架构和数据方案、WooCommerce 取舍、接口草案、权限建议、最小流程、实施顺序、验证方式及最多三个关键问题。当前先做方案，不自动引入依赖或真实支付。

## ROOM-01 验收

- 共享记录在主仓库 docs/chat/ROOM.md，前后端都向它追加。
- 本地界面支持用户留言、作者筛选、搜索和 Markdown 原文。
- 10 项读写与 HTTP 测试通过；JavaScript 语法检查通过。
- 浏览器实测消息同步、搜索、筛选、原文、390 像素宽度布局；隔离记录中实测网页发送和刷新后保留。
- AGENTS.md / CLAUDE.md 规定每次实际项目任务结束后、最终回复前追加并核对工作进度。仅此项目生效。
- 这只是本地协作工具；尚无自动 AI 回复，也不代表 WordPress 环境已运行。
