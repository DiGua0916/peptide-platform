# 购物车推荐未显示：HTTP 根因交接

2026-11-04 · Codex · CART-RECOMMENDATION-DIAGNOSIS。**本轮完成诊断，后端修复与修复后的端到端验收尚未完成。**

## 真实现象与已排除项

用户在正式同源手机预览中看到购物车 $258.90、距 $300 档差 $41.10，却没有凑单推荐。`tools/mobile-preview.html` 的 `#site` 是当前 WordPress 网站的同源 iframe，共用真实购物车，并非 17863 的独立 Demo。

实际读取到活动与推荐均开启；配置在检查期间由 revision 4 / 20% 更新为 revision 5 / 30%，Codex 未修改此配置。最终浏览器真实 enrichment 确认：`historyEnabled/activityEnabled/activityVisible/allowed/themeEnabled/hasNext` 均为 true，revision 5，普通最近浏览显示 2 项，**推荐 candidates 为 0**。因此 `assets/live-cart-experience.js:404` 按现有门控未发出推荐 POST，页面没有可渲染的报价。

只读本机 CLI 检查为 Woo 11.2.1、税关闭、不含税价、活跃 gift/bundle 为 0；唯一有效活动 10980 是 `spend_amount`、全部顾客/商品、`exclude_sale=false`。已在车内的 SKU 仍包含在推荐候选中；普通 Woo sale 价不属于 gift/bundle，均不是本次全空原因。

## 已证实的后端链路

1. `includes/recent/class-history.php:178` 的 `recommendation_candidates()` 使用 `Activity\Catalog_Read::product()`；普通 `items` 展示另走原生读取，故可以出现有最近浏览列表、无推荐候选。
2. `includes/activity/class-catalog-read.php:45` 要求 `Quote_Cart::supported_hooks()` 成功，否则每个产品读取返回 null。
3. 实际 HTTP 最近浏览响应前后聚合探针均读到 `catalogSupported=true`、`quoteHooksSupported=false`、`unsupportedHook="woocommerce_currency"`，同时 `candidateCount=0`、`displayItemCount=2`。同一本机 CLI 则为支持，说明此前 CLI 验证遗漏了 REST 上下文差异。
4. 当前安装的 WooPayments `includes/multi-currency/MultiCurrency.php:307` 对非 `wc/store` REST 请求注册原生保持店铺币种/不转换价格的适配，实际探针记录恰有以下三个回调，均 priority 10、accepted args 1：

| Hook 后缀（前缀 `wcpay_multi_currency_`） | 实际回调 |
| --- | --- |
| `should_return_store_currency` | `__return_true` |
| `should_convert_product_price` | `__return_false` |
| `override_selected_currency` | 原生 `MultiCurrency.php:318` Closure |

5. `includes/activity/class-quote-cart.php:130` 的 `default_only_wcpay_callback()` 遇到任意 `wcpay_multi_currency_*` filter 直接拒绝，把上述原生 REST 适配也视为未知 override，最终拒绝 `woocommerce_currency`。最近浏览和 `/activity-listener/recommendations` 都是非 `wc/store` REST，后者也会受同一共享 guard 影响。

## 交给 Claude 的修复范围

主要修复 `wp-content/plugins/peptide-core/includes/activity/class-quote-cart.php` 的共享 WCPay 兼容性判定及必要的独立 helper。依据实际当前 WooPayments 版本，严格识别上述原生 REST 不转换路径的回调身份、优先级、参数、绑定对象和来源，以及已初始化的原生默认币种/单位汇率上下文；具体实现由后端审查决定。

- 继续拒绝未知额外 filter、替换/伪造 Closure、显式外币选择、非单位汇率和其他不支持的价格集成。不得将所有 `wcpay_multi_currency_*` 或 Closure 一概放行。
- 不删除/替换业务 hook，不为探测强制初始化货币模块，不读写客户身份或切换货币，不改活动/最近浏览/购物车配置。
- 不在 `class-history.php` 改用不受保护的 `wc_get_product()`、最近两项展示或最低规格绕过 guard；否则会丢失只读保证，且报价接口仍可能失败。
- 保持签名历史、精确规格、商品权限/库存、最多盒数、服务端原生报价、新鲜度/快照、同源权限及 no-store 规则。无需前端新造价格/库存/推荐规则。

## 必需回归与交付边界

补充真正 HTTP 上下文的已开启推荐回归：同源 `/recently-viewed/items` 在有效签名历史下返回非空精确候选；接着用当前真实购物车的 state/snapshot/config/rule 标识调用 `/activity-listener/recommendations`，验证服务端可见有效报价，经现有前端显示小型推荐模块。使用隔离的自有测试历史/购物车，不修改用户购物车或订单。

同时覆盖未知额外 hook、伪造 Closure/来源、`currency` 查询或显式外币、旧 snapshot/版本、库存不足、未选完整规格、推荐关闭等拒绝路径；证明没有客户车/历史/配置/价格/订单的隐式写入。保留既有原生结算及增量节省金额校验。CLI 算法通过、资源加载或进度条可见均不能替代 HTTP 候选与报价验收。

旧交接的原生算法/候选回归主要使用 CLI；其 HTTP 运输回归当时推荐配置保持关闭，未证明本次原生 REST 适配能产生可见报价。这是补测要求，不否定其原已记录范围。

本轮实际证据在 `D:/Codex/Tasks/2026-11-03/cart-recommendation-diagnosis/outputs/php-compatibility-probe.json` 与 `http-recommendation-aggregate.json`，只保留聚合条件、回调身份和数量，不含 cookie、token、nonce 或客户身份；任务输出不入 Git。临时主题诊断已由根代理按确切预期 hash 还原原始字节，运行文件 git clean，未修改插件或 DB。本次只提交诊断文档；无后端修复、配置启用或公网发布。
