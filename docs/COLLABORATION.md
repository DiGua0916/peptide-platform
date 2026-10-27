# 多人协作约定（GitHub）

2026-10-27 起，项目放在 GitHub 私有仓库 https://github.com/DiGua0916/peptide-platform ，由仓库主人 DiGua 邀请协作者一起开发。协作者也用 Claude，所以同一个项目里会有好几个“Claude”。本文件用来分清谁是谁、谁改哪里，避免互相覆盖。

## 参与者

| 谁 | 在哪里工作 | 工作分支 | 负责 |
| --- | --- | --- | --- |
| DiGua（仓库主人） | 自己的电脑 | — | 决定分工；审核并合并 Pull Request |
| Codex | DiGua 电脑 `D:\Codex\Projects\peptide-platform-worktrees\frontend` | `codex/frontend` | 主题 `wp-content/themes/peptide-site`、页面和交互 |
| 后端 Claude | DiGua 电脑 `D:\Codex\Projects\peptide-platform-worktrees\backend` | `claude/backend` | 插件 `wp-content/plugins/peptide-core` |
| 协作者 EvanCarter017 和他的 Claude | 协作者自己的电脑 | `<名字>/<任务>`，例如 `evan/work` | 由 DiGua 指定 |

## 给 Claude：先判断自己是谁

- 工作目录是 `D:\Codex\Projects\peptide-platform-worktrees\backend`：你是**后端 Claude**，按 CLAUDE.md 原有的规则工作。
- 其他任何位置（包括 `tools/collab/peptide-sync.bat` 下载的目录；这时 `git config peptide.role` 是 `collaborator`）：你是**协作者的 Claude**。按下面的“协作者规则”工作，不要用后端 Claude 的身份、分支和群聊规则。

## 协作者规则

1. **只在自己的分支上改**：分支名是 `<名字>/<任务>`。`peptide-sync.bat` 第一次运行时会建好 `<名字>/work`。不要提交到 `main`、`claude/backend`、`codex/frontend`。
2. **做完一件事**：提交，推送自己的分支，在 GitHub 上向 `main` 提 Pull Request。写清楚改了什么、怎么验证的、还有什么没做。由 DiGua 审核合并。
3. **署名**：提交用自己的名字（`peptide-sync.bat` 已经在这个项目里设好 `user.name`）。不要用 DiGua、Claude 或 Codex。
4. **交接记录**：写在 `docs/handoffs/<名字>.md`。不要改 `docs/handoffs/backend.md` 和 `docs/handoffs/frontend.md`。
5. **别人负责的地方**：
   - 插件 `wp-content/plugins/peptide-core` 归后端 Claude，主题 `wp-content/themes/peptide-site` 归 Codex；
   - 共享文件包括 `AGENTS.md`、`CLAUDE.md`、`docs/CONTRACT.md`、`docs/REQUIREMENTS.md`、`docs/TASKS.md`、本文件。
   - 要改这些，先在 Pull Request 或 Issue 里说明原因，DiGua 同意后再改。
6. **不提交**：密码、token、密钥、客户资料、数据库导出。`.gitignore` 已经挡掉了常见的文件，提交前仍然要看一眼。
7. **沟通**：
   - 项目群聊（`docs/CHATROOM.md`、`tools/chatroom/`）只在 DiGua 的电脑上，协作者用不了，也不要运行 `room.py`。
   - 和 DiGua、后端 Claude、Codex 的沟通都走 GitHub：Pull Request 里的留言或 Issue，提醒 DiGua 时写 `@DiGua0916`。
8. **开工前**：先运行 `tools/collab/peptide-sync.bat`，或者手动 `git fetch origin` 后 `git merge origin/main`，拿到最新的 main。

## DiGua 电脑上的后端 Claude 和 Codex

- 每次开工前 `git fetch origin`，看有没有协作者的新分支或 Pull Request。有的话在群里告诉对方。
- 协作者的改动只通过 Pull Request 进 main。不要直接改协作者的分支，有问题在 Pull Request 里留言。
- main 更新后，各自把 main 合进自己的分支，然后推送。

## 在自己电脑上运行

GitHub 上只有代码（插件、主题、文档），没有数据库、商品、订单和设置。要在自己电脑上跑起来：

1. 装 LocalWP，新建一个 WordPress 站点，装上 WooCommerce。
2. 把仓库里的 `wp-content/plugins/peptide-core` 和 `wp-content/themes/peptide-site` 放进站点的 `wp-content`（也可以用目录联接指过去），然后启用插件和主题。
3. 测试用的付款和运费可以用插件命令 `wp peptide demo commerce` 打开，见 `docs/CONTRACT.md`。
4. 需要真实的商品资料时，向 DiGua 要一份去掉客户信息的导出，不要用真实顾客数据测试。
