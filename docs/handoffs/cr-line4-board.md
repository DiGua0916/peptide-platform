# CR 素材归因 · 线 4：素材看板、漏参提醒、检查点导出、每日上报（2026-11-11）

分支 `claude/cr-line4-board-a00308`，基于 `claude/creative-merge`（7b14c01）。只改了线 4 名下的文件和新文件，共用文件没动。

## 改了哪些文件

| 文件 | 内容 |
| --- | --- |
| `peptide-core/includes/creatives/class-board-page.php`（原占位） | 看板页面、筛选、漏参提醒、导出列表和手动导出、设置（阈值、站点版本号）、每日上报状态；`register_hooks()` 里登记 admin-post 动作和两个定时任务 |
| `creatives/class-intent-tiers.php`（新） | 意向分层纯函数：高 / 中 / 低 / 无效，访客多次访问合并 |
| `creatives/class-board.php`（新） | 看板数据：访问特征、归因、按 5 种维度汇总、指标、样本不足、漏参判断；`load()` 读库 |
| `creatives/class-export.php`（新） | 检查点判断、导出 JSON / CSV、文件存放和下载、后台提示 |
| `creatives/class-daily-report.php`（新） | 每日上报总后台：请求体、签名、队列、重试；给线 2 的 `rereport()` |
| `peptide-core/tests/cr-board-pure.php`（新） | 173 项纯逻辑检查 |
| `tests/data-observations-suites.json` | 手工追加一行 `cr-board-pure`（没有整体重新格式化） |

## 功能和口径

**意向分层**（`Intent_Tiers`）：
- 无效：访问分类为异常（`Visit_Classifications` 的 abnormal）；`ip_type` 是 hosting / vpn（也认 datacenter、proxy、tor）；停留 < 5 秒且没有任何主动操作、没翻第二页、没滚动；时区所在大洲和 IP 国家所在大洲不同（缺时区时用语言里的地区码，如 `en-US`；只看语言不判）。
- 高：加购或进结账；回访且前 2 个页面内打开了以前看过的商品；企业 / 高校 IP（business、education 等）且深度浏览（≥ 5 页、≥ 3 个商品或 ≥ 3 分钟）。
- 中：看了 ≥ 2 个不同商品或选过规格；看过检测报告 / FAQ（模块 `faq`、`report-feature` 被点，或路径含 /faq、/coa、/lab-report 等）；累计第 3 次以上来。
- 低：其余。冲突取高档，无效优先；任何一项看不出（空、null、不认识的国家）都不判无效。
- 一个访客按“归因访问到范围结束”的全部访问合并后分层（任一次机房 / VPN / 冲突 = 无效；两次都很短不算跳出）。
- 前端目前**不上报滚动**，所以“没有滚动”用“没有主动操作”近似；线 1 以后若加 `scroll` 事件，`visit_features()` 已经认 `scroll` / `product_scroll`。

**看板**（`pc-creatives-board`）：
- 维度：按素材属性（默认，钩子 / 形式 / 卖点 / 语言分组，每组合计 = 总数）、单个素材（带缩略图）、广告、广告组、平台 / 版位。口径：首次来源（默认）/ 最近来源。
- 只算 `source_class = paid` 带来的访客。首次来源 = `first_visit_id`（没写时用最早一次访问）是付费访问且在范围内；最近来源 = 范围内最后一次付费访问。
- 编号：访问的 `creative_code` → `pc_ad_ads.code` → 广告名里的编号；没在素材库登记（或已归档）的都算 `UNREG`。属性以素材库为准。
- 指标：花费、链接点击、进站访客、到达率（访客 ÷ 链接点击）、有效进站率、中高意向占比（÷ 有效）、加购率、进结账率（÷ 有效）、进收银页（阶段 ≥ 4）、订单（阶段 5 且有订单号，按订单去重）、销售额（订单金额减退款）、每有效访客成本、每高意向访客成本、ROAS。分母为 0 显示“—”。
- 有效访客 < 30 的行置灰、标“样本不足”。点行名展开访客编号（最多 200 个），链到 `pc-visitors&visitor=ID` 访客档案。
- 平台 / 版位维度没有花费（Meta 报表按广告导出），花费和成本显示“—”。
- 日期筛选按店铺时区；“站点版本”筛选 = 版本起止时间和所选日期取交集。

**站点版本号**（先手工维护）：选项 `pc_creatives_site_versions` = `[{ver, since_ms, user}]`，在看板底部“设置”里填；填新版本号 = 从保存时刻起算新版本，不能和旧版本号重复。

**漏参提醒**（看板顶部）：
- `fb_untagged` 访客 ÷（付费 + fb_untagged）> 10% 时提醒；
- 某广告某天链接点击 ≥ 20、但那天带这个 ad_id 的付费访客为 0 → 列入“疑似漏填参数”，显示广告名（花费表的广告名优先，其次 `pc_ad_ads`）。
- 两个阈值存在选项 `pc_creatives_board`（`untagged_pct`、`min_clicks`），页面上可改。

**检查点导出**：
- 每小时（`pc_creatives_checkpoint_tick`）数付费访客（首次来源口径）：累计 100 / 300 / 500 / 1000，以及当前版本满 500、1000 时生成一份；一次跨过多个节点只生成一份，节点都记为已完成（选项 `pc_creatives_checkpoints_done`）。
- 内容（`format: 1`）：版本日志、意向分层定义、三套数据（`all` 全量 / `current_version` 本版本 / `previous_version` 上一版本，没设版本时只有全量），每套有合计、意向分层、未带参访客、人群分布（国家、省州、设备、内置浏览器、平台、版位、语言、IP 类型、时区，各带意向分层）、按素材、按属性。不含访客编号。
- 手动导出：看板上按日期导出，只有 `range` 一套。
- 文件在 `uploads/peptide-creatives-exports/`（`.htaccess` 拒绝访问 + `index.php`），文件名带随机数，只能通过 `admin-post.php?action=pc_creative_export&op=download`（CAP_VIEW + nonce）下载。保留最近 60 份。
- 提示：有没看过的检查点导出时，后台顶部给有 CAP_VIEW 的人（投手、店铺管理员）一条提示；打开看板后不再提示（用户 meta `pc_creatives_export_seen`）。
- 所有写操作都走 `Module::ACTION_EXPORT`（投手已放行），用 `op` 区分：`download`、`manual`、`settings`。

**每日上报**：
- 每小时（`pc_creatives_report_tick`）把店铺时区的“昨天”放进队列（每天一次），然后报到时间的日期（每次最多 7 天）。口径：当天最近来源、当天行为、当天花费；`Contract::report_payload()` 生成，签名同订单上报，带 nonce。
- 200 完成；501、5xx、429、401/403/404/408/409/425、网络失败 → 1、2、4、8、16 小时后重试，之后每天一次，最多 20 次；400 / 422 等 → 放弃并记日志。
- 总后台没配置（`Order_Report::enabled()` 为 false）时整次跳过，队列保留。
- 看板底部显示待报日期和最近 10 次结果（501 显示“总后台还没开通素材日报，会继续重试”）。

## 给线 2 调用的方法

```php
\PeptideCore\Creatives\Daily_Report::rereport( array $days ): int
```
素材登记 / 改属性 / 花费导入之后调用，`$days` 是受影响的店铺时区日期（`YYYY-MM-DD`）。只收最近 62 天内、不晚于昨天的合法日期，放进队列并在 1 分钟后后台上报（总后台按天整体覆盖）；返回进队列的天数。导入花费时传导入范围 `day_from`–`day_to` 内的日期即可；改素材属性时可以传这个素材有数据的日期（简单做法：最近 62 天）。

## 怎么验证的

- `php -l`：5 个类文件和测试文件都通过。
- 纯 PHP：`php -n wp-content/plugins/peptide-core/tests/cr-board-pure.php` → `PASS 173`。覆盖意向分层每条规则（含缺数据、冲突、合并）、访问特征、5 种维度汇总、首次 / 最近来源、UNREG、花费关联、指标 0 分母、样本不足（29 / 30）、漏参两条、检查点（含跨节点、版本节点、新版本重新计）、导出字段和 CSV、上报请求体和 `tests/fixtures/creatives/report-sample.json` 一致、签名按总后台算法验证、501 / 网络失败 / 400 / 最大次数、队列和退避、页面参数和格式。
- 全部套件：`node tests/run-data-observations.cjs --php tools/php/php` → 77 通过、1 失败；失败的是 `mail-source-observations`（基线同样失败，需要已安装 WordPress 的目录）。基线 76/1，多出的 1 个通过是新套件。
- 本地 WordPress（把 `../localwp/site`、`../localwp/db` 复制到临时目录，peptide-core 软链接到本工作区，SQLite，只用 WP-CLI，没起 Web 服务，没用 8766 端口）：
  - 写入 130 个假访客（vid 以 `l4fake` 开头，is_test = 1）、2 个素材、3 条广告、6 行花费（含一条有点击没访客的广告）。
  - `Board::load()` 在 SQLite 上能跑，5 种维度汇总结果合理；漏参列出那条广告的两天；页面在默认、按素材、按平台（最近来源）、按版本 + 按广告四种参数下都能渲染，包含漏参提醒、样本不足、检查点导出、每日上报各块。
  - 检查点：付费访客 117 → 生成“累计 100 访客”导出（全量 + 本版本两套），第二次检查不重复生成；手动导出成功；JSON / CSV 写入、`.htaccess` 已建。
  - 每日上报：未配置总后台时返回 not_connected、队列保留；临时定义 `PEPTIDE_HQ_URL` / `SECRET` 并用 `pre_http_request` 依次模拟 501 → 网络超时 → 200：队列按 1 小时、2 小时重试，最后成功出队，日志和页面正确。
  - 用总后台的 `PeptideHq\Creatives::clean_report()` 校验实际生成的请求体：接受，0 行被拒，整理前后完全相同。

## 没验证的

- 没在浏览器里看过页面（只看了 WP-CLI 渲染出的 HTML 内容），也没用投手账号实际登录点过；下载链接走 `admin-post.php` 的 GET，按 `Staff_Buyer::classify()` 的规则应放行，没有实测。
- 没有对真实总后台发过请求（只模拟了 HTTP 回应）；线 3 实现 `store_rows()` 后需要联调一次。
- 线 1 的真实采集数据还没有：`source_class`、`first_visit_id`、`ip_type` 等都是假数据。WP 定时任务没有真正等到触发，是直接调用 `tick()` 测的。
- 访问分类读的是 `Visit_Classifications::for_visits()`（只认 is_test = 0 的访问），假数据是 is_test = 1，所以实测里“机器人”一条没走到，只有纯 PHP 测试覆盖。
- 没在 MySQL 上跑过（只有 SQLite）；`Export::paid_visitors()` 用了关联子查询，日访客几百的规模没问题。

## 需要联调时改共用文件的地方

- `ip_type` 取值：线 1 现在只写 `vpn / hosting / mobile / residential`，“企业 / 高校 IP”那条规则要等线 1 能判断出 `business` / `education`（或别的名字，告诉我对齐 `Intent_Tiers::ORG_IP`）。
- 国家：访问表没有国家列，我从 `page_view` 事件的 `page_context.geo.country_code` 取；线 1 如果加了国家列（或 `geo_region` 带国家代码），在 `Board::visit_features()` 改一行即可。建议在 `docs/CONTRACT.md` 的 v5 列里补一个 `geo_country`。
- 滚动：前端没有 `scroll` 事件。如果要严格按“没有滚动”判无效，需要线 1 / 主题加一个轻量滚动事件（`Tracking\Ingest` 的白名单是共用文件）。
- `docs/CONTRACT.md` 的 CR 一节建议补：每日上报的口径（当天最近来源）、`Daily_Report::rereport()`、导出格式 `format: 1` 的字段、选项名 `pc_creatives_site_versions` / `pc_creatives_board`。
- `PEPTIDE_CORE_VERSION` 没动。

## 下一步

1. 线 1 合入后用真实访问数据再跑一次看板和导出，核对意向分层的比例是否合理。
2. 线 3 实现写入后联调每日上报（预期 501 变 200，队列里积压的日期会自动补报）。
3. 线 2 在导入 / 登记后调用 `rereport()`。
4. 浏览器里用投手账号看一遍页面和下载。
