# US / PR 邮编辅助填写 — 2026-11-05 Codex

当前为默认关闭的源码交付；未安装、未修改真实客户/购物车/订单/支付，未发送任何真实地址、未调用真实 Google 服务，也未注册或开通付费账号。

## Root 最小接线

- 插件启动调用 `Postal_Assist::register_hooks()` 一次。既有 autoload 可加载 `includes/class-postal-assist.php` 和 `includes/admin/class-postal-assist-page.php`。
- 应用中心新增 app=`postal-assist`、名称“邮编辅助填写”，`on` 读 `Postal_Assist::settings()['enabled']`；dispatch `Admin\Postal_Assist_Page::render()`。共享 `class-plugin.php`、`class-apps-page.php` 均由 root 接。
- `assets/postal-assist.js` 仅真实结账页且注册了 `wp-data` / `wc-blocks-data-store` 时挂载；preview、自定义器及订单付款/成功等 endpoints 不挂载。没有传统结账 DOM 填写后门。
- option `pc_postal_assist` 精确三布尔 `{enabled:false,google_enabled:false,merchant_consent:false}`，默认全部关闭；后台 POST + nonce + manage_woocommerce，仅写该独立 option。
- 主开关打开即允许本地已有地址匹配。外部适配需额外服务端 `PC_GOOGLE_ADDRESS_VALIDATION_KEY` 常量、Google API/配额设置、商家明确披露同意、顾客当前页默认不勾选的同意框。钥匙不存本 option、不返给浏览器。没有配置时不会调用外部服务。

## 实际能力及限制

- 本地匹配只用 `new WC_Customer(get_current_user_id())` 的本人 shipping/billing 已保存地址；不查订单历史、不查他人、不从请求接收客户 ID。匿名用户没有此权威来源。
- 必须 US/PR + 城市 + 完整街道与门牌数字；US 还需原生有效州。PR 原生 states 为空，PR 可空州，country 保持 PR，不伪造为 US/州。城市、州、街道、单元号都匹配且 ZIP 唯一才返回。两个相同地址记录 ZIP 冲突则 ambiguous，绝不随便选一个。
- 不根据国家/州/城市猜 ZIP，不填已有 postcode。填写或清除 ZIP 的手动行为优先；地址改变、国家 US→PR、同意项改变、页面销毁、结账正在处理，均使过时结果失效。
- 只返回 `{state,postcode?,source?}`，不回显地址、姓名、邮箱、电话、客户 ID；地址只在当前请求和浏览器内存中处理，无日志、localStorage、地址缓存或分析上报。
- 服务器 POST `/wp-json/peptide/v1/postal-assist` 验 X-WP-Nonce、同源 Origin/Referer、严格 JSON/字段/长度，不接受 query/userID。错误与成功都 private/no-store，合并 Vary Cookie/Origin，不清除既有 Accept-Encoding。
- 外部查询前使用 Woo 原生安全限流记录，仅 HMAC 用户/REMOTE_ADDR 标识，5 秒间隔；不保存原 IP 或地址。此安全写入发生在明确同意的外部查询，不在普通前台 GET。
- Google 请求固定 `https://addressvalidation.googleapis.com/v1:validateAddress`，HTTPS 验证、无重定向、4 秒超时、64KiB 响应预算，key 放服务器 header 不放 URL；仅 country/state/city/street/address2 + CASS，没有姓名/邮箱/电话。
- Google 结果须 `addressComplete=true`、PREMISE/SUB_PREMISE、USPS DPV=`Y`，无未确认/替换/拼写更正；除了缺失的 postal_code 外不允许推断。返回国家、城市、街道、单元号与输入一致；ZIP 与 USPS 标准化 ZIP 相符，否则保持手填。这是刻意保守的填空，不把地址更正后默默盖回客户原字段。
- Google 没配置时，只有已登录且与本人保存地址完全相同的地址可以补全；新地址、匿名地址仍手填。该限制须如实说明，不能把适配源码说成已开通实时地址查找。

## Woo Blocks 原生持久

1. `wp.data.select(CART_STORE_KEY).getCustomerData()` 读取当前原生地址。
2. 仅改 postcode，保留当时最新姓名、邮箱、扩展和所有其他字段，`setShippingAddress()` / `setBillingAddress()` 改本地原生 store。
3. **实际安装的 Woo 版本** `updateCustomerData({shipping_address:...}, true)` / `billing_address` 使用 snake_case；`editing=true` 走原生 `receiveCartContents`，不会用过期 HTTP 回执覆盖顾客仍在编辑的本地地址。该参数不是 getCustomerData 的 camelCase 键。
4. 原生返回相同国家和 ZIP 才显示“已填写”；失败或不完整回执不声称持久成功，不重发 mutation。无 DOM input.value 写入，也不手写 `/cart/update-customer` fetch。

## 验证与依据

- `node --test .../tests/postal-assist.cjs`：15/15，含空邮编、手动输入/清除、异步过期、国家改变、单元号、多 ZIP、原生忙碌、失败不重发。
- 第 15 项读取本机实际 `woocommerce/assets/client/blocks/wc-blocks-data.js`，从真实 action 导出表定位并离线执行原生 updateCustomerData thunk，验证实际 `/wc/store/v1/cart/update-customer` POST/snake_case 与 editing=true 的 receiveCartContents 分支；不是镜像重写动作。无 HTTP/DB。其他机器可用 `PC_NATIVE_WOO_DATA_JS` 指定该原生 asset，缺失则该项明确 skip。
- `php .../tests/postal-assist-pure.php`：45/45，实际新增 PHP 类在合成依赖下执行，包含最小地址请求、本人限定、nonce/同源、默认零外传、DPV N/D/S/无值拒绝、更正拒绝、缓存头、双语键与实际西语错误回执。Google 返回值是合成样例，不代表真实服务已验。
- 两语言的新增买家 UI/同意说明/成功/恢复提示与服务端错误都由 `Postal_Assist::strings()` 集中提供；后台设置继续中文，不依赖共享翻译 catalog。
- 还需 root 在实际 Blocks 浏览器核对 DOM 挂载/手机易用性，且只在备份后启用；真实 API 账号/配额/顾客同意与新地址在线验证仍未完成。
- 官方 [Woo Cart Store](https://developer.woocommerce.com/docs/block-development/reference/data-store/cart/) 说明本地地址动作与 updateCustomerData 的区别；本机实际 action 优先于文档示例的命名歧义。
- Google [validateAddress API](https://developers.google.com/maps/documentation/address-validation/reference/rest/v1/TopLevel/validateAddress) 定义 CASS/DPV/确认标志，并要求 PR 请求明确 regionCode=PR；[coverage](https://developers.google.com/maps/documentation/address-validation/coverage) 列出 PR 支持。未将“可用 API”当作当前账号已启用。

源码/测试 SHA、lint、原生 action 所用 asset SHA 见任务目录 `work/postal-assist/verification.json`。
