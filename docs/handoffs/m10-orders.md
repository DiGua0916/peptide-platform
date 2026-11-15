# M10 订单汇总、报表与退款 — 交接（F3，2026-11-10）

需求：HQ-13、OR-03。分支 `claude/m3-series-361018`（和 M3 在同一个分支上）。接口细节在 `docs/CONTRACT.md` 的“M10 订单汇总、报表与退款”一节。

## 做了什么

- **订单汇总**（`hq/class-orders.php`，版本选项 `pchq_m10_db`）：建 `pchq_orders`、`pchq_refunds`、`pchq_ad_spend`，给 `pchq_daily` 加 `gross_cents`、`refunded_cents`。接收店铺上报 `POST /peptide-hq/v1/orders/report`（每批最多 50 条，按店铺 + 订单号覆盖写入，重复上报结果不变）。三级汇总、广告花费手填、和店铺核对合计、巡检项 `order_report_lag`。
- **退款标记**（`hq/class-refunds.php`）：标记（金额、日期、备注）、撤销（记录保留，写撤销人和时间）；每次操作后重算订单的已退款金额和状态、重算每日净额、回写店铺；店铺连不上时记为待回写，每 10 分钟重试。只有总后台管理员可用（页面和函数里各检查一次）。
- **订单与退款页**（`hq/admin/class-orders-page.php`）：订单列表（按系列、店铺、状态、订单号、付款日期筛选）、订单详情里标记退款、退款记录和撤销、各店合计与“核对”。
- **跨店报表页**（`hq/admin/class-report-page.php`）：系列 → 投手 → 店铺三级；订单、订单金额、已标记退款、净销售额、广告花费、ROAS、客单价、访客、转化率；按天手填广告花费。
- **店铺端**（`core/class-order-report.php`）：付款和状态变化时异步上报，每小时补报近 48 小时；`wp peptide orders backfill [--dry-run] [--since=…]` 回填历史已付款订单；`/hq/order-refund` 只写 `_pc_refund_cents`、`_pc_refund_state` 和一条订单备注，**不创建 WooCommerce 退款对象、不改订单状态**；`/hq/orders/digest` 给总后台核对；订单详情里显示退款标记（只读）。
- **共用文件**：`hq/class-plugin.php`、`core/class-plugin.php` 各追加一行；`tests/data-observations-suites.json` 末尾追加 2 条。

## 口径

- 报表只算“已付款且不是测试单”的订单；销售额 = 订单金额 − 已标记退款；时间按付款时间，日期边界用总后台时区。
- 店铺上报的 `paid / is_test / total_cents / paid_at`：F4 的 `Data\Metrics::order_fact()` 合进来后自动改用它；现在用同口径的本地实现。
- `pchq_daily`（概览页用）也变成净额：轮询覆盖之后 F3 按下单日把已标记的退款扣掉，可以反复执行。
- 顾客姓名、邮箱、电话、地址不进总后台，只有邮箱的 SHA-256 和客户编号（`C` + 7 位）。
- 顾客数：按客户编号去重，没有编号的订单退回用邮箱哈希；上一级不是下一级相加（同一位顾客在几家店买过只算一位）。
- 弃单率 = 弃单数 ÷（弃单数 + 订单数 − 召回成功数）（平台所有者 2026-11-14 定）。弃单数含召回成功的，减掉召回成功数是为了同一位顾客不算两次；三个数都取带了弃单数的那些店、那些天的店铺汇总；最高 100%。

## 实际运行过的检查（2026-11-12 更新）

本机有了原生 PHP 8.3.32、WP-CLI 和 localwp 的站点副本，之前“只做过静态检查”的结论作废，下面是实际运行的结果：

| 检查 | 环境 | 结果 |
| --- | --- | --- |
| `php -l` | F3 名下全部 PHP 文件 | 无语法错误 |
| `peptide-hq/tests/m10-or03-refund-mark-pure.php`（71 项，11-12 第三轮加了顾客去重、弃单数和弃单率） | 本机 PHP，纯逻辑 | 通过 |
| `peptide-core/tests/m10-order-report-pure.php`（48 项） | 同上 | 通过 |
| `peptide-core/tests/m10-order-report-smoke.php`（32 项，多了“摘要里带客户编号”） | 本机店铺副本，真订单：摘要不含个人信息、上报、`hang-` 订单号、手工测试单标记、手动订单、总后台连不上后补报、回填试运行 / 正式 / 再跑一遍、退款标记部分 / 全额 / 撤销（不产生 WooCommerce 退款、不改状态） | 通过 |
| `peptide-hq/tests/m3-m10-hq-smoke.php`（99 项，M3 和 M10 共用；11-12 第三轮加了 20 项：客户编号入库和搜索、同一位顾客在两家店只算一位、弃单数入库可重复、从每日汇总的回应里认店、弃单率、报表页新列） | 本机总后台副本，用店铺副本报上来的真订单摘要：重复上报不变、总后台合计 = 店铺合计、按 `hang-` 号搜索、退款三种情况和每日净额、离线重试、三级报表合计相等、订单页和报表页渲染 | 通过 |
| `wp peptide orders backfill --dry-run` | 本机店铺副本 | 能运行，输出正确 |
| 数据库从第 1 版升到第 2 版 | 本机总后台副本：先用上一个提交（`332458b`）的代码建表、上报 1 笔订单、标记 1 笔退款，再换成新代码 | 版本选项变成 2，`pchq_orders` 多了 `customer_id` 列和索引，`pchq_abandoned` 建好；原来的订单（金额、订单号、退款 2300、部分退款状态）和退款记录原样 |
| 沙盒（F1，11-12，MySQL，候选含 `332458b`） | 总后台升级两遍结果不变、店铺升级、订单回填（试运行 → 正式 1 笔 → 再跑 0 笔、`verify_site` 一致）、总后台到店铺的真实签名调用 | 通过，没有发现要改的问题。记录在 F1 分支的 `docs/handoffs/m1-sandbox-acceptance-all-2026-11-12.md`。第三轮的改动当时还没上沙盒，见下一行 |
| 沙盒第二轮（F1，11-12，MySQL，候选 `c5fb1d8`，含 `d4a2f24`） | 总后台数据库升到第 2 版：版本选项 `1/2`，删版本选项重跑两遍结果相同，`customer_id` 列和 `pchq_abandoned` 表都在，系列和归属无差异；订单回填重跑（试运行两次相同 → 正式 1 笔 → 再跑 0 笔，`verify_site` 一致，无重复行）；一次轮询里 `series_out_of_sync`、`order_report_lag` 两个巡检项都报正常；店铺 `/hq/summary` 每天带弃单的四个键（7 天合计 3） | 通过。记录在 F1 分支的 `docs/handoffs/m1-sandbox-acceptance-all-2026-11-12b.md`。弃单数有没有真的写进 `pchq_abandoned`、报表页上的弃单率，F1 的记录里没有提，还没确认 |

实际跑出来并修掉的问题：系列运费内容没变时也会加版本号（存库再读出后 `1000.0` 变成 `1000`，数组比较误判成“改过”，每次保存都让各店重新同步）——之前两轮人工复查都没看出来。另有两处是测试自己的问题（全局变量重名、闭包按值捕获）。

仍然没有验证的（要靠 F1 的沙盒，步骤见 `docs/handoffs/m3-m10-sandbox-rehearsal.md`）：退款标记三种情况在沙盒上（要第二家店，多数操作在页面上点）；弃单数从真实的轮询进来（本机只模拟了回应）；定时任务真的触发；店铺后台里的只读保护（命令行不受保护限制，冒烟测试测不到）；首次同步时下载图片；商品多时的耗时。

## 还没做 / 要别人配合

| 事项 | 谁 |
| --- | --- |
| 在沙盒上演练：回填试运行 → 正式运行 → 再运行一遍（数字不变）→ 总后台“核对”一致；退款三种情况。步骤和通过标准见 `docs/handoffs/m3-m10-sandbox-rehearsal.md` | F1 执行，F3 修问题 |
| 功能键 `orders.report / orders.refund`、巡检类型 `order_report_lag` 登记；数据表脱敏登记 | F1 |
| 店铺侧销售额减去 `_pc_refund_cents`（F4 的 `order_fact()` 已经这样设计） | F4 |
| 投手订单页用 `_pc_refund_cents`、`_pc_refund_state` 显示“已退款 / 部分退款” | F5 |
| 弃单率：F4 的弃单数、F1 的 `/hq/summary`（`d2f05cf`）和 F3 的报表列都已做完，三边字段名一致。还差一步可选的：F1 在轮询存完每日汇总后加一行 `do_action( 'pchq_summary_days', $site_id, $days )`（没有这一行时 F3 用保底办法，从回应里按地址认店） | F1 |
| “弃单率”的口径：2026-11-14 平台所有者已定，弃单数 ÷（弃单数 + 订单数 − 召回成功数），已改 | — |
| 脱敏快照：`pchq_orders.customer_id` 和主线的客户编号用同一套办法处理；`pchq_abandoned` 保留 | F1 |
| 新页面 `pchq-orders`、`pchq-report` 加进 `Menu::navigation_policy()`（可选） | 日常会话 |

## 待平台所有者做的事

1. 上线后每家店运行一次回填：先 `wp peptide orders backfill --dry-run` 看报告，再去掉 `--dry-run`。之后在“订单与退款”页每家店点“核对”。
2. 已知的历史退款由平台所有者在“订单与退款”里补标。
3. 广告花费先手填（跨店报表页下方，每天每店一个金额）。
4. 在“系列管理”里给每家店填投手，否则报表的第二级都显示“未填投手”。

## 2026-11-14：模块发布准备

发布材料：`docs/handoffs/m3-m10-release-f1.md`（给 F1 的一页说明：发什么、和线上的差异、证据、升级和回退、测试、开关、沙盒上要做的、离完全上线还差什么）。要点：分支已合并主线 `4a161de`（合并提交 `4793430`，无冲突；素材第二批对 `class-orders.php`、`class-report-page.php`、`class-creatives.php` 的改动原样带入）；11-12 之后加的报表列和客户编号放进两个默认关闭的开关；用同一份数据比对线上版和发布版，开关关着时只差 hang- 单号前的“#”（修 bug）。

## 2026-11-13：合并主线 `4059779`（含 `251b727`，素材归因已上线）

**背景**：11-13 素材归因分支合进了主线并上线到 6 家店和总后台。它带上的是本分支 **11-10 的旧代码（`5100ad8`）**，所以线上现在有 M3 / M10 的表（`pchq_m3_db = 1`、`pchq_m10_db = 1`）和页面，但没有 11-12 之后的修复。这次把主线合进本分支（合并提交 `99de8a4`，没有 rebase、没有强推），没有上线、没有动生产。

**冲突三处，怎么解的**

| 文件 | 冲突 | 取舍 |
| --- | --- | --- |
| `peptide-hq/includes/class-plugin.php` | 主线把 `Series`、`Orders`、`Creatives` 注册在中间；本分支上次合并时把 `Series`、`Orders` 追加在了末尾 | 用主线的顺序，去掉末尾重复的两行。合并后和主线的这个文件逐字节相同：`Series`、`Orders`、`Creatives` 各注册一次 |
| `peptide-hq/includes/class-series.php` | “运费内容没变也加版本号”这个问题两边各修了一次 | 用主线的写法（把库里读出的值按同样规则清洗后再比）。本分支的 M3 纯测试 64 项、总后台冒烟测试在这个写法上通过。合并后这个文件和主线相同 |
| `peptide-core/tests/m10-order-report-pure.php` | 同一条用例两边各改了一个变量名 | 用主线的名字。是同一条用例，没有重复保留；本分支新增的用例都在（共 48 项） |

`docs/CONTRACT.md`、`tests/data-observations-suites.json` 自动合并，没有冲突：两个 F3 小节各一份、没有重复标题；测试登记 108 条、没有重名，F3 的 5 条都在。主线对 `hq/class-orders.php` 的两处改动（花费来源允许 `csv`）自动合并，保持原样，没有改逻辑。合并后除了 F3 名下的 13 个代码 / 测试文件、`tools/catalog/` 和 F3 的文档，其余文件和主线完全相同。

**迁移没有回退**：`pchq_m3_db` 仍是 `1`，`pchq_creatives_db` 仍是 `1`。`pchq_m10_db` 从 `1` **向前**升到 `2`（只加 `pchq_orders.customer_id` 列和索引、新表 `pchq_abandoned`；每次请求启动时检查，不用人工触发）。如果升到 `2` 之后又把代码退回现在线上的版本，旧代码会把版本号写回 `1`，多出来的列和表留着不用，不影响旧代码。

**测试**（本机，原生 PHP 8.3.32；副本是 SQLite）

- M3 / M10 的 5 个纯测试：64 / 35 / 71 / 71 / 48 项全部通过；报告脚本自检 7 项通过。
- 默认套件（`tests/run-data-observations.cjs`）：主线 83 过 / 1 败，合并后 83 过 / 1 败，失败的是同一个 `mail-source-observations`（日常会话的）。
- 仓库里全部 94 个 `*-pure.php` 逐个用 `php -n` 跑：主线 79 过 / 15 败，合并后 79 过 / 15 败，**是同样的 15 个，没有新增失败**。这 15 个都不是 M3 / M10 的：
  - peptide-core（7）：`client-environment-pure`、`data-dimensions-pure`、`data-product-exposure-pure`、`first-order-offer-pure`、`tracking-impressions-pure`、`tracking-native-visit-classifier-pure`、`tracking-preferences-pure`
  - peptide-hq（2）：`management-addresses-pure`、`provision-customer-domain-pure`
  - peptide-panapay（6）：`callback-return-status-pure`、`hub-new-account-defaults-pure`、`hub-return-failure-binding-pure`、`hub-return-status-check-pure`、`provider-site-bindings-pure`、`return-cart-pure`
- 3 个冒烟测试在合并后的代码上重跑通过：店铺 40 + 32 项，总后台 99 项。订单冒烟测试这次是第一次跑在主线已合入的 F4 `Data\Metrics::order_fact()` 上（以前走的是本地的同口径实现）。
- **按线上状态升级**（总后台副本）：先装主线代码（版本 `1/1/1`），写入 1 笔订单、1 笔退款标记、1 行手填花费、1 行素材推送的花费（`csv`）；换成合并后的代码 → 版本 `1/2/1`，订单、退款、两行花费原样；素材的花费推送接口照常写入（`csv`）；按 `hang-` 订单号能搜到；报表数字正确。

**哪些修复还不在线上、合并后要在沙盒（MySQL）上重新验证**

| 修复 / 功能 | 提交 | 沙盒上要看什么 |
| --- | --- | --- |
| 订单页按 `hang-` 订单号搜索、显示店内编号 | `b41e00a` | 总后台“订单与退款”里输 `hang-…` 能搜到 |
| 订单带客户编号；总后台数据库第 2 版；报表“顾客”一列 | `0c9d2cd` | 在**已有素材表和 `csv` 花费**的总后台库上从 `1` 升 `2`，数据不变（演练第 2 步）；每家店重跑回填（第 4 步），订单带上编号 |
| 报表“弃单”“弃单率”两列 | `0c9d2cd` | 主线还没有 F1 的 `/hq/summary` 弃单字段和 `pchq_summary_days`，所以合到主线后这两列先显示“—”，等 F1 那部分合入才有数 |
| 系列活动：类型清单、内容没变不加版本、每条带 `ver`、钩子多第 5 个参数 | `332458b` | 和 F2 的代码合在一起时走一遍系列活动下发（F2 的走查脚本） |
| 命令行导出 / 比较完全只读；`export --local`、`diff --store-file`；差异报告细节 | `0c9d2cd`、`9647a51` | 沙盒店上 `series_diff_all.sh` 的自检（主店和自己比无差异）、写入 0 条 |
| 素材看板和花费推送在升级后照常 | 主线功能 | 升到第 2 版后素材对比页能打开、店铺导入报表后花费能推上来 |

线上已经有、不用重验的：运费重复保存不加版本（主线自己修了，`d43c45e`）。

**看到但这次没有动的两处**（2026-11-14 平台所有者已定：两件都按线上保持、不改。邮箱哈希不加盐；花费表格推送不覆盖手填——素材第二批已上线的做法。下面第 2 条描述的是第二批之前的旧行为，已被第二批改掉。详见 `docs/handoffs/m3-m10-release-f1.md` 第十节）

1. **邮箱哈希没有加盐**：`peptide-core/includes/class-order-report.php` 第 213 行，`hash( 'sha256', $email )`。两边代码一样，保持原样。
2. **素材的花费推送会覆盖手填的花费，金额为 0 时删掉那一行**：
   - 店铺端 `peptide-core/includes/creatives/class-spend-import.php`：导入 Meta 报表后把受影响日期的全店每日花费推给总后台 `/peptide-hq/v1/creatives/spend`。
   - 总后台 `peptide-hq/includes/class-creatives.php` 的 `handle_spend()`（约 906–921 行）：逐天调用 `Orders::set_spend( …, 'csv' )`。
   - `peptide-hq/includes/class-orders.php` 的 `set_spend()`（约 607–628 行）：`pchq_ad_spend` 每店每天只有一行（主键是店铺 + 日期），写入用的是整行替换，不看原来是 `manual` 还是 `csv`；金额为 0 时直接删行。
   - 所以：某天手填过花费、之后店铺导入的报表里也有这一天 → 手填的被换掉，来源变成 `csv`；导入的报表里这一天花费是 0 → 手填的那一行被删。反过来也一样，在报表页手填会盖掉推送来的数（`admin/class-report-page.php` 第 99 行）。上面“按线上状态升级”的测试里两行花费日期不同，所以没有互相覆盖。
## 2026-11-12：主线新变化的核对结论

- 新订单的 `hang-` 订单号：`store_order_id` 用的是 WooCommerce 内部订单编号，`order_number` 是显示用的号；总后台订单页两个号都显示、都能搜。已实测。
- 测试单标记（`_pc_test_order`，含事后手工标记）：上报、回填、报表都排除。已实测。
- 手动订单 / 付款链接的订单：照常上报。已实测。
- 物流：主线用 WooCommerce 自带的订单履约记录存运单号，总后台的 17TRACK / FedEx 只查询不写订单。开发文档里预留的 `_pc_shipment` 和 `/hq/shipment` F3 没有实现，以主线为准，不再需要。
- 细节见 CONTRACT “M10” 一节的“2026-11-12：主线新变化对订单上报的影响”。

## 2026-11-12：主线 `61ce4f8`（物流填单、腾讯文档）的核对结论

- 主线新增的物流填单是另一条独立的上报：店铺 `POST /peptide-hq/v1/logistics/push`，总后台存在它自己的表里，页面和定时任务都是日常会话的文件。它不读也不写 F3 的 `pchq_orders`、`pchq_refunds`，店铺端也不动 `_pc_hq_reported`、`_pc_refund_cents`、`_pc_refund_state`、`_pc_series_id`。订单上报、回填、退款标记不受影响。
- 两条上报各管各的：物流填单只出“处理中 / 已完成”的订单并带收件信息；F3 的订单汇总不带任何收件信息。以后如果要在物流表里显示“已标记退款”，读 `pchq_orders.refund_state` 即可，现在没有做。
- 合并时 `hq/class-plugin.php` 的注册区又冲突了一次（主线加了 `Logistics`、`Tencent_Docs` 两行），两边都保留。合并后 5 个纯逻辑测试和 3 个冒烟测试重跑通过。

## 2026-11-12：客户编号要不要用于订单汇总和报表（评估结论）

**结论：用，作为补充，不替换邮箱哈希。已经做完。**

- 为什么用：客户编号由邮箱归一后算出（小写、去掉 `+后缀`、Gmail 去掉点），没有邮箱时用手机号，同一个人在所有店都是同一个编号。邮箱哈希只做了小写，`a.b@gmail.com` 和 `ab@gmail.com` 是两个哈希；只留手机号的订单没有哈希。所以认“同一位顾客”编号更准，而且平台所有者在支付中心看到的就是这个编号，订单页能对上。
- 为什么不替换：还没更新程序的店不会发编号；邮箱哈希留着做退路（没有编号的订单用 `e:<哈希>` 当标识），也不用改已有的列。
- 做了什么（都在 F3 自己的文件里，没有动主线的 `Customer_Id` / `Customers`，也没有读 `pchq_customer_links`）：
  - 店铺上报的订单摘要多一个 `customer_id`（`Customer_Id::for_order()`；类不存在时为空串）。
  - `pchq_orders` 加 `customer_id` 列和索引（数据库第 2 版）；格式不对的编号丢掉、订单照收。
  - 跨店报表多一列“顾客”：店铺一级数本店顾客，投手 / 系列 / 合计按编号去重（不是下一级相加，页面上有说明）。
  - 订单页：详情里显示客户编号；搜索框可以输客户编号，一次找出这位顾客在所有店的订单。
- 为什么不直接用主线的 `pchq_customer_links`：那张表归日常会话，由另一条定时任务推送，补报只管近 30 天；订单上报本来就逐笔经过每张订单，回填覆盖全部历史，把编号随订单带上来最省事，报表也不依赖别人的表在不在。
- 上线后要做的：编号进了上报内容的指纹，所以每张订单会重新上报一次。近 48 小时的自动补上；更早的要每家店再跑一遍 `wp peptide orders backfill`（本来上线时就要跑，可重复）。跑之前，老订单只有邮箱哈希，同一个人的新老订单在“顾客”一列里可能被算成两位。
- 没做的：按顾客的复购率、新老客拆分。数据已经够算，但口径没人定过，等平台所有者提需求。

## 已知限制

- `order_report_lag` 用“店铺每日汇总的已付款订单数”和“已上报的订单数”比较。M6 的开关打开后两边对“已付款”的定义如果有出入，可能误报，需要那时再对一次。
- 报表里的访客和转化率来自 `pchq_daily.visitors`（店铺时区的日期），和按付款时间统计的订单口径略有出入，页面上已注明只作参考。
- 多币种没有换算：现在所有店都是美元。
