# 后端对接：订单优惠明细与运费追溯

2026-11-05 · Codex · M05.06 / M05.07

本轮只改前端呈现，不修改 WooCommerce 购物车、结算或促销计算。

## 已有字段与当前实现

- 最终应付：Store API `cart.totals.total_price`，原样显示，不在浏览器重算下单金额。配送尚未计算时标 `Estimated total`，已计算则 `Total payable`。
- 商品原价差：优先 `extensions.peptide_core.sale_savings`，要求币种/精度与原生 totals 一致，`regular_subtotal - sale_subtotal = sale_savings >= 0`，且 `sale_subtotal === totals.total_items`。否则只有全部精确 SKU 单价乘数量都等于其原生 `line_subtotal`、行小计之和等于 `total_items` 时，才使用原生 SKU 常规价差。无法证明税/金额口径则保守不列商品差，不伪造0或重新扣款。
- 优惠券：`coupons[].totals.total_discount` 逐项显示，需同币种精度、code唯一，金额之和等于 `totals.total_discount`。代码不在展示标签/辅助标签里出现。分配不全时只展示原生合计一次。原移除按钮、实际 code 请求保持。
- 活动：最终 applied 金额是 `fees[].totals.total` 的负值，fee 实际字段为 `key`（例如 `pc-promo-10980`），不是仅 schema 文档里的 `id`。按精确 `pc-promo-ID` 与 `extensions.peptide_core.promotion.applied_all[].id` 关联名称，既定中文名“黑色星期五”在英文 UI 显示 `Black Friday offer`。`promotion.discount`、`applied_all[].discount`、`activity_listener.actualDiscount` 不再次加入总省，也不覆盖 Woo 最终 capped fee。
- 费用逐项金额之和需与 `totals.total_fees` 一致；无法证明完整分配时保留原生 `Order adjustments` 合计，不将失配列表视为完整优惠来累计。
- 总省：已证实商品原价差 + 原生优惠券合计 + 已核对的实际负 fee + 可选有效运费折扣。正手续费不当优惠、不重复加优惠券/活动汇总；有税时明确 `Before tax`，不杜撰原始税额或税务节省。

## 当前运费信息的缺口

现有 `Shipping\\Discounts::apply()` 把折前 cost 和折扣名保存在运费 rate 的 `_pc_ship_original_cost`、`_pc_ship_discount`。Woo `CartShippingRateSchema::get_rate_meta_data()` 过滤所有下划线开头的私有 metadata，因此当前公开 cart DTO 无折前运费，前端不能凭活动规则或现价反推。

同时没有保存原税额、实际折扣记录 ID，不能把所有私有 metadata 直接公开。当前前端只显示原生 `totals.total_shipping`，并且不假称运费节省。

## 可选增量公开 DTO（前端已做严格 fail-closed 消费）

建议由后端在同一次原生 cart 响应增加：

```json
{
  "extensions": {
    "peptide_core": {
      "shipping_savings": {
        "version": 1,
        "status": "applied",
        "complete": true,
        "tax_basis": "exclusive",
        "currency_code": "USD",
        "currency_minor_unit": 2,
        "original_shipping": "4500",
        "shipping_discount": "500",
        "payable_shipping": "4000",
        "packages": [
          {
            "package_id": 0,
            "rate_id": "pc_plans:1-2",
            "original_shipping": "4500",
            "shipping_discount": "500",
            "payable_shipping": "4000",
            "discount_id": 10,
            "discount_name": "Delivery offer"
          }
        ]
      }
    }
  }
}
```

这里的金额全部为**最小货币单位整数的十进制字符串**，同 `cart.totals` 币种及精度，范围0..9007199254740991；示例仅协议例值，不是店铺配置。`tax_basis:exclusive` 与原生 shipping totals 的税外口径一致。

### 后端必须保证

1. 使用当前真实已选运费，不是可选费率最小值或宣传活动预测。`package_id` / `rate_id` 精确关联原生 `cart.shipping_rates[].shipping_rates[selected=true]`。
2. 所有实际选择包裹恰好覆盖一次；无折扣的已选包裹也放入 packages，三金额为原值/0/原值。不得只覆盖有优惠包裹却标 complete。
3. 每包裹 `original_shipping - shipping_discount = payable_shipping`；payable 等于该原生 selected rate.price；各包裹之和分别等于三个顶层金额，顶层 payable 等于 `cart.totals.total_shipping`。
4. 每个正折扣包裹提供真实稳定 `discount_id` 和公开 `discount_name`。同ID在不同包裹重复应用允许，前端按ID合并金额一次；同ID名称不一致拒绝trace。
5. 仅已计算且有实际正运费节省时提供 status applied / complete true。没有折扣可省略或显式 none；未知、部分、错误必须不提供完整 applied trace。
6. 不公开顾客地址、资格私有规则、账户或其他 private metadata。不新增前端运费计算器，不改原运费选择或支付总额。
7. 为以后含税总省，需在折扣引擎修改 rate taxes **之前**保存真实 original taxes，并在增量 DTO 中明确税前/税后口径；不能从已经归零的税额反推。目前前端仅消费税外版本，不加未证实税务节省。
8. 现有 Cart_Snapshot key 未包含所有配送选择/运费信息，不应单独用旧 key 当运输追溯身份。优先在同次原生 cart DTO 生成，并与 selected rates + totals 严格对账；如独立缓存/端点再做包含配送及金额的专属指纹。

### 前端展示及拒绝行为

有效 trace：`Shipping before offers` 折前值 → 每个真实 shipping offer 负值 → 末条小字 `Shipping after offers: <payable>`。末项只是说明，绝不作为第二笔金额重复累计。底部 Total payable 始终原生总额；You save 只把实际 shipping_discount 加一次。

缺失/非法/币种不一致/包裹错位/重复/总额不符/未计算：安全回退原生 Shipping 当前值或 At checkout，不出现折前值和运费节省。

## 本轮代码范围与验证

- FE `assets/live-cart-experience.js`：totals()、可选 shippingSavingsTrace()、summary renderer。
- FE `assets/live-cart-experience.css`：紧凑优惠明细、红色优惠、清晰 payable / You save 层级。
- FE `tests/cart-summary-breakdown.cjs`：专用原生字段与严格金额边界回归。
- 本轮不改后端、促销配置或客户购物车；此规范不代表后端已支持 shipping_savings。
