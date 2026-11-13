# M6 追踪与指标：交接（F4「数据」会话）

2026-11-10 起，最近更新 2026-11-13 · 分支 `claude/m6-metrics-db7036`（已推送，没有合进主线，没有发布）· 基线 `82032bd`，已合并主线到 `4059779`（11-13，含已上线的素材归因）。
对应需求：NF-02（口径部分）、FE-05、ST-05。口径见 `docs/metrics.md`，给别的会话的签名见 `docs/CONTRACT.md`「M6 / M7」。

## 先说最重要的

**纯逻辑测试已经在本机跑过并通过；需要 WordPress / WooCommerce / 数据库 / 浏览器的部分一样都没跑过。**
平台所有者同意后，我在本会话的临时目录里从 php.net 源码编译了 PHP 8.3.36（方法见文末），跑了新写的 16 个用例和仓库里现有的全部纯逻辑用例，结果见“实际做过的检查”。
11-12 起另外有了一份能跑真正 WordPress 的本地环境（见“本地 WordPress 拷贝”一节），对账报告、报表三档、写入侧的漏斗规则、按广告拆分、同屏数据页都在上面实跑过；那是 SQLite。
11-12 F1 在沙盒（MySQL）上按手册跑通了第 0、1、2、3、7 步（见“F1 第一次沙盒结果”一节），但沙盒的数据只有一份旧快照加 1 张测试订单：**接近真实的数据上仍然没有跑过**，归 F4 的 12 个冒烟用例修完之后也还没有在 MySQL 上重跑。
没有验证过的：任何真实数据库上的查询（`Metrics` 的订单和访客读数、对账报告）、概览页和数据页在浏览器里的样子、`wc_get_orders( date_paid … )` 在真实 WooCommerce 下的行为、追踪事件的真实写入。这些要到沙盒或脱敏快照上才能验证。

开关默认是 `off`。按设计，off 时线上行为和这条分支之前完全一样（见“开关关着时改了什么”一节的逐项说明）。

## 做了什么

| 步骤 | 内容 | 提交 |
| --- | --- | --- |
| 1 | `Data\Metrics` 统一指标入口：访客、转化率、订单、销售额（已付款 − `_pc_refund_cents`）、客单价、弃单数、召回激活率、八层漏斗、按天拆分、单张订单事实 | `2609dce` |
| 2 | 口径文档 `docs/metrics.md`；`docs/CONTRACT.md` 追加「M6 / M7」一节 | `2609dce` |
| 3 | FE-05 漏斗推进规则 `Tracking\Funnel_Guard`：没有填地址的记录不推进到下单 / 支付成功，记异常；填地址事件晚到时补推进 | `e0831a7` |
| 4 | 按店开关 `Data\Metrics_Switch`（关 → 影子 → 开）和对账工具 `Data\Metrics_Reconcile`；概览页、运营数据接入（`Data\Metrics_Overlay`） | `2609dce`、`2cfce80` |
| 5 | ST-05：数据子页对投手合并成两页（`Data\Audience` + `Admin\Data_Pages`） | `fe085b5` |
| 6 | 其余报表接入：测试单规则、订单集合、单笔销售额和归属时刻、分组转化率、八层漏斗（见“已经接入的地方”）；每处一个对账用例 | `e7883ce` |
| 7 | 排行接入：“已付款订单”的那几张表按付款时间取，支付成功率仍按下单时间算尝试 | `addde8c` |
| 8 | 合并主线到 `976c1fa`（没有冲突，主线对 F4 名下 5 个文件的改动原样保留）；复核测试单规则，用真正的类补用例；口径文档补访问时长、在线访客、弃单的新规则 | `3658844`、`fb86c21` 及之后 |
| 9 | 报表三档冒烟脚本 `m6-reports-switch-smoke.php` 和给 F1 的沙盒手册；实跑发现并修掉一处漏斗不一致（填地址事件落在没打开过结账页的访问里） | `eff5f25` |
| 10 | 按广告拆分：广告系列 / 广告组 / 广告进归因、入库（`Tracking\Ad_Params`），来源报表加三种分组 | `8989f1f` |
| 11 | 投手的两个合并页改成同屏布局，页签布局留作退路 | `d65f4ca` |
| 12 | 每日汇总带弃单数（`Metrics::daily()` / `abandoned_daily()`）；员工角色不被追踪、不触发像素（F5 的请求） | `db7df57` |
| 13 | 再合并主线到 `7c6d2d3`（跨店顾客编号，没有冲突，不涉及 F4 名下的文件） | 合并提交 `423a0d7` |
| 14 | 修 F1 冒烟清单里归 F4 的 12 个用例（只改用例）；修 `customer-checkout-observer` 并登记进默认套件 | `12ada08` |
| 15 | 口径文档把三项没定的口径标成“待确认”，代码保持现状 | `0e733c4` |
| 16 | 手机宽度检查投手数据页和仪表盘，修三处排版；商品报表对投手不再给“编辑商品”链接 | `51059f1` |
| 17 | F1 第一次在沙盒跑完手册之后：补两条不用浏览器的验证（广告参数入库用例 `m6-ad-params-smoke.php`、影子档观察的命令），手册文末记下 F1 的结果和说明 | `400db8d` |
| 18 | 再合并主线到 `61ce4f8`（物流表、腾讯文档同步、加购推荐修复；没有冲突，不涉及 F4 名下的文件） | 合并提交 `574a67d` |
| 19 | FE-03“看过 / 已加购”的公开接口 `Tracking\Product_Activity`（F5 等着的）；不用浏览器的八步漏斗回放夹具（给 F1 的 critical-path） | `9ed88cb` |
| 20 | 合并主线 `4059779`（含已上线的素材归因 `251b727`）：解 4 处冲突；`pc_track_ads` 的建表和写入放到 M6 开关后面；确认员工、测试单两个修复仍然生效；逐条确认审查的 6 个问题（只确认，没修） | 合并提交 `8b36cd7` |

M7 的部分见 `docs/handoffs/m7-pixels.md`。

## 改动文件

新增（都在 `wp-content/plugins/peptide-core/` 下）：

- `includes/data/class-metrics.php`、`class-metrics-switch.php`、`class-metrics-reconcile.php`、`class-metrics-overlay.php`、`class-audience.php`
- `includes/tracking/class-funnel-guard.php`
- `tests/m6-stubs.php`（共用桩）、`m6-metrics-pure.php`、`m6-metrics-switch-pure.php`、`m6-funnel-guard-pure.php`、`m6-reconcile-orders-pure.php`、`m6-reconcile-overlay-pure.php`、`m6-reconcile-reports-pure.php`、`m6-reconcile-conversion-pure.php`、`m6-reconcile-rankings-pure.php`、`m6-test-order-flag-pure.php`、`m6-buyer-data-pages-pure.php`、`m6-reconcile-report-smoke.php`
- `tests/fixtures/conversion-db-059.php`（转化报表用例共用的假数据库，从 `data-classified-conversion-pure.php` 里原样搬出来）
- `docs/metrics.md`、本文件

修改：

- `includes/tracking/class-tracker.php`：`raise_stage()` 先问 `Funnel_Guard`；`register_hooks()` 注册 `Funnel_Guard`。
- `includes/tracking/class-ingest.php`：新保存了填地址事件后触发 `peptide_core_tracking_address_completed`。
- `includes/data/class-operations.php`：`period()` 的五个核心指标经 `Metrics_Overlay::operations()`；`paid_orders()` 的结尾经 `Metrics_Overlay::paid_orders()`。
- `includes/data/` 下 18 个报表类和 `includes/tracking/class-checkout-dropoffs.php`：各自末尾加了几个私有的小函数（`is_test()`、`sales()`、`order_ts()`、`conversion()`），
  原来直接写的 `Overview::is_test_order()`、`订单金额 − 退款`、`创建时间`、`订单数 ÷ 访客` 改成调它们。小函数里先看 `Metrics_Overlay` 在不在：
  不带自动加载的旧用例里没有这个类，走的就是原来那一行算式。
- `includes/data/class-visitor-funnel.php`、`class-classified-conversion.php`：开关开着时后四层按 FE-05 的规则结算。
- `includes/admin/class-overview-page.php`：页面和接口的数据经 `Metrics_Overlay`（新方法 `Overview_Page::data()`）。
- `includes/admin/class-data-pages.php`：投手的两个合并页。
- `includes/class-plugin.php`：只追加一行 `Data\Metrics_Switch::register_hooks();`。
- `tests/data-observations-suites.json`：末尾追加 16 条（`module: M6 / M7`）。
- 11-12 新增：`includes/tracking/class-ad-params.php`；`tests/m6-reports-switch-smoke.php`（要 WordPress）、`m6-ad-params-pure.php`、`m6-data-sources-ads.cjs`、`m6-data-stacked.cjs`；`docs/guides/m6-sandbox-runbook.md`。
- 11-12 修改：`includes/tracking/class-attribution.php`、`class-privacy.php`、`class-schema.php`、`class-tracker.php`；`includes/data/class-sources.php`、`class-report-exports.php`、`class-visitor-funnel.php`、`class-classified-conversion.php`、`class-metrics.php`；`assets/data.js`、`data.css`、`data-sources.js`、`data-sources.css`；`languages/customer-data-observations-zh_CN.php`（只加了四条新文案的中文）。
- `docs/CONTRACT.md`：末尾追加「M6 / M7」一节。
- 11-12 晚新增：`includes/tracking/class-product-activity.php`（FE-03 接口）；`tests/m6-product-activity-pure.php`、`m6-product-activity-smoke.php`、`m6-funnel-replay-smoke.php`、`m6-ad-params-smoke.php`（后三个要 WordPress）。修改：`includes/tracking/class-tracker.php`（`register_hooks()` 里多注册一行 `Product_Activity`）、`tests/m6-smoke-fixtures.php`（加了回放访客行为的几个函数）。

现有的表结构没有改。11-12 新增一张表 `{prefix}pc_track_ads`（访问的广告参数，没有顾客资料），有自己的版本选项 `peptide_core_track_ads_db`，第一次请求时自动建；建不出来只影响“按广告拆分”，追踪和归因照常。新增的选项见 CONTRACT「M6 / M7」第 2、4、6 小节。

## 开关关着时改了什么

逐项对照“开关关着时线上行为与现在完全一致”：

| 位置 | off 时 |
| --- | --- |
| `Tracker::raise_stage()` | 阶段 ≥ 4 时多读一次选项 `peptide_core_m6_mode`，然后执行和以前一样的 UPDATE；不查事件表、不写计数 |
| `Ingest` | 一批里有新的填地址事件时多触发一个动作，监听方读到 off 立刻返回 |
| `Operations::period()` | `Metrics_Overlay::operations()` 原样返回传入的数组，`Metrics` 不被调用 |
| `Operations::paid_orders()` | 查询和以前一样，`Metrics_Overlay::paid_orders()` 原样返回这一批订单，不再查第二次 |
| 各报表的 `is_test()` / `sales()` / `order_ts()` / `conversion()` | 多读一次开关，然后算出和原来那一行算式相同的值 |
| `Visitor_Funnel::period()` | 多读一次开关；SQL、循环和结果都不变（每张订单多记一个不会被用到的字段） |
| `Rankings::period()` | 多读一次开关；订单只查原来那一次，循环里“已付款订单”那一段搬进了一个小函数，算出来的表不变 |
| 概览页 | `Overview::range_data()` / `summary()` 的结果原样输出；缓存名和以前相同 |
| 数据页（管理员） | 菜单多注册两个隐藏的合并页（和“来源”页一样在 admin_head 里从菜单拿掉），12 个原有页面的输出不变 |
| 数据页（投手） | 不受开关控制：只要用户有 `pc_media_buyer` 角色就生效。角色现在还不存在（F5 在做），所以目前对所有人都不生效 |
| FE-03 接口 `/peptide/v1/product-activity` | 不受 M6 开关控制，有自己的按店开关 `pc_product_activity`（和主题同一个，默认关）。关着时这个地址一律 404，不查库；线上多出来的只是这个 404 地址，和 `rest_api_init` 时多注册一条路由 |

两处不受开关控制、但对现有用户可见的变化都在 M7：像素页多了“发送测试事件”按钮；AddPaymentInfo 的触发方式（见 M7 交接）。

## 上线步骤（开发文档 12.6）

1. 有 PHP 的环境里跑 `m6-*-pure.php`，全绿。
2. 脱敏快照上跑对账：

```bash
wp eval-file wp-content/plugins/peptide-core/tests/m6-reconcile-report-smoke.php
```

   订单和访客的差异都要落在写明的原因里（退出码 0）。转化率和弃单激活是定义 / 数据源变了，脚本把新旧两个数并排打印，需要人看一眼是否合理。
3. 发布到试点店，开关保持 `off`，确认页面没有变化。
4. 试点店切影子，观察几天：

```bash
wp option update peptide_core_m6_mode shadow
```

```bash
wp eval 'print_r( PeptideCore\Data\Metrics_Switch::log() ); print_r( PeptideCore\Tracking\Funnel_Guard::counters( 7 ) );'
```

5. 差异都有解释、漏斗异常数可以接受之后切 `on`；先试点店，再全部店。出问题改回 `off` 即可。

## 已经接入的地方

开关开着时，下面这些和 `Data\Metrics` 是同一个口径；关着和影子时仍然是旧算法（影子多记一份对账）。

| 口径 | 接入点 | 覆盖到的报表 |
| --- | --- | --- |
| 全店合计 | `Metrics_Overlay::overview()` / `recovery()` / `operations()` | 概览页、运营数据的五个核心指标 |
| 谁是测试单 | 各报表的 `is_test()` → `Metrics_Overlay::is_test_order()` | 运营数据、转化详情（三种）、趋势、实时、实时筛选、报表筛选、商品、来源、落地页、分维度明细、排行、实验、页面转化、结账步骤、八层漏斗、结账流失 |
| 哪些订单算在时间段里 | `Operations::paid_orders()` → `Metrics_Overlay::paid_orders()` | 运营数据其余几项、转化详情、趋势、实时、实时筛选、商品、来源、落地页、分维度明细、实验 |
| 排行里“已付款订单”的那几张表 | `Rankings::period()` → `modern_paid()` → `Metrics_Overlay::paid_orders()` | 下单支付方式、订单 / 顾客来源国家、优惠券、热卖 / 热门商品、商品类型、订单浏览器、订单来源 / 域名。开了之后这几张表的订单数加起来等于概览页的订单数 |
| 排行的支付成功率 | 同一个循环，“成功”改用 `Metrics::is_paid_order()` | 尝试仍然按**下单**时间归属（一次尝试发生在下单那一刻），所以它和上面几张表不是同一批订单，这是有意的 |
| 一张订单的销售额 | 各报表的 `sales()` → `Metrics_Overlay::order_sales()` | 运营数据、转化详情（三种）、趋势、实时、实验、`Visit_Metrics`（落地页、来源、分维度明细） |
| 一张订单算在哪个时刻 | 各报表的 `order_ts()` → `Metrics_Overlay::order_time()` | 趋势、实时筛选、实验、只看某类访客的转化详情 |
| 分组转化率 | `conversion()` → `Metrics_Overlay::conversion()` | 落地页、来源、分维度明细（`Visit_Metrics`）、转化详情按设备 / 浏览器 / 国家等、只看某类访客的转化详情 |
| 八层漏斗（含分组和回访） | `Visitor_Funnel::period()` 自己看开关；`Classified_Conversion` 用 `Metrics::tail_steps()` | 运营数据、转化详情（三种）、实时数据、概览页、总后台 AI 摘要 |

## 还没有接入的地方

| 位置 | 现在怎么算 | 为什么没动 |
| --- | --- | --- |
| 商品级金额：`Products`、`Rankings` 的商品 / 类目销售额、运营数据的“商品净销售额” | 减 WooCommerce 里逐件记的退款 | 总后台标记的退款是整单的，分不到每件商品。系统内不做退款，正常都是 0 |
| 运营数据的“退款”一项 | WooCommerce 里的退款单 | 这一项本来就是“WooCommerce 里退了多少”，和销售额口径无关。上线后如果要显示总后台标记的退款，换成 `Metrics::orders()['refund_cents']` |
| `Data\Page_Conversion`（页面转化） | 按访问上挂的订单算件数；已付款 = 已付款状态或有付款时间，货到付款不算 | 是按件数的报表，只统一了测试单规则 |
| `Tracking\Query::stats()`（访客页的六层阶段漏斗） | 按 `stage` 直接数 | 写入侧的规则开了之后自然满足“不跳步”，不用改；规则打开之前的历史访问仍然可能跳步 |
| 概览页的商品排名、注册用户 | 旧订单集合 | 在 `core/class-overview.php` 里，不归 F4；低优先级 |
| 影子模式下的漏斗对账 | 只比后四层的规则差异 | “不算测试版留下的访问”要换 SQL 才能比，影子时没有做；这部分差异在 `operations.kpis` 的访客数里能看到 |
## ST-05 的现状

- 投手（角色 `pc_media_buyer`，由 F5 创建）的“数据”菜单只有两页：「漏斗 + 来源」「商品 + 落地页」。用的还是原来的四个报表（转化率详情、来源、商品详情、落地页转化），报表脚本和接口没有动。
- **11-12 起默认是同屏布局**：每页把两个报表上下排在一屏，各有小标题和自己的导出按钮；换时间段两段一起刷新；地址里的 `tab` 用来在打开时滚到那一段。第一版的页签布局留作退路：`wp option update peptide_core_buyer_data_layout tabs`。
- 11-12 下午在本地拷贝上用手机宽度（375）、平板宽度（768）又看了一遍两个合并页（同屏和页签两种布局）和仪表盘，界面语言设成中文（和生产店后台一样）。仪表盘没有问题。数据页修了三处，都在 `assets/data.css` 末尾：① 时间段工具条的日期框，后台通用样式（`admin-workspace.css`）给所有输入框加了 12px 内边距，四位年份的最后一位被切掉——**桌面宽度也有**，一起修了；② 商品 / 落地页报表的筛选行（入口域名、访问国家）在手机上不换行，输入框伸出屏幕；③ “每页条数”下拉的数字和箭头重叠。修完后两种布局、三种宽度都没有横向溢出，宽表格在自己的滚动框里。界面语言是英文时仪表盘有四个标签会被省略号截断（中文不会），没有处理。
- 在本地 WordPress 拷贝上用浏览器看过两个合并页（桌面宽度 1280）：两段都出数，按 `tab` 滚到第二段，来源那一段切到“广告组”分组，当成投手时数据菜单只有这两页，控制台没有报错。**手机宽度没有看；真正的投手账号（F5 的角色）没有试过**，这里是用过滤器把管理员当成投手看的。
- **更正**：上一版这里写“转化详情里每组的‘查看详情’链到分维度明细页，投手打不开”，那是我看错了。它是页内展开按钮（5 层漏斗展开成 8 层），不是链接，投手能正常用。11-12 下午在浏览器里逐个点过、把两个合并页里的链接全部列出来核对过：投手真正打不开的只有商品报表里商品名上的“编辑商品”链接。按“没有别的意见就对投手隐藏”处理了——`Data\Audience::product_edit_url()` 对投手返回空，商品名显示成普通文字；管理员不变。约定写在 CONTRACT「M6 / M7」第 4 小节。
- 仍然没有试过的：真正的投手账号（F5 的角色和菜单白名单）。我是用过滤器把管理员当成投手看的。

## 待确认

1. `docs/metrics.md` 第 6 节的三条（WooCommerce 历史退款、填地址事件丢失时的付款、保留订单）。
2. 订单按付款时间归属是我按“收到支付成功通知的订单”和总后台 `pchq_orders.paid_at` 推出来的，PRD 没有明说按哪个时间。和现在的“按创建时间”相比，晚付款的订单会挪到付款那天。请确认。
3. 弃单数的定义我取的是“待召回 + 召回中 + 召回失败 + 召回成功”（期间内弃过的都算，包括后来召回成功的）。如果投手首页想要的是“现在还没付的”，用 `abandoned_open`（待召回 + 召回中）或者 `abandoned − recall_recovered`。
4. 排行里的支付成功率按下单时间算（其他已付款订单的表开关开着时按付款时间）。

第 2、3、4 条 11-12 平台所有者还没定：在 `docs/metrics.md` 第 6 节第 4–6 条标了“待确认”，每条写了现状、另一种算法、定了之后改哪一处。**定之前代码不动。**

## 实际做过的检查（2026-11-10，本机 PHP 8.3.36 + Node 26.13）

环境：PHP 是从 php.net 的 `php-8.3.36.tar.xz`（校验值和官方公布的一致）在本会话临时目录里编译的最小命令行版（带 mbstring、bcmath、ctype、tokenizer、filter）；Node 用机器上已有的 `~/.local/opt/node-v26.13.0-darwin-arm64`。

| 检查 | 结果 |
| --- | --- |
| `php -l`：全部新增和修改的 PHP 文件 | 无语法错误 |
| `node --check assets/pixels.js` | 通过 |
| 新用例 `m6-metrics-pure`（61 条断言）、`m6-metrics-switch-pure`（30）、`m6-funnel-guard-pure`（36）、`m6-reconcile-orders-pure`（23）、`m6-reconcile-overlay-pure`（26）、`m6-reconcile-reports-pure`（46）、`m6-reconcile-conversion-pure`（26）、`m6-reconcile-rankings-pure`（27）、`m6-test-order-flag-pure`（41）、`m6-ad-params-pure`（32）、`m6-data-sources-ads`（25）、`m6-data-stacked`（14）、`m7-purchase-dedupe-pure`（24）、`m6-buyer-data-pages-pure`（36）、`m7-pixel-acceptance-pure`（32）、`m7-pixels-pure`（33） | 全部通过，`E_ALL` 下没有警告 |
| 默认套件 `node tests/run-data-observations.cjs`：主线 `f0e58d2` | 42 通过 / 9 失败 |
| 同一命令：本分支第一批（`830c2e2`） | 53 通过 / 6 失败；没有新增失败，修好了 3 个原有失败 |
| 同一命令：本分支第二批（`e7883ce`，已合并主线 `dcaf95e`） | 58 通过 / 3 失败；又修好 3 个原有失败，剩下 3 个不归 F4 |
| 同一命令：排行接入之后（`addde8c`） | 59 通过 / 3 失败（多的 1 个是新用例） |
| 同一命令：合并主线 `976c1fa` 之后、修用例之前 | 58 通过 / 4 失败——主线的两处改动让 F4 的两个用例报错（见下） |
| 同一命令：11-12 复核购买事件和测试单规则之后 | 61 通过 / 3 失败（新增 `m7-purchase-dedupe-pure`、`m6-test-order-flag-pure`） |
| 同一命令：11-12 全部做完之后 | **64 通过 / 3 失败**（又新增 `m6-ad-params-pure`、`m6-data-sources-ads`、`m6-data-stacked`；剩下 3 个不归 F4） |
| 同一命令：再合并主线 `7c6d2d3` 之后 | 64 通过 / 3 失败，和合并前逐个相同 |
| 同一命令：11-12 下午修完冒烟用例和 `customer-checkout-observer` 之后 | **65 通过 / 3 失败**（多的 1 个是新登记的 `customer-checkout-observer`；剩下 3 个不归 F4） |
| 归 F4 的 12 个冒烟用例（`smoke-data*`、`smoke-overview`、`smoke-pixels`、`smoke-tracking`），本地拷贝，两种条件各跑一遍：本地原样；模拟沙盒（只有演示用的简单商品 + 环境在较晚的时机拦掉对外请求） | 两种条件下 12 个都全过（每种条件 453 条断言，0 失败）；临时商品都删干净了。**MySQL 上没有跑**，见下面“冒烟用例”一节 |
| 真正的 WordPress（本地拷贝，SQLite，HPOS 开）：`m6-reconcile-report-smoke`、`m6-reports-switch-smoke`（202 项检查） | 都通过；归 F4 的 12 个旧冒烟用例主线和本分支逐个相同 |
| 24 个非默认的可选套件，主线和本分支逐个对比（第一批时做的） | 结果完全相同（两边都是 12 个失败，都是历史套件） |
| 插件里全部可以单独运行的测试文件（PHP 和 JS，11-12 时共 127 个），改动前后逐个对比 | 只有变好的，没有变差的 |

第二批（其余报表接入）是这样保证“关着时不变”的：

- 每改一步都把上面这 124 个文件重跑一遍，和改之前逐个比退出码——其中 `data-classified-conversion-pure` 把转化详情七种分组的完整 JSON 和 CSV 同一份冻结的旧输出逐字比对，`data-native-search-pure` 把排行前十九张表同冻结的旧输出逐字比对，都没有变。
- `m6-reconcile-conversion-pure` 用真正的报表类把转化详情（按设备、按国家、只看正常访客）在同一份数据上跑三遍：关着时是旧数字；影子时三张报表的输出和关着时逐字节相同、差异进日志；开了之后三张报表的漏斗都等于 `Metrics::funnel()`，分组加起来等于合计；换回关又和最开始逐字节相同。
- `m6-reconcile-reports-pure` 对五个接入点各做一组 关 / 影子 / 开，并用 `Metrics_Reconcile` 确认每一处差异都落在写明的原因里。
- `m6-reconcile-rankings-pure` 用真正的 `Rankings` 跑 关 / 影子 / 开（含选了入口域名的情况）：七张订单各代表一种情况（当天下单当天付、隔天才付、前一天下单今天付、一直没付、全 1 测试单、付过款后改成已退款、没付过款就改成已退款），影子时整份输出和关着时逐字节相同，开了之后支付方式一表的订单数等于 `Metrics::orders()`。这个用例第一次运行就全部通过。

第二批第一次运行时的情况：两个新用例各有断言写错的地方（一处把日志里新旧两栏看反了，一处以为影子时会记分组转化率的差异——那份数据里每人只有一单，本来就没有差异），改的都是用例。
实现上只动了一处：`Metrics::order_facts()` 按订单号补查时，如果环境给回来的是订单对象而不是订单号也能认（测试夹具就是这样）。

第一批第一次运行时的情况：8 个新用例里 7 个一次通过；`m6-funnel-guard-pure` 有 1 条断言失败，原因是测试桩把“异常数”查询误认成了另一条查询（桩的问题，不是实现的问题），改了桩之后通过。`m7-pixels-pure` 是有了 PHP 之后新写的，加载真正的 `Pixels` / `Server` / `Acceptance`。

第一批修好的原有失败（都是测试桩或期望值没跟上主线的改动，实现没有问题）：

- `tracking-product-actions-pure`、`tracking-native-visit-classifier-pure`：桩里的 `Tracker` 缺 `is_automated()`（11-08 加的爬虫过滤）。
- `tracking-store-api-cart-success-pure`：缺 `Recovery::CRON`（11-10 加的付款事件补记）。
- `experiment-data-pure`：数据页数量的期望值还是 11，现在是 12。

合并主线 `976c1fa` 之后修的 2 个（主线 11-11 改了实现、没改用例；改的都是测试桩）：

- `tracking-native-visit-classifier-pure`：`Ingest` 更新访问的那条语句多了一个参数（这一批里“人还在”的事件数），桩按参数个数认浏览数。
- `m7-pixels-pure`：`Server::installed()` 现在每次请求确认一次发送记录表在不在，假数据库要回答“在”。

第二批修好的 3 个（也都是用例没跟上主线的改动）：

- `data-native-search-pure`：排行后来多了“收藏商品”一张表，用例单独运行时没有加载 `Data\Product_Actions`；冻结的期望值是加商品缩略图之前导出的，每一行后来多了一个 `image` 字段。现在数字、名称、顺序仍然逐字比对，`image` 单独检查。
- `data-product-action-rankings-pure`：同上，另外假商品缺缩略图函数要读的三个方法。
- `data-product-user-ratios-node`：对比期那一行的“VS”后来改成只给读屏软件念，断言还在找连在一起的文字。

默认套件里还在失败的 3 个（主线上同样失败，不归 F4）：

| 套件 | 原因 | 归属 |
| --- | --- | --- |
| `mail-source-observations` | 需要一个装好的 WordPress 目录，不是纯逻辑用例 | 日常会话 / F1 |
| `visitor-intents`、`visitor-product-actions` | 主题的用例 | F5 |
没有做的：需要 WordPress 的 `smoke-*.php` / `*-native.php`、任何数据库上的查询、任何浏览器里的页面检查。

### 独立静态复查（另一个只读会话，没有运行环境，逐条手工推演）

它确定的 1 处和实际运行发现的是同一处（测试桩认错查询）。另外提出 5 处实现上的疑点，都核对过，处理如下：

| 疑点 | 处理 |
| --- | --- |
| 一批事件里有两条填地址事件时，晚到补推进被跳过，访问永远停在第 3 步 | 属实，已改：`Ingest` 把这一批新保存了几条传给 `Funnel_Guard::settle()`；开关开着时补推进可以重复执行。加了两条用例 |
| 像素验收：两个像素里一个收下、一个拒收同一个事件，结果仍然显示通过 | 属实，已改：按“每个应该回传的像素都要收下”判断；同一个像素重发成功的不算失败。加了四条用例 |
| 同一次访问里先付了一单、又下了一张没付的单，访问上只留后一张的订单号，支付成功访客被漏掉 | 属实，已改：`Metrics` 同时读付款事件里的订单号。加了四条用例 |
| 开关开着时，被拦下的访问阶段完全不动；浏览器的结账事件一条都没到的订单会从“发起结账”里掉出去 | 采纳：被拦下时最多记到 3（下了单说明到过结账页）。加了一条用例 |
| 像素验收只读“开始以来的前 5000 条发送记录”，生产店流量大时验收人的记录可能被挤掉 | 属实，已改：先取验收浏览器上报的 event_id，再按这些 id 查服务端记录。加了一条 2500 条外部记录的用例 |

复查同时确认：开关关着或没有这个选项时，`Tracker::raise_stage()`、`Operations::period()`、概览页数据、管理员的数据页输出四条路径和改动前没有行为差别。它列出的三处不受开关控制的变化（概览页先算时间段再算汇总、管理员多两个隐藏的合并页、M7 的改动）都已经写在本文件和 `m7-pixels.md` 里。

## 冒烟用例（2026-11-12 下午）

F1 的清单（`tests/smoke-suites.json`）里归 F4 的 12 个都修了，只改了用例和一个共用的准备文件 `tests/m6-smoke-fixtures.php`，没有改插件代码。沙盒上每个用例失败的原因，都先在本地拷贝上造出同样的条件、复现出和 F1 记录一样的通过数 / 失败数，再改：

| 用例 | 沙盒上的结果（F1 记录） | 原因 | 怎么改的 |
| --- | --- | --- | --- |
| `smoke-data-conversion`、`-landing`、`-operations`、`-products`、`-rankings`、`-realtime`、`-trends` | 一上来 `FAIL fixture product` | 快照里没有“已发布、有价格、不是演示商品的简单商品”（生产店都是多规格商品，演示数据里的都带演示标记） | 找不到时临时建一个（目录里不可见），脚本结束时删掉 |
| `smoke-overview` | 19 过 5 败 | 同上：它不排除演示商品，拿演示商品下的单被算成测试单 | 同上，并且排除演示商品；开头清掉上次中途停下留下的临时用户 |
| `smoke-data-apps` | 13 过 8 败 | 版本选项说“已安装”，统计表 `pc_app_stats` 却不在（快照带选项不带表），一条也记不进去 | 用例开头确认表真的在，不在就用插件自己的建表语句补上 |
| `smoke-pixels` | 46 过 2 败 | 沙盒自己有一个拦掉对外请求的过滤器，排在用例的模拟回应后面，把回应换成了错误 | 用例的过滤器排到最后 |
| `smoke-tracking` | 90 过 2 败 | “回访访客”：访客存档 11-10 起也列出正在逛的访问，期望值过时。“重复事件只存一次”：本地复现不出来，推测是沙盒里有别的功能给同一个访客另记了事件 | 前一条按现在的规则断言；后一条改成只数这一批的三个事件编号，访客的事件总数不是 3 时打印一行 `INFO`，F1 下次跑请把这一行贴回来 |
| `smoke-data` | 38 过 3 败 | 数据菜单 9 页变 12 页；11-04 起“数据”顶替了“分析”的位置；夏令时用例假定店铺时区是洛杉矶 | 按现在的菜单断言；用过滤器在这一次运行里固定时区 |

另外三个在本地原来就失败、和沙盒无关的期望值过时：`smoke-data-conversion`（`utm` 现在是合法分组）、`smoke-data-rankings`（14 张表变 21 张）、`smoke-data-realtime`（11-11 起心跳要带“前台、有操作”的标记）。

**更正上一版的说法**：我上一轮写“6 个是期望值没跟上后来的改动”，其中 `smoke-data-products` 和 `smoke-tracking` 在本地的失败其实不是期望值的问题，而是本地这套 SQLite 环境和 MySQL 不一样（见“本地 WordPress 拷贝”一节的两条限制）。补上这两处差别之后，`smoke-data-products` 本来就全过。

`customer-checkout-observer.cjs`（F5 报的失败）归 F4：它测的是插件的 `assets/tracking-checkout.js`。失败原因是主题脚本 11-08 起“访客有真实操作或页面开满 6 秒才发送事件”，用例里的访客从来没动过，所以一条事件都没发出去；另外主题的点击处理现在会对按钮调用 `closest()`，用例里的假按钮没有这个方法。改了用例（访客先动一下、假按钮补上方法），补了三条“没动之前不发送”的断言，86 条全过，并登记进了默认套件——它以前不在任何套件里，所以坏了没人发现。

## F1 第一次沙盒结果（2026-11-12，合并候选里的 F4 是 `6fa1907`）

F1 把 F2 / F3 / F4 / F5 合在一起放上沙盒跑了手册（记录在 F1 分支的 `docs/handoffs/m1-sandbox-acceptance-all-2026-11-12.md`）：第 0、1、2、3、7 步通过，没有发现代码问题；五条分支合并时代码零冲突，合并后默认套件 80 过 / 0 败 / 9 跳过。**这是 M6 / M7 第一次在 MySQL 上跑通。** 但沙盒的数据只有 11-10 的快照加 1 张测试订单，对账只说明“能跑、结果自洽”，不代表真实店铺；F1 已经问平台所有者能不能重新导快照。

两项当时没验到，原因都是沙盒没有浏览器、curl 不执行页面脚本，已经补了不用浏览器的做法（都在本地拷贝上跑过）：

- 第 6 步“广告参数入库”：新用例 `tests/m6-ad-params-smoke.php`（`wp eval-file … run`），直接走真正的事件接收接口，17 项检查，写入的 4 个测试访客结束时删掉。
- 第 3 步“影子档观察”只比到了概览页两项：手册里加了一条命令，以管理员身份把概览页和 11 张报表的接口各调一遍，对账日志就会记全。

F1 请平台所有者看的两行（近 7 天转化率旧 1.82% / 新 0%；弃单激活旧“发信 2”/ 新“发信 0”）在手册文末逐行写了说明：前一行是定义不同，后一行数据太少看不出来，换了快照之后还是这样就要查。

F1 那边已经接好的：`/hq/summary` 每天带 `abandoned / abandoned_open / recall_emailed / recall_recovered`（取自 `Metrics::abandoned_daily()`）；功能键 `m6.metrics`、`m7.pixel_acceptance` 已登记；`pc_track_ads` 在脱敏快照里登记为 keep；快照里 `peptide_core_m6_mode` 一律设回 `off`。总后台远程换档的通道 F1 还没做。

## FE-03“看过 / 已加购”接口和八步漏斗回放（2026-11-12 晚）

**接口**（F5 等着的，约定在它分支的 CONTRACT「M14 前台主题」里；我的答复在 CONTRACT「M6 / M7」第 8 小节）：`GET /wp-json/peptide/v1/product-activity?product_id=<商品 ID>`，类 `Tracking\Product_Activity`。

- 返回 `viewing`（最近 10 分钟看过这件商品的不同访客）、`added`（最近 24 小时加购的不同访客）、实际的统计窗口、`generated_at`、`ttl`，响应头 `Cache-Control: public, max-age=60`。只有这几个字段，没有任何访客资料。
- 只有真实数字：从事件表里按访客去重数出来的。访客口径和 `Data\Metrics` 一样（员工、爬虫写入时就不记；测试版数据和已清掉的访客不算），另外多排除“窗口内的访问上挂着测试单的访客”。
- 和 F5 的约定有三处出入，都是往少暴露的方向：低于店铺下限的数接口直接给 0（公开接口不暴露比页面上显示的更多的东西）；接口也看按店开关 `pc_product_activity`，默认关，关着一律 404；`pc_product_activity_available` 只在追踪开着时才是 true。
- 轻：全店的数字一次算好缓存 60 秒（三条走已有索引的查询），不管多少个商品弹窗，每家店每分钟最多算一次；没有加新表、新索引、新选项。没有加限频，理由写在 CONTRACT 里。
- 注册：在 `Tracker::register_hooks()` 里（F4 自己的文件），`class-plugin.php` 没有再加行。
- 验证：`m6-product-activity-pure`（默认套件里；假数据库 68 条断言，PHP 带 pdo_sqlite 时再用内存 SQLite 把真正的 SQL 执行一遍，共 106 条；故意改坏五处，每次都报错）；`m6-product-activity-smoke.php` 在本地拷贝上 20 项全过；拿接口的真实返回喂给 F5 分支上的 `assets/product-activity.js`，两行文案正常显示。**MySQL 上还没跑，F5 的主题和这个接口也还没有在同一个站上、用真正的浏览器看过。**

**八步漏斗回放**（给 F1 的 critical-path，那一项原来因为事件要浏览器上报而跳过）：`tests/m6-funnel-replay-smoke.php`，用法在沙盒手册第 9 节。

- 一次完整购买按顺序走八步，每走一步读一次 `Metrics::funnel()`，核对这一步正好多一个访客、后面的还是 0。浏览器事件走真正的接收接口，加购走真正的购物车，下单和付款走 WooCommerce 的钩子。
- 反例：不填地址直接选物流、选支付、下单、付款——后四步一个都不多；订单照挂、付款事件照记、订单数和销售额照算；开关按 `on` 时访问停在“发起结账”并记两次异常。再加一段“填地址事件晚到就补上”。
- 可以按 `on`（默认）/ `shadow` / `off` 三种设定跑，用的是这一次运行里的过滤器，不改选项。写入的 2 位访客、2 张订单结束时删掉。
- 本地拷贝上三种设定都是 25 项全过；故意把读数规则或写入规则改坏，夹具都会报没通过。**MySQL 上还没跑。**

顺带修了三档冒烟脚本的一处误报：概览页的接口有 30 秒缓存（关着时一份，其余每档各一份），紧跟在会改订单的用例（例如 `smoke-overview`）后面跑 `m6-reports-switch-smoke.php`，会报“影子档的输出和关着时不一样”。现在脚本看接口的状态码，拿来比较的数字直接现算，不走缓存。只改了脚本。

## F1 第二次沙盒结果（2026-11-12，候选 `c5fb1d8`，含 F4 `400db8d`）

对账、三档冒烟（206 项）、影子档（新命令，36 次全部 200）、广告参数入库（17 项）全部通过；`smoke-data-apps` 在候选上 21 / 0。沙盒数据没有换，结论仍然只是“能跑、结果自洽”。影子档日志里有差异的是 `operations.kpis` 和 `reports.conversion`，都在手册第 3 节表里的说明之内（那张测试订单没有带填地址记录的访问）。F1 的摘要里没有概览页的两项对账，已在手册文末请它下次把日志原文存一份。逐项说明在沙盒手册文末。

## 2026-11-13：合并主线 `4059779`（含已上线的素材归因 `251b727`）

合并提交 `8b36cd7`（`git merge`，没有 rebase、没有强推）。主线领先 55 个提交，其中 54 个到 `251b727`（11-13 已上线到 6 家店和总后台），外加 1 个只动部署工具的 `4059779`。**没有上线，没有碰生产。**

**平台所有者 11-13 定的原则：我们是优化，不改功能。** 线上已有的功能保持原样；素材会话还在改的部分暂时不碰。这次合并按它收尾：

- `tracking/class-attribution.php` 以线上为准。和主线逐行比，`clean()` 一个字没动；`capture()` 只多了“另存 `pc_track_ads`”那一段，而它在开关关着（默认）时第一行就返回，不查库、不写入，所以两个函数的行为和线上一致。
- `pc_track_ads` 留在开关后面，不删，也不在它上面继续做。来源报表改读访问表记成后续（见“下一步”），这次不做。
- 追踪表版本号是主线的 `'6'`，没有改。
- 素材会话的分支 `claude/creative-merge`（`1b25f74`：追踪表 v7、滚动深度、曝光）**没有合进来**，核对过它不在本分支的历史里。等它进了主线再做一次增量合并。

### 冲突和解法（4 个文件，各 1 处）

| 文件 | 怎么解的 |
| --- | --- |
| `tracking/class-attribution.php` | `clean()` 以主线为准（参数白名单 `Contract::PARAMS`），线上行为不变。`capture()` 自动合并，里面另存 `pc_track_ads` 的那一行保留，但 `Ad_Params` 的**建表和写入都放到了 M6 开关后面**（`Ad_Params::writing()`：`peptide_core_m6_mode` 是 `off` 时不建表、不写；过滤器 `peptide_core_track_ads_write` 可以改写）。默认关着时线上只有访问表新列那一份 |
| `tests/m7-pixels-pure.php` | 取本分支的版本。主线那边的 31 条断言本分支都有（本分支 33 条）；它唯一的改动是给内联测试桩加“发送记录表当作存在”，本分支已经把测试桩拆到 `m7-stubs.php` 并做了同样的修正 |
| `docs/CONTRACT.md` | 两边都保留：「M6 / M7」第 8 小节留在本节末尾，主线新增的 M9 / M11 / M14、CR 两节接在后面 |
| `tests/data-observations-suites.json` | 两边都保留：115 条，没有重复，两边原有的条目一条不少 |

`tracking/class-schema.php`、`class-tracker.php` 自动合并。追踪表版本号是 `'6'`，没有回退。

受合并影响、跟着改的两个用例：`m6-ad-params-pure`（清洗以主线为准——主线不拦没被替换的 `{{…}}` 占位符，入 `pc_track_ads` 之前由 `Ad_Params::pick()` 拦；写入在开关后面；37 条断言）、`m6-ad-params-smoke.php`（在运行里用过滤器打开写入，并多核对一项“访问表新列里也是同样三项”，18 项检查）。

### 两个修复在新代码上仍然生效

- `db7df57`（员工角色不被追踪、不触发像素）：`Tracker::is_staff()`、`Pixels::allowed()` 里按员工角色判断的那几行都在（主线没有这几行）。在本地站（合并后的代码，追踪表已升到 6）上建账号实测：
  - 投手（销售账号 + 投手角色，界面上开出来就是这样）：`is_staff = true`，`Pixels::allowed() = false`，上报事件被接口拒收（`pc_track_staff`）。
  - 普通销售账号：同上。顾客和匿名访客：照常追踪、照常触发像素。
  - **一个没覆盖到的边角**：只有 `pc_media_buyer` 角色、没有 `pc_staff` 角色的账号不算员工。界面上开不出这种账号（投手版只能在销售账号上打开），所以现在没有影响；要补的话是在两处各加一个 `Staff_Buyer::is_buyer()`，没有做。
- `ea72130`（测试单不发购买、补发任务不重复）：`Pixels::is_test_order()` 和付款回传、“订单已收到”页两处调用，补发任务的 10 分钟缓冲（`CATCHUP_GRACE`）都在。`m7-purchase-dedupe-pure`、`m7-pixels-pure`、真正 WordPress 上的 `smoke-pixels`（48 条）都通过。
- 给热修复包的提醒：这两个提交里除了修复还带着别的改动（每日汇总的弃单数、文档），不能整个摘。只要修复的话，取 `git diff 4059779 8b36cd7 -- wp-content/plugins/peptide-core/includes/class-pixels.php` 的全部，和 `…/includes/tracking/class-tracker.php` 里 `is_staff()` 那一段（同一个文件里另外两行是注册 `Ad_Params`、`Product_Activity`，不属于修复）。**11-13 晚已经按这个范围摘成了单独的分支 `claude/hotfix-a-m6`，见下面“热修复 A 的分支”一节，打包从那里取。**

### 测试

| 范围 | 主线 `4059779` | 合并后 `8b36cd7` |
| --- | --- | --- |
| 仓库里全部 `*-pure.php`（94 / 98 个） | **15 个失败** | 14 个失败，**没有新增** |
| 全部 Node 用例（109 / 111 个） | 19 个失败 | 18 个失败，没有新增 |
| 默认套件 | 83 过 / 1 败 | 90 过 / 1 败（`mail-source-observations`，主线原有） |
| M6 / M7 的 18 个默认条目 | — | 全部通过 |
| 真正的 WordPress（本地拷贝，SQLite）：12 个旧冒烟、广告参数入库、接口、漏斗回放、对账、三档 | — | 全部通过 |

主线原有的 15 个失败（合并后还剩 14 个，这 14 个在合并之前的本分支上也一直失败）：
`client-environment-pure`、`data-dimensions-pure`、`data-product-exposure-pure`、`first-order-offer-pure`、`tracking-impressions-pure`、`tracking-preferences-pure`、`management-addresses-pure`、`provision-customer-domain-pure`、`callback-return-status-pure`、`hub-new-account-defaults-pure`、`hub-return-failure-binding-pure`、`hub-return-status-check-pure`、`provider-site-bindings-pure`、`return-cart-pure`；第 15 个 `tracking-native-visit-classifier-pure` 合并后通过（本分支 11-12 修过它的测试桩）。前 6 个在追踪和数据目录下，归 F4，是历史用例，这次没有动。

### 广告参数存哪一份：我的意见

同意“用访问表的新列，不要 `pc_track_ads`”。补充三点：

1. 访问表的三列现在存的是**编号**（投放链接模板已经改成 `{{campaign.id}}`、`{{adset.id}}`、`{{ad.id}}`）。`pc_ad_ads` 里按广告编号有广告名，也有它所属的广告组编号、广告系列编号，但**广告组和广告系列的名称哪里都没有**。所以来源报表改接之后，“广告”一级能显示名称，“广告组”“广告系列”两级只能显示编号，除非链接模板再带上名称，或者从花费导入里取。
2. 主线的 `clean()` 不拦没被替换的 `{{…}}` 占位符（会当成编号存下来），我的 `Ad_Params::pick()` 会拦。只留一条路的话，建议把这条规则搬进主线的 `clean()`。
3. 拆掉 `pc_track_ads`（类、表、隐私抹除里的两处、3 个用例）并把来源报表改读访问表 + `pc_ad_ads`，大约半天。

**“传编号”对来源报表的影响范围**：只影响来源数据里“广告系列 / 广告组 / 广告”三种分组——管理员的来源页、投手的“漏斗 + 来源”合并页、对应的 CSV 导出和接口 `group_by=campaign|adset|ad`。开关关着（现在的默认，也是线上）时这三种分组没有数据，只有“没有观察到 / 已捕获但未提供此参数”两行；开关打开之后显示的是一串数字。其他报表和七个指标不受影响。数据源定下来之前，可以先把这三个分组选项藏起来（约半小时），没有做。

### 审查发现的 6 个问题：逐条确认（都没有修）

行号是合并后的代码里的。

| # | 位置 | 确认 | 修复工作量 |
| --- | --- | --- | --- |
| 1 | `tracking/class-visit-facts.php` 第 63、129 行 | **属实**。`user_agent()` 原样取浏览器标识，只按字节截到 400，`columns()` 原样放进 `ua`；实测带乱码字节的标识出来的值不是合法 UTF-8。它和来源字段在同一条 UPDATE 里写，MySQL 严格模式下这条语句会整条失败，来源一起丢。**MySQL 上的失败本机复现不了**（本地是 SQLite），要在沙盒实测 | 入库前把标识清成合法文本（型号、系统版本同样），并让事实列写失败时退回只写来源；加乱码用例。约 2 小时，另加沙盒实测。已经丢掉的来源补不回来 |
| 2 | `tracking/class-tracker.php` 第 288 行（主线是第 279 行） | **属实**。`first_visit_id` 是 0 就写成这一次新建的访问。上线前就来过的老访客这一列都是 0，所以他们上线后的第一次访问被记成“首次访问”；素材看板的“首次来源”因此虚高（看板本来有“没写就用最早一次访问”的退路，写错之后退路不再生效） | 代码 1 行（为 0 时写这位访客最早的那次访问）加用例，约 1 小时。**线上要补数据**：每家店一条 UPDATE 把这一列改回最早一次访问，由 F1 执行、先在沙盒演练，约 1–2 小时；超过保留期被清掉的访问找不回来，只能改到现存最早的一次 |
| 3 | `tracking/class-ip-network.php` 第 33 行 | **属实**。`VPN_ASN` 里有 13335，实测 `ip_type( 13335, … )` 返回 `vpn`。13335 是 Cloudflare 的主 ASN，苹果的专用代理（iCloud Private Relay）有一部分出口也在这里，这些是普通 iPhone 用户。`vpn` 在素材看板的意向分层里算“无效流量” | 去掉这一个编号加用例，约半小时。已入库的可以补一条 UPDATE 把 `asn = 13335` 的 `vpn` 清掉，约半小时 |
| 4a | `tracking/class-privacy.php` 第 183 行起 | **属实**。个人数据导出只取开始时间、落地页、设备、订单和来源快照，没有 IP、地区、运营商、浏览器标识、机型、时区、语言；隐私说明的建议文字也只写到来源和媒介。抹除是整行删，不缺 | 导出补字段、说明文字更新、加用例，约 2–3 小时；说明文字要平台所有者过目 |
| 4b | `tracking/class-query.php` 第 69、273 行；`class-staff-buyer.php` 第 67、509 行 | **属实**。投手的菜单里有访客页，白名单放行了整个 `/peptide/v1/track/admin`；访客档案返回 IP，搜索框能按 IP 或 IP 开头一段搜 | 先要平台所有者定“投手能不能看 IP”。按不能做：档案对投手不返回 IP、不允许按 IP 搜、页面不显示，约 2 小时 |
| 5 | `pixels/class-health.php` 第 45 行 | **属实**。统计的是发送记录里这个像素的全部 `facebook` 行。“发送测试事件”按钮发的那条（事件编号以 `test_` 开头）也在里面，所以点一次测试、还没有真实流量时也会显示“有回传、正常”。另外：设置里填了测试事件代码时，所有回传都带着它，记录里分不出来 | 排除 `test_` 开头的行加用例，约 1 小时。要把“测试事件代码开着”也区分出来，得在发送记录里加一列或者在检查结果里提示，另加 1–2 小时 |
| 6 | `tracking/class-ad-params.php` 第 41 行；`creatives/class-schema.php` 第 35 行 | **属实**，两处都只看版本选项。从快照或复制出来的店带着选项、没有表时，写入一直失败且不报错（像素发送记录、离开意图的统计表出过同样的事） | 照 `Pixels\Server::installed()` 的做法每次请求确认一次表在不在，各约半小时加用例。`pc_track_ads` 如果决定不要，它这一半就不用修 |

六条加起来大约一到一个半人日（含用例），另加沙盒实测和线上补数据（第 2、3 条）。

## 2026-11-13 晚：合并主线 `4a161de`、准备模块发布

- 合并主线 `4a161de`（线上 `fd0c316`：素材第二批，追踪表 v7、`first_visit_id` 修复、滚动事件），合并提交 `5d6f255`。唯一的冲突在 `tests/tracking-product-actions-pure.php` 的测试桩（两边修的是同一行，取主线的写法）；归因、表结构、`class-tracker.php` 自动合并。追踪表版本跟主线是 7。
- 员工、测试单两项修复和热修复包 A（`claude/m1-pkg2-hotfix-a` `60de32c`）逐字一致；包 A 里的 `m6-tracker-staff-pure` 也带进来了（`932a0f5`）。
- 按“只优化、不改功能”把会改变线上默认行为的地方都收回开关后面（`1016b2e`、`63d9a42`、`81e0eb4`）：投手合并页默认布局改回页签；按广告拆分改用自己的开关 `peptide_core_track_ads`（不再跟 M6 开关走），关着时来源页下拉、接口参数、页面文案都和线上一样；每日汇总带弃单数加开关 `peptide_core_daily_abandoned`（关着时 `abandoned_daily()` 抛异常，包 A 的 `Hq_Connector::with_abandoned()` 就不加那几项）；商品活跃度接口在开关关着时不注册路由。
- 新增 `tests/m6-release-snapshot.php`（run / compare），本机同一份数据上线上代码和本分支各读一遍：188 项一样（off / shadow / on 三档）；同一串操作写进库里的东西也一样；升级前后表、选项、定时任务一样。
- 发布材料、离完全上线的差距、新发现的退款 bug：`docs/handoffs/m6-m7-release.md`。
- 所有者的原则（11-13）：“优先用站点已经存在的……你的是没经历过测试的”。和线上重叠的一律以线上为准：投手“编辑商品”链接去掉 F4 另写的 `Audience::product_edit_url()`，用线上 WordPress 自己的权限检查；像素这次不发任何文件，没有第二份健康检查或测试接口。逐项核对在发布材料第 6 节。

## 2026-11-13：热修复 A 的分支 `claude/hotfix-a-m6`

给 F1 的热修复包 A 用。**只含两项 bug 修复**，起点是主线 `4059779`，已推送，没有强推，没有上线。没有带任何功能改动，没有动表结构和追踪表版本号（仍是 `'6'`），没有碰素材会话的分支。

| 提交 | 内容 | 代码改动 | 用例改动 |
| --- | --- | --- | --- |
| `04aac82` | 测试单四条路都不发购买；补发任务留 10 分钟缓冲（`ea72130` 整体） | `includes/class-pixels.php` +26 / −2；`includes/pixels/class-acceptance.php` +3 / −3（验收页的三句提示文字） | `m7-purchase-dedupe-pure.php`（新，24 条）、`m7-stubs.php`（新，从 `m7-pixels-pure.php` 里抽出来的测试桩）、`m7-pixels-pure.php`、`m7-pixel-acceptance-pure.php`、`m6-stubs.php`、套件清单加 1 条 |
| `b4aece9` | 员工角色不被追踪、不触发像素（`db7df57` 里只取这一部分） | `includes/tracking/class-tracker.php` +8 / −1（`is_staff()`）；`includes/class-pixels.php` +2 / −1（`allowed()`） | `m6-tracker-staff-pure.php`（新，8 条）、`m7-pixels-pure.php` 加 2 条、`m7-stubs.php` 加员工桩、套件清单加 1 条 |

合计 10 个文件，+480 / −215；其中上线会跑到的代码是 3 个文件，+39 / −7。`db7df57` 里的“每日汇总带弃单数”和文档没有带。

和本分支的关系：这两项修复本分支早就有（`8b36cd7` 合并后仍然生效）。热修复分支上的 `class-pixels.php` 和本分支的完全一样；`class-tracker.php` 只比本分支少注册 `Ad_Params`、`Product_Activity` 的两行（那是功能）。所以热修复进了主线之后，本分支再合并主线时这几处不会有实质冲突。

### 测试（本机，原生 PHP 8.3 / Node 26）

| 范围 | 主线 `4059779` | 热修复分支 `b4aece9` |
| --- | --- | --- |
| 仓库里全部 `*-pure.php` | 94 个，**15 个失败** | 96 个，**同样 15 个失败，没有新增**；多出来的 2 个是新用例，都通过 |
| 全部 Node 用例 | 19 个失败 | 同样 19 个失败，没有新增 |
| 默认套件 | 83 过 / 1 败 | 85 过 / 1 败（`mail-source-observations`，主线原有） |
| M6 的纯测试（10 个） | — | 全部通过 |
| M7 的纯测试（3 个：33 / 24 / 32 条） | — | 全部通过 |

主线原有的 15 个失败（热修复分支上一个不少、一个不多）：`client-environment-pure`、`data-dimensions-pure`、`data-product-exposure-pure`、`first-order-offer-pure`、`tracking-impressions-pure`、`tracking-native-visit-classifier-pure`、`tracking-preferences-pure`、`management-addresses-pure`、`provision-customer-domain-pure`、`callback-return-status-pure`、`hub-new-account-defaults-pure`、`hub-return-failure-binding-pure`、`hub-return-status-check-pure`、`provider-site-bindings-pure`、`return-cart-pure`。

用例确实在看这两项修复：把 `class-tracker.php`、`class-pixels.php` 换回主线的版本，`m6-tracker-staff-pure`、`m7-pixels-pure`、`m7-purchase-dedupe-pure` 三个都失败。

### 在真正的 WordPress 上对照（本地拷贝，SQLite，对外请求全部拦下）

同一份核对脚本（临时的，没有进仓库），21 项检查，分别用热修复分支和主线的代码跑：

- 热修复分支：**21 项全过**。投手（销售账号 + 投手角色）和普通销售账号：算员工、像素不触发；顾客和匿名访客：照常。顾客下的单付款时发一次购买（事件编号 `order_<订单号>`）；付款 10 分钟内补发任务不再发第二次；付款 20 分钟还没发过的，补发任务补一次，之后不再发；测试单付款时和补发时都不发。
- 主线：**8 项失败**，正是要修的这几处——投手和销售账号被追踪、像素照常触发；补发任务在付款后马上再发一次；测试单付款时发购买。

没有在 MySQL 上跑，没有连真实的 Meta；“订单已收到”页那条路只有纯测试覆盖（命令行里模拟不了那个页面）。

### 上线后要知道的一件事

员工下的单本来就不回传购买（订单记 `_pc_px_optout`）。这次修复之后，“员工”里多了投手和销售账号：**投手登录着自己的账号在前台下单，这张单不会发购买事件**（主线现在会发）。投手要用真实下单验证像素时，得退出登录或者开无痕窗口。

还有一个没覆盖的边角（和 11-13 合并时写的一样）：只有 `pc_media_buyer`、没有 `pc_staff` 的账号不算员工；界面上开不出这种账号，没有修。

## 下一步

1. 等 F1 在沙盒上再跑：手册第 5 节的 12 个冒烟用例、第 8 节（接口冒烟）、第 9 节（漏斗回放，接进 critical-path）；F5 的主题和接口合在一起之后用真正的浏览器看一眼弹窗里的两行字。之前的：手册第 3 节的新命令、第 6 节的新用例 F1 第二次已经跑过；数据换成更真实的快照之后第 1、2 步重跑，重点看 `reports.visitor_funnel`（有多少付款没有填地址的记录）和 `reports.paid_orders`（有多少订单不是在下单当天付的款）。F1 发现的问题交回 F4 修。
2. 归 F4 的 12 个冒烟用例等 F1 在沙盒上重跑确认，过了之后由 F1 把 `tests/smoke-suites.json` 里的 status 改回去。
3. 真正的投手账号（F5 的角色）在沙盒上点一遍两个合并页。
4. （11-13 起搁置，见第 6 条）弃单详情、结账流失页读广告参数的地方（以前一直是空的）改成读 `Ad_Params`——那两个页面不归 F4，读法写在 CONTRACT「M6 / M7」第 6 小节，等日常会话接。
5. “还没有接入的地方”里剩下的几项按表里写的原因暂时不动。
6. **后续（这次不做）**：来源报表“广告系列 / 广告组 / 广告”三种分组改读访问表的新列，并拆掉 `pc_track_ads`。前提是平台所有者定了数据源、素材会话那部分稳定下来。要点和工作量见“广告参数存哪一份：我的意见”。在那之前 `pc_track_ads` 保持现状：开关关着不建表、不写入。
7. **后续**：素材会话的 `claude/creative-merge` 进主线之后，再做一次增量合并（它带追踪表 v7，合并时版本号跟主线走）。
8. **等分配**：审查发现的 6 个问题（见“审查发现的 6 个问题：逐条确认”）都没有修。
10. **等 F1**：M6 / M7 模块发布按 `docs/handoffs/m6-m7-release.md`。先要沙盒时段跑第 5.4 节的比对；发布后影子期 7 天。
9. **等 F1**：热修复包 A 从 `claude/hotfix-a-m6`（`b4aece9`）取这两项修复去打包；F1 在 MySQL 沙盒上验证时发现问题交回 F4。进了主线之后，本分支下一次合并主线时确认这几处没有重复。
## 本地 WordPress 拷贝（2026-11-12）

`../tools` 里有 PHP 8.3.32（带 pdo_sqlite）、WP-CLI、WordPress 7.1.4 和 WooCommerce 11.2.1 的源码，`../localwp/site` 是日常会话的本地站（SQLite，HPOS 开着，插件链到主检出目录）。**不要直接用那个站**，拷一份到会话临时目录再用：

1. 新建一个站点目录：WordPress 根目录下的 `.php` 入口文件和整个 `wp-admin` 要**真的拷贝**，`wp-includes`、WooCommerce、SQLite 插件、主题可以用链接。入口文件如果也用链接，PHP 会按链接的真实位置去找 `wp-config.php`，网页请求就落到原来那个站上了（我第一次就这样，有两次请求打到了原站的登录页，它的数据库文件修改时间因此变了；没有登录、没有改任何设置）。
2. `wp-content/plugins/peptide-core` 链到自己的工作目录；`wp-config.php` 拷一份，把 `DB_DIR` 和 `WP_DEBUG_LOG` 改到临时目录，数据库文件 `localwp/db/vialcove.sqlite` 拷过去。
3. 命令行：`../tools/php/php ../tools/wp-cli.phar --path=<站点目录> eval-file …`。
4. 要在浏览器里看：`php -S 127.0.0.1:<端口> -t <站点目录> <路由文件>`。命令行沙盒不让监听端口；不用关沙盒的办法是把这条命令登记到工作目录的 `.claude/launch.json`，用应用内浏览器的预览来启动（F5 的做法，11-12 下午我也是这样跑的）。`.claude/launch.json` 不要提交。`wp-config.php` 里加 `WP_HOME` / `WP_SITEURL` 指到这个地址。

**这套 SQLite 环境和 MySQL 不一样的两处（11-12 下午发现，会让结果看起来像插件的毛病）：**

- `GREATEST()`：SQLite 插件用 PHP 自定义函数实现，而 PHP 的 pdo_sqlite 把传给自定义函数的整数截成 32 位。毫秒时间戳（13 位）经过 `GREATEST( last_ms, … )` 之后变成负数，所以**在本地站上，访问 / 访客的最后活动时间和标签页的最后信号时间都是错的**：实时访客列表是空的，在线访客、访问时长也不对。`localwp` 原站同样如此（我拷过来的库里 508 次访问有 499 次的最后活动时间早于开始时间）。生产是 MySQL，没有这个问题。
- `JSON_UNQUOTE()`：SQLite 插件没有这个函数，商品报表“按款式的加购件数”那句查询在本地直接失败，加购件数是 0、款式行不全。

我在自己的拷贝里放了一个只在本机用的 mu-plugin，在 `query` 过滤器上把 `GREATEST( a, b )` 改写成 `CASE WHEN`、去掉 `JSON_UNQUOTE()` 外层（SQLite 的 `JSON_EXTRACT` 取出来的本来就是不带引号的值），之后两处都和 MySQL 的行为一致。它不在仓库里；别的会话在本地看实时数据、访问时长、商品加购件数之前，需要同样处理，或者直接到沙盒看。

这份环境上做过的事和结果写在 `docs/guides/m6-sandbox-runbook.md` 第 0 节。本地库里原来没有真实订单，我在拷贝里造了 6 张（当天下单当天付、9 天前下单 2 天前付、没付款、没有填地址记录就付款、全 1 测试单、总后台标了退款）。

## 在这台机器上跑测试

机器上没有现成的 PHP。这个会话是这样得到的（会话结束临时目录就没了，要重做，约 3 分钟）：

```bash
export SDKROOT=/Applications/Xcode.app/Contents/Developer/Platforms/MacOSX.platform/Developer/SDKs/MacOSX.sdk
export PATH="/Applications/Xcode.app/Contents/Developer/Toolchains/XcodeDefault.xctoolchain/usr/bin:/Applications/Xcode.app/Contents/Developer/usr/bin:$PATH"
export CC=clang CPP="clang -E"
./configure --prefix="<临时目录>/php" --disable-all --enable-cli --disable-cgi --disable-phpdbg --disable-fpm --enable-mbstring --disable-mbregex --enable-ctype --enable-tokenizer --enable-filter --enable-bcmath --without-pear
make -j8 && make install
```

要点：沙盒里 `/usr/bin/cc`、`/usr/bin/make` 这层外壳每次调用都报“写不了缓存”，报错会混进 configure 的检测结果导致编译失败；把 Xcode 工具链目录放到 `PATH` 最前面、设好 `SDKROOT` 就没有这个问题。

```bash
<临时目录>/php/bin/php -n wp-content/plugins/peptide-core/tests/m6-metrics-pure.php
```

```bash
~/.local/opt/node-v26.13.0-darwin-arm64/bin/node tests/run-data-observations.cjs --php <临时目录>/php/bin/php
```
