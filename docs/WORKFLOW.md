# 开发与交接流程

## 三个工作目录

主仓库：`D:\Codex\Projects\peptide-platform`。

前端工作目录：`D:\Codex\Projects\peptide-platform-worktrees\frontend`。

后端工作目录：`D:\Codex\Projects\peptide-platform-worktrees\backend`。

用户已指定此后新项目和任务一律使用 D 盘。该项目已从 C 盘迁移，后续不要使用旧路径。

| 角色 | 分支 | 职责 |
| --- | --- | --- |
| 集成 | main | 共同需求、代码合并和联调 |
| Codex | codex/frontend | 主题、页面、交互 |
| Claude | claude/backend | 业务插件、数据和权限 |

运行 git worktree list 可查看这台电脑上的绝对路径。它们是同一 Git 仓库的不同工作副本，不会实时共享未提交文件。Git 工作目录也不会自动共享 WordPress 数据库。

## 开始任务前

1. 用 git status --short --branch 检查分支和改动，并按 docs/CHATROOM.md 读取主仓库中的最新聊天室记录。
2. 读取共同需求、契约、任务清单和自己负责的交接文档。
3. 确定本次负责的文件和验收条件。
4. 如共同契约有更新，先由集成负责人同步到自己的开发分支。

## 完成任务时

1. 检查修改范围，执行适当验证。
2. 更新自己的交接记录，说明实际运行和未运行的检查。
3. 仅提交本任务文件，不覆盖另一方改动。
4. 将提交标识交给集成负责人，由其在 main 合并。
5. 在双方协调的 LocalWP 开发站点验证主题和插件，再同步共同基线。
6. 在最终回复用户前，按 docs/CHATROOM.md 向唯一的主仓库记录追加任务进度，并重新读取确认。无法完成时记录实际进度与阻塞，不写成已完成。

共享文档变更需协调，避免两边独立修改同一契约。不要在有未提交改动时切换、重置或重建另一方的工作目录。

## Claude 怎么加入

在 Claude Code 中打开 backend 的绝对路径，读取当前任务和最新 handoff；首次方案任务已完成。只使用普通聊天时，通过任务文档与文件交接。

通过主仓库的 Markdown 聊天室共享进度。显式提及可在独立助手会话回复（见 CHATROOM.md）；既有桌面开发任务不被自动接管，需要该任务读取记录。按当前会话明确绑定的口令使用中心 helper，不跨群复制。

## 运行环境

源码仓库与运行站点分开。2026-10-12 Claude 在 INT-01 明确同意共用 LocalWP 开发站点（覆盖早期分别建库的建议）：

- 站点目录：D:/Codex/Sites/vialcovepeptidescom/app/public；访问 http://vialcovepeptidescom.local/。
- 主题目录联接指向 frontend 工作树；业务插件目录联接指向 backend 工作树。main 是源码集成副本，不是另一套数据库。
- WordPress 7.1.3；PHP 8.3.29 的运行程序在 D:/Codex/LocalWP/data/lightning-services/，应用在 D:/Codex/LocalWP/app。CLI 测试配置存 D:/Codex/Tasks/peptide-platform/work/frontend-cli.ini，不修改站点运行 ini。
- 数据库/后台设置改动先在群内协调，测试只删除本轮创建且能准确识别的记录，禁止 demo purge。既有订单 #54 和退款 #55 保留。
- 前端联调用临时顾客及其独立订单；临时设置测试后恢复，测试邮件由 pre_wp_mail 拦截。报告和凭据不进入 Git。
- 原版 Lunira 静态预览 17842 与 WordPress 独立；本机 .local 域名不代表手机可直接访问。

## 远程仓库

当前只建立本地 Git，不依赖 GitHub。后续可在确定账号和可见性后配置远程仓库；不要将密钥、真实客户资料或无权再分发的商业源码推送上去。
