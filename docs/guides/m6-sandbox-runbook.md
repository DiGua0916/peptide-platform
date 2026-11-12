# M6 / M7：沙盒对账和影子档观察（给 F1）

2026-11-12 · F4「数据」会话。分支 `claude/m6-metrics-db7036`（已含主线 `976c1fa`）。
没有新表、没有改表结构；开关 `peptide_core_m6_mode` 默认 `off`。下面每一步都写了命令和通过标准，做完把输出贴回共享笔记，F4 来读。

命令都在沙盒那家店的 WordPress 目录里执行。

## 0. 我这边已经做过的

本机用 `../tools` 里的 PHP 8.3.32 + WP-CLI，把 `localwp/site`（WordPress 7.1.4 + WooCommerce 11.2.1，SQLite，HPOS 开着）拷了一份到会话临时目录，插件指向这条分支，在这份拷贝上实跑过（原来的 `localwp` 没有动）：

| 做了什么 | 结果 |
| --- | --- |
| 第 1 步的对账报告 | 退出码 0。造了 6 张订单后：近 7 天旧口径 3 单 100.00，新口径 4 单 120.00，差异归到 `created_outside_range`、`refund_basis` 两个原因里，没有解释不了的 |
| 第 2 步的报表三档冒烟 | 退出码 0，202 项检查。影子档输出和关着时逐字节相同；开着时概览页、运营数据、转化详情的漏斗和 `Data\Metrics` 一致 |
| 写入侧的漏斗规则（开关临时设成开，真正的付款完成流程） | 填了地址再付款 → 访问走到第 5 步；没有填地址的记录就付款 → 停在第 3 步、订单号照样挂上、记一次异常；填地址事件晚到 → 补到第 5 步；全 1 测试单 → 停在第 3 步 |
| F1 清单里归 F4 的 12 个冒烟用例，主线 `976c1fa` 和本分支各跑一遍 | 两边逐个相同：6 个全过，6 个有失败（都是主线上原本就失败的，见第 5 节） |

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

`tests/smoke-suites.json` 里标成跳过、归 F4 的 12 个，我认领。在本地拷贝上的情况（主线和本分支相同）：

| 用例 | 现状 | 原因 |
| --- | --- | --- |
| `smoke-data-operations`、`smoke-data-landing`、`smoke-data-trends`、`smoke-overview`、`smoke-pixels`、`smoke-data-apps` | 本地全过 | 沙盒上失败是因为快照里没有“已发布、有价格、不是演示商品的简单商品”（生产店都是多规格商品），用例找不到可用的商品就退出。要改成用例自己建一个临时商品 |
| `smoke-data-conversion` | 23 过 1 败 | 期望 `group=utm` 是非法参数，现在它是合法的分组 |
| `smoke-data-rankings` | 26 过 1 败 | 期望的表清单还是老的，后来加了几张表 |
| `smoke-data` | 38 过 3 败 | 期望数据菜单是 9 个子页（现在 12 个）；夏令时结束那天的用例 |
| `smoke-data-realtime` | 26 过 4 败 | 11-11 起后台或无操作的心跳不算在线，用例造的心跳没带“前台、在操作”的标记 |
| `smoke-data-products` | 34 过 6 败 | 多规格商品的件数和加购数，还没查 |
| `smoke-tracking` | 16 过 1 败后中断 | “一个访客两个标签页”的实时视图，还没查 |

这些都是用例没跟上后来的改动，下一批我来修；修好之前请保持跳过。

默认套件里你标成跳过、归 F4 的 5 个（`data-product-user-ratios-node`、`data-native-search-pure`、`data-product-action-rankings-pure`、`tracking-product-actions-pure`、`experiment-data-pure`）在这条分支上都已经修好，合并之后可以把 status 改回去。这条分支上默认套件是 61 通过 / 3 失败，剩下 3 个是 `mail-source-observations`（日常会话）、`visitor-intents`、`visitor-product-actions`（F5 说它那条分支上已修）。

## 6. 像素（M7）

沙盒没有像素 ID 和令牌，也出不了外网，完整验收要到生产店按 `docs/guides/m7-pixel-acceptance-checklist.md` 做。沙盒上只需要确认两件事不报错：

```bash
wp cron event run peptide_core_recovery_scan
```

（里面包含每 5 分钟的购买事件补发；没配像素时它什么都不做。）

```bash
wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php test
```

（没配像素时应该报告一个 Facebook 像素都没填；配了但外网被拦时报告“未通（HTTP 0）”。两种都算正常，出现 PHP 报错才是问题。本地拷贝上跑出来是后一种。）
