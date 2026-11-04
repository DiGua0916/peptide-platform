# 每瓶参考价：后端接入交接

作者：Codex。日期：2026-11-04。来源：用户 `前端ui.md`，商品已选规格旁标注“一瓶多少钱”。本轮用户确认凑单推荐仍按现有销售单位一次加购一盒；每瓶价格只是等效说明，不能据此拆盒售卖、改价或新增单瓶 SKU。

## 已实现

新增 `PeptideCore\Unit_Pricing`。复用原生 Woo 商品、变体、显示税设置和当前价，只做金额说明，没有价格过滤器、订单写入、库存更改、购物车加购或迁移操作。

- 原生商品编辑的常规面板，以及各变体价格区，新增“每销售单位瓶数”。输入范围 0–10000 整数；留空解除覆盖，0 明确隐藏。保存沿原生 Woo 的 nonce、商品编辑权限和 CRUD 保存过程，不建立另一个保存接口。非法输入显示原生错误并保留旧瓶数。
- 已选的简单商品/具体变体返回每销售单位价格、每瓶价格、瓶数、币种、小数位和显示税上下文。父可变商品未选规格返回 `null`；不会使用最低价变体冒充当前规格。
- `woocommerce_available_variation` 载荷追加 `pc_unit_pricing`，原变体字段不变。原有完整商品页/Quick View 的原生变体选择都能消费该字段。
- Store API 商品与购物车行的 `extensions.peptide_core.unit_pricing` 追加同一 DTO。商品使用 shop 税显示口径；购物车行使用 cart 税显示口径。
- 公共 PHP helper：`peptide_core_get_unit_pricing( WC_Product|int $product ): ?array`，前端不需要读私有元数据。

## 包装数量的确定

优先级：

1. 当前 SKU 显式设置的瓶数；0 或损坏值直接隐藏。
2. 当前 SKU 的明确 `Pack` / `pa_pack` 属性；简单商品兼容既有 `_pc_specs` 中 `key=pack` 的规范规格数据。
3. 仅当变体本身完全没有包装数据，才沿用父商品显式瓶数。

只严格解析完整、单一包装文字，例如 `20mg*10vials`、`2.5 mg × 5 vials / box`、`10瓶/盒`。`10mg`、`10ml`、混合套装、多条冲突包装、缺失瓶数、附加不明成分都返回 `null`；不会从名称、描述或图片猜数量，更不默认所有商品每盒10瓶。已有规范 Pack 可直接获得数据，无需先批量写商品。

自定义 taxonomy Pack 的值若是非规范 slug，不按 slug 猜测；后台可填写显式瓶数。未知/歧义包装会让每瓶价隐藏，原销售与结账保持。

## DTO 与前端用法

以下为合成说明：一个10瓶销售单位，当前显示价 USD55.00。

```json
{
  "version": 1,
  "productId": 123,
  "variationId": 456,
  "purchasableId": 456,
  "vialsPerSalesUnit": 10,
  "countSource": "stored_pack",
  "salesUnitPrice": {"minor":"5500","text":"$55.00"},
  "perVialPrice": {"minor":"550","text":"$5.50"},
  "approximate": false,
  "currency":"USD",
  "currencyMinorUnit":2,
  "taxDisplay":"excl",
  "basis":"catalog_display_price",
  "includesCoupons":false,
  "isPurchasableAsSingleVial":false
}
```

`countSource` 为 `explicit_sku`、`stored_pack`、`explicit_parent`。`basis` 在购物车行是 `cart_display_price`，其余是 `catalog_display_price`。参考价使用当前实际售价，优惠券与购物车活动不含在此单价中；这些折扣仍从原生 totals/活动 DTO 单独显示。

前端在原生 `found_variation` 时读取 `variation.pc_unit_pricing` 并展示 `perVialPrice.text`；在 reset、未选规格或 `null` 时移除旧单价，不保留上一个规格的价格。已有原生 JSON/Store API 返回的格式化文本按纯文本插入，别重算金额或用正则抓页面价格。样式、位置和醒目程度由前端窗口处理，本轮没有改主题或追加客户端播放器。

金额使用当前币种的 0–8 位精度、最小单位计算和每瓶四舍五入；不可整除时 `approximate:true`，前端须标“约”。例如10美元/3瓶显示约3.33美元/瓶，实际整盒仍收10美元；不能反过来用3.33×3改实际购物车总额。0小数 JPY 与3小数币种已覆盖。非有限、负数、超过 JS 安全最小单位的金额拒绝说明。显示价插件发生异常时返回 `null`，不能因为这项说明阻塞原生购物车或结账。

## 变更文件与验证

- `includes/class-unit-pricing.php`：服务、原生后台字段、保存和变体载荷。
- `includes/class-plugin.php`：注册服务钩子。
- `includes/api/class-store-api.php`：商品/购物车行增量 DTO 和 schema。
- `includes/functions.php`：每瓶价 helper，以及并行黑五公告服务的 `peptide_core_get_campaign_announcement(array $header_settings,bool $is_home): array` wrapper。
- `tests/unit-pricing-pure.php`：实际类的内存 Woo fixture 验证。

实际执行：79/79 纯 PHP fixture 检查通过；新服务、Store API、functions 的 PHP 语法检查通过；`git diff --check` 通过。覆盖包装歧义/缺数/显式覆盖/0隐藏，父品与变体身份、未选规格、下架不可见商品、USD/JPY/KWD、税口径、舍入、异常不阻塞、非法保存保旧值和 native payload 增量。原生 Woo 本地源码已核对四个后台钩子签名与显示税接口。

后续原生验证：既有隔离购物车 QA 的实际 WordPress CLI / WooCommerce 11.2.1 加载新服务，克隆其已有公开简单商品，只在克隆内改 price/Pack/meta；16/16 检查通过。实际调用 WC 显示价、税引擎与金额格式，覆盖 USD、0小数JPY、3小数KWD、不可整除、明确Pack/歧义/0隐藏，以及进程内20%合成税率下 shop含税66美元、cart不含税55美元。没有保存商品、改税率表或切换全局客户/购物车。

该原生测试在现有 QA 启动后安装只读 SQL 守卫，所有非 SELECT/SHOW/DESCRIBE/EXPLAIN 请求拒绝；0次禁止写入尝试。前后业务表计数、全 options 值哈希、全部商品元数据哈希一致，原 QA 隔离 MU 文件 SHA 与全局 cart/customer/session 身份和数据均一致。没有复制文件到指向旧脏后端的 QA 插件 junction，没有改永久 bootstrap 或 guard，没有重启服务。

第一次原生验证按“必须已有simple及variation”的前提停止，因为该 QA 实际只有1个已发布简单商品、0个变体；该初始未通过验收记录保留，不能计入通过数，也不是源码功能失败。随后按父任务明确范围，仅验现有simple，原生variation路径仍未验（79项纯fixture包含此分支）。未创建变体或任何永久业务样例来凑通过。原生脚本与完整收据在任务 `work/unit-pricing/verify-unit-pricing-native.php`、`native-results.json`，初始记录 `native-missing-variation-initial.json`。

限制：本子任务没有部署、执行公开 HTTP/浏览器验收、保存真实商品、改生产数据库或测前端可见效果。原生 CLI 与纯 fixture 均不代表完整 WordPress/Woo 前端流程验收，父任务负责整合与发布。提交标识由父任务整合后记录。
