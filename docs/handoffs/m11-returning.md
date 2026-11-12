# M11 已购回访与查订单 交接（F5，2026-11-10）

分支 `claude/m9-buyer-admin-940389`（M9、M11 提交在同一个分支上），提交 `99d23e8`。没有合并 main，没有发布。

对应需求：FE-10、FE-11。接口约定在 `docs/CONTRACT.md` 的「M11 已购回访和查订单」。

## 和主线 11-11 新功能的重叠（2026-11-12，等平台所有者决定，决定前 M11 不再扩展）

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

**请平台所有者定 A / B / C。** 决定之前 M11 的代码保持现状（开关关着，对线上没有任何影响）。

不管选哪个都要修的一件事已经修了：订单号改成 `hang-数字` 之后，`/order-lookup` 原来只认纯数字，顾客照着订单号填会一律“没找到”。现在数字号和 `hang-` 号都能查（提交 `8dc2a04`，本机 WordPress 里实际查过）。

## 做了什么

- `core/class-buyer-status.php`
  - 按店开关 `pc_buyer_recognition`（`on` 才生效，**默认关**）。关着时不写 cookie、不静默弹窗、不加载前台脚本，线上行为和以前一样。
  - 付款成功后写 cookie `pc_buyer`：订单号列表 + HMAC，1 年，HttpOnly，SameSite=Lax。写入时机：付款成功弹窗的落地页（`/?pc_paid=…&pc_key=…`）和 WooCommerce“订单已收到”页面，订单密钥对得上、订单已付款、不是测试单。
  - `GET peptide/v1/buyer/status`：只看 cookie 里最近一张订单，返回订单号、下单日期、状态、商品、运单；不含顾客资料。
  - 已购顾客在前台时，F2 的过滤器 `pc_marketing_popups_suppressed` 返回 `true`，并在 `<head>` 输出 `window.pcBuyer`。F2 已在自己分支让首单券条、离开挽回、转盘、倒计时读这个开关，**两个分支合到一起才生效**；公告、购买播报等 F2 没覆盖的内容要日常会话接（见 CONTRACT）。
  - 物流卡片：只给已购顾客加载 `assets/buyer-status.{js,css}`；进店时浮在页面下方，关掉后这次浏览不再弹；结账、购物车、付款成功落地页不弹。没有运单时显示 “Awaiting shipment”。
  - 运单读取 `Buyer_Status::shipment()`：订单元数据 `_pc_shipment` 优先，其次 WooCommerce 订单履约。
- `core/class-order-lookup.php`
  - `POST peptide/v1/order-lookup`（邮箱 + 订单号）。每个 IP 每分钟 5 次；订单不存在、邮箱不符、测试单都返回同一句提示。
  - 短代码 `[pc_track_order]`；开关第一次打开时自动建 `/track-order` 页面。核对成功且订单付过款时，把这个浏览器记成已购。
- 价格逻辑（满减、优惠码、阶梯价、首单优惠的计算）一行没动。

## 改动文件

- 新增：`core/class-buyer-status.php`、`core/class-order-lookup.php`、`assets/buyer-status.js`、`assets/buyer-status.css`、`tests/m11-buyer-status-pure.php`。
- 修改：`core/class-plugin.php`（追加 `Buyer_Status::register_hooks();` 一行）、`tests/data-observations-suites.json`、`docs/CONTRACT.md`。
- 没有新数据表。新选项：`pc_buyer_recognition`、`pc_track_order_page`。

## 实际做过的检查

- `php -l` 通过；`m11-buyer-status-pure`（51 项）用本机编译的 PHP 8.3.36 跑过，全部通过。
- 独立复查对照 WordPress / WooCommerce 源码核对过 cookie、两个公开接口和限流的写法，没有发现问题；提醒了一条配置：店铺在 CDN 后面而 `PEPTIDE_CORE_TRUSTED_PROXIES` 没设时，所有顾客会共用同一个“每分钟 5 次”的额度，上线前请 F1 确认生产已配置。
- `buyer-status.js` 做过语法检查。**没有在浏览器里跑过**，卡片和表单的样式没有在手机上看过；没有真实下单验证过 cookie。

## 上沙盒后的验收清单

1. `php -n wp-content/plugins/peptide-core/tests/m11-buyer-status-pure.php` 全部 PASS。
2. 开关关着：下单付款后浏览器里没有 `pc_buyer` cookie；`/wp-json/peptide/v1/buyer/status` 返回 `{"buyer":false}`；页面源码里没有 `buyer-status` 脚本。
3. `wp option update pc_buyer_recognition on`：出现 `/track-order` 页面。
4. 用手机下一单并付款（非测试单）：回到店铺后有 `pc_buyer` cookie（HttpOnly）。关掉浏览器重新进店：出现物流卡片，订单号和商品正确，状态 “Awaiting shipment”；关掉卡片后同一次浏览不再出现；加购、用券、结账价格和新客一致。
5. 新浏览器（无 cookie）：页面和开关关着时一样，没有多余请求。
6. 查订单：邮箱和订单号对得上时显示订单并写入 cookie；任何一项不对都是同一句提示；连续第 6 次返回 429。
7. 改 cookie 里的任意一位：`/buyer/status` 返回 `{"buyer":false}`。
8. F2 的分支合进来之后：已购浏览器不再出现首单券、离开挽回、转盘、倒计时。

## 没做的

- 营销弹窗的静默依赖 F2 和日常会话读过滤器，F5 没有去改它们的文件。
- 前台“查订单”入口已在主题页脚上方输出（M14，开关打开且有查订单页面时才出现）。
- 发货邮件（OR-02）暂缓，所以卡片上没有承诺“发货后邮件通知”。
- 已付款的老顾客没有 cookie，只能从上线后下单或用“查订单”的顾客开始识别（开发文档 12.5 已说明）。
