# 推广人归属（REF-01）— 2026-11-07

一个店、多个推广人：每人一个专属链接 `?ref=代号`，客户归第一个带他进站的推广人（谁先带来就算谁的）。

## 规则

- 链接：任何页面加 `?ref=代号`，比如 `https://www.vialcovepeptides.com/?ref=a`、`/product/xxx/?ref=a`。代号不在名单里就忽略。
- 第一次带有效代号进站：`init` 时写签名的第一方 cookie `pc_ref`（`代号.时间.签名`，HttpOnly、SameSite=Lax、365 天），并在 `wp_pc_referral_touches` 记一条“带来访客”。已有有效 cookie，或者已登录且账号已有归属，后来的代号一律忽略。爬虫（含 facebookexternalhit）不记。
- 注册、登录：账号还没有归属时继承 cookie（user meta `_pc_ref_owner`、`_pc_ref_owner_at`）。之后换设备登录仍算原推广人。
- 下单：经典结账 `woocommerce_checkout_create_order`、区块结账 `woocommerce_store_api_checkout_update_order_from_request`，订单写 `_pc_ref_owner`。优先账号归属，其次 cookie；已有归属的订单不改。
- 从名单删掉的代号：不再接收新访客，cookie 失效；已有的账号、订单归属保留，后台显示代号。
- 页面缓存：`ref` 和 `utm_*` 一样算广告参数，不影响命中，渲染前从地址里去掉。`pc_ref` 在本次请求里下发不阻止写缓存，缓存不保存 Set-Cookie。`ref` 不传给 Facebook 像素或 CAPI。

## 后台

- 左侧菜单“推广人”（紧跟“顾客”，`admin.php?page=pc-referrals`）：
  - 名单（每行 `代号|名字`）；
  - 专属链接和复制按钮；
  - 近 7 天 / 30 天 / 全部的带来访客、新归属客户、已付款订单、销售额（扣退款）；
  - 手动改客户归属；
  - 最近归属的客户。
- 订单列表：状态后面加“归属人”列，并有筛选（HPOS 和旧版列表都支持；可选“无归属”）。
- 订单编辑页、用户资料页：下拉改归属。改客户只影响以后的订单。

## 文件

- `wp-content/plugins/peptide-core/includes/class-referrals.php`（新）
- `wp-content/plugins/peptide-core/includes/admin/class-referrals-page.php`（新）
- `wp-content/plugins/peptide-core/includes/class-plugin.php`：注册两个类
- `wp-content/plugins/peptide-core/includes/class-page-cache.php`：`tracking_name()` 加 `ref`，`issued_this_request()` 加 `pc_ref`
- `wp-content/plugins/peptide-core/tests/smoke-referrals.php`（新，22 项）

新增的公开约定：查询参数 `ref`、cookie `pc_ref`、meta `_pc_ref_owner` / `_pc_ref_owner_at`、表 `{prefix}pc_referral_touches`、选项 `peptide_core_referral_owners`。没有改已有字段、接口或返回格式。

## 验证（本机）

- 冒烟测试 22/22；page-cache-pure 113、static-cdn-pure 11、smoke-pixels、smoke-modules 全部通过。
- HTTP：`/?ref=a` 下发 `pc_ref` 且页面缓存 HIT（约 0.25 秒），HTML 里不出现 `ref=a`。之后再访问 `/?ref=b`，不覆盖原来的归属。
- 区块结账：先 `/product/x/?ref=b`，再 `/?ref=a`，下游客单 #104，归属 b。后台统计 b：订单 1，销售额 $10。订单列表“归属人”列和筛选正常。

## 已知限制

- 客户换设备又不登录，或者清了 cookie，就认不出来，只能靠注册账号兜底。
- 历史客户和订单默认无归属，可以在后台手动分配。
