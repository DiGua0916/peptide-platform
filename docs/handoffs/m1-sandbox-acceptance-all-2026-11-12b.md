# 沙盒验收（第二轮）：主线 + M1 + 四条分支的新候选（2026-11-12，F1）

候选 `c5fb1d8`（本地分支，不推送）= F1 `033128d`（主线 `61ce4f8`，含 M1-4、M1-6）+ F5 `788f32f` + F4 `400db8d` + F2 `27b3c37` + F3 `d4a2f24`。发布包 1182 个文件。

## 结论

- 合并：这次出现了第一处代码文件冲突——`hq/class-plugin.php`，F1 和 F3 都在注册区末尾各加了一行（F3 分支还没同步主线的物流填单两行）。两边取并集即可，属于“只追加一行”约定内的冲突。
- 合并后的测试（本机）：PHP 全部 `php -l` 通过；连跳过的一起跑是 **95 过 / 1 败**，唯一失败是 `mail-source-observations`（日常会话）。
- **开关全关时前台和主线逐字节相同**：首页、购物车、我的账户、商品链接四个页面的状态码和字节数、首页脚本样式清单、管理员菜单、16 个后台页面，换候选前后都相同（都带查询参数绕过整页缓存，比的是真实渲染）。唯一差别是 `/track-order/` 小 23 字节（F5 自己的页面）。上一轮 F2 多出的内联脚本已经没有了。
- **critical-path：通过 46，失败 0，跳过 2**（比主线多 1 项：导航里有“物流填单”）。
- 各模块手册的步骤全部通过；F1 上一批接线第一次在真实 WordPress 里跑通。

## 各模块

**F3（`d4a2f24`）**
- `pchq_m3_db / pchq_m10_db` = `1/2`；删版本选项重跑两遍，四个 `bool(true)`，表结构（153 行，含 `customer_id` 列和 `pchq_abandoned` 表）、系列、归属无差异。
- 轮询后 `lagging()` 为空。
- 回填：两次试运行相同；正式“发出 1 笔，总后台接收 1 笔”（升级加了客户编号，所以原来那一笔要重报）；再跑“需要上报 0 笔”；`verify_site` `match: true`（1 笔 / 4200 分）；无重复行。
- 没跑：三个 smoke、第 6 / 7 步、各店差异报告（等平台所有者确认导出）。

**F4（`400db8d`）**
- 对账报告退出码 0，“订单和访客的差异都有解释”，没有未解释、没有 `test_order_rule`。
- 三档冒烟退出码 0，“通过（206 项检查）”。
- 影子档（用它新给的命令，以管理员身份把概览和 11 张报表接口按 3 个时间段各调一遍）：36 次全部 200；对账日志 `compared`：`reports.paid_orders` 48、`operations.kpis` 15、`reports.conversion` 4；`differed`：`operations.kpis` 4、`reports.conversion` 4，都在它手册的说明表里；3 条明细；`Funnel_Guard::counters(7)` 为空。做完已改回 off 并清日志。
- 广告参数入库（不用浏览器的新用例）：退出码 0，“通过（17 项检查）”。
- 像素：报告“这家店还没有保存 Facebook 像素”（预期）。

**F2（`27b3c37`）**
- 24 组合走查：退出码 0，`RESULT 一致 48，不一致 0，跳过原因 0 种`；系列活动 23 项通过；档位回 off，无残留。
- 归 F2 的 5 个冒烟用例和 F4 名下的 `smoke-data-apps`：全部通过，数字和它预期的一致（40/0、81/0、34/0、23/0、31/0、21/0）。它们在不含 F2 分支的主线上仍会失败，所以 `tests/smoke-suites.json` 里先保持跳过并写明“F2 合进主线时改回 gate”。

**F5（`788f32f`）**
- `smoke-admin-menus`：48 过 2 败。两条失败原文：
  - `FAIL payment settings open under the WooCommerce payments menu`
  - `FAIL wc-settings tab checkout highlights admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM`
- 这一轮没有逐项重做 M9 / M11 / M14 的验收（上一轮对 `a94443b` 做过）；critical-path 的后台页面检查在候选上通过。

**F1 的接线（第一次在真实 WordPress 里跑）**
- `/hq/status` 的 `modules`：有 `pricing_engine`（`mode: off`，今天各项 0）和 `onboarding`（steps / remaining / complete / current）。
- `/hq/summary` 每天的键：`day, orders, revenue, visitors, abandoned, abandoned_open, recall_emailed, recall_recovered`；7 天弃单合计 3。
- 巡检项一次轮询里共 7 条，全部 ok：`pricing_mismatch`、`code_drift` × 4 个组件、`series_out_of_sync`、`order_report_lag`。未处理告警 0。
- 店铺上报 19 个功能键。

## M1-6 在沙盒的实测（候选之前，`033128d`）

- 店铺的前台和后台两份副本都登记了自己的组件版本和清单哈希；两份的哈希和发布包算出来的完全相同（PHP 和 Python 两边算法一致，在 MySQL + 真实目录上成立）。
- 记了一次发布（`wp pchq-release start … finish`）；发布进行中第二个发布被拒绝（发布锁）；进行中轮询不报漂移。
- 人为在前台副本的 `peptide-site/style.css` 末尾加了一行：下一次轮询开出 `code_drift` 告警，写明是哪份副本、哪个组件；恢复文件后下一次轮询告警自动关闭。
- “发布记录”页能打开，显示两份副本“一致”和这条发布记录。

## 沙盒现在的状态

候选 `c5fb1d8`，所有开关关着。沙盒总后台里有两条发布记录（`sandbox-m16`、`sandbox-candidate-c5fb1d8`），当前清单是候选的。冒烟跑完数据库已恢复，8 项健康检查正常，FPM 日志无 fatal。
