# 多肽独立站 · 协作仓库

面向省级技能大赛的海外独立站项目。平台确定采用自托管 WordPress；Codex 负责前端主题与交互，Claude 负责后端业务插件。

主仓库现在位于 `D:\Codex\Projects\peptide-platform`。前后端独立工作目录位于 `D:\Codex\Projects\peptide-platform-worktrees` 下。

## 当前状态

- 已准备 Git 仓库、分工约定、需求基线和交接文档。
- 初始化后使用 main 作为集成分支，codex/frontend、claude/backend 分别开发。
- 尚未安装 WordPress、引入付费模板或实现业务功能。
- 尚未连接 Claude、配置在线服务或创建 GitHub 远程仓库。
- 仓库名称和代码标识是开发用名称，不是最终品牌。

## 目录

| 目录或文件 | 用途 |
| --- | --- |
| AGENTS.md | 共同开发约定及 Codex 工作边界 |
| CLAUDE.md | Claude 后端工作入口 |
| docs/REQUIREMENTS.md | 已确认需求与待确认事项 |
| docs/CONTRACT.md | 前后端数据与接口草案 |
| docs/WORKFLOW.md | 独立工作目录、提交和联调流程 |
| docs/TASKS.md | 当前任务和验收条件 |
| docs/handoffs/ | 双方分别维护的交接记录 |
| wp-content/themes/peptide-site/ | Codex 负责的主题源码预留目录 |
| wp-content/plugins/peptide-core/ | Claude 负责的业务插件预留目录 |

主题和插件目录目前只有说明文件，还不是可激活的 WordPress 主题或插件。

## 从哪里开始

1. 先读 docs/REQUIREMENTS.md、docs/CONTRACT.md。
2. 按 docs/WORKFLOW.md 在自己的工作目录工作。
3. 只修改当前任务负责的文件；接口变更先记录再同步。
4. 每个任务完成后写明改动、验证结果和剩余问题。

Claude 的启动指令可直接复制 CLAUDE.md 中的“首个任务”。

## 开发环境与数据

WordPress/PHP/数据库版本和运行方式尚待选定。WordPress 核心、数据库、上传文件、真实客户数据和凭据不纳入此源码仓库。演示数据必须标注为模拟数据；实验室、工厂和美国仓库的具体资料以用户提供的信息为准。

## 模板与许可

Lunira、Genesis Lab 目前仅为候选参考。本仓库没有收录其商业源码、图片或字体。引入资源时记录来源和许可范围。项目尚未指定开源许可证。

## Git

首次提交由 Codex 使用临时提交身份完成，不更改用户的全局 Git 身份。后续人工提交可单独设置本仓库的 user.name 和 user.email。
