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

### DATA-01 第 1、2 步：数据菜单、时间段、运营数据（指标卡片和漏斗）· 2026-10-28 · evan 的 Claude

- 任务（来自用户）：先做起来，用 Shopname 的页面做验收对照，之后再由用户和 DiGua 验收。用户决定在 DiGua 回复 PR 之前开工，所以这部分改了 peptide-core，**需要 DiGua 审核**。
- 做法：按 shadcn/improve 的 `execute` 流程，每一步写成可独立执行的计划，由执行器子代理在单独的 git 工作树里实现，再由审查人重跑全部验收、检查范围、读代码。
- 完成（合并提交 `3412a9d`，包含 `2e1cc64`、`e9ee609`，基于 main `15f0731`）：
  - 第 1 步 `2e1cc64`：后台顶层菜单“数据”（排在“营销活动”后面），九个子页面和 Shopname 一样：实时数据、运营数据、商品详情数据、页面访问统计、页面商品转化、应用统计、落地页转化、转化率详情、邮件详情数据。还没做的页面显示“正在开发中”。
    - 共用时间段 `PeptideCore\Data\Range`：店铺时区，结束日期包含当天，默认对比上一个同样长度的时间段，夏令时按日历天计算。
    - 接口 `GET /peptide/v1/data/range`，共用脚本 `assets/data.js`（`window.PcData`）。
  - 第 2 步 `e9ee609`：“运营数据”页。
    - 12 个指标卡片：销售额、访问用户、注册用户、订单数、转化率、客单价、商品销售件数、商品净销售额、退款金额、平均访问时长、顾客数、移动端。
    - 访客转化漏斗、回访用户转化漏斗，每个数字都和对比期并列，并显示变化。
    - 口径按 Shopname 帮助中心“运营数据”和现有仪表盘：测试订单不计入；转化率 = 已付款订单数 / 访问用户；“支付成功”按订单数计；转化率的变化按百分点差显示。
    - 接口 `GET /peptide/v1/data/operations`。
- 改动文件（都在 `wp-content/plugins/peptide-core/` 下）：
  - 新增：`includes/data/class-range.php`、`includes/data/class-operations.php`、`includes/admin/class-data-pages.php`、`assets/data.js`、`assets/data.css`、`assets/data-operations.js`、`assets/data-operations.css`、`tests/smoke-data.php`、`tests/smoke-data-operations.php`。
  - 修改：`includes/class-plugin.php`（加两行注册）；`languages/` 三个文件（新增中文翻译，原有 2036 条译文一条没改）。
  - 没有改版本号（留给 DiGua 合并时改）。没有改主题、共享文档，也没有改其他人的代码。
- 检查（实际运行过的）：
  - 本机测试站（WordPress 7.1.3 + WooCommerce 11.2.1，HPOS，PHP 8.3，MariaDB）跑完整冒烟测试：33 个文件全部 `fail 0`，其中新增 `smoke-data.php` 41 项、`smoke-data-operations.php` 32 项。
  - 同一份计划由两个执行器各做一遍，交叉运行对方的测试也全部通过。
  - 在本机测试站以游客身份真实下单（加购、结账、货到付款）：订单、访客追踪和运营数据页的数字对得上。
  - 浏览器检查：菜单顺序、时间切换、刷新后保持选择、错误提示、手机宽度 375px，控制台没有错误。
- 已知缺口：
  - 浏览器端的行为事件（浏览页面、查看商品、进入结账）靠主题的 `visitor-tracking.js` 上报，这个脚本还不在仓库里。在只用仓库代码的站点上，这些事件和平均访问时长不完整。
  - 注册用户沿用仪表盘的查询：恰好在午夜注册的账号会在相邻两天各算一次。这是仪表盘已有的行为，没有改。
- 下一步：等 DiGua 审核这部分；之后按方案继续做商品详情数据、转化率详情等页面。
