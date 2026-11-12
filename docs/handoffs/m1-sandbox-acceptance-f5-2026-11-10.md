# 沙盒验收：F5（M9 投手后台 / M11 已购回访 / M14 主题）— 2026-11-10，F1

对象：`claude/m9-buyer-admin-940389` @ `05e0a59`。沙盒上实际运行的是本地候选提交 `d829bf1` = F1 `65a4920` + F5 `05e0a59`（只在 `docs/CONTRACT.md` 和测试编排文件有合并冲突，都是两边各自在末尾追加，已合并保留双方内容；候选分支不推送）。发布包 1116 个文件，用 `tools/sandbox/refresh.sh code` 换上，8 项健康检查通过。

验收方式：在后台机本机用 curl 和 WP-CLI 做的 HTTP 层检查。登录态用 `tools/sandbox/accept/cookie.php` 生成一小时有效的 cookie 和 nonce，不经过密码。**没有浏览器**（这台开发机没有沙盒的访问账号），所以凡是要“看页面”“点按钮”“按返回键”的项都没验，列在最后。

## 结论

HTTP 层能验的项全部通过，没有发现 F5 代码的问题。发现两件不属于 F5 的事（见“顺带发现”），其中页面缓存那条会影响 F5 开关的生效时间。

## 开关全关时与主线一致 — 通过

- 管理员左侧菜单（12 个顶级、22 行）换代码前后逐行相同；10 个后台页面都是 200。
- 前台首页、购物车、我的账户、`/?vc_product=11006` 的状态码和**字节数完全相同**；首页加载的脚本和样式清单相同。
- `/peptide/v1/buyer/status` 返回 `{"buyer":false}`；首页没有 `buyer-status`、`entry-address` 脚本；`/track-order/` 是 404。
- FPM 日志无 fatal。

## M9 投手版 — 通过（HTTP 层）

测试账号 `acc_buyer`（pc_staff，沙盒里新建）。

- `wp peptide buyer on acc_buyer`：左侧正好 6 个菜单（首页、订单、弃单、数据、广告设置、店铺信息）；后台首页跳到 `pc-buyer-home`（200）；订单列表、单张订单、弃单、数据、实时访客都是 200，页面无致命错误。
- 直接打开以下 22 个地址全部 403：`pc-promotions`、`pc-system`、`pc-staff`、`wc-settings`、`plugins.php`、`users.php`、`edit.php?post_type=product`、`pc-manual-order`、`post-new.php?post_type=product`、`pc-store-design`、`pc-mail`、`pc-shipping`、`pc-apps`、`options-general.php`、顾客页、优惠券列表、`themes.php`、`tools.php`、`export.php`、`wc-status`、`pc-media`、`upload.php`。
- REST（带有效 nonce）：`wc/v3/orders`、`payment_gateways`、`customers`、`settings`、`products`、`coupons`、`wc-analytics/customers` 都是 403；`wp/v2/users?context=edit` 403。
- 订单写操作重放（都带**有效 nonce**，所以 403 来自权限而不是 nonce）：AJAX 改状态、AJAX 退款、AJAX 加备注、订单页保存、批量移到回收站、`delete_all`、批量标记完成、REST 更新订单、REST 删除订单 —— 全部 403。重放后订单状态、备注数、退款数都没变。
- `--must-change-password`：后台首页、数据、订单、弃单全部跳到 `pc-onboarding`。
- `wp peptide buyer off`：账号回到按模块勾选（`mode = modules`），强制改密码标记清除。
- 管理员：菜单与基线相同；员工管理页有“后台版本”一列。

## M11 已购回访 — 通过（HTTP 层）

- `wp option update pc_buyer_recognition on`：`/track-order/` 从 404 变 200，页面里有查订单表单。
- 在沙盒建了一张已付款订单 #11883（货到付款，邮箱 `acc.customer@example.test`）。
  - 邮箱和订单号都对：`found: true`，返回订单号、日期、商品；响应带 `Set-Cookie: pc_buyer=…; Max-Age=31536000; path=/; secure; HttpOnly; SameSite=Lax`。
  - 邮箱不对、订单号不对、别人的订单：三种情况是同一句提示，HTTP 200。
  - 一分钟内第 6 次请求起返回 429（开关关着时的那一次也计数）。
  - 带 cookie 请求 `/buyer/status`：`buyer: true` 和同一张订单；把 cookie 最后一位改掉：`{"buyer":false}`。
  - 带 cookie 打开首页：比基线只多一个 `buyer-status.js`；不带 cookie：脚本清单与基线相同。
- 开关改回 off：首页脚本清单回到基线，`/buyer/status` 回到 `false`。
- 提示一处（不算缺陷，请 F5 确认是不是本意）：订单状态是 `completed` 但没有物流单号时，卡片显示 “Shipped”，`shipment` 三个字段是空的。

## M14 商品地址 — 部分通过（HTTP 层）

- `wp option update pc_entry_address on`：没被缓存的首页里出现 `entry-address.js` 和 `vcEntryAddress = {"enabled":"1","home":"/","category":""}`；页脚上方出现 “Track your order”；`/track-order/` 页面自己不显示这个入口。
- `/?vc_product=11006`、带 `utm_source` 和 `fbclid` 的同一链接都是 200；`/product/retatrutide/` 302 到 `/?vc_product=11006#vc-products`，查询参数保留（与生产 s0010 现在的行为相同）。购物车 200，空购物车时结账跳购物车。
- 地址栏变化、返回键次数、分享链接直接弹出商品、专辑地址 —— 都是浏览器里的行为，没验。

## 没验的（需要浏览器，或依赖别的分支）

1. 投手账号登录后的实际页面：首页数字、切换时间段、当前菜单高亮、`pc-data` 走 iframe 外壳时的菜单。
2. 订单页：写操作按钮是否真的看不见。HTML 源码里仍有 `save_order`、`refund-items`、`order_status` 这些控件（F5 说明是用 CSS 隐藏），服务端已确认拒绝；WooCommerce 管理端脚本请求被 403 时会不会弹错误提示，也要在浏览器里看。
3. 向导 4 步走完、红条消失（第 4 步依赖 F4 的 `pixel-test`）。
4. 已购回访：真实浏览器下单后再进店出现物流卡片、关掉后不再出现；已购顾客不弹营销弹窗（依赖 F2 的 `Popups::suppressed()`）。
5. 商品地址：地址栏、返回键、分享、专辑；Facebook 事件管理工具里没有多出 PageView（要和 F4 一起看）。
6. F5 登记的 8 条用例和主题用例没有在沙盒机上重跑（沙盒机没有 Node；PHP 纯逻辑用例留到 M1-3 一起跑）。

## 顺带发现（不是 F5 的问题）

1. **后台改了设置，前台缓存页最多 5 分钟后才变。** 页面缓存的失效钩子只在定义了 `PEPTIDE_CORE_PAGE_CACHE` 的那份安装里注册。生产上 6 家店的前台配置都开了它，6 份后台配置都没有。所以在店铺后台（或用后台那份跑 WP-CLI）改 `pc_*` 设置、商品、价格时，不会让前台缓存立刻失效，要等缓存到期（默认 300 秒）。沙盒同样如此：打开 M14 开关后，首页（缓存命中，age 295）仍是旧的，带随机参数的地址是新的。对 F5：三个开关打开或关闭后，匿名访客最多要 5 分钟才看到变化；验收时用带参数的地址或等 5 分钟。`class-page-cache.php` 归日常会话，已在共享笔记里告知。
2. **生产没有定义 `PEPTIDE_CORE_TRUSTED_PROXIES`**（6 家店前台配置都没有）。不过前台机 nginx 有 `real_ip_header CF-Connecting-IP`（`/etc/nginx/conf.d/vialcove-cloudflare-realip.conf`），PHP 拿到的 `REMOTE_ADDR` 应当已经是顾客真实 IP。查订单的限流是否按顾客 IP 生效，要在试点店用两个不同网络各试一次确认。
3. `wp/v2/users` 对任何人（包括未登录）返回 200 和 1 个用户，这是 WordPress 默认行为，主线上就有，和投手版无关；M2 安全项里可以一并关掉。

## 沙盒现在的状态

- 代码：候选 `d829bf1`（F1 + F5）。三个开关都是关的。旧代码备份 `/var/lib/vialcove-sandbox/backups/refresh-20261110T161637Z/code`。
- 留下的测试数据：账号 `acc_buyer`（已改回按模块、无任何模块权限）、订单 #11883（已付款的测试订单）、自动建的 `/track-order` 页面。

---

# 复验：2026-11-12，F5 `a94443b`

沙盒候选 `c4b8e78` = F1 `6d121d9`（主线 `976c1fa`）+ F5 `a94443b`，1136 个文件。合并冲突仍只在 `docs/CONTRACT.md` 和测试编排文件（两边各自追加）。注意：主线此时已到 `7c6d2d3`，F5 分支和这个候选都还没包含它。

候选上的默认测试套件（本机）：54 过 / 0 败 / 9 跳过（45 + F5 登记的 9 条）。

## 结论

HTTP 层全部通过，F5 说的两处修复都确认生效。有一处请 F5 看（投手的订单详情页源码里还有三个写表单），一处请平台所有者知道（投手能导出顾客邮箱）。需要浏览器的项仍然没验。

## 开关全关 — 通过

管理员菜单 12 项、16 个后台页面（含主线新加的手动订单、顾客账户页）、首页 60 个脚本样式，换代码前后都相同；首页、购物车、我的账户、商品链接的状态码和字节数相同。唯一差别：`/track-order/`（上次验收时自动建的页面）小了 23 字节，属于 F5 自己的页面。

## M9 — 通过

- 投手 6 个菜单；首页 `pc-buyer-home` 200。
- **命令面板修复确认**：投手首页源码里 `menu_commands` 0 次，`pc-mail`、`pc-system`、`pc-promotions`、`wc-settings`、顾客页的地址都是 0 次。
- **订单修复确认**：原生订单页 302 到 `pc-order&id=…`，详情页 200、无致命错误；带 `pc_native` 是 403。
- 26 个禁止地址全部 403（比上次多了主线新页面：顾客账户、运费预览、物流追踪球、库存区间）。
- REST 8 个端点（带有效 nonce）全 403。
- 订单写操作重放（带有效 nonce）10 种全 403：AJAX 改状态 / 退款 / 备注、原生保存、批量回收、`delete_all`、REST 改 / 删、`pc-order` 上的 POST、`pc_order_save`。订单状态、备注数、退款数、地址都没变。
- **请 F5 看**：投手打开的 `pc-order` 详情页源码里有三个 POST 表单（“作废订单”、备注“保存”、标签），带页面自己发的有效 nonce。我用页面给的字段原样提交，三个都是 403，订单没变——服务端是安全的。但 F5 说详情页对投手只读，这三个表单是没去掉还是用样式藏了，HTTP 层看不出来，要么不输出，要么请在浏览器里确认看不见。
- 订单导出：订单列表页上有导出表单，提交后返回 CSV（`orders-<起>-<止>.csv`），表头含订单号、时间、状态、金额、商品、**顾客邮箱**、国家、广告来源、UTM、承运商、运单号、退款。不带 nonce 直接请求是 403。
- 强制改密码：首页、数据、订单详情都跳 `pc-onboarding`。改回按模块：正常。

## M11 / M14 — 通过

- 开关打开：查订单页 200 有表单；邮箱和订单号都对返回订单并下发 `pc_buyer`（HttpOnly、Secure、SameSite=Lax、一年）；邮箱不对、单号不对是同一句提示；一分钟内第 6 次起 429；带 cookie `buyer: true`，改一位 `false`；带 cookie 首页只多 `buyer-status.js`；不带 cookie 首页资源与开关关时相同；页脚入口出现，查订单页上不显示。
- 商品地址：`entry-address.js` 和 `vcEntryAddress` 出现；带 `utm_source`、`fbclid` 的旧链接 200；`/product/<slug>/` 302 并保留参数。
- 两个开关改回 off 后首页资源回到基线。

## 请平台所有者知道

投手版的订单导出包含顾客邮箱。这是 F5 按需求做的只读下载，但等于投手可以把本店付过款顾客的邮箱整批带走。要不要保留这一列，请定。

## 仍然没验（要浏览器）

投手首页数字和菜单高亮、订单详情页上那三个表单看不看得见、物流卡片的显示与关闭、地址栏与返回键、分享链接、PageView。F5 在共享笔记里写了本机起站并用应用内浏览器看的办法，我下一步建本地站时一起做。

## 沙盒现在的状态

候选 `c4b8e78`（F1 + F5 `a94443b`），三个开关关着；`acc_buyer` 已改回按模块；测试订单 #11883 还在。旧代码备份 `/var/lib/vialcove-sandbox/backups/refresh-20261112T103227Z/code`。
