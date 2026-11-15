# M11 已购回访 交接（F5，2026-11-10；2026-11-14 按 Q20 方案 A 改完）

分支 `claude/m9-buyer-admin-940389`（M9、M11、M14 在同一个分支上）。11-10 的 M11 代码（`99d23e8`）已经随素材归因版本在线上，但开关从没开过；11-14 按方案 A 改的是 `c7e7d52`，还没发布（随 F5 模块 11-23 发布）。

对应需求：FE-10（FE-11 查订单交给线上物流面板）。接口约定在 `docs/CONTRACT.md` 的「M11 已购回访」。发布材料在 `docs/handoffs/m9-m11-m14-release.md`（第七节）。

## 2026-11-14：Q20 定为方案 A，已改完（`c7e7d52`）

平台所有者原话：“以后遇上类似的问题，优先用站点已经存在的，因为那个是我亲自加的，而不是用你这块后面自己写的，你的是没经历过测试的。”

- **下线**：物流卡片（`assets/buyer-status.js`、`buyer-status.css`）、`/track-order` 页面（开关打开时不再自动建）、`[pc_track_order]`（留一个输出空字符串的处理）、`POST peptide/v1/order-lookup`、`GET peptide/v1/buyer/status`、`core/class-order-lookup.php`、`Buyer_Status::card() / shipment() / state()`。
- **留下的只有一件事**：认出已购顾客 → F2 的过滤器 `pc_marketing_popups_suppressed` 返回真、页头输出 `window.pcBuyer`。认的方式：cookie `pc_buyer`（签发和校验代码逐字没动，线上发出去的照样认），或**已登录且账户名下有已付款订单**（新加，处理中 / 已完成、有付款时间、不是测试单，同一次请求只查一次）。仍在 `pc_buyer_recognition` 后面，默认关。
- **新挂的钩子**：动作 `pc_track_order_verified`（参数是 `WC_Order`）。线上物流面板核对成功时由日常会话触发，F5 把付过款、非测试单的订单记进 cookie。日常会话的文件没改，生产冻结，之后再接。
- **页脚 “Track your order”**（主题）：改成按钮，用线上物流面板自己的 `[data-pc-open-track]` 打开物流面板；物流球关着（`pc_track_ball = 0`）的店不显示；开关关着时和线上一样不显示。
- 顺带：投手订单导出里的承运商、运单号原来读 `Buyer_Status::shipment()`，改成读线上物流用的 `Shipping_Tracking::customer_view()`；投手订单列表（`pc_buyer_extras`）去掉“来源广告”“运单号”，用线上的列（见 `m9-buyer-admin.md` 第十一轮）。
- 功能声明多一项 `m11.plan_a => 1`。

**实际检查**（本机 SQLite 副本，浏览器 + WP-CLI）：`m11-buyer-status-pure` 59 项、`m14-storefront-identity-pure` 17 项通过；开关关时访客页面和线上同库逐行一致，两个下线接口返回 404；开关开时：新访客不认、页脚按钮打开线上物流面板；无订单的登录账户不认；有已付款订单的登录账户认出；退出后走 `/?pc_paid=…&pc_key=…` 落地，下一页认出（cookie HttpOnly）；物流球关掉后按钮消失；打开开关不建页面。**没验的**：营销弹窗真的不弹（要 F2 的代码，F2 11-18 上线）；MySQL 沙盒。

**11-14 合主线 `3a297df` 后又核对的两处**：

- 主线 `1a4c189` 给老顾客补建账户（按下单邮箱建账户、把老订单归到名下）：M11 “已登录且名下有已付款订单”认的就是订单上的账户，补建之后老顾客登录同样认得出，不重复。
- 主线 `8cdb833` 斗篷信任 IP 会给付过款的设备发签名 cookie `pc_buyer_trust`，和 `pc_buyer` 是同一件事。线上的判断方法是私有的，要日常会话加公开方法后 M11 才能改读它（约 0.5 天，写在 CONTRACT M11 一节的配合表里）；在那之前 M11 仍用 `pc_buyer`，开关默认关，线上不会两套同时出现。

下面“和主线 11-11 新功能的重叠”和“11-10 原来做了什么”是方案 A 之前的记录，留作历史；以本节和 CONTRACT 为准。

## 和主线 11-11 新功能的重叠（2026-11-12 的分析；11-14 已定方案 A，见上一节）

日常会话 11-11 在主线上做了顾客账户、右下角物流球和物流面板、付款成功弹窗里的 Track my order、17TRACK / FedEx 物流记录、`hang-` 订单号。读的是合并后的 `core/class-accounts.php`、`class-track-widget.php`、`class-paid-popup.php`、`class-order-number.php`、`class-fedex-track.php`、`assets/track-widget.js`、`assets/account-gate.js` 和总后台的 `class-track17.php`。

### 两边各自怎么做

| | 主线（账户 + 物流面板） | M11（`pc_buyer` cookie + 查订单 + 物流卡片） |
| --- | --- | --- |
| 怎么认出已购顾客 | ① 登录的顾客账户（付款成功时自动建，账号是下单邮箱，顾客收邮件自己设密码）；② 这台设备的 localStorage `pcTrackOrders`（订单号 + 订单密钥，最多 5 张，付款成功弹窗写入）；③ 订单号 + 下单邮箱 | ① 签名 cookie `pc_buyer`（订单号列表，HttpOnly，1 年；付款成功落地页、订单已收到页、查订单成功时写入）；② 下单邮箱 + 订单号 |
| 服务器认不认得出 | 登录了认得出；只在 localStorage 里的认不出（有意这样做，首页缓存不受影响） | 认得出（带 cookie 的访客本来就不走首页缓存） |
| 看物流的入口 | 右下角物流球 → 物流面板（结账页不显示）；付款成功弹窗的 Track my order；“我的账户”首页 | 进店自动浮出的物流卡片；页脚上方的 “Track your order” → `/track-order` 页面 |
| 能看到什么 | 登录看最近 10 张订单，设备记住的看最近 5 张；每张订单的全部包裹、进度、FedEx / 17TRACK 扫描记录 | 只有最近 1 张订单、1 个运单号，没有扫描记录 |
| 订单号 + 邮箱查询 | `POST peptide/v1/track/lookup`，每个 IP 10 分钟 20 次，对不上统一提示 | `POST peptide/v1/order-lookup`，每个 IP 每分钟 5 次，对不上统一提示；测试单、草稿单不给查 |
| 营销弹窗静默 | 没有。注册弹窗对“买过但没登录”的顾客照样弹 | 有（F2 的过滤器，服务器端判断，页面不闪） |
| 开关 | `pc_track_ball`、`pc_accounts` 默认开，随主线发布就生效 | `pc_buyer_recognition` 默认关，还没有店打开过 |

### 哪里重复

1. **看物流**：物流卡片和物流面板是同一件事，主线的更全（多张订单、多个包裹、扫描记录）。两个都开时，同一个页面会同时出现卡片和物流球，内容还不一样。
2. **订单号 + 邮箱查订单**：`/track-order` 页面和物流面板里的查询是同一件事，接口也是两套。
3. **“这台设备付过款”的记录**不算重复：localStorage 是给物流面板取订单用的，服务器看不到；cookie 是给服务器判断“这是已购顾客”用的。两边都在付款成功落地页写入，互不影响。

只有 M11 有、主线没有的：**服务器端认出已购顾客，从而静默营销弹窗**（FE-10 的核心）。

### 建议（方案 A）

1. 看物流只留主线的物流面板一个入口。M11 的物流卡片下线：不再加载卡片脚本和样式，`GET peptide/v1/buyer/status` 只回答是不是已购。
2. 查订单并进物流面板：`/track-order` 页面、`[pc_track_order]` 短代码和 `POST peptide/v1/order-lookup` 下线；主题页脚上方的 “Track your order” 改成打开物流面板（物流面板已经认 `data-pc-open-track`），物流球关着的店不显示这个入口。
3. M11 只保留“认出已购 → 营销弹窗静默”这条线：cookie 保留；另外把“已登录、名下有付过款订单的顾客账户”也算已购，这样顾客换手机登录后同样不弹。
4. 需要日常会话配合两处（已写进 CONTRACT，标注“等决定”）：
   - 物流面板核对成功（订单号 + 邮箱，或设备记住的订单号 + 密钥）时触发 `do_action( 'pc_track_order_verified', $order )`。M11 挂在上面把这个浏览器记成已购，顶替现在“查订单成功时写 cookie”。
   - 注册弹窗对已购顾客不弹：`Accounts::assets()` 输出 `signup` 时读 `\PeptideCore\Pricing\Popups::suppressed()`。进站的 18+ 确认照常。
5. F5 这边的工作量：删卡片和查订单（两个前台文件、一个类、主题一处链接）、`Buyer_Status::known()` 加上登录顾客、改用例和约定。开关 `pc_buyer_recognition` 不变，仍然默认关。

顺带看到的（主线的文件，没有动，供日常会话参考）：`track/lookup` 没有排除测试单和草稿单；限频按 `REMOTE_ADDR` 计，F1 已确认生产的 nginx 会把它还原成顾客真实 IP，所以没有问题。

### 另外两个方案

- **方案 B：两套都留。** 不建议。顾客会同时看到卡片和物流球，卡片只显示 1 张订单，查订单有两个地方、两种限制。
- **方案 C：M11 整个下线，弹窗静默改成只看“是否登录”。** 不建议。自动建的账户要顾客去邮箱设密码才能登录，多数回访顾客是没登录的，认不出来，营销弹窗照弹。

**11-14 平台所有者定了方案 A**，已按上面 1–5 改完。（11-12 修的“查订单认 `hang-` 号”随查订单一起下线了。）

## 11-10 原来做了什么（方案 A 之前，留作历史）

- `core/class-buyer-status.php`
  - 按店开关 `pc_buyer_recognition`（`on` 才生效，**默认关**）。关着时不写 cookie、不静默弹窗、不加载前台脚本，线上行为和以前一样。
  - 付款成功后写 cookie `pc_buyer`：订单号列表 + HMAC，1 年，HttpOnly，SameSite=Lax。写入时机：付款成功弹窗的落地页（`/?pc_paid=…&pc_key=…`）和 WooCommerce“订单已收到”页面，订单密钥对得上、订单已付款、不是测试单。
  - `GET peptide/v1/buyer/status`：只看 cookie 里最近一张订单，返回订单号、下单日期、状态、商品、运单；不含顾客资料。
  - 已购顾客在前台时，F2 的过滤器 `pc_marketing_popups_suppressed` 返回 `true`，并在 `<head>` 输出 `window.pcBuyer`。F2 已在自己分支让首单券条、离开挽回、转盘、倒计时读这个开关，**两个分支合到一起才生效**；公告、购买播报等 F2 没覆盖的内容要日常会话接（见 CONTRACT）。
  - 物流卡片：只给已购顾客加载 `assets/buyer-status.{js,css}`；进店时浮在页面下方，关掉后这次浏览不再弹；结账、购物车、付款成功落地页不弹。没有运单时显示 “Awaiting shipment”。
  - 运单读取 `Buyer_Status::shipment()`：订单元数据 `_pc_shipment` 优先，其次 WooCommerce 订单履约。
- `core/class-order-lookup.php`
  - `POST peptide/v1/order-lookup`（邮箱 + 订单号）。每个 IP 每分钟 5 次；订单不存在、邮箱不符、测试单都返回同一句提示。订单号认两种：数字号，和 11-11 起顾客看到的 `hang-数字`。
  - 短代码 `[pc_track_order]`；开关第一次打开时自动建 `/track-order` 页面。核对成功且订单付过款时，把这个浏览器记成已购。
- 价格逻辑（满减、优惠码、阶梯价、首单优惠的计算）一行没动。

## 改动文件

- 新增：`core/class-buyer-status.php`、`core/class-order-lookup.php`、`assets/buyer-status.js`、`assets/buyer-status.css`、`tests/m11-buyer-status-pure.php`。
- 修改：`core/class-plugin.php`（追加 `Buyer_Status::register_hooks();` 一行）、`tests/data-observations-suites.json`、`docs/CONTRACT.md`。
- 没有新数据表。新选项：`pc_buyer_recognition`、`pc_track_order_page`。

## 实际做过的检查

- `php -l` 通过；`m11-buyer-status-pure`（51 项）用本机编译的 PHP 8.3.36 跑过，全部通过。
- 独立复查对照 WordPress / WooCommerce 源码核对过 cookie、两个公开接口和限流的写法，没有发现问题；提醒了一条配置：店铺在 CDN 后面而 `PEPTIDE_CORE_TRUSTED_PROXIES` 没设时，所有顾客会共用同一个“每分钟 5 次”的额度，上线前请 F1 确认生产已配置。
- 11-10 F1 在沙盒用真实的已付款测试单验了 cookie、两个接口、限流和篡改（HTTP 层，全部通过）。
- 11-12 在本机临时站里用浏览器（手机宽度）看过：查订单页面用 `hang-` 号和邮箱能查到，结果卡片正确；之后 `buyer/status` 认出已购，页面带 `window.pcBuyer`；回到首页出现物流卡片，订单号、商品、状态正确；关掉后刷新不再出现；新浏览器不加载卡片脚本。cookie 用页面脚本读不到（HttpOnly）。
- **仍然没验的**：营销弹窗静默（F2 的分支没合进来，本机也没有）；真实付款后落地页写 cookie 的整条流程在浏览器里没有走（F1 用 HTTP 验过）。
- 已知的行为（F1 问是不是本意，是）：订单状态是 `completed` 但没有运单号时，卡片显示 Shipped，下面没有运单信息。店铺把订单标成已完成就是发货了，只是没录运单号；卡片不编运单信息，也不另加说明文字。

## 上沙盒后的验收清单（11-14 方案 A 版）

注意生效时间：开关打开或关闭后，匿名访客看到的缓存页（页脚上方的 “Track your order”）最多 5 分钟后才变；验收时等 5 分钟，或用带任意参数的地址（例如 `/?nocache=1`）。带 `pc_buyer` cookie 的顾客和已登录的顾客不走缓存，立刻生效。

1. `php -n wp-content/plugins/peptide-core/tests/m11-buyer-status-pure.php` 和 `php -n wp-content/themes/peptide-site/tests/m14-storefront-identity-pure.php` 全部 PASS。
2. 开关关着：页面源码里没有 `window.pcBuyer`、没有 `vc-track-order`；`POST /wp-json/peptide/v1/order-lookup` 和 `GET /wp-json/peptide/v1/buyer/status` 都是 404 `rest_no_route`；右下角物流球照常。
3. `wp option update pc_buyer_recognition on`：**不**出现 `/track-order` 页面，`wp option get pc_track_order_page` 报不存在。
4. 新浏览器：页脚上方有 “Track your order” 按钮，点开的是右下角物流球那个面板；页面里没有 `window.pcBuyer`。
5. 用手机下一单并付款（非测试单），回到店铺：有 `pc_buyer` cookie（HttpOnly）；之后每页有 `window.pcBuyer = {known:true,suppressPopups:true}`；加购、用券、结账价格和新客一致。
6. 换一个浏览器，用那张订单的顾客账户登录：同样有 `window.pcBuyer`。名下没有已付款订单的账户登录：没有。
7. `wp option update pc_track_ball 0`：页脚按钮和物流球都不见；删掉这个选项恢复。
8. F2 上线之后：带 cookie 或已登录的已购顾客不再出现首单券、离开挽回、转盘、倒计时；新访客照常出现。
9. 回退：`wp option delete pc_buyer_recognition`，第 2 条的样子回来。

## 没做的

- 营销弹窗的静默依赖 F2 和日常会话读过滤器，F5 没有去改它们的文件。
- 日常会话的两处钩子（`pc_track_order_verified`、注册弹窗读 `Popups::suppressed()`）只写在 CONTRACT，生产冻结，之后再接。
- 已付款的老顾客没有 cookie：登录账户就能认出；没登录的从上线后下单开始识别（日常会话接上 `pc_track_order_verified` 后，用物流面板查过订单的也算）。
