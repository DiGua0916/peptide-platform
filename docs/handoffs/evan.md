# 协作者 evan 的交接

协作者 evan（GitHub EvanCarter017）和他的 Claude 的交接记录。规则见 docs/COLLABORATION.md：在 `evan/work` 分支工作，经 Pull Request 进 main。

## 交接记录

### DATA-01 后台“数据”板块：调研与方案 · 2026-10-27 · evan 的 Claude

- 任务（来自用户）：照 Shopname 后台左侧“数据”板块做一套数据页面。
- 完成：
  - 只读查看了 Shopname“数据”下的 10 个页面；
  - 对照了 peptide-core 现有的仪表盘、访客追踪、应用统计和邮件记录；
  - 写了方案 [[evan-data-plan-v1]]（`docs/handoffs/evan-data-plan-v1.md`）：页面清单、现有数据和缺口、口径、接口、四个阶段、开工前要解决的问题。
- 改动文件：`docs/handoffs/evan-data-plan-v1.md`、`docs/handoffs/evan.md`（都是新文件）。没有改插件、主题和共享文档。
- 检查：只读查看了开发站点后台和 Shopname 后台。没有跑测试，因为协作者电脑上没有 WordPress。
- 其他：开发站点上把用户自己账号的个人资料语言设成了简体中文（只影响该账号的后台界面）。
- 待定：
  - DiGua 同意由协作者修改 peptide-core；
  - 主题里没进仓库的前台脚本需要推上来；
  - 测试环境怎么安排；
  - 开发站点的站点语言目前是中文。
  - 详见方案第 5 节。
