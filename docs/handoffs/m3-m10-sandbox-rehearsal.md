# M3 / M10 沙盒演练步骤（F3 交给 F1，2026-11-12）

在 F1 的沙盒（脱敏快照：一个总后台 + 至少两家店，其中一家当母版店）上演练系列和订单模块的升级、回填、同步和恢复。每一步写了命令和“什么结果算通过”。**全程不碰生产。第 5 步的差异报告出来后先交平台所有者确认，确认之前不要做第 6 步里的“开启同步”。**

代码：分支 `claude/m3-series-361018` 的最新提交。先发店铺端（peptide-core），再发总后台（peptide-hq）。下面 `wp` 都是在对应站点目录里运行的 WP-CLI。

## 0. 本机已经跑过什么（2026-11-12，F3）

| 检查 | 环境 | 结果 |
| --- | --- | --- |
| 5 个纯逻辑测试（263 项断言） | 本机 PHP 8.3.32 | 全部通过 |
| `m3-series-sync-smoke.php`（36 项） | 本机店铺副本：WordPress + WooCommerce 11.2.1，HPOS 开，SQLite，5 个商品 | 通过 |
| `m10-order-report-smoke.php`（31 项） | 同上，真订单 | 通过 |
| `m3-m10-hq-smoke.php`（79 项） | 本机总后台副本：SQLite，30 家店铺记录，用店铺副本导出的真目录和真订单摘要 | 通过 |
| `php -l` | F3 名下全部 PHP 文件 | 无语法错误 |

本机没覆盖、要靠沙盒验证的：**MySQL / MariaDB**（本机是 SQLite 翻译层）、总后台和店铺之间**真的 HTTP 调用和签名**、定时任务真的触发、店铺后台里的**只读保护**（命令行不受保护限制，所以冒烟测试测不到）、**图片下载**（本机店铺和母版是同一份文件）、商品多时的耗时。

## 1. 自动测试（沙盒机器上）

```
node tests/run-data-observations.cjs --suite m3-series-pure --suite m3-series-catalog-pure --suite m3-series-sync-pure --suite m10-or03-refund-mark-pure --suite m10-order-report-pure
```

通过：最后一行 `{"passed":5,"failed":0}`。

三个冒烟测试会写数据（结束时自己恢复，但总后台那个会留下一个测试系列和几张订单），只在**沙盒的一次性副本**上跑，站点要是 `WP_ENVIRONMENT_TYPE=local`，否则脚本拒绝运行：

```
# 店铺副本（第二个要求 wp-config.php 里有 PEPTIDE_HQ_URL / PEPTIDE_HQ_SECRET，测试值即可；请求全部被截住，不出网）
PC_SMOKE_THROWAWAY=1 wp eval-file wp-content/plugins/peptide-core/tests/m3-series-sync-smoke.php
PC_SMOKE_THROWAWAY=1 PC_SMOKE_OUT=/tmp/store-orders.json wp eval-file wp-content/plugins/peptide-core/tests/m10-order-report-smoke.php
wp peptide series export --file=/tmp/series.json
# 总后台副本
PC_SMOKE_THROWAWAY=1 PC_SMOKE_CATALOG=/tmp/series.json PC_SMOKE_ORDERS=/tmp/store-orders.json wp eval-file wp-content/plugins/peptide-hq/tests/m3-m10-hq-smoke.php
```

通过：三条都以 `Success: PASS …` 结束。在 MySQL 上跑通这三条，就补上了本机 SQLite 没验证的那部分。

## 2. 总后台升级：跑两遍，结果不变

升级前记下：

```
wp db query "SELECT COUNT(*) sites FROM wp_pchq_sites; SELECT COUNT(*) days, ROUND(SUM(revenue),2) revenue, SUM(orders) orders FROM wp_pchq_daily;"
```

装上新代码后打开一次总后台任意页面（或 `wp eval 'echo 1;'`），然后：

```
wp eval 'echo get_option("pchq_m3_db"), "/", get_option("pchq_m10_db"), "\n";'
wp db query "SELECT id, slug, name, status, catalog_ver, template_ver, campaign_ver, shipping_ver FROM wp_pchq_series; SELECT series_id, series_sync, COUNT(*) n FROM wp_pchq_sites GROUP BY 1,2;"
wp db query "SHOW CREATE TABLE wp_pchq_series\G; SHOW CREATE TABLE wp_pchq_series_products\G; SHOW CREATE TABLE wp_pchq_series_campaigns\G; SHOW CREATE TABLE wp_pchq_orders\G; SHOW CREATE TABLE wp_pchq_refunds\G; SHOW CREATE TABLE wp_pchq_ad_spend\G; SHOW COLUMNS FROM wp_pchq_sites; SHOW COLUMNS FROM wp_pchq_daily;" > /tmp/hq-schema-1.txt
```

通过：

- 两个版本选项是 `1/1`；`wp_pchq_series` 只有一行，`slug = series-1`、`name = 系列 1`；所有店铺 `series_id = 1`、`series_sync = 0`；
- `wp_pchq_sites` 多了 `series_id`、`buyer_name`、`onboarding_state`、`series_sync` 四列，`wp_pchq_daily` 多了 `gross_cents`、`refunded_cents` 两列；
- 升级前记下的店铺数、`pchq_daily` 的天数、销售额合计、订单合计一个都没变。

再强制重跑两遍：

```
wp option delete pchq_m3_db pchq_m10_db
wp eval 'var_dump( PeptideHq\Series_Db::install(), PeptideHq\Orders::install() );'
wp option delete pchq_m3_db pchq_m10_db
wp eval 'var_dump( PeptideHq\Series_Db::install(), PeptideHq\Orders::install() );'
# 再执行一次上面的三条查询，第三条输出到 /tmp/hq-schema-2.txt
diff /tmp/hq-schema-1.txt /tmp/hq-schema-2.txt
```

通过：四个 `bool(true)`；`diff` 没有输出；系列还是一行；店铺归属没变。再手工把一家店改到另一个系列（系列管理页），重跑一遍，那家店不会被改回“系列 1”。

## 3. 店铺升级：什么都不变，然后收到系列信息

每家店装上新代码后：

```
wp peptide series status
wp post list --post_type=product --post_status=publish --format=count
wp eval 'echo count( wc_get_orders( array( "limit" => -1, "return" => "ids", "status" => array( "wc-processing", "wc-completed" ) ) ) ), "\n";'
```

通过：状态显示“还没收到”；商品数、已付款订单数和升级前一样；前台商品页和结账页正常。

让总后台把系列信息发过去（或者等 10 分钟的轮询）：

```
# 总后台
wp cron event run pchq_poll
wp cron event run --due-now
wp eval 'print_r( PeptideHq\Series_Push::lagging() );'
```

通过：`lagging()` 是空数组（或者只剩没更新程序的店）；店铺上 `wp peptide series status` 显示“系列 1（编号 1，代号 series-1）”，四个版本号都是 0，商品没有任何变化。这一步只写了一个选项 `peptide_core_series`。新下的订单带订单元 `_pc_series_id = 1`。

## 4. 订单回填：试运行 → 正式 → 再跑一遍数字不变 → 总后台核对一致

每家店：

```
wp peptide orders backfill --dry-run | tee /tmp/backfill-dry.txt
wp peptide orders backfill | tee /tmp/backfill-1.txt
wp peptide orders backfill | tee /tmp/backfill-2.txt
```

通过：

- 试运行只出报告（已付款订单数、其中测试单数、金额合计、按月分布、缺所属系列的订单数），最后一句是“没有改任何订单，也没有上报”；连续跑两次试运行，输出完全一样；
- 正式运行：“总后台接收”的笔数 = “需要上报”的笔数，“已补”的笔数 = 试运行里“缺所属系列”的笔数；订单数和金额合计与试运行相同；
- 再跑一遍：“需要上报：0 笔”“缺所属系列的订单：0 笔”，订单数和金额不变。

总后台核对（每家店）：订单与退款 → 各店合计 → “核对”，或者：

```
wp eval 'foreach ( array_keys( PeptideHq\Sites::all() ) as $id ) { $r = PeptideHq\Orders::verify_site( $id ); echo $id, " ", is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r ), "\n"; }'
wp db query "SELECT site_id, store_order_id, COUNT(*) c FROM wp_pchq_orders GROUP BY 1,2 HAVING c > 1;"
```

通过：每家店 `"match":true`（总后台的笔数、金额 = 店铺 `backfill` 报告里“已付款订单 − 测试单”的笔数和金额）；第二条查询没有结果（同一张订单只有一行）。测试单（收件信息全填 1 的、店主手工标记的、含演示商品的）在总后台是 `is_test = 1`，不计入合计。用支付方订单号的新订单，`order_number` 是 `hang-…`，`store_order_id` 是店铺内部的订单编号，订单页两个号都能搜到。

## 5. 主商品库导出和差异报告（到这里先停，交平台所有者确认）

总后台：系列管理 → 选母版店（s0001）→ “从母版店导入”（只勾“商品和价格”）→ 每家店点“生成差异报告”。或者用命令行，不经过总后台：

```
# 母版店
wp peptide series export --file=/tmp/series.json
# 每家店（只比较，不改任何东西）
wp peptide series diff --file=/tmp/series.json --out=/tmp/diff-<店铺编号>.json
# 汇总
python3 tools/catalog/series_diff_report.py /tmp/diff-*.json > series-diff-report.md
```

通过：

- 导出的商品数 = 母版店已发布的商品数；
- 母版店拿自己的导出和自己比：一样 = 商品数，有差异、新建、下架都是 0；
- 做差异报告前后，每家店的商品数、价格都没变（`wp peptide series status` 仍然是商品第 0 版）；
- `series-diff-report.md` 生成出来，每家店一节。

**把 `series-diff-report.md` 交给平台所有者。没有确认之前，不要在任何店点“确认并开启同步”。**

## 6. 同步和恢复（沙盒里演练；平台所有者确认差异之后才在生产做）

在沙盒挑一家非母版店：

1. 同步前留底：`wp peptide series export --file=/tmp/before.json`。
2. 总后台系列管理：这家店“确认并开启同步”。几分钟后（或 `wp cron event run --due-now`）这家店的“已同步到”显示商品第 N 版。
3. 店铺：`wp peptide series status` 的商品版本 = 总后台的商品版本，列出一份快照；`wp peptide series diff --file=/tmp/series.json` 显示有差异 0、新建 0、下架 0。
4. 店铺后台用投手账号打开一个商品改价格并保存：被拦下，提示“由系列统一管理”。评价管理、专辑、批次 COA 照常能用。
5. 总后台改一个商品的价格（系列管理 → 主商品库 → 保存价格）。通过：这个系列里已开启同步的店几分钟内前台价格变了；没开启同步的店、别的系列的店价格不变；母版店价格也跟着变，别的内容不动。
6. 同一个版本再推一次（系列管理 → “立即同步”）：店铺的商品修改时间不变（版本不新，直接跳过）。
7. 恢复：系列管理 → 这家店“恢复到同步前”（或店铺上 `wp peptide series restore --yes`）。通过：`wp peptide series export --file=/tmp/after.json` 之后，`before.json` 和 `after.json` 里的 `products` 一样（`exported_at` 不算；原来是“私密 / 待审”的商品恢复后是草稿，属于已知限制）；这家店的系列同步已关闭；运费方案回到同步前。

运费两档（平台所有者确认门槛之后）：系列管理 → 运费 → 保存。通过：已开启同步的店结账时，不满门槛的购物车运费是高档，满门槛是低档；新开的店不用配置就有。

## 7. 退款标记三种情况

用沙盒里一张已付款的测试订单（总后台 订单与退款 → 搜订单号）：

| 操作 | 通过 |
| --- | --- |
| 标记部分退款 | 总后台订单显示“部分退款”；店铺订单多一条备注，订单元 `_pc_refund_cents` = 金额、`_pc_refund_state = partial`，订单状态没变，`wc_get_order( … )->get_refunds()` 是空的；跨店报表里店铺、投手、系列三级的净销售额都少了这个数，三级合计相等；概览页那一天的销售额也少了这个数 |
| 再标记到全额 | “已退款”；`_pc_refund_state = full`；超过订单金额的标记被拒绝 |
| 撤销 | 记录还在，显示撤销人和时间；金额和三级报表恢复；店铺订单元回到 `none` |
| 店铺离线时标记 | 总后台先记下，显示“待写回店铺”；店铺恢复后 10 分钟内（或点“重试回写”）写回 |

查店铺订单：

```
wp eval '$o = wc_get_order( <订单编号> ); echo $o->get_status(), " ", $o->get_meta( "_pc_refund_cents" ), " ", $o->get_meta( "_pc_refund_state" ), " refunds=", count( $o->get_refunds() ), "\n";'
```

## 8. 回退

- 关掉某家店的系列同步：系列管理 → “关闭同步”。需要时“恢复到同步前”。
- 代码回退：表只加不删，旧代码能在新表结构上运行。`pchq_orders`、`pchq_refunds`、`pchq_ad_spend` 可以清空后重新回填。
- 出问题先告诉 F3：贴命令的完整输出。
