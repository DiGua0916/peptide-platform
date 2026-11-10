# M4 营销与价格引擎 · 交接（F2 价格营销会话）

分支 `claude/m4-pricing-35ba90`，基于主线 `codex/checkout-acceptance-20261105`（6aece05）。对应需求 NF-02（优惠部分）、HQ-07（店铺端）。

本文分四部分：一、现有 7 处优惠逻辑盘点；二、新引擎和开关；三、怎么验证；四、下一步怎么切换。

---

## 一、现有 7 处优惠逻辑盘点（2026-11-10，代码 6aece05）

店铺的应付总额由 WooCommerce 计算，本插件通过钩子在四个位置插手：**商品行单价、优惠码、负数费用、运费**。一次 `calculate_totals()` 里的先后顺序是固定的：

```
① 赠品 / 捆绑改单价 → ② 商品小计 → ③ 优惠码逐张校验并计算 → ④ 运费（含运费折扣）→ ⑤ 满减活动（负数费用）→ ⑥ 合计
```

### 1. 每一处挂在哪里

| # | 优惠 | 代码 | 钩子（优先级） | 改的是什么 |
| --- | --- | --- | --- | --- |
| 1 | 满减活动（7 种类型，最多 5 档，可上不封顶） | `class-promotions.php` | `woocommerce_cart_calculate_fees`（20）→ `apply_to_cart` | 每个生效的活动加一笔**负数费用** `pc-promo-<活动 ID>`，不计税 |
| 2 | 优惠码规则（叠加策略、开始时间、满金额 / 满件数、全局首单、适用顾客） | `class-coupon-rules.php` | `woocommerce_coupon_is_valid`（10）→ `validate` | 不改金额，只决定这张 WooCommerce 优惠码**能不能用** |
| 3 | 积分券（积分兑换的个人券，整单减、单独使用、限本人） | `class-loyalty.php` | `woocommerce_coupon_is_valid`（20）→ `validate_coupon` | 同上：只有本人、未退回、未关联订单才能用；金额由 WooCommerce 按“整单减”算 |
| 4 | 首单优惠（WELCOME5，5%） | `class-first-order-offer.php` | `woocommerce_coupon_is_valid`（30）→ `validate_stack` | 同上：券的设置必须合规，且不能顶掉别的优惠；金额由 WooCommerce 按“按比例”算 |
| 5 | 赠品 / 捆绑 | `class-product-offers.php` | `woocommerce_before_calculate_totals`（100）→ `sync_cart`；`woocommerce_coupon_is_valid`（30）→ `coupon_valid`；`woocommerce_coupon_is_valid_for_product`（30） | 自动加 / 移除赠品行并把它的**单价改成 0**；把捆绑组的各行**单价改成分摊价**；有捆绑时任何优惠码都不能用；赠品 / 捆绑行不参加商品型优惠码 |
| 6 | 运费折扣（减元 / 打折，固定或分阶段，会员日，适用顾客） | `shipping/class-discounts.php` | `woocommerce_package_rates`（120，在物流方案 100 之后）→ `apply`；`woocommerce_cart_shipping_packages`（20） | 把每个**运费金额**改成折后价，税按比例减；运费缓存的键带上当前可用的折扣 |
| 7 | 加购推荐券 | `activity/class-recommendation-coupon.php` | Store API 加购成功后（`internal_woocommerce_cart_item_added_from_user_request`，90） | 不算价：加购成功后把后台选定的一张**普通优惠码**挂到购物车上，之后按第 2 行的规则走 |

另外三种“发券”的入口也不算价，只是把普通优惠码挂到购物车：**分享链接**（`?pc_coupon=`，`class-coupons.php`）、**离开挽回弹窗**和**大转盘**（`class-exit-intent.php`，中奖后调 `Coupons::remember`）。挂上以后和手输的优惠码完全一样。

### 2. 金额怎么算

- **优惠码**：全部是 WooCommerce 原生的三种类型——每件减、按比例、整单减。计算顺序固定为“每件减 → 按比例 → 整单减”，同类里金额小的先算；每张券都按**商品原小计**算（WooCommerce 默认；如果店铺开了“依次计算优惠码”，则按前一张券减完之后的金额算、同类按添加顺序——两种引擎都支持），一行最多减到 0。
- **满减活动**：按“分到这个活动的商品行”的小计 / 件数算，**不看优惠码减了多少**（即满减和优惠码都按原价算，再各自相减）。金额四舍五入到分。所有满减费用合计不超过“商品折后金额 + 运费”（WooCommerce 对负数费用的上限），所以应付总额不会是负数。
- **捆绑**：整组目标价（一口价 × 套数，或原价合计 × 折扣）先换成分，按各行原价比例向下取整分摊，剩下的零头按小数部分大的先给。
- **运费折扣**：`运费 ×（1 − 折扣%）` 或 `运费 − 金额`，四舍五入到分，最低 0。

### 3. 怎么叠加、谁覆盖谁

| 组合 | 结果 | 依据 |
| --- | --- | --- |
| 满减 + 满减 | 一件商品只参加**一个**活动：指定商品 > 指定专辑 > 全部商品，同级取最新建的；没到门槛也不会改用别的活动。不同商品可分别参加不同活动，优惠相加 | `Promotions::owner` |
| 满减 + 优惠码（策略“叠加”，默认） | 两样都生效 | `Coupon_Rules::strategy` |
| 满减 + 优惠码（策略“不叠加”） | 购物车里有**生效的**满减时，优惠码不能用；满减没到门槛时优惠码照常 | `Coupon_Rules::validate` |
| 满减 + 优惠码（策略“替换”） | 只要这张券**挂在购物车上**，所有满减都不生效 | `Promotions::usable_ids` |
| 满减设了“不能和优惠码同时使用”（旧数据） | 购物车里挂着**任何**优惠码时，这个活动不生效 | `Promotions::usable_ids` |
| 优惠码 + 优惠码 | WooCommerce 原生规则：有一方是“单独使用”就不能同时挂；积分券都是“单独使用” | WooCommerce |
| 首单券 + 其他 | 可以和满减、其他可叠加的券同时用；如果加上它会顶掉“单独使用”的券或“不能和优惠码同时使用”的满减，首单券不生效 | `First_Order_Offer::validate_stack` |
| 赠品 + 其他 | 购物车里有任何优惠码、有捆绑、或参加计算的商品属于某个满减活动时，**不送赠品**；一份购物车只送一个赠品活动（ID 最大的那个满足条件的） | `Product_Offers::gift_qualifies` |
| 捆绑 + 其他 | 捆绑里的商品不能属于满减活动；购物车里挂了优惠码时捆绑**恢复原价**，同时优惠码也不能用；加捆绑会替换掉赠品 | `Product_Offers::group_state`、`coupon_valid` |
| 运费折扣 + 运费折扣 | 不叠加，每个运费取折后**最低**的那个 | `Shipping\Discounts::apply` |
| 运费折扣 + 其他 | 互不影响；优惠码自带的“免运费”由 WooCommerce 的免运费方式处理 | — |

### 4. 盘点时发现的现状（没有改，引擎原样照做，列出来供决定）

1. **“挂着但这次不能用”的优惠码仍然会关掉满减。** “替换满减”和“不能和优惠码同时使用”看的是购物车上挂着的优惠码，不管它这一次有没有通过校验。例：顾客挂着 WELCOME5，而活动设了“不能和优惠码同时使用”——首单券因为会顶掉活动而不生效，活动又因为购物车里有优惠码而不生效，这一次计算**两样都没有**。WooCommerce 随后会把无效的券移除，下一次计算恢复正常。对照样例 31。
2. **捆绑 + 优惠码 = 两样都没有。** 挂上优惠码后捆绑恢复原价，同时优惠码因为有捆绑而不能用（样例 43）。结账时会提示顾客重新添加捆绑。
3. **满减按原价、不按券后价算。** 所以“满 $100 减 $10”+“9 折券”的 $100 订单是 100 − 10 − 10 = 80，不是 81（样例 15）。
4. **满减的负数费用有上限**，超出部分被 WooCommerce 截掉（样例 49、50），订单里记的活动金额会比活动设置的小。
5. `tests/first-order-offer-pure.php` 在主线上就有 4 项不通过（63 项里的后台保存 4 项）。原因是 11-08 首单优惠后台保存加了 `Apps_Page::require_allowed()`，测试里没有跟着补这个替身，和本次改动无关；见文末“遗留问题”。

---

## 二、新引擎和开关

### 1. 代码在哪

`wp-content/plugins/peptide-core/includes/pricing/`（命名空间 `PeptideCore\Pricing`）：

| 文件 | 作用 |
| --- | --- |
| `class-engine.php` | `Engine::price( $input )`：输入一份购物车，输出优惠明细（`entries`）、没生效的优惠和原因（`rejected`）、各项合计（`totals`，单位分）。纯计算 |
| `class-promotion-math.php`、`class-coupon-math.php`、`class-offer-math.php`、`class-shipping-math.php` | 七处优惠各自的算法，和旧类、WooCommerce 原生算法逐步对应 |
| `class-cart-reader.php` | `Cart_Reader::read( $cart )`：只读地把 WooCommerce 购物车整理成引擎输入；引擎算不了的情况写进 `unsupported` |
| `class-mode.php` | 按店开关：选项 `pc_pricing_engine_mode`，`off` / `shadow` / `on`，默认 `off` |
| `class-shadow.php` | 请求末尾的核对、统计表和明细表 |
| `class-runtime.php` | 接到站点上的那一层：注册、开档时给旧类供数、总后台接口 |
| `class-cli.php` | `wp peptide price-engine …` |
| `class-popups.php` | 营销弹窗静默开关（和价格无关，放在这里是因为归 M4） |
| `class-campaigns.php` | 当前在跑的活动（HQ-07 只读视图用） |

旧类的改动（都很小，关着时不走）：

- `Promotions::discounts()`、`Product_Offers::sync_cart()`、`Shipping\Discounts::apply()` 开头各加一个 `Runtime::on()` 判断；
- `Product_Offers::pricing_facts()`、`Loyalty::coupon_facts()`：新增的只读方法，给引擎取事实；
- `class-plugin.php` 加一行 `Pricing\Runtime::register_hooks();`。

### 2. 三档各做什么

| 档 | 顾客看到的价格 | 引擎做什么 | 额外开销 |
| --- | --- | --- | --- |
| `off`（默认） | 旧逻辑 | 不挂任何钩子（只注册总后台接口和命令行），不建表 | 每个请求多读一个已自动加载的选项 |
| `shadow` | 旧逻辑，一分不变 | 请求末尾（`shutdown`，页面已生成）用同一份购物车再算一遍，和 WooCommerce 实际给出的数字逐项核对；同一份购物车同样的结果只核对一次 | 购物车算过总价的请求末尾多几毫秒；第一次写记录时建两张表 |
| `on` | 满减金额、赠品 / 捆绑行价、运费折扣由引擎给出（钩子、回调名、费用 ID `pc-promo-<ID>`、订单上记的内容都不变）；优惠码的金额仍由 WooCommerce 算，优惠码能不能用仍由原来的四个检查决定 | 同上继续核对；引擎出错或遇到算不了的购物车，这一次请求自动退回旧逻辑并记一条出错 | 同影子档 |

核对的项目：应付总额、商品小计、优惠码合计、每张优惠码、每个满减活动、运费、每行单价。结果分四种：一致、不一致、跳过（引擎算不了，或购物车算完之后又被改过）、出错。

记录在哪：`{prefix}pc_m4_shadow_stats`（每天每档的次数）、`{prefix}pc_m4_shadow_log`（不一致 / 出错 / 跳过的明细，JSON，只留最近 300 条；不含顾客邮箱，积分券只记 ID）。

### 3. 引擎现在不支持的情况（遇到就记“跳过”，开档退回旧逻辑）

- 价格含税（`woocommerce_prices_include_tax`），或启用税费且购物车按含税价显示；
- 购物车里有不是本插件满减活动的费用（别的插件加的手续费等）；
- 有代码挂了会改变原生金额算法的过滤器（`woocommerce_coupon_get_discount_amount`、`woocommerce_get_discounted_price`、`woocommerce_calculated_total` 等，清单见 `Cart_Reader::FOREIGN_FILTERS`）；
- 优惠码不是 WooCommerce 原生的三种类型。

启用税费、价格不含税时：引擎照常核对不含税的各项，只是不比含税的应付总额。

---

## 三、怎么验证

全部是不连 WordPress、不连数据库的脚本测试，`php -n <文件>` 或 `node <文件>` 直接跑；已追加到 `tests/data-observations-suites.json`（5 条，`status: m4-pricing`）。

| 测试 | 内容 | 2026-11-10 实际结果 |
| --- | --- | --- |
| `tests/m4-price-engine-pure.php` | 52 个购物车样例（单品、多品、满减边界、七种满减类型、券叠加 / 不叠加 / 替换、首单、积分券、赠品、捆绑、运费折扣、推荐券和转盘券组合、负数费用封顶）+ 3000 份随机购物车：旧逻辑和引擎逐分一致；其中 31 个样例另有手算的应付总额 | 全部一致。另用 `--fuzz=30000` 跑过 3 万份，全部一致 |
| 同上，`PC_WC_SRC=<woocommerce 目录>` | 金额计算改用**真实的 WooCommerce 源文件**（`WC_Discounts`、`WC_Cart_Totals`、`WC_Cart_Fees`、`NumberUtil`，trunk，11-10 取） | 52 个样例 + 3 万份随机购物车全部一致 |
| `tests/m4-engine-mode-pure.php` | 32 项：默认关；关着不挂钩子；52 个样例和 1500 份随机购物车在关 / 影子 / 开三档下顾客看到的数字逐分相同、核对结果“一致”；开档引擎出错退回旧逻辑；不一致、跳过、出错的记录内容；常量一键关闭 | 32 / 32 |
| `tests/m4-marketing-quiet-pure.php`、`tests/m4-marketing-quiet.cjs` | 静默开关：服务端 17 项、前台 16 项；含“静默与否价格逐分相同”“不静默时弹窗照常” | 17 / 17、16 / 16 |
| `tests/m4-campaigns-readonly-pure.php` | HQ-07：投手打开新建 / 编辑地址 403，列表只读，提交保存 / 启停 / 批量 403；管理员照旧 | 32 / 32 |
| 变异检查（手工） | 故意改错引擎的 8 处（取整方式、活动优先级、负数费用封顶、运费取最低、捆绑零头、替换策略、优惠码顺序……） | 每一处都被对照测试抓到 |
| 原有测试 | `tests/run-data-observations.cjs` 默认 51 组，改动前后对比 | 结果完全相同（改动前后都是同样的 10 组不通过，见“遗留问题”）；`first-order-offer-pure.php` 从 59 / 63 变为 63 / 63 |

**没有做的验证（如实说明）**：这台开发机没有 PHP、没有 WooCommerce 站点，以上是在 PHP 8.3（WebAssembly 版）里用替身购物车跑的；替身的金额算法和真实 WooCommerce 源文件对过。**还没有在真实的 WordPress + WooCommerce 站点上跑过**——影子模式在沙盒跑通、无差异日志这一条，要等 F1 的沙盒 / 本地环境。

---

## 四、下一步怎么切换

按开发文档 12.6 的步骤，由 F1 发布、平台所有者确认。每一步都能单独退回（把档位拨回 `off`）。

1. **合并和发布**：本分支合进主线，随 `peptide-core` 正常发布到沙盒。此时所有店都是 `off`，线上行为不变，也不会建表。
2. **沙盒开影子档**：`wp peptide price-engine mode shadow`（或总后台调 `POST /hq/pricing-engine`）。在沙盒前台把下面的组合各走一遍到结账页：
   - 普通商品 1 件、多件；有满减的商品刚好到门槛、差一点；
   - 手输优惠码、首单券 WELCOME5、分享链接带券、加购推荐券、离开挽回弹窗 / 转盘领的券；
   - “不叠加”“替换”策略的券遇到满减；积分兑换的券；
   - 赠品自动加入、加捆绑、捆绑后再输券；
   - 有运费折扣时填地址选运费。
3. **看结果**：`wp peptide price-engine status`、`wp peptide price-engine log`。要求：有“一致”的计数，`mismatched` 和 `errors` 都是 0。出现“跳过”时看原因（`log` 里的 `reason`），按第二部分第 3 点判断是不是这家店的设置所致。
4. **生产开影子档**：先试点店（s0001），观察 24 小时；再开其余 5 家，观察几天。影子档不改价格，可以放心开。每天看一眼 `status`。
5. **有不一致怎么办**：`log` 里每条都有购物车内容（商品 ID、数量、单价、券的类型和金额、活动 ID）、新旧各项数字和引擎的优惠明细，交给 F2 复现——把它加进 `tests/fixtures/m4-cases.php` 作为新样例，修到一致再继续。**不一致没清零之前不要开 `on`。**
6. **开 `on`**：影子档连续几天 `mismatched = 0`、`errors = 0` 后，先试点店 `wp peptide price-engine mode on`，对比开启前后同时段的销售额、支付成功率、报错；再开全部店。
7. **出问题**：`wp peptide price-engine mode off`；总后台够不着时，在该店 `wp-config.php` 里加 `define( 'PC_PRICING_ENGINE_MODE', 'off' );`。不需要回滚代码。
8. **下一个版本**：开档稳定后，再做两件事——把优惠码“能不能用”的四个检查也改为由引擎判定（现在引擎只是复算核对）；删掉旧类里被引擎替代的算法（按开发文档 10.2，旧代码保留一个版本再删）。

### 还没做的

- 按系列接收营销活动（`Series_Campaigns`）：等 F3 的系列结构定稿，约定已写在 `docs/CONTRACT.md` 的 M4 一节。
- 总后台的三档开关界面和巡检告警：接口已提供，界面由 F1 / 日常会话在总后台实现（见 CONTRACT）。
- 店铺后台没有做引擎开关的界面（用命令行、总后台接口或常量）。
- 带系列标记的活动在店铺后台“对所有人只读”还没做（现在只区分投手和非投手）。

### 遗留问题（不是本次改动造成的，记给归属会话）

- 默认测试套件在这台机器的 PHP 8.3 下有 10 组不通过，改动前后一样：`country-resource-attribution-pure`、`data-native-search-pure`、`data-product-action-rankings-pure`、`data-product-user-ratios-node`、`experiment-data-pure`、`mail-source-observations`、`tracking-product-actions-pure`、`tracking-store-api-cart-success-pure`、`visitor-intents`、`visitor-product-actions`（都在追踪 / 数据模块，归 F4；清点归 F1）。另有不在默认套件里的 `fragment-cache-pure.php`、`page-cache-pure.php` 也不通过。
- `tests/first-order-offer-pure.php` 原有的 4 项不通过已顺手修好（测试里补上 `Apps_Page::require_allowed` 的替身）。
- 项目聊天室（`docs/chat/ROOM.md`）在这台 Mac 上不存在（它是另一台 Windows 机器上的本地文件），所以开工、完工登记写在了并行会话的共享笔记目录里，没有发到聊天室。
