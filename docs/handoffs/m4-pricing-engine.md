# M4 营销与价格引擎 · 交接（F2 价格营销会话）

分支 `claude/m4-pricing-35ba90`，从主线 `codex/checkout-acceptance-20261105` 开出，已合入主线到 `976c1fa`（2026-11-11）。对应需求 NF-02（优惠部分）、HQ-07（店铺端）。接口约定见 `docs/CONTRACT.md` 文末的 M4 一节；沙盒走查清单见 `docs/handoffs/m4-sandbox-walkthrough.md`。

> **2026-11-12 要先看的一件事**：主线的“手动订单”（11-10 起）把**加购推荐**关掉了——它在每个请求上往购物车计算的钩子加了回调，加购推荐的报价只认白名单里的回调，于是整体放弃。这不是 M4 引起的，优惠码那一半 F2 已修，剩下的要日常会话改 `activity/class-quote-cart.php`（两处，写在 CONTRACT 的 M4 一节“给日常会话”第 1 条）。在生产上核对：`wp eval 'var_dump( \PeptideCore\Activity\Quote_Cart::supported_hooks() );'`，`false` 即中招。

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

### 5. 2026-11-12 同步主线（976c1fa）后新增的“读优惠 / 算优惠”的地方

主线在 11-10 至 11-11 多了约 50 个提交，F2 名下的文件没有被改。逐个看过代码，结论：

| 新功能 | 文件（归属） | 和优惠的关系 | 引擎关着时 | 影子档 |
| --- | --- | --- | --- | --- |
| **手动订单链接** | `class-manual-orders.php`（日常） | **第 8 处会改金额的地方。** 每个请求都挂着四个回调：`woocommerce_before_calculate_totals`（9999）把链接里的商品改成店主配的价；`woocommerce_cart_calculate_fees`（99）去掉满减费用（这一单开了“店铺满减”就只留选中的活动），再加上店主配的自定义优惠 `pc-manual-<n>`；`woocommerce_package_rates`（9999）把运费换成店主配的；`woocommerce_coupon_is_valid`（9999）挡掉首单券以外的优惠码。只对带 `pc_manual` 标记的购物车生效 | 不变（引擎没碰它） | 引擎已认识手动订单的购物车，不会误报。对照样例 53–59，随机购物车里 20% 是手动订单 |
| 手动订单后台的“这一单满减能减多少” | 同上 | 读：用一份临时购物车调 `Promotions::discounts()` | 不变 | 不经过请求末尾的核对；开档时这次调用也由引擎给数，出错退回旧逻辑 |
| 运费预览 | `class-ship-preview.php`（日常） | 读：`Shipping\Discounts::applicable()` + `discounted()` 自己取折后最低 | 不变 | 不影响购物车，不参与核对 |
| 商品页的满减档位券（带进度和锁） | 主题 `product-dialog-experience.js`（F5 / 日常） | 读：Store API 购物车扩展里的 `activity_listener`（`Activity\Progress::for_cart()` → `Promotions::discounts()`）。浏览器不自己算折扣 | 不变 | 不影响购物车 |
| 购物车金额统一口径 | `tracking/class-carts.php` 等（F4 / 日常） | 读：WooCommerce 会话里算好的应付总额 | 不变 | 不影响购物车 |

手动订单带来两个要别人处理的问题（都写进了 CONTRACT 的 M4 一节）：

1. **加购推荐被关掉**（见本文开头）。
2. 手动订单链接的说明写的是“不叠加店铺的满减活动和优惠券、运费优惠”，实际代码里：店主没填自定义运费时，店铺的**运费折扣照常生效**（样例 54）。引擎照代码做。要不要改由平台所有者定。

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
| `class-series-campaigns.php` | 按系列接收营销活动：M3 的四个钩子（下发、导出、快照、恢复）；系列下发的活动只读 |

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

## 三、怎么验证（2026-11-12 的实际结果）

本机现在有原生 PHP 8.3.32、Node 26、WooCommerce 11.2.1 源码和一个真实的本地站点（WordPress 7.1.4 + WooCommerce 11.2.1，SQLite）。F2 用的是这个站点的**私有副本**（数据库复制一份、插件指向本分支），没有动共用的那份。

**不需要站点的测试**（`php -n <文件>` / `node <文件>`；5 条已登记在 `tests/data-observations-suites.json`，`module-gate` / `M4`）：

| 测试 | 内容 | 结果 |
| --- | --- | --- |
| `tests/m4-price-engine-pure.php` | 59 个购物车样例（单品、多品、满减边界和七种类型、券叠加 / 不叠加 / 替换、首单、积分券、赠品、捆绑、运费折扣、推荐券和转盘券、负数费用封顶、**手动订单 7 个**）+ 3000 份随机购物车：旧逻辑和引擎逐分一致；37 个样例另有手算的应付总额 | 全部一致；`--fuzz=30000` 也全部一致 |
| 同上，`PC_WC_SRC=<woocommerce 目录>` | 金额计算改用真实的 WooCommerce 源文件（`WC_Discounts`、`WC_Cart_Totals`、`WC_Cart_Fees`、`NumberUtil`） | 用本机 WooCommerce **11.2.1** 的源码：59 个样例 + 3 万份全部一致；用 trunk（11-10 取）：同样一致 |
| `tests/m4-engine-mode-pure.php` | 33 项：默认关、关着不挂钩子；59 个样例和 1500 份随机购物车在关 / 影子 / 开三档下顾客看到的数字逐分相同、核对“一致”；开档出错退回；日志内容；常量一键关闭；功能声明 | 33 / 33 |
| `tests/m4-marketing-quiet-pure.php`、`tests/m4-marketing-quiet.cjs` | 静默开关：服务端 17 项、前台 16 项 | 17 / 17、16 / 16 |
| `tests/m4-campaigns-readonly-pure.php` | 50 项：投手只读（403、只读列表）；系列下发的活动对所有人只读；系列接管后店铺后台不给新建入口 | 50 / 50 |
| 变异检查（手工） | 故意改错引擎 12 处（取整、活动优先级、封顶、运费取最低、捆绑零头、替换策略、优惠码顺序、手动订单的四条规则） | 每一处都被抓到 |
| 原有测试 | `tests/run-data-observations.cjs` 默认套件，改动前后对比 | 没有新增失败（改动前后都是同样的 9 组不通过，见“遗留问题”）；`first-order-offer-pure.php` 63 / 63 |

**真实 WordPress + WooCommerce 上的测试**（`wp eval-file`，本机私有副本）：

| 测试 | 内容 | 结果 |
| --- | --- | --- |
| `tests/m4-shadow-walkthrough-native.php` | 临时建数据，24 个组合在影子档和开档各走一遍，引擎逐项核对；另走一次请求末尾的入口，确认真的写进统计表 | 一致 48，不一致 0，跳过 0（运费 $15、运费折扣 5 折的情况下） |
| `tests/m4-series-campaigns-native.php` | 按系列接收活动：试运行、下发、重复下发、不合规、全量对齐、冲突、快照恢复、导出；真实购物车里下发的满减和首单券生效 | 23 / 23 |
| F3 的真实同步入口（手工） | 把 F3 分支的 `class-series-sync.php` 临时放进私有副本，调 `Series_Sync::sync()` / `restore()` | 试运行出差异、下发记版本、同版本跳过、少发的停用、恢复后回来、不合规返回错误且不记版本——都符合 M3 的约定。临时文件已删，没有提交 |
| 加购推荐的钩子白名单（手工） | `Quote_Cart::supported_hooks()`、`Recommendation_Coupon::coupon_hooks_supported()` | 主线上两个都是 false；F2 改了自己那半之后优惠码的为 true，报价的仍为 false（等日常会话） |

**还没有做的验证**：没有在 F1 的沙盒（MySQL、s0010 的脱敏数据、nginx）上跑；没有用浏览器走过前台流程（Store API、弹窗领券、分享链接）。这两项按 `docs/handoffs/m4-sandbox-walkthrough.md` 由 F1 执行。积分券在真实站点上没有走（本地站点是 SQLite，积分表要 InnoDB），只在不需要站点的测试里覆盖。

---

## 四、下一步怎么切换

按开发文档 12.6 的步骤，由 F1 发布、平台所有者确认。每一步都能单独退回（把档位拨回 `off`）。

1. **合并和发布**：本分支合进主线，随 `peptide-core` 正常发布到沙盒。此时所有店都是 `off`，线上行为不变，也不会建表。
2. **沙盒走查**：照 `docs/handoffs/m4-sandbox-walkthrough.md`——第 0 步确认关着时没变，第 1 步一条命令走完 24 个组合（影子档 + 开档），第 2 步按系列接收活动，第 3 步开影子档用浏览器走真实流程。每一步写了看哪条命令的输出、什么算通过、不通过怎么办。
3. **生产开影子档**：先试点店（s0001）`wp peptide price-engine mode shadow`，观察 24 小时；再开其余 5 家，观察几天。影子档不改价格。每天看一眼 `wp peptide price-engine status`（或总后台的巡检，见 CONTRACT 给 F1 的第 2 条）。
4. **有不一致怎么办**：`wp peptide price-engine log` 里每条都有购物车内容（商品 ID、数量、单价、券的类型和金额、活动 ID）、新旧各项数字和引擎的优惠明细，交给 F2 复现——把它加进 `tests/fixtures/m4-cases.php` 作为新样例，修到一致再继续。**不一致没清零之前不要开 `on`。**
5. **开 `on`**：影子档连续几天 `mismatched = 0`、`errors = 0` 后，先试点店 `wp peptide price-engine mode on`，对比开启前后同时段的销售额、支付成功率、报错；再开全部店。
6. **出问题**：`wp peptide price-engine mode off`；总后台够不着时，在该店 `wp-config.php` 里加 `define( 'PC_PRICING_ENGINE_MODE', 'off' );`。不需要回滚代码。
7. **下一个版本**：开档稳定后再做两件事——把优惠码“能不能用”的四个检查也改为由引擎判定（现在引擎只是复算核对）；删掉旧类里被引擎替代的算法（旧代码保留一个版本再删；删的时候保留手动订单、运费预览在用的那几个方法，清单见 CONTRACT）。

**系列活动怎么上线**（依赖 M3）：M3 合进主线并发布后，店铺端自动接上四个钩子，不需要另开开关。总后台第一次给一家店下发活动前先发 `dry_run`，看 `diff.campaigns`：`conflict` 的条目是和这家店自己建的活动撞了，由平台所有者决定停哪一个；`skipped` 是活动指定的商品在这家店找不到（先同步商品）。确认后再正式下发。下发后这家店的营销活动页面对店员变成只读。

### 还没做的

- 系列下发 `upsell`（加购推荐）和 `progress_bar`（进度条）：它们的设置只能由登录的管理员保存，等日常会话提供写入入口（CONTRACT 给日常会话的第 4 条）。现在收到会标 `unsupported`。
- 总后台的三档开关界面、巡检告警、系列活动的编辑界面：店铺端接口都已提供，界面由 F1 / F3 / 日常会话在总后台实现。
- 店铺后台没有做引擎开关的界面（用命令行、总后台接口或常量）。
- 优惠码“能不能用”仍由原来的四个检查执行（见第四部分第 7 步）。
- WooCommerce 的 REST 接口（`wc/v3/coupons`）改系列下发的券没有拦（后台页面和原编辑页已拦）。现在没有人用这个接口改券；要拦的话在下一版加。

### 需要平台所有者定的

1. 手动订单链接没填自定义运费时，店铺的运费折扣要不要生效（现在生效，和说明文字不一致）。
2. 盘点第 4 点里的两个现状要不要改：挂着但不能用的优惠码会关掉“不能和优惠码同时使用”的满减；捆绑之后输优惠码，捆绑恢复原价、券也不能用。
3. 系列接管营销活动后，店铺后台现在的做法是：店员只读，平台管理员（总后台一键登录）还能处理本店自己建的活动，系列下发的谁都不能改。要不要连管理员也完全只读。

### 遗留问题（不是本次改动造成的，记给归属会话）

- 默认测试套件用本机原生 PHP 8.3.32 跑，有 9 组不通过，改动前后一样：`data-native-search-pure`、`data-product-action-rankings-pure`、`data-product-user-ratios-node`、`experiment-data-pure`、`mail-source-observations`、`tracking-product-actions-pure`、`tracking-store-api-cart-success-pure`、`visitor-intents`、`visitor-product-actions`（都在追踪 / 数据模块）。
- 项目聊天室（`docs/chat/ROOM.md`）在这台 Mac 上不存在，开工、完工登记写在并行会话的共享笔记目录里。
