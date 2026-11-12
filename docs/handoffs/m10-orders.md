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
- 顾客姓名、邮箱、电话、地址不进总后台，只有邮箱的 SHA-256。

## 实际运行过的检查（2026-11-12 更新）

本机有了原生 PHP 8.3.32、WP-CLI 和 localwp 的站点副本，之前“只做过静态检查”的结论作废，下面是实际运行的结果：

| 检查 | 环境 | 结果 |
| --- | --- | --- |
| `php -l` | F3 名下全部 PHP 文件 | 无语法错误 |
| `peptide-hq/tests/m10-or03-refund-mark-pure.php`（59 项） | 本机 PHP，纯逻辑 | 通过 |
| `peptide-core/tests/m10-order-report-pure.php`（47 项） | 同上 | 通过 |
| `peptide-core/tests/m10-order-report-smoke.php`（31 项） | 本机店铺副本，真订单：摘要不含个人信息、上报、`hang-` 订单号、手工测试单标记、手动订单、总后台连不上后补报、回填试运行 / 正式 / 再跑一遍、退款标记部分 / 全额 / 撤销（不产生 WooCommerce 退款、不改状态） | 通过 |
| `peptide-hq/tests/m3-m10-hq-smoke.php`（79 项，M3 和 M10 共用） | 本机总后台副本，用店铺副本报上来的真订单摘要：重复上报不变、总后台合计 = 店铺合计、按 `hang-` 号搜索、退款三种情况和每日净额、离线重试、三级报表合计相等、订单页和报表页渲染 | 通过 |
| `wp peptide orders backfill --dry-run` | 本机店铺副本 | 能运行，输出正确 |

实际跑出来并修掉的问题：系列运费内容没变时也会加版本号（存库再读出后 `1000.0` 变成 `1000`，数组比较误判成“改过”，每次保存都让各店重新同步）——之前两轮人工复查都没看出来。另有两处是测试自己的问题（全局变量重名、闭包按值捕获）。

仍然没有验证的（要靠 F1 的沙盒，步骤见 `docs/handoffs/m3-m10-sandbox-rehearsal.md`）：MySQL / MariaDB 上的升级和写入（本机是 SQLite 翻译层）；总后台和店铺之间真的 HTTP 调用和签名；定时任务真的触发；店铺后台里的只读保护（命令行不受保护限制，冒烟测试测不到）；首次同步时下载图片；商品多时的耗时。

## 还没做 / 要别人配合

| 事项 | 谁 |
| --- | --- |
| 在沙盒上演练：回填试运行 → 正式运行 → 再运行一遍（数字不变）→ 总后台“核对”一致；退款三种情况。步骤和通过标准见 `docs/handoffs/m3-m10-sandbox-rehearsal.md` | F1 执行，F3 修问题 |
| 功能键 `orders.report / orders.refund`、巡检类型 `order_report_lag` 登记；数据表脱敏登记 | F1 |
| 店铺侧销售额减去 `_pc_refund_cents`（F4 的 `order_fact()` 已经这样设计） | F4 |
| 投手订单页用 `_pc_refund_cents`、`_pc_refund_state` 显示“已退款 / 部分退款” | F5 |
| 弃单率进报表：要店铺把弃单数放进每日汇总（`/hq/summary` 归 F1，数字来自 F4） | F1 + F4，之后 F3 加一列 |
| 新页面 `pchq-orders`、`pchq-report` 加进 `Menu::navigation_policy()`（可选） | 日常会话 |

## 待平台所有者做的事

1. 上线后每家店运行一次回填：先 `wp peptide orders backfill --dry-run` 看报告，再去掉 `--dry-run`。之后在“订单与退款”页每家店点“核对”。
2. 已知的历史退款由平台所有者在“订单与退款”里补标。
3. 广告花费先手填（跨店报表页下方，每天每店一个金额）。
4. 在“系列管理”里给每家店填投手，否则报表的第二级都显示“未填投手”。

## 2026-11-12：主线新变化的核对结论

- 新订单的 `hang-` 订单号：`store_order_id` 用的是 WooCommerce 内部订单编号，`order_number` 是显示用的号；总后台订单页两个号都显示、都能搜。已实测。
- 测试单标记（`_pc_test_order`，含事后手工标记）：上报、回填、报表都排除。已实测。
- 手动订单 / 付款链接的订单：照常上报。已实测。
- 物流：主线用 WooCommerce 自带的订单履约记录存运单号，总后台的 17TRACK / FedEx 只查询不写订单。开发文档里预留的 `_pc_shipment` 和 `/hq/shipment` F3 没有实现，以主线为准，不再需要。
- 细节见 CONTRACT “M10” 一节的“2026-11-12：主线新变化对订单上报的影响”。

## 已知限制

- `order_report_lag` 用“店铺每日汇总的已付款订单数”和“已上报的订单数”比较。M6 的开关打开后两边对“已付款”的定义如果有出入，可能误报，需要那时再对一次。
- 报表里的访客和转化率来自 `pchq_daily.visitors`（店铺时区的日期），和按付款时间统计的订单口径略有出入，页面上已注明只作参考。
- 多币种没有换算：现在所有店都是美元。
