# M6 追踪与指标：交接（F4「数据」会话）

2026-11-10 · 分支 `claude/m6-metrics-db7036`（已推送，没有合进主线，没有发布）· 基线 `82032bd`，已合并主线到 `f0e58d2`。
对应需求：NF-02（口径部分）、FE-05、ST-05。口径见 `docs/metrics.md`，给别的会话的签名见 `docs/CONTRACT.md`「M6 / M7」。

## 先说最重要的

**纯逻辑测试已经在本机跑过并通过；需要 WordPress / WooCommerce / 数据库 / 浏览器的部分一样都没跑过。**
平台所有者同意后，我在本会话的临时目录里从 php.net 源码编译了 PHP 8.3.36（方法见文末），跑了新写的 8 个用例和仓库里现有的全部纯逻辑用例，结果见“实际做过的检查”。
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

## 实际做过的检查（2026-11-10，本机 PHP 8.3.36 + Node 26.13）

环境：PHP 是从 php.net 的 `php-8.3.36.tar.xz`（校验值和官方公布的一致）在本会话临时目录里编译的最小命令行版（带 mbstring、bcmath、ctype、tokenizer、filter）；Node 用机器上已有的 `~/.local/opt/node-v26.13.0-darwin-arm64`。

| 检查 | 结果 |
| --- | --- |
| `php -l`：全部新增和修改的 PHP 文件（26 个） | 无语法错误 |
| `node --check assets/pixels.js` | 通过 |
| 新用例 `m6-metrics-pure`（54 条断言）、`m6-metrics-switch-pure`（30）、`m6-funnel-guard-pure`（36）、`m6-reconcile-orders-pure`（23）、`m6-reconcile-overlay-pure`（26）、`m6-buyer-data-pages-pure`（31）、`m7-pixel-acceptance-pure`（32）、`m7-pixels-pure`（31） | 全部通过，`E_ALL` 下没有警告 |
| 默认套件 `node tests/run-data-observations.cjs`：主线 `f0e58d2` | 42 通过 / 9 失败 |
| 同一命令：本分支 | 53 通过 / 6 失败；**没有新增失败**，修好了 3 个原有失败 |
| 24 个非默认的可选套件，主线和本分支逐个对比 | 结果完全相同（两边都是 12 个失败，都是历史套件） |
| 插件里全部可以单独运行的测试文件（PHP 和 JS 共 114 个），主线和本分支逐个对比 | 只有变好的：除上面 3 个外，`tracking-native-visit-classifier-pure` 也从失败变为通过 |

第一次运行时的情况：8 个新用例里 7 个一次通过；`m6-funnel-guard-pure` 有 1 条断言失败，原因是测试桩把“异常数”查询误认成了另一条查询（桩的问题，不是实现的问题），改了桩之后通过。`m7-pixels-pure` 是有了 PHP 之后新写的，加载真正的 `Pixels` / `Server` / `Acceptance`。

修好的原有失败（都是测试桩或期望值没跟上主线的改动，实现没有问题）：

- `tracking-product-actions-pure`、`tracking-native-visit-classifier-pure`：桩里的 `Tracker` 缺 `is_automated()`（11-08 加的爬虫过滤）。
- `tracking-store-api-cart-success-pure`：缺 `Recovery::CRON`（11-10 加的付款事件补记）。
- `experiment-data-pure`：数据页数量的期望值还是 11，现在是 12。

默认套件里还在失败的 6 个（主线上同样失败，不是这条分支引起的）：

| 套件 | 原因 | 归属 |
| --- | --- | --- |
| `data-native-search-pure`、`data-product-action-rankings-pure` | 共用的 `data-dimensions-pure.php` 是一串“冻结期望值”。我改掉了前两处过期的（11-08 的漏斗规则、新增的 channel 分组），后面还有：排行需要 `Data\Product_Actions` 而用例没加载、“原有十九项 JSON 不变”的期望值也已经过期 | F4，下一批继续 |
| `data-product-user-ratios-node` | 商品数据页“至少选五列”的界面行为在页面重做后变了，用例没跟上 | F4，下一批 |
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

## 下一步

1. 沙盒或脱敏快照到了之后：`wp eval-file …/m6-reconcile-report-smoke.php`，把结果补到本文件；在沙盒上把开关切到 `shadow` 观察。
2. 按“还没有接入”一节的表继续接入其余报表（现在本机能跑测试了）。
3. 修完上表里归 F4 的 3 个原有失败。
4. 同屏合并的投手数据页（需要浏览器环境）。
5. “按广告拆分”：现在主题和 `Tracking\Attribution` 的白名单都只收 `utm_source`、`utm_medium`，广告系列 / 广告组 / 广告三个参数在上报时就被丢掉了（弃单详情、结账流失页读这几个字段一直是空的）。要做：主题两个脚本放开白名单（F5 / 主题归属方），`Attribution` 放开白名单，来源报表加维度。

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
