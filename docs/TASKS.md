# 任务清单

| 编号 | 负责人 | 任务 | 状态 |
| --- | --- | --- | --- |
| SETUP-01 | Codex | 初始化本地仓库、协作规则、角色分支与工作目录 | 已完成（验收项如下） |
| HANDOFF-01 | Codex | 完整项目交接说明和给 Claude 的首次任务消息 | 已完成 |
| SPEC-01 | 用户 + Codex | 确认比赛、产品用途、客户、资源和模板 | 待确认 |
| BE-01 | Claude | 提出产品/批次/报告存储及接口方案 | 待启动 |
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
