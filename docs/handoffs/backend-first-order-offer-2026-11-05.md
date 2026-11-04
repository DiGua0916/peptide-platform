# 首页首单 5% 优惠块 — 2026-11-05 Codex

用户已授权本轮首页优惠券/装修块功能实施。当前为源码与隔离测试交付；本子任务未安装运行文件、未创建或启用真实优惠券、未修改真实购物车/订单/支付，未部署。

## 新文件

- `wp-content/plugins/peptide-core/includes/class-first-order-offer.php`
- `wp-content/plugins/peptide-core/includes/admin/class-first-order-offer-page.php`
- `wp-content/plugins/peptide-core/assets/first-order-offer.js`
- `wp-content/plugins/peptide-core/assets/first-order-offer.css`
- `wp-content/plugins/peptide-core/tests/first-order-offer.cjs`
- `wp-content/plugins/peptide-core/tests/first-order-offer-pure.php`

## Root 最小接线

1. 插件既有 autoload 支持以上类路径；启动时调用 `First_Order_Offer::register_hooks()` 一次。它自行注册模块 definition/render、公开 assets、受保护后台 POST 和唯一窄 coupon guard。
2. `Admin\Apps_Page::apps()` 加 `first-order-offer` 条目，中文名“首单 5% 优惠”、icon `dashicons-tickets-alt`，`on` 读 `First_Order_Offer::settings()['enabled']`；render dispatch 调 `Admin\First_Order_Offer_Page::render()`。
3. 新原生装修块 type=`first_order_offer`，block=`vialcove/first-order-offer`，category=`operation`，`singleRender=true`。根代理在首页服务图标之后、商品区之前插入。只存引用，优惠规则在应用中心维护。公开直接调用亦可 `First_Order_Offer::render(false)`；editor/preview 必须传 `true`，该状态无 apply 写车。
4. 全局 `VialCoveLiveCart` 必须增加 `applyCoupon: code => client.write('apply-coupon', { code })`，复用现有会话、nonce、全车 pending 锁与超时核对；本 JS 不存在独立 fetch 写车。桥接未挂载时明确不可用，可复制后结账手填。
5. `Activity\Recommendation_Coupon::coupon_hooks_supported()` 的 `allowed['woocommerce_coupon_is_valid']` 追加 `\PeptideCore\First_Order_Offer::class . '::validate_stack'`。注册固定 priority 30 / args 3；不泛化放行未知 hook。新回调只读且只在 enabled/绑定券 ID 命中时检查；首次券本来就不进入推荐试算支持集合。

## 配置与真实行为

- 独立 option `pc_first_order_offer` 精确 `{enabled:bool,coupon_id:int}`，默认关闭。前台 GET/render 不写 option/coupon，不获取客户姓名/地址，也不自动建券。
- 后台保存/创建必须 POST、nonce、`manage_woocommerce` 和 `edit_shop_coupons`。仅绑定原生 5% percent、首单 `_pc_first_order=yes`、每人一次、非独享、strategy=`stack`、不附免运费的券；启用时须有效且 Woo 优惠券功能已开。商品范围、金额/件数门槛、时间沿用所选券。
- 显式“创建并绑定 WELCOME5”创建上述配置，没有到期日和总次数上限；绑定但不自动开启。已有同名兼容券复用；不同规则绝不覆盖。只在该明确 admin create 动作使用 per-database/site/code MySQL advisory lock，散列包含 DB_NAME 与站点表前缀，零等待，锁失败不创建；锁内重新查询，finally 释放，不留持久票据或数据库名明文。
- 首单判定复用现有 `Coupon_Rules::validate()`：本店账户和结账邮箱；已支付、on-hold、refunded 属历史订单。匿名未填邮箱时只能暂时应用，结账按邮箱复核；不承诺通过换 IP/浏览器可判定新客，也不保证跨店身份唯一。
- 满减活动须允许与券共用。现有独享券或不允许共用的已生效活动存在时拒绝新券，保留当前优惠；绝不自动移除旧券或暗改活动开关。不得为追求“叠加”绕过原生优惠约束。
- 非空车点击：先复用共享 client GET 核当前车，随后原生 Store API `apply-coupon`；成功必须 rawCart 存在唯一同码 coupon 且旧券未被移除。失败、失效、异常响应、超时均不假成功；不自动重试 POST。已应用时零 POST。
- 弹窗展示原生该 coupon 的 `totals.total_discount`（标签明确税前）和原生当前 `totals.total_price`。没有 `total×5%`，没有把活动优惠或运费变化算作该券优惠。金额按原生币种/精度精确格式化。
- 空车仅尝试复制，无申请券 POST；剪贴板失败如实提示，可手动选中复制。优惠块常驻，结果弹窗 X/Escape 可关，15 秒自动关，仅关闭提示，不移除已应用优惠。
- 所有新增买家文案英文/西语完整覆盖；西语模式下原生首单/超时/忙碌错误精确翻译、未知原生错误使用西语检查条件/购物车恢复提示，不透出混杂英文。后台与装修预览管理文案维持用户中文，不修改共享翻译表。CSS 有 1000/600px 响应式、44px 控件、focus-visible、reduced-motion。当前未声称完成真实 360/375px 浏览器视觉验收。

## 验证

- `node --test wp-content/plugins/peptide-core/tests/first-order-offer.cjs`：17/17，包含实际共享 `createClient` 的 nonce/native endpoint、超时只 reconciliation GET、重复点击、不完整回执、0/大整数/8 位精度、15 秒取消及西语错误分支。
- `php wp-content/plugins/peptide-core/tests/first-order-offer-pure.php`：59/59，使用真实新增 offer/admin 类及既有 `Coupon_Rules`，WP/Woo 依赖为内存 fixture；含保存权限/nonce、首单邮箱/账户、叠加保护、显式创建规则、数据库隔离锁/异常释放及双语键覆盖。不是实际支付或原生数据库全流程证明。
- 测试/源码 SHA 和 lint 收据由任务目录 `D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/work/first-order/` 保存。
- 后续 root 在先备份后配置既有或受控新券、安装/接线，在隔离实际 Woo 环境验收优惠金额与桌面/手机效果；真实新老客户结账仍遵循原生校验。本交付没有修改这些运行数据。
