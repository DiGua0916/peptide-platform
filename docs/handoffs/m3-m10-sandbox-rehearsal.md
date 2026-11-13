# M3 / M10 沙盒演练步骤（F3 交给 F1，2026-11-12）

在 F1 的沙盒（脱敏快照：一个总后台 + 至少两家店，其中一家当母版店）上演练系列和订单模块的升级、回填、同步和恢复。每一步写了命令和“什么结果算通过”。**全程不碰生产。第 5 步的差异报告出来后先交平台所有者确认，确认之前不要做第 6 步里的“开启同步”。**

代码：分支 `claude/m3-series-361018` 的最新提交。先发店铺端（peptide-core），再发总后台（peptide-hq）。下面 `wp` 都是在对应站点目录里运行的 WP-CLI。

## 0. 本机已经跑过什么（2026-11-12，F3）

| 检查 | 环境 | 结果 |
| --- | --- | --- |
| 5 个纯逻辑测试（279 项断言） | 本机 PHP 8.3.32 | 全部通过 |
| `m3-series-sync-smoke.php`（40 项） | 本机店铺副本：WordPress + WooCommerce 11.2.1，HPOS 开，SQLite，5 个商品 | 通过 |
| `m10-order-report-smoke.php`（32 项） | 同上，真订单 | 通过 |
| `m3-m10-hq-smoke.php`（99 项） | 本机总后台副本：SQLite，30 家店铺记录，用店铺副本导出的真目录和真订单摘要 | 通过 |
| `php -l` | F3 名下全部 PHP 文件 | 无语法错误 |
| 总后台数据库第 1 版升第 2 版 | 本机总后台副本，先用 `332458b` 的代码写入订单和退款，再换新代码 | 数据原样，新列、新索引、新表都在 |

**F1 在沙盒（MySQL）上已经跑过的（11-12，候选含 `332458b`）**：第 2、3、4 步和第 5 步的“自己和自己比”，全部通过，没有要改的问题。**之后 F3 又加了一轮改动**（客户编号、弃单率、总后台数据库第 2 版、只读导出），下次换候选时请重跑第 2 步（版本选项应为 `1/2`，见第 2 步末尾）和第 4 步。

**第二轮（11-12，候选 `c5fb1d8`，含 `d4a2f24`）**：F1 重跑了第 2、4 步，版本 `1/2`，全部通过；两个巡检项在一次轮询里都报正常。各店差异报告（第 5b 节）还没跑，等平台所有者在 F1 的会话里确认导出。

本机没覆盖、要靠沙盒验证的：**MySQL / MariaDB**（本机是 SQLite 翻译层）、总后台和店铺之间**真的 HTTP 调用和签名**、定时任务真的触发、店铺后台里的**只读保护**（命令行不受保护限制，所以冒烟测试测不到）、**图片下载**（本机店铺和母版是同一份文件）、商品多时的耗时。

## 1. 自动测试（沙盒机器上）

```
node tests/run-data-observations.cjs --suite m3-series-pure --suite m3-series-catalog-pure --suite m3-series-sync-pure --suite m10-or03-refund-mark-pure --suite m10-order-report-pure
```

通过：最后一行 `{"passed":5,"failed":0}`。

三个冒烟测试会写数据（结束时自己恢复，但总后台那个会留下一个测试系列和几张订单），只在**一次性副本**上跑。默认只认 `WP_ENVIRONMENT_TYPE=local`；沙盒是 `staging`，要跑的话再加一个 `PC_SMOKE_ALLOW_STAGING=1`（2026-11-12 新增，生产环境永远拒绝），**跑完请重新导入沙盒数据**。总后台那个要求至少登记了 3 家店，沙盒总后台只有一家时第一项检查就会停下（什么都没改），那一条只能在本机跑。这一步是可选的：

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

- 两个版本选项是 `1/2`（2026-11-12 第三轮之后；之前的候选是 `1/1`）；`wp_pchq_orders` 有 `customer_id` 列，`wp_pchq_abandoned` 表存在；从 `1/1` 升上来时原有的 `wp_pchq_orders`、`wp_pchq_refunds` 行数和金额不变；`wp_pchq_series` 只有一行，`slug = series-1`、`name = 系列 1`；所有店铺 `series_id = 1`、`series_sync = 0`；
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

### 5b. 真实各店的差异报告怎么出（2026-11-12，答复 F1 验收记录里“没跑”的那一项）

沙盒只有一家店的数据，真报告需要 6 家店各自的商品。F1 列了两条路（再导 5 份脱敏快照轮流导入沙盒；或者等新代码上了生产再在各店跑 `series diff`）。F3 准备了**第三条路，建议用这条**：每家店只读导出一个商品文件，拿回来离线比较。不用拉整库快照，不用等发布，店铺上不部署任何代码。

| | 办法 A：只读导出商品文件（建议） | 办法 B：5 份脱敏快照轮流进沙盒 | 办法 C：新代码上生产后各店跑 `series diff` |
| --- | --- | --- | --- |
| 在生产店上做什么 | 每家店跑一次 `wp eval-file`，只读 | 每家店导一次整库脱敏快照 | 先发布新代码，再每家店跑一条只读命令 |
| 带出来的数据 | 只有商品、图片地址和哈希、运费方案、店名和域名 | 整库（脱敏后） | 只有差异报告 |
| 写数据库 | 0 条（脚本自己数，会打印出来） | 不写生产库 | 0 条 |
| 要等什么 | 平台所有者同意 | 平台所有者同意 + 5 次导入沙盒 | 第一次正式发布 |

**办法 A 的步骤**（哪台机器、什么时候跑由 F1 定；生产店上跑之前要平台所有者同意）：

1. 把两个文件放到店铺服务器上网站目录之外的地方（例如 `/root/f3-diff/`），**不要放进插件目录**：
   - `tools/catalog/store_products_dump.php`
   - `wp-content/plugins/peptide-core/includes/class-series-sync.php`（本分支的）
2. 每家店（含母版店）跑一次：

   ```
   wp --path=<店铺目录> eval-file /root/f3-diff/store_products_dump.php /root/f3-diff/class-series-sync.php /root/f3-diff/out/<店铺编号>.json
   ```

   它做的事：把 `class-series-sync.php` 这一个类载入到这一次命令里（只定义类，不注册任何钩子），读商品、款式、分类、图片文件（算哈希）、运费方案，写出一个 JSON。输出文件已存在时拒绝覆盖。正常的输出：

   ```
   店铺：<店名>（<域名>）
   商品：全部 N 个（含草稿），其中已发布、能进主商品库的 M 个
   导出期间对数据库的写入语句：0 条
   Success: 已写到 …json（… KB）。
   ```

   **通过：写入语句是 0 条。** 不是 0 就停下告诉 F3（可能是别的插件的后台任务碰巧在这次命令里跑了）。
3. 把 6 个 JSON 拿到任何一个装了本分支代码的 WordPress 上（沙盒，或本机副本），用母版店那一份当主商品库，逐店比较，再汇总：

   ```
   WP="wp --path=<那个 WordPress>" tools/catalog/series_diff_all.sh out s0001 > series-diff-report.md
   ```

   它先拿主店和自己比（必须一个差异都没有，否则停下），再逐店比较，最后汇总。分开做也行：

   ```
   for s in s0002 s0003 …; do
     wp peptide series diff --file=out/<母版店>.json --store-file=out/$s.json --out=diff-$s.json
   done
   python3 tools/catalog/series_diff_report.py --master s0001 diff-*.json > series-diff-report.md
   ```

   `--store-file` 不读当前站点的任何数据，只比较两个文件。
4. `series-diff-report.md` 交平台所有者。JSON 和报告里有各店域名，**不要提交进仓库**（仓库是公开的），放共享笔记目录或直接发给平台所有者。用完删掉服务器上的 `/root/f3-diff/`。

F3 在本机验证过的：在一份跑**主线代码**（`7c6d2d3`，没有系列类）的店铺副本上用这个脚本导出，写入语句 0 条，没有留下任何选项或附件元；导出的文件和新代码 `wp peptide series export --local` 导出的逐项相同；`--store-file` 比较的结果和在店里直接跑 `series diff` 的结果逐字节相同。没验证的：生产的 PHP / WooCommerce 版本和商品数量（s0010 有 92 个商品，每张图片要读一遍算哈希，估计几秒到几十秒）。

**2026-11-12 更新**：平台所有者已同意在 6 家生产店做只读导出，母版店用主店 s0001（上级会话转达的原话：“同意，用主店”）。同意的范围只是只读导出和离线比较，不执行同步、不开启任何店的系列同步。导出由 F1 执行；F1 要等平台所有者在 F1 的会话里直接确认后才开始（转达的同意不算对 F1 的批准）。导出文件**留在服务器上，不拉回开发机**：F1 把 6 个文件服务器对服务器传到后台机，在沙盒店（装 `9647a51` 或更新的代码）上跑 `series_diff_all.sh`，只把报告、导出记录（时间、全部 N / 已发布 M、写入条数、耗时）放到共享笔记目录的 `f3-diff/`；F3 核对后整理成给平台所有者的版本，写在 `docs/handoffs/m3-series-diff-report-<日期>.md`（只写店铺编号，不写域名）。

报告的写法（给不写代码的人看）：一、结论（每家店一样 / 有差异 / 主店有这家没有 / 这家多出来各多少个）；二、需要确认的清单——价格会变的、会下架的本店商品、会停售的款式、会被换掉的文字图片分类、名字一样但编码对不上的；三、各店明细，每处差异两边各是什么；四、比了什么没比什么（库存数量不比）。离线比较的结果比总后台页面上的差异报告多几样细节：描述从哪里开始不同、图片文件名、附加信息的两边取值、运费方案。
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
