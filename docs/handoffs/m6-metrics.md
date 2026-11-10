# M6 追踪与指标：交接（F4「数据」会话）

2026-11-10 · 分支 `claude/m6-metrics-db7036`（已推送，没有合进主线，没有发布）· 基线 `82032bd`，已合并主线到 `f0e58d2`。
对应需求：NF-02（口径部分）、FE-05、ST-05。口径见 `docs/metrics.md`，给别的会话的签名见 `docs/CONTRACT.md`「M6 / M7」。

## 先说最重要的

**这一批代码一行都没有运行过。** 这台开发机没有 PHP、Node、Docker、Homebrew，现有的和新写的测试都跑不了。
做过的检查只有三样：逐条手工推演自己写的用例；用脚本检查括号配平；请另一个会话做了一遍只读的静态复查并按结果修改（见文末“实际做过的检查”）。
所以**上沙盒之前必须先在有 PHP 8.1+ 的环境里跑一遍** `m6-*-pure.php` 和现有的数据 / 追踪用例，再谈发布。

开关默认是 `off`。按设计，off 时线上行为和这条分支之前完全一样（见“开关关着时改了什么”一节的逐项说明）。

## 做了什么

| 步骤 | 内容 | 提交 |
| --- | --- | --- |
| 1 | `Data\Metrics` 统一指标入口：访客、转化率、订单、销售额（已付款 − `_pc_refund_cents`）、客单价、弃单数、召回激活率、八层漏斗、按天拆分、单张订单事实 | `2609dce` |
| 2 | 口径文档 `docs/metrics.md`；`docs/CONTRACT.md` 追加「M6 / M7」一节 | `2609dce` |
| 3 | FE-05 漏斗推进规则 `Tracking\Funnel_Guard`：没有填地址的记录不推进到下单 / 支付成功，记异常；填地址事件晚到时补推进 | `e0831a7` |
| 4 | 按店开关 `Data\Metrics_Switch`（关 → 影子 → 开）和对账工具 `Data\Metrics_Reconcile`；概览页、运营数据接入（`Data\Metrics_Overlay`） | `2609dce`、`2cfce80` |
| 5 | ST-05：数据子页对投手合并成两页（`Data\Audience` + `Admin\Data_Pages`） | `fe085b5` |

M7 的部分见 `docs/handoffs/m7-pixels.md`。

## 改动文件

新增（都在 `wp-content/plugins/peptide-core/` 下）：

- `includes/data/class-metrics.php`、`class-metrics-switch.php`、`class-metrics-reconcile.php`、`class-metrics-overlay.php`、`class-audience.php`
- `includes/tracking/class-funnel-guard.php`
- `tests/m6-stubs.php`（共用桩）、`m6-metrics-pure.php`、`m6-metrics-switch-pure.php`、`m6-funnel-guard-pure.php`、`m6-reconcile-orders-pure.php`、`m6-reconcile-overlay-pure.php`、`m6-buyer-data-pages-pure.php`、`m6-reconcile-report-smoke.php`
- `docs/metrics.md`、本文件

修改：

- `includes/tracking/class-tracker.php`：`raise_stage()` 先问 `Funnel_Guard`；`register_hooks()` 注册 `Funnel_Guard`。
- `includes/tracking/class-ingest.php`：新保存了填地址事件后触发 `peptide_core_tracking_address_completed`。
- `includes/data/class-operations.php`：`period()` 的五个核心指标经 `Metrics_Overlay::operations()`。
- `includes/admin/class-overview-page.php`：页面和接口的数据经 `Metrics_Overlay`（新方法 `Overview_Page::data()`）。
- `includes/admin/class-data-pages.php`：投手的两个合并页。
- `includes/class-plugin.php`：只追加一行 `Data\Metrics_Switch::register_hooks();`。
- `tests/data-observations-suites.json`：末尾追加 7 条（`module: M6 / M7`）。
- `docs/CONTRACT.md`：末尾追加「M6 / M7」一节。

没有新表、没有改表结构。新增的选项见 CONTRACT 第 2 小节。

## 开关关着时改了什么

逐项对照“开关关着时线上行为与现在完全一致”：

| 位置 | off 时 |
| --- | --- |
| `Tracker::raise_stage()` | 阶段 ≥ 4 时多读一次选项 `peptide_core_m6_mode`，然后执行和以前一样的 UPDATE；不查事件表、不写计数 |
| `Ingest` | 一批里有新的填地址事件时多触发一个动作，监听方读到 off 立刻返回 |
| `Operations::period()` | `Metrics_Overlay::operations()` 原样返回传入的数组，`Metrics` 不被调用 |
| 概览页 | `Overview::range_data()` / `summary()` 的结果原样输出；缓存名和以前相同 |
| 数据页（管理员） | 菜单多注册两个隐藏的合并页（和“来源”页一样在 admin_head 里从菜单拿掉），12 个原有页面的输出不变 |
| 数据页（投手） | 不受开关控制：只要用户有 `pc_media_buyer` 角色就生效。角色现在还不存在（F5 在做），所以目前对所有人都不生效 |

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

## 还没有接入 `Data\Metrics` 的地方

开发文档说“约 35 个报表类逐步改”。这一批只接了全店合计这一层，下面这些还在用各自的旧算法（开关开着时它们和概览页、运营数据的合计会不一致，差异就是 `docs/metrics.md` 第 4 节那几条）：

| 位置 | 自己算的东西 | 建议怎么接 |
| --- | --- | --- |
| `Data\Operations::period()` 其余 7 项和五层漏斗的“支付成功” | 件数、商品净销售额、退款、顾客数、时长、移动端 | 订单来源换成 `Metrics::order_facts()` 对应的订单（按付款时间、新的测试单规则） |
| `Data\Realtime` 的时间窗卡片、分钟曲线 | 最近 N 分钟的订单数、销售额 | `Metrics::orders( 窗口 )` |
| `Data\Trends` | 每个时间格的订单、销售额、复购 | 复用 `Metrics_Overlay::series()` 的做法 |
| `Data\Visit_Metrics`（落地页、来源、分维度明细共用） | 每组的订单、销售额、转化率 | `order_fact()` 换成 `Metrics::order_fact()`，转化率换成支付成功访客 ÷ 访客 |
| `Data\Conversion`、`Data\Classified_Conversion`、`Data\Dimension_Conversion` 的分组 | 每组销售额和转化率 | 同上 |
| `Data\Products`、`Data\Rankings`、`Data\Page_Conversion`、`Data\Experiments`、`Data\Realtime_Filter`、`Data\Report_Filter` | 商品级销售额；测试单过滤用的是 `Overview::is_test_order()` | 先统一把测试单判断换成 `Metrics::is_test_order()` |
| `Data\Visitor_Funnel`（带分组、回访的八层漏斗） | 填地址之后几层和“支付成功” | 把 `Metrics::funnel_counts()` 的规则搬进去（分组和回访 `Metrics` 现在没有） |
| `Tracking\Query::stats()`（六层阶段漏斗，访客页在用） | 按 `stage` 直接数 | 规则开了之后自然满足“不跳步”，口径不用改 |
| 概览页的商品排名、注册用户 | 旧订单集合 | 低优先级 |

建议的顺序：先做“测试单判断统一”（改动小、对数字影响最直接），再做 `Visit_Metrics`（一处改三个报表），然后 `Trends`、`Realtime`，最后 `Visitor_Funnel`。每接一个加一个对账用例。
这些类都有现成的纯逻辑测试，接入必须在能跑测试的环境里做，所以这一批没有动。

## ST-05 的现状

- 投手（角色 `pc_media_buyer`，由 F5 创建）的“数据”菜单只有两页：「漏斗 + 来源」「商品 + 落地页」。每页顶部两个页签，切换的是原来的四个报表（转化率详情、来源、商品详情、落地页转化），脚本和接口没有动。
- **不是同屏合并**：一页里同时只显示一个报表。同屏要改 `assets/data.js`（现在一页只挂一个报表），需要浏览器环境验证，留到有环境之后。
- 没有在浏览器里看过页面。

## 待确认

1. `docs/metrics.md` 第 6 节的三条（WooCommerce 历史退款、填地址事件丢失时的付款、保留订单）。
2. 订单按付款时间归属是我按“收到支付成功通知的订单”和总后台 `pchq_orders.paid_at` 推出来的，PRD 没有明说按哪个时间。和现在的“按创建时间”相比，晚付款的订单会挪到付款那天。请确认。
3. 弃单数的定义我取的是“待召回 + 召回中 + 召回失败 + 召回成功”（期间内弃过的都算，包括后来召回成功的）。如果投手首页想要的是“现在还没付的”，用 `abandoned_open`（待召回 + 召回中）或者 `abandoned − recall_recovered`。

## 实际做过的检查

- 运行过的自动化测试：**无**（没有 PHP / Node）。
- 手工推演：`m6-*-pure.php` 每条断言都对着实现和桩推过一遍，推演中改掉了 2 处用例期望值算错的地方。
- `python3` 脚本检查了所有新增和修改的 PHP / JS 文件的括号配平（只能发现漏括号，不是语法检查）。
- 独立静态复查：结果和处理见提交记录里 “静态复查” 那一次提交的说明。
- 没有做：`php -l`、现有 75 组数据用例的回归、任何浏览器里的页面检查、任何数据库上的查询。

## 下一步

1. 有 PHP 之后：跑 `m6-*`、`m7-*` 和现有 `data-*` / `tracking-*` 用例，修到全绿。
2. 脱敏快照到了之后：跑对账报告，把结果补到本文件。
3. 按上表继续接入其余报表。
4. 同屏合并的投手数据页（需要浏览器环境）。
