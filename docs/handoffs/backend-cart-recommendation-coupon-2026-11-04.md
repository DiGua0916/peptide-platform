# 购物车单盒推荐、自动优惠券与 WooPayments REST 兼容

2026-11-04，Codex。依据用户 `前端ui.md`；用户随后明确“一次推荐加购1盒（现有销售单位）”。本轮是后端及插件配置，不修改主题视觉，不把每瓶参考单价变成拆盒销售，不创建优惠券、不修改活动金额、商品价格、库存、客户购物车、订单或支付配置。

## 实现

- 沿用 `activity-listener`、现有推荐报价、Woo 原生商品/购物车/优惠券与满减计算。一次只返回一个推荐，实际追加数量固定为 **1盒**。一盒不能达到下一档，或受库存/步长/最小购买量限制时隐藏；不偷偷增加盒数。
- 旧合法 `recommendation.maxAddBoxes/maxVisible` 字段继续接受，但在读取/规范化时只在内存中变成1，不写旧 option。管理页取消两项可编辑数量，明确固定1盒/1项。
- 增加 `recommendationCoupon:{enabled:false,couponId:0,message:""}`。仅管理权限的 DTO 具有 `couponChoices`（已有券名称、规则、状态），可选择既有券并填写200字符以内文案。沿用原有权限、WP REST nonce、草稿/已应用快照与 CAS；发布须选择有效券，不自造折扣。缺少整个新对象时兼容旧配置、默认关闭。
- 原生报价支持当前这组保守范围的券：已启用、有效的 `percent/fixed_product/fixed_cart`，叠加策略 `stack`；推荐 SKU 也须在券适用范围。指定邮箱、首单/客户分组、积分定向券、免运、替换/互斥券、未知回调/非原生数据存储不承诺报价。已有其他券的购物车继续返回 `coupon_conflict`，不删除或替换用户的券。
- `Quote_Cart` 对象内复制并用原生 `WC_Discounts` 校验券、原生 `WC_Cart_Totals` 计算满减及券后金额。不调用真实购物车加购、用券、session 保存或全局替换。运费未知的实物订单仍不捏造应付总额；原有税费等不支持情形保持隐藏。

## 前端接入（需要前端落实）

原 POST `/peptide/v1/activity-listener/recommendations` 保留原五个必填字段，新增可选严格布尔 `supportsAutomaticCoupon:true`。只有准备好在唯一原生加购请求携带下述票据的前端才能声明。配置已启券、旧客户端未声明能力时，返回 `visible:false, reason:"client_update_required"`，不会给旧界面展示无法兑现的券省额。该门控不影响未启券的现有推荐。

可见报价追加：

```json
{
  "quantity": 1,
  "offerToken": "短效服务端签名票据",
  "couponDiscount": {"minor":"1000","text":"$10.00"},
  "automaticCoupon": {
    "enabled": true,
    "message": "商家配置的文案",
    "discount": {"minor":"1000","text":"$10.00"},
    "requiresCodeEntry": false
  }
}
```

此处金额只是合成测试例子，不是新增活动。`couponDiscount` 是券在整个报价购物车的实际优惠；`afterAdd.incrementalSavings` 是相对当前购物车的总额外省额，不能重复加上档位奖励。实物的运费/税费与原 `totalsScope` 规则保持。

客户明确点击加购时，仍只发一次原生请求 `POST /wc/store/v1/cart/add-item`，沿用原生 `Nonce`/有效 Cart-Token 和登录所需的 WP nonce：

```json
{
  "id": 123,
  "quantity": 1,
  "extensions": {
    "peptide_core": {
      "activityRecommendation": {"offerToken":"原报价完整票据"}
    }
  }
}
```

`id` 使用报价的准确 `purchasableId`，不擅自选另一规格。服务端票据45秒有效，绑定站点、原生 Woo session 的单向身份、原购物车完整快照、活动/配置修订、准确 SKU 和所选券完整配置指纹。唯一主键票据占用仅发生在实际带票据的原生 POST，阻止同票据重复加购，包括恢复成相同前态后重放。写入的是不可逆摘要与到期秒数、非自动加载 option；到期计划任务有界清理。不为只读报价创建持久记录。该保护不替代前端共享忙碌锁，也不宣称不同票据/跨标签的所有原生购物车操作均物理串行。

Woo 原生 nonce 校验与商品/库存/变体验证保持。只有真正的原生成功钩子才自动调用原生 Store API coupon 方法；原生加购失败不会留下自动券。成功响应保留原生商品和金额，追加：

```json
{"extensions":{"peptide_core":{"activity_recommendation":{
  "requested":true,"added":true,"couponRequested":true,
  "couponApplied":true,"reason":"applied"
}}}}
```

票据失效/参数不同/状态变化/重复占用返回409，未加购；刷新只读报价，不能自动重发原加购。极短窗口内券在原生加购成功后失效时，保持201及真实成功加购，并返回 `added:true,couponApplied:false,reason:"coupon_changed"/"coupon_not_applied"/"coupon_conflict"`，金额以响应为准；**不得因此再次发送 add-item**。后台没有改商品价格或伪造优惠。

插件新增 DTO 与签名票据不包含优惠码。Woo 原生 `cart.coupons[]` 仍可能包含券 code；“不展示给客户/不让客户手输”是前端文案和标签处理，不是将原生券变成网络不可见秘密。本轮未修改主题接线。

## WooPayments 阻断根因与修复

前端交接 `cart-recommendation-diagnosis` 的真实 HTTP 证据：非 Store REST 请求上，WooPayments 安装了原生“不转换/返回店铺币种”三钩子；旧 quote guard 见到任何 `wcpay_multi_currency_*` 就拒绝，进而 `Catalog_Read::product()` 返回空。CLI 无此三钩子，先前 CLI 通过不能否定此故障。

现仅接受完整且唯一的三个原生回调：10/1 的 `__return_true`、`__return_false` 和原生默认币种闭包。要求非 Store/非 batch REST、没有 `currency` 查询参数；闭包须无参数/捕获、绑定同一个原生 MultiCurrency 对象、原生 scope、位于 `init_hooks` 方法内、canonical 插件路径一致，且完整源文件与闭包片段的规范 LF SHA 匹配。仍要求单一默认币种、汇率1及既有格式器状态；未知/外币/额外/替换回调不执行并拒绝。源字节/行只在单 PHP 请求内 memoize，回调、绑定、币种状态不缓存。

- 已审 MultiCurrency.php LF SHA：`925ea6363029cd689be370d572544d823cc31b59209f172e136d2b839c7dea3b`。
- 原生默认闭包片段 SHA：`733e7622cec4ee19fbf7a0a8d24d32d7b297eb2086cfed305ddecdd0d729641d`。
- 券路径另外严格认可同一默认币种状态下的原生 WCPay coupon amount/min/max getter（99/2、99/1、99/1），不泛放 coupon hooks；原生保存后 analytics action 不在只读报价调用路径。升级这些第三方源后若 SHA 改变，需要重新核验，不能自动信任新闭包。

## 实际验证与边界

工作证据在 `D:/Codex/Tasks/2026-11-04/frontend-backend-ui-split/work/recommendation-coupon/`。

- 实际配置纯测试108/108；JS parse、9个PHP语法、`git diff --check` 通过。
- `native-recommendation-coupon-quotes.php`：隔离购物车 QA、连接内 TEMP posts/postmeta/options，67/67。合成$440购物车加$100一盒，满减从$25到$55、原生券再减$10，额外省$40、虚拟无税场景再付$60；不是省$65。过期/不满足门槛/互斥等保持隐藏；原永久产品/订单/访客/session/options指纹及全局对象不变，无 quote 阶段写入。
- `native-recommendation-coupon-add.php`：同隔离QA、原生 Store API REST dispatch、TEMP配置/商品/券+内存 Woo session，18/18。覆盖缺 Woo nonce、错误SKU/2盒、相同匿名内容不同session、签名篡改、真正201加购/原生券/总额、同票据重放、原生产品验证失败、报价后过期、成功加购后券过期的诚实201、到期票据清理。保留实际 schema 空 `stdClass` 扩展兼容。不是持久客户会话或多进程压测。
- 真正 HTTP bootstrap：临时 `127.0.0.1:59864` PHP helper，从实际 LocalWP `wp-load.php` 加载原生 WooPayments 非 Store REST 上下文、进程 prepend 映射候选源，46/46（含额外/外来闭包、优先级/参数/绑定对象、缺少三钩子、currency参数等反例）。这是 HTTP 请求中原生过滤器与真实公开目录/脱离会话报价，不是原 recent/items 与 recommendations 的认证传输/前端完整页面验收。
- 启券 HTTP fixture 同一实际 WCPay HTTP 上下文，67/67：只允许同连接 TEMP 表写入，所有永久SQL/外部HTTP拒绝，真实原 options/session/目录指纹恢复一致。原生三个 coupon getter在此场景明确通过，不只测试无券。
- 所有 HTTP helper runner 在 finally 终止子进程；收尾确认59864没有监听。初次HTTP启券测试暴露原生券保存 analytics action 被误当读取过滤器，已修并重跑通过；初始失败收据保留，不算成功。实际 LocalWP/plugin junction、源站生产、真实活动配置和用户购物车均未安装/修改。安装与原页面验收由根代理继续。

根代理的后续实际状态：已将通过HTTP回归的 **WCPay方法片段热修复** 选择性安装到本机旧 Core QuoteCart（备份后SHA `a073ef2971f362250217ab72b31c745fbcfb2b180e577e88c004dc4bccd26630`，未把其他新功能混入）。真实 IAB 手机预览现有购物袋已见 Tirzepatide `20mg*10vials`、`Add 1 box $55` 推荐：当前$258.90、差$41.10到$300解锁$25；未加购或修改数量/数据库。截图 `outputs/mobile-recommendation-restored.png`。此热修复尚未含后续 coupon getter 扩展；最终全包需保护实际新文件、覆盖这份自身热修复，不能回滚其它工作。自动券与配置等完整功能仍待根代理安装与原页面接线验证，不代表生产部署。

后端启动需增加 `Activity\Recommendation_Coupon::register_hooks()`（交根代理统一接线）。不要将上述原生 fixture 验证写成生产、自动券前端完整页面或付款流程完成。
