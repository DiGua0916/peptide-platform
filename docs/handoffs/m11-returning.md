# M11 已购回访与查订单 交接（F5，2026-11-10）

分支 `claude/m9-buyer-admin-940389`（M9、M11 提交在同一个分支上），提交 `99d23e8`。没有合并 main，没有发布。

对应需求：FE-10、FE-11。接口约定在 `docs/CONTRACT.md` 的「M11 已购回访和查订单」。

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
