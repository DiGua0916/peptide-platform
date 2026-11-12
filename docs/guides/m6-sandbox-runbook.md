# M6 / M7：沙盒对账和影子档观察（给 F1）

2026-11-12（下午更新了第 0、5、6 节）· F4「数据」会话。分支 `claude/m6-metrics-db7036`（已含主线 `7c6d2d3`）。
现有的表结构没有改；新增一张表 `{prefix}pc_track_ads`（访问的广告参数，没有顾客资料，自己的版本选项 `peptide_core_track_ads_db`），部署后第一次请求时自动建，建好后 `wp option get peptide_core_track_ads_db` 是 `1`。开关 `peptide_core_m6_mode` 默认 `off`。下面每一步都写了命令和通过标准，做完把输出贴回共享笔记，F4 来读。

命令都在沙盒那家店的 WordPress 目录里执行。

## 0. 我这边已经做过的

本机用 `../tools` 里的 PHP 8.3.32 + WP-CLI，把 `localwp/site`（WordPress 7.1.4 + WooCommerce 11.2.1，SQLite，HPOS 开着）拷了一份到会话临时目录，插件指向这条分支，在这份拷贝上实跑过（原来的 `localwp` 没有动）：

| 做了什么 | 结果 |
| --- | --- |
| 第 1 步的对账报告 | 退出码 0。造了 6 张订单后：近 7 天旧口径 3 单 100.00，新口径 4 单 120.00，差异归到 `created_outside_range`、`refund_basis` 两个原因里，没有解释不了的 |
| 第 2 步的报表三档冒烟 | 退出码 0，202 项检查。影子档输出和关着时逐字节相同；开着时概览页、运营数据、转化详情的漏斗和 `Data\Metrics` 一致 |
| 写入侧的漏斗规则（开关临时设成开，真正的付款完成流程） | 填了地址再付款 → 访问走到第 5 步；没有填地址的记录就付款 → 停在第 3 步、订单号照样挂上、记一次异常；填地址事件晚到 → 补到第 5 步；全 1 测试单 → 停在第 3 步 |
| F1 清单里归 F4 的 12 个冒烟用例，主线 `976c1fa` 和本分支各跑一遍（11-12 上午，修之前） | 两边逐个相同：6 个全过，6 个有失败 |
| 同样 12 个，修完之后（11-12 下午），两种条件各跑一遍：本地原样；模拟沙盒（只有演示用的简单商品 + 环境在较晚的时机拦掉对外请求） | 两种条件下 12 个都全过，每种条件 453 条断言。见第 5 节 |
| 按广告拆分的整条路径：真正的事件接收 → 入库 → 来源报表三种分组 → CSV → 隐私抹除 | 都对。带 `{{campaign.name}}` 这类没被替换的占位符时只丢广告参数，来源照常记下 |
| 投手的两个合并页（同屏布局），浏览器里看 | 两段都出数，换时间段一起刷新，按 `tab` 滚到第二段，控制台没有报错。只看了桌面宽度 |
| `Metrics::daily()` 近 7 天 | 每天的弃单数加起来等于整段的弃单数 |

这是 SQLite 上的结果。**MySQL 上、真实快照上还没跑过**，所以需要沙盒再跑一遍。
第一次实跑时冒烟脚本就抓到一处真问题（已修）：填地址的事件落在一次没打开过结账页的访问里时，带分组的漏斗和 `Data\Metrics` 数出来不一样。

## 1. 对账报告（开关保持 off）

```bash
wp eval-file wp-content/plugins/peptide-core/tests/m6-reconcile-report-smoke.php
```

只读。把今天、昨天、近 7 天、近 30 天的订单和访客在新旧两种口径下逐张比，差异归到写明的原因里。

通过标准：

- 退出码 0，最后一行是“结果：订单和访客的差异都有解释。”
- 没有“没解释的”订单号。有的话把那几个订单号贴回来，先不要往下做。
- `test_order_rule` 这一项应该不出现（11-11 起新旧两条测试单规则相同）。出现了也贴回来。

“转化率”“弃单激活”两行是定义或数据源变了，脚本只把新旧两个数并排打印，不算通过不通过，贴回来给平台所有者看是否合理。
“漏斗跳步”一行是历史数据里没有填地址记录就下单或付款的访问数，同样贴回来——这个数大说明“填地址”事件没有稳定上报。

## 2. 报表三档冒烟（不改开关）

```bash
wp eval-file wp-content/plugins/peptide-core/tests/m6-reports-switch-smoke.php
```

在这一次运行里用过滤器临时换档，把概览页和 13 张数据报表在 关 / 影子 / 开 下各跑一遍。选项 `peptide_core_m6_mode` 不动，对账日志不写；会留下报表自己的几条短期缓存（最长 5 分钟）。

通过标准：

- 退出码 0，最后一行是“结果：通过（… 项检查）”。
- 没有通过时，脚本会逐条列出是哪张报表、哪一档、哪一项，原样贴回来。

每个时间段下面“影子档记到的差异”那几行不影响通过与否，但请一并贴回来：那就是切到开之后这家店哪些数会变、变多少。

## 3. 影子档观察（要改开关，做完改回去）

```bash
wp option update peptide_core_m6_mode shadow
```

然后像平时一样用后台：打开概览页，数据菜单下每个子页各点一遍、换几个时间段。影子档下页面显示的仍然是旧数字。过一会儿：

```bash
wp eval 'print_r( PeptideCore\Data\Metrics_Switch::log() ); print_r( PeptideCore\Tracking\Funnel_Guard::counters( 7 ) );'
```

通过标准：

- 页面上的数字和切档之前一样（影子不改显示）。
- `differed` 里出现的每个指标名都在下表里，数值的方向和说明一致。出现表里没有的指标名，或者说明解释不了的差异，贴回来。

| 指标名 | 记的是什么 | 正常会看到的差异 |
| --- | --- | --- |
| `overview.totals`、`operations.kpis` | 概览页、运营数据的合计 | 订单数、销售额：晚付款的订单从下单那天挪到付款那天；转化率变小（一人多单只算一人，没有填地址记录的付款不算）；漏斗后四层 |
| `overview.recovery` | 概览页的弃单激活 | 数据源不同（邮件序列表 → 弃单列表），两个数本来就不一样 |
| `reports.paid_orders` | 各报表用的订单 | 只在旧口径或只在新口径里的订单号：下单和付款不在同一个时间段的订单；已退款但付过款的订单 |
| `reports.conversion` | 分组转化率 | 有顾客一人买了多单的那几组变小 |
| `reports.visitor_funnel` | 带分组的八层漏斗后四层 | “支付成功”变少（没有填地址记录的付款）；“添加物流 / 添加支付”可能变多（付了款的都算） |
| `reports.test_order` | 测试单规则 | **应该没有记录** |

观察完改回去并清掉日志：

```bash
wp option update peptide_core_m6_mode off
```

```bash
wp eval 'PeptideCore\Data\Metrics_Switch::reset_log();'
```

## 4. 开着走一单（可选，同样做完改回去）

想在 MySQL 上确认写入侧的漏斗规则时做：

```bash
wp option update peptide_core_m6_mode on
```

用沙盒的货到付款走两单：一单正常填地址；一单想办法不触发填地址事件（最简单的是付款前在后台把订单状态直接改成处理中）。然后：

```bash
wp eval 'print_r( PeptideCore\Tracking\Funnel_Guard::counters( 1 ) ); print_r( PeptideCore\Tracking\Funnel_Guard::anomalies( time() - DAY_IN_SECONDS, time() ) );'
```

通过标准：第二单对应的访问停在第 3 步（访客页里能看到），计数里 `no_address_4` 或 `no_address_5` 加了 1；第一单的访问走到第 5 步。做完把开关改回 `off`。

## 5. 归 F4 的冒烟用例

`tests/smoke-suites.json` 里标成跳过、归 F4 的 12 个，11-12 下午都修了（只改用例，没有改插件代码）。请在沙盒上重跑：

```bash
tools/sandbox/run-smoke.sh <解开的测试目录> smoke-data
```

```bash
tools/sandbox/run-smoke.sh <解开的测试目录> smoke-overview
```

```bash
tools/sandbox/run-smoke.sh <解开的测试目录> smoke-pixels
```

```bash
tools/sandbox/run-smoke.sh <解开的测试目录> smoke-tracking
```

（第一条的 `smoke-data` 会匹配 `smoke-data.php` 和 `smoke-data-*.php`，其中 `smoke-data-exports`、`smoke-data-full-rankings` 等不在这 12 个里，结果可以忽略。）

打包测试目录时要带上 `wp-content/plugins/peptide-core/tests/m6-smoke-fixtures.php`：它不是用例（名字不以 `smoke-` 开头，`run-smoke.sh` 不会去跑它），是 8 个用例共用的准备文件，和用例放在同一个目录里按相对位置引用。

通过标准：12 个都是 `PASS`、失败数 0。输出里以 `INFO` 开头的行不算失败，请一并贴回来：

- `INFO no usable simple product in this store: temporary product #… created` —— 快照里没有可用的简单商品，用例临时建了一个，结束时会删掉。沙盒上 8 个用例应该都有这一行。
- `INFO table … was missing although its version option said installed: created it` —— `smoke-data-apps`：统计表不在，用例补建了。
- `INFO this visitor has N events in all; types: …` —— `smoke-tracking`：见下表最后一行。

每个用例之前在沙盒上为什么失败、怎么改的（原因都先在本地拷贝上造出同样的条件，复现出和你记录的一样的通过数 / 失败数，再改）：

| 用例 | 沙盒上的结果 | 原因 | 改法 |
| --- | --- | --- | --- |
| `smoke-data-conversion`、`-landing`、`-operations`、`-products`、`-rankings`、`-realtime`、`-trends` | `FAIL fixture product` | 快照里没有“已发布、有价格、不是演示商品的简单商品”。`SEED=1` 写入的演示数据也不行：它们带演示标记，含演示商品的订单算测试单 | 找不到时临时建一个（目录里不可见），脚本结束时彻底删除 |
| `smoke-overview` | 19 过 5 败 | 它原来不排除演示商品，拿演示商品下的单被算成测试单 | 排除演示商品，找不到时同样临时建；开头清掉上一次中途停下留下的临时用户 |
| `smoke-data-apps` | 13 过 8 败 | 选项 `peptide_core_app_stats_db` 说“已安装”，表 `pc_app_stats` 却不在，`Exit_Intent::record()` 一条也写不进去 | 用例开头确认表在，不在就补建 |
| `smoke-pixels` | 46 过 2 败 | 沙盒自己拦对外请求的过滤器排在用例的模拟回应后面，回应被换成了错误，发送记录里全是失败 | 用例的过滤器排到最后（`PHP_INT_MAX`） |
| `smoke-data` | 38 过 3 败 | 菜单 9 页变 12 页；“数据”11-04 起顶替了“分析”的位置；夏令时用例假定店铺时区是洛杉矶 | 按现在的菜单断言；在这一次运行里用过滤器固定时区（不改选项） |
| `smoke-tracking` | 90 过 2 败 | ① “回访访客仍在同一次访问”：访客存档 11-10 起也列出正在逛的访问，期望值过时。② “重复事件只存一次”：**本地复现不出来**，推测沙盒里有别的功能（实验、访问分类之类）给同一个访客另记了事件，所以访客的事件总数不是 3 | ① 按现在的规则断言。② 改成只数这一批的三个事件编号；总数不是 3 时打印上面那行 `INFO`。如果这一条在沙盒上仍然失败，说明真的存了重复事件，请把输出贴回来，我查 |

另外有三处和沙盒无关的期望值过时也改了：`smoke-data-conversion`（`utm` 现在是合法分组）、`smoke-data-rankings`（14 张表变 21 张）、`smoke-data-realtime`（11-11 起心跳要带“前台、有操作”的标记，补了一条“开着不动不算在线”）。

**这些用例在 MySQL 上还没有跑过。** 本地拷贝是 SQLite，而且有两处和 MySQL 不一样（`GREATEST()` 把毫秒时间截成 32 位、没有 `JSON_UNQUOTE()`），我在自己的拷贝里用一个只在本机用的补丁抹平了这两处之后才全过；所以沙盒上的结果才算数。

顺带发现、不归 F4 的两件事（已写进共享笔记）：

- 快照带着 `peptide_core_app_stats_db` 这个版本选项，却不带表 `pc_app_stats`。`Exit_Intent::installed()` 只看选项，所以从快照恢复出来的店，离开意图的统计一直写不进去（像素发送记录的同一个问题日常会话 11-11 已经修了）。
- `smoke-data-apps` 之外，别的会话的冒烟用例如果也在沙盒上“模拟回应不生效”，多半是同一个原因：过滤器的优先级比沙盒的拦截早。

默认套件里你标成跳过、归 F4 的 5 个（`data-product-user-ratios-node`、`data-native-search-pure`、`data-product-action-rankings-pure`、`tracking-product-actions-pure`、`experiment-data-pure`）在这条分支上都已经修好，合并之后可以把 status 改回去。这条分支上默认套件是 65 通过 / 3 失败（11-12 下午新登记了 `customer-checkout-observer`，它以前不在任何套件里），剩下 3 个是 `mail-source-observations`（日常会话）、`visitor-intents`、`visitor-product-actions`（F5 说它那条分支上已修）。

## 6. 按广告拆分和同屏数据页

```bash
wp option get peptide_core_track_ads_db
```

应该是 `1`（表已建好）。然后用带广告参数的地址打开店铺首页一次，例如 `/?utm_source=facebook&utm_medium=paid&utm_campaign=sandbox-c&utm_term=sandbox-s&utm_content=sandbox-a`（不要用登录了后台的浏览器），再到后台数据 → 来源数据，分组选“广告系列”。

通过标准：表里有一行 `sandbox-c`，访客数 1。分组换成“广告组”“广告”，分别多出 `sandbox-s`、`sandbox-a` 两列。
这一步要主题那一半（F5 的分支）也在沙盒上才看得到值；只有本分支时这三项不会上报，表里只有“没有观察到”和“已捕获但未提供此参数”两行，这也算正常。

同屏数据页：管理员直接打开 `wp-admin/admin.php?page=pc-data-funnel-sources` 和 `…?page=pc-data-products-landing`。通过标准：每页上下两个报表都有数、没有页签、浏览器控制台没有报错。手机宽度（375）和平板宽度（768）我在本地拷贝上看过并修了三处排版；沙盒上如果有真正的投手账号，请用它点一遍这两页——我只能用过滤器把管理员当成投手看。投手看到的商品名应该是普通文字（没有“编辑商品”的链接），“查看详情”能在页内展开。

## 7. 像素（M7）

沙盒没有像素 ID 和令牌，也出不了外网，完整验收要到生产店按 `docs/guides/m7-pixel-acceptance-checklist.md` 做。沙盒上只需要确认两件事不报错：

```bash
wp cron event run peptide_core_recovery_scan
```

（里面包含每 5 分钟的购买事件补发；没配像素时它什么都不做。）

```bash
wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php test
```

（没配像素时应该报告一个 Facebook 像素都没填；配了但外网被拦时报告“未通（HTTP 0）”。两种都算正常，出现 PHP 报错才是问题。本地拷贝上跑出来是后一种。）
