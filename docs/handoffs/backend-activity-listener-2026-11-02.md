# 活动监听：购物车活动进度后端契约

2026-11-02 · Codex · CART-ACTIVITY-LISTENER-01。后台应用、配置接口及只读进度已完成。后续用户明确要求“装上吧”，插件默认购物车显示组件现已实装，见 [安装与实际验收](backend-activity-listener-install-2026-11-02.md)。主题窗口勿再次重复挂载。

## 目的与职责

应用名称为“活动监听”，管理入口为应用中心 `admin.php?page=pc-apps&app=activity-listener`。运营人员选择本站已经创建的一个满减活动，购物车根据现有 WooCommerce / Promotions 规则读取参加该活动的金额或件数、达到的档位、距离下一档的差额和节点状态。

该应用只解释已有优惠，不新增优惠、费用、优惠码、商品价格、礼物、库存、订单或客户行为数据。显示总开关和动画开关不影响原活动实际优惠是否生效；关闭应用只是关闭提示。活动依旧在营销活动 → 满减活动维护，监听设置不复制另一份门槛、优惠值、商品范围或活动时间。

后端负责应用配置、权限、草稿与已应用快照、真实活动引用、只读购物车进度和接口。前端窗口负责购物车页面的具体位置、进度条与节点样式、烟花/笑脸、无障碍、手机布局和动态生命周期。只在购物车页面使用，不因后台配置开启而把它放到首页、商品弹窗、结账或全站购物车抽屉。

## 配置与管理

配置协议版本为 1，字段为：

```json
{
  "version": 1,
  "enabled": false,
  "promotionId": 0,
  "title": "Your offer progress",
  "celebrationEnabled": true,
  "smileEnabled": true
}
```

这只是结构及默认值，不是当前店铺已应用内容。`promotionId` 是本站真实 `pc_promotion` 记录 ID；`title` 是纯文本，最多 100 字符。开关与活动选择保存于本站数据库，不能从请求选择其它站点或覆盖其它站点配置。

管理模型沿用商品播报的单站双快照：读取/保存草稿与已应用配置分开，编辑修订 `revision` 用于 CAS 冲突保护。未应用输入不进入公开接口或购物车输出。未配置时关闭；不自动发布示例活动。保存草稿不改变客户使用的快照，只有“保存并应用”更新公开配置。多个窗口冲突以 409 拒绝，不自动覆盖输入。相同已应用配置重复提交不应让前端重建动画。

现有应用配置权限和 REST nonce 均在服务端校验。平台员工除了 `manage_woocommerce`，还必须有 `apps` 模块授权；页面与 API 都需要守卫，不能只隐藏应用卡片。活动选择必须在服务端重新核对存在、类型及状态，不信任下拉框；保存监听配置不改活动内容。生效以后活动被删除、停用、过期或改了条件时，应在下一次实际购物车读取时重新判定。

路由相对当前站点 `peptide/v1`：

| 方法及路径 | 用途 |
| --- | --- |
| `GET /activity-listener` | 公开只读已应用提示配置，不返回草稿、管理账号或客户购物车 |
| `GET /activity-listener/admin` | 授权读取草稿、已应用配置、编辑修订、默认值和活动选项 |
| `PUT /activity-listener/admin` | `{config,expectedRevision}` 仅保存草稿 |
| `POST /activity-listener/publish` | `{config,expectedRevision}` 保存并应用 |
| `POST /activity-listener/preview` | 授权后台合成示例预览；不能读取或写入客户购物车 |

公开 URL 应由 `rest_url('peptide/v1/activity-listener')` 或公开 PHP helper 生成，不硬编码本机域名。配置读取须禁止缓存过期启用状态。管理接口不得接收 `site_id`、`blog_id`、客户 ID、任意购物车 ID 等跨站或跨客户选择参数。导出当前编辑草稿可用于冲突时备份，不意味着另外实现了完整文案导入功能。

## 真实购物车来源与金额口径

客户购物车进度通过原生 WooCommerce Store API 购物车响应的 `extensions.peptide_core.activity_listener` 返回。应扩展既有 `Api\Store_Api` 的同一个 `peptide_core` CartSchema 回调，保留原有 `sale_savings`、`promotion` 和 `pixel_events`；不要用同 namespace 的第二次注册覆盖它们。

服务端只读取 WooCommerce 当前会话的 `WC_Cart`，不能用客户上传的金额推定达标，也不能接收另一个客户的购物车身份。Woo 的匿名购物车会话或 Cart Token 仍由原生 Store API 管理；该应用不另建客户身份或购物车表。客户进度不可进入公共共享缓存，不能把 SSR 购物车 JSON 跨用户缓存。

计算复用 `Promotions::usable_ids()`、`assign()`、`evaluate()` 和 `discounts()` 的已有规则：活动状态与时间、顾客资格、优惠码叠加/替换策略、变体/父商品、排除促销商品、指定商品/专辑/全站优先级均保持。某活动覆盖商品不代表它实际拥有这行商品；更具体活动优先时，不能仍把这一行计入所选活动进度。

`cartSubtotal` 是所有购物车商品的税前小计；`eligibleSubtotal` 是实际分配给所选活动的商品税前小计。金额活动用后者与真实门槛比较，不含运费、税费或其它费用，也不是折后应付款总额。原生 `line_subtotal` 已包含商品当前售价，与商品划线原价不同。数量活动按实际参加活动的件数比较，不能拿购物车金额套件数门槛。

金额 DTO 使用 `{minor: "整数字符串", text: "格式化金额纯文本"}` 并携带币种与小数位，避免浏览器重新计算优惠或显示浮点误差。前端应消费服务端的节点状态和差额，不从 HTML 价格、划线价或另一个统计报表拼总额。

`actualDiscount` 只读取当前购物车已结算的 `pc-promo-{id}` 费用行的负数 `total`，不是原活动引擎预测值，也不是付款、扣款、退款或到账确认。WooCommerce 会在优惠码等折扣后对负费用再封顶；例如活动引擎预测减 10，但费用行最终只减 5，就只能报告 5。缺失最终费用或最终费用为零时，不回退到预测值或未结算的 `amount`，也不为了提示调用 `calculate_totals()`。门槛达到、活动有资格和当前确有正优惠是不同状态：指定活动被优惠码替换、商品被更高优先活动分走，或一口价活动门槛够但原价更便宜时，不能显示“已获得优惠”或播放庆祝。

## 进度 DTO 与节点

公开进度由 `Activity\Progress::for_cart(?WC_Cart $cart = null, ?array $settings = null)` 返回；省略参数使用本站已应用配置和当前 WooCommerce 购物车。草稿预览单独使用明确的合成输入，不改 `WC()->cart`，也不调用 `calculate_totals()` 来伪造真实客户状态。

| 字段 | 用途 |
| --- | --- |
| `version/enabled/visible` | 协议、配置开关和这份状态是否应显示 |
| `revision` | 已应用提示配置的修订 |
| `promotionRevision` | 所选活动真实规则指纹，用于活动规则改变后重建基线 |
| `stateKey` | 当前已规范化状态的指纹；相同响应不重复播放 |
| `status/reason` | 实际活动/资格/空车/关闭等状态与原因 |
| `currency/currencyMinorUnit` | 当前购物车币种及金额小数位 |
| `cartSubtotal/eligibleSubtotal/actualDiscount` | 全车商品小计、参加活动商品小计和所选活动最终负费用对应优惠 |
| `basis/measure/measureText` | 金额或件数口径；金额 measure 使用最小货币单位字符串，件数为整数 |
| `progressPercent/completed` | 当前显示目标的进度与是否完成；百分比为有限的 0–100 值 |
| `discountApplied` | 这项活动当前已结算的负费用是否确有正优惠，不等于付款完成 |
| `achievedKeys` | 当前达到的节点集合；庆祝仍必须以节点 `celebrationEligible` 为准 |
| `promotion` | 所选真实活动的编号、标题、类型、状态及安全详情链接 |
| `celebration` | 烟花与笑脸显示开关，不是服务器已经播放的事件 |

`nodes` 的每项包含 `key/index/threshold/thresholdText/rewardText/reached/positionPercent/celebrationEligible`。`measure`、`threshold` 和 `remaining` 统一随 `basis` 取类型：`spend` 是最小货币单位的整数字符串，`quantity` 是 JSON 整数；`index` 也是整数。有限档位活动最多使用已有五档；下一档 `next` 包含相应节点和 `remaining/remainingText/message`，没有下一档时为 null。

上不封顶活动并没有“最后一档”。只展示当前已达周期和下一周期等有界节点（最多两项），节点 key 必须含稳定周期身份，不能通过累加任意多的里程碑撑满响应。前端不能把上不封顶活动误显示为永久完成，周期切换时也不能回放整个历史。

关闭、无活动、无购物车、空车、无参加活动商品、活动未开始/停用/过期、顾客不合资格、优惠码阻断、活动规则异常等状态不能生成假的成功。后台 preview 的 DTO 应明确是示例，`discountApplied` 和所有 `celebrationEligible` 均为 false；它只帮助布局，不证明真实客户已经达标。

## 购物车挂载与前端动画

公开 PHP helpers 约定为 `peptide_core_activity_listener_config()`、`peptide_core_activity_listener_url()`、`peptide_core_activity_listener_progress(?WC_Cart $cart = null)` 和 `peptide_core_activity_listener_markup()`，具体源码签名由 Root 最终交接核对。`Activity\Mount` 管理默认挂载。

默认 markup 只包含公开已应用配置和路由：`{config,extensionNamespace:'peptide_core',extensionKey:'activity_listener',cartUrl:rest_url('wc/store/v1/cart'),configUrl:rest_url('peptide/v1/activity-listener')}`；不把当前客户金额、商品或进度写进 SSR JSON，以免私人购物车结果落入整页缓存。PHP progress helper 可让主题在明确的当前请求读取，但主题若主动 SSR 私人进度须自行承担无缓存及会话隔离责任。默认只通过动态 Store API extension 取客户状态。

经典购物车通过 `woocommerce_before_cart` 输出 inert、默认隐藏的挂载；购物车表单正常更新或 Woo fragments 更新后重新读取当前会话结果。不要将已有经典钩子误认为它会自动在 Blocks Cart 内执行。挂载只在 `is_cart()` 且非结账时启用，一请求只输出一次；应用关闭时不输出。公开配置有效而活动刚过期时，动态 extension 的 `visible=false` 仍须阻止显示。

Blocks Cart 应通过官方 `wc.wcBlocksData.cartStore` 的 `getCartData().extensions.peptide_core.activity_listener` 响应订阅状态。现有主题 `assets/cart-pricing.js` 已用 `wp.data.useSelect` 订阅这个原生 Store，前端可沿用其数据来源，但本后端不改该文件。视觉位置应由主题/Blocks 扩展挂载；不要查询 Woo 内部 React DOM 后强插或重复创建 root。后端用 `render_block_woocommerce/cart` 输出稳定 sibling 挂载，并与经典入口共用一次输出保护，避免重复。前端成功配置且渲染后才解除 `hidden`，无可显示结果时保持或恢复隐藏，不留占位。

现主题 `inc/cart-drawer.php` 在 `is_cart()` 或 `is_checkout()` 时明确不开全站抽屉，因此本功能不需要也不应扩展首页或商品页抽屉。保持 Woo 原生购物车增减、优惠码、结账及提交过程不变。

前端把成功加载的第一份状态作为基线，不对已经达到的历史节点放烟花；刷新、重新挂载、开页或重复收到同 `stateKey` 不得重放。之后仅对新跨过且 `celebrationEligible=true` 的节点播放小范围效果；不能只比较金额百分比或 `reached`。

若配置 revision 或 `promotionRevision` 改变，先重新建立基线，不把新活动旧档位全部当成新奖励。活动指纹覆盖门槛、优惠、时间、范围类型及指定商品/专辑选择等规则；在同一个范围类型内改变商品集合，也要重建基线。指纹不公开顾客资格规则或客户信息。客户减量/删商品后撤销已不满足节点和当前提示；失去资格、活动关闭/过期或优惠码替换后立即清理成功样式和效果。读失败不阻塞购物车操作，不沿用一个失效的成功状态来鼓励结账。

动画限制在当前节点附近，烟花/笑脸不挡金额、加减按钮、优惠码或结账按钮。尊重 `prefers-reduced-motion`，减少动态模式用静态节点/轻量淡变；新里程碑可用节制的 `aria-live="polite"` 文字提示。离屏、页面隐藏或卸载时停止动画/定时器；重复打开不创建多个订阅和计时器。390/430px 与更窄窗口的实际排版和真实购物车动态效果由前端联调验收。

## 现有赠品/套餐边界

只读源码核查：现有 `Product_Offers` 在新套餐选项和赠品资格中使用 `Promotions::owner(active_ids())` 阻止冲突；已存在的套餐若活动后来改变，`validate_cart()` 会把不再合法的组合交给原生结账错误。当前 `Promotions::assign/evaluate/discounts` 并没有另一个统一的 `_pc_offer` 行排除器。

因此新提示不能擅自过滤一组行后声称这就是原生实际优惠，也不能为了进度条改写旧费用逻辑。若所选活动分配到产品优惠行，或已有产品优惠校验错误，进度服务应保守停止成功/庆祝并返回明确冲突原因。最终处理方式和实际测试以 Root 与进度服务源码为准；这一边界不等于本轮重写赠品/套餐系统。

## 实现与实际验证

代码位于 `wp-content/plugins/peptide-core`：新增 `includes/activity/{class-config,class-store,class-progress,class-mount}.php`、`includes/api/class-activity-listener-controller.php`、`includes/admin/class-activity-listener-page.php` 及 `assets/activity-listener-admin.{js,css}`；接入现有 Plugin、Apps_Page、Store_Api 与 functions helpers。未改旧 Promotions 优惠引擎、前端主题或前端工作树。

- 406 项计算隔离断言通过：执行生产 Promotions/Progress，WordPress/Woo 对象用内存替身；覆盖七种活动、多档与循环、资格/优先级/变体、优惠码、未结算或封顶费用、浮点边界、规则变更及只读行为。这不等于原生结算验证。
- 62 项真实 WordPress/REST/MySQL 配置检查通过：TEMP options/posts/postmeta，活动存在与状态、非法规则、nonce/权限/员工应用授权、CAS、草稿隔离、公开白名单、合成预览无写入和坏记录关闭。两张临时 options 表模拟站点隔离，不等于完整多站网络测试；CAS 使用可控查询交错，不是多进程压测。
- 41 项真实 WooCommerce 检查通过：内存商品/购物车、真实 WC_Cart_Totals 和 Promotions，95% 优惠码使预测减 10 最终仅减 5；100% 优惠码使最终活动费用为零且不庆祝。验证金额/件数、增减、范围/优先级、过期空车、Store API schema 和 cart-only/config-only/once 挂载。挂载条件由 CLI 中真实 WP_Query、wp action marker 与 Woo 位置缓存模拟，不冒称网页动画验收；内存商品为虚拟免税商品，不代表真实物流税费或支付验收。
- 后台 22 项 DOM 状态检查及 8 项 PHP 页面 hook/权限检查通过；实际 IAB 打开中文 QA 设置页，读取默认关闭/空活动提示，未选活动预览会阻止并保留输入，重新读取恢复原配置；1280px 视口无横向溢出。实际页面截图在 `outputs/admin-qa.png`。没有制造可供截图的示例商品或活动，未声称手机及客户动画已验收。
- 原站匿名 HTTP：配置 GET 200、默认关闭且不含草稿/活动列表、Cache-Control no-store；管理 GET 与发布 POST 返回 401。真实 Store API GET 200，空车返回 activity_listener，保留 sale_savings、promotion、pixel_events。
- 10 个 PHP 文件语法、JS 语法与 git diff --check 通过。QA 与仓库源码逐文件 SHA 对齐；原站插件通过既有 junction 使用本后端工作树。

测试文件和回执：`D:/Codex/Tasks/2026-11-02/cart-activity-listener/{work,outputs}`。源码修改前备份位于 `work/source-backup`，QA 覆盖前备份位于 `work/qa-backup`。原生测试 SQL 仅允许连接内 TEMP 表；永久 visitor-only guard 未改。前后业务/访客/会话计数及原应用配置一致，购物车验证还比对全部 options 指纹不变。合成预览写 SQL 为零。没有往原站应用示例配置、修改原活动或创建永久测试商品、顾客、订单、访客记录。

初建时默认关闭的配置规则仍保留。后续已增加插件自己的真实购物车显示组件；原站本轮读到的已应用配置已启用并选活动10980/revision1，本轮没有更改运营选择或活动规则。新阶段覆盖上文“仅新跨节点播放”和“UI待接”描述：用户要求每个变化后的有效达标购物车状态可重播当前最高档，重复同stateKey合并；窄屏横排与金额下移已接入。到期/关闭、首次静态基线和异常隐藏规则保留。详见安装交接。
