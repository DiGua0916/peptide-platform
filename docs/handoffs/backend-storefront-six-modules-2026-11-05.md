# 店面六模块整合交付 · 2026-11-05 · Codex

本文件记录用户批准“所有任务先做完，再一起修改”后的整合状态。六项源码及本机安装、配置已完成，实际页面已有截图；**本轮未部署服务器、未配置 CDN**。Git 提交、拉取同事更新与推送由 Root 收尾，本文不预先声称已同步。各子模块旧交接中的“candidate / 未安装 / 等 Root 接线”是其当时的子任务边界，本文件及整合收据补充其后的实际本机状态，不抹除原记录。

源码工作树：`D:/Codex/Worktrees/peptide-admin-ui-taste-20261103`。本机站点：`D:/Codex/Sites/vialcovepeptidescom/app/public`。任务证据：`D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/`，下文 `outputs/` 均相对该任务目录。保持现有 VialCove 白色、深青、青色与促销印泥红，不引入后台设计包覆盖公开店面。

## 六项完成内容

| 模块 | 已接入的实际行为 | 主要实现位置（相对源码根） |
| --- | --- | --- |
| 结账印章与摘要 | 删除指定解释文案；轻微倾斜的双圈红色印泥章，金额更新时短促盖落与薄尘，减少动效模式静态呈现。保留上方原生摘要并常驻展开；隐藏下方原生重复摘要。最终应付金额仍由 Woo 提供且单独可见。 | 主题 `assets/cart-pricing.js`、`assets/checkout-polish.css/js`、`inc/cart-pricing.php`；插件 `includes/class-pricing.php` |
| 活动倒计时 | 应用中心可选择已有活动。结账采用红色圆角胶囊；商品播报后、主图前采用透明外层的紧凑分格。共用同一真实活动截止时间，刷新、切规格或重开窗口不重新计时。 | 插件 `includes/class-campaign-countdown.php`、`includes/admin/class-campaign-countdown-page.php`、`assets/campaign-countdown.css/js`；主题 `page-commerce.php` |
| 商品窗口与旧入口 | 移除窗口 Overview / Details / COA / Bag 导航，保留原关闭 X、焦点、规格、报告和购买逻辑。活动区透明并使用同一黑五数据；优惠和倒计时均受当前 SKU 原生资格约束。普通旧 Shop、分类、商品链接恢复到首页目录或对应商品窗口。 | 主题 `inc/storefront-entry.php`、`assets/product-dialog-polish.css/js`、`assets/demo-catalog.js` 的小型恢复事件接线 |
| 邮编辅助 | US / PR 完整地址与本人已保存地址唯一精确匹配时才补空 ZIP；信息不足、多结果、新匿名地址均留手填。手动填写/清除优先，过时异步结果不覆盖当前输入。可选外部地址服务适配已实现但未开通。 | 插件 `includes/class-postal-assist.php`、`includes/admin/class-postal-assist-page.php`、`assets/postal-assist.js` |
| COA 页面 | `/coa-library/` 公开页面完成桌面及手机布局，依报告实际日期按年月分组，保留 219 份报告原有搜索、产品/日期筛选、排序、分页、证书预览、PDF 查看/下载及 EN/ES。 | 插件 `includes/class-report-archive-page.php`、`assets/public-report-library.css/js`；不改同名用途不同的后台 `report-library.css` |
| 首页首单券 | 四个服务图标之后、商品集合之前加入原生可编辑 `vialcove/first-order-offer` 积木。真实 5% 首单券，支持复制及共享原生购物车应用；空车仅复制。结果提示 X / Escape 可关，15 秒自动关闭，不移除已应用券。 | 插件 `includes/class-first-order-offer.php`、`includes/admin/class-first-order-offer-page.php`、`assets/first-order-offer.css/js`；主题共享 `live-cart-experience.js` 仅导出原生写车桥 |

共享注册已接在插件 `includes/class-plugin.php`、`includes/admin/class-apps-page.php`，主题 `functions.php` require 新旧入口适配。首单叠加 guard 只在推荐模块的明确 hook 支持列表追加已知只读回调，未放开未知 hook。

## 结账金额口径：前端必须继续分开使用

所有字段在原生 Store API `cart.extensions.peptide_core.sale_savings` 中，金额是字符串形式的最小货币单位，跟随 `currency_code` / `currency_minor_unit`。本轮不修改 Woo 应付总额或价格。

- **上方商品小计旁印章**：`display_regular_subtotal - display_sale_subtotal`，只表示商品对照价与现价的差额，标签为 Item savings / En productos。不要把活动与券的优惠放到这一区，导致左侧两项相减与右侧不相等。
- **摘要底部印章**：`total_savings`，表示商品差价加上实际原生券优惠及实际已入账的本插件活动负费用，标签为 Total savings / Total saved / Ahorro total。该印章不再展示应付金额。
- **最终应付金额**：继续显示上方摘要标题里的 Woo 原生 `totals.total_price`；包括实际运费、税费、券与费用。只有标题和该金额已就绪，才隐藏底部原生重复总计；标题金额缺失时保留原生总计兜底。
- 复审案例：对照价 `$672.00`、现价 `$502.60`，上方商品差价应为 **`$169.40`**；实际活动优惠 `$55.00` 时，底部总优惠为 **`$224.40`**。若另有实际券优惠 `$25.13`，上方仍是 `$169.40`，底部改为 `$249.53`。这些是口径回归样例，不是固定网站金额。
- 新增 `coupon_savings`、`promotion_savings`、`total_savings`、`display_regular_subtotal`、`display_sale_subtotal`、`savings_available`、`savings_basis`；旧 `regular_subtotal` / `sale_subtotal` / `sale_savings` 契约保留。新增 display 字段把原生单价精度交给 Woo 一次性按数量与税制计算后转为 minor units；不先舍入单价再乘数量。
- `coupon_savings` 使用已计算的原生券金额，含税显示时包括原生 discount tax。活动仅计匹配 `pc-promo-<正整数ID>` 且实际已入账的负 fee.total；不拿资格试算、其他负费用或前端百分比估算当实际节省。`savings_basis` 明确税费口径，不计运费节省。

## 本机配置与数据保护

`outputs/local-configuration.json` 记录以下**本机专属 ID，不能照抄到服务器数据库**：

- 首页装修文档 `685`，服务模块 `service-benefits` 之后插入 `welcome-first-order`，商品集合 `collection` 之前。发布前草稿与发布快照相同，未覆盖另一个未发布草稿。
- 实际黑五活动 `10980`，沿用已有原生结束时间 `1796038061`（UTC epoch 秒），已有档位 300→25、500→55、1000→120。未修改活动的日期或优惠额度。
- 首单券 `11748`，显示码 `WELCOME5`，原生 percent 5%、每人一次、首单校验、兼容叠加、非独享、不自动免运费。已通过显式受控创建后绑定启用，非前台访问自动创建。
- `pc_campaign_countdown_v1` 已启用并绑定上述活动，两位置打开。
- `pc_first_order_offer` 已启用并绑定上述原生券。
- `pc_postal_assist.enabled=true`，仅本人已保存地址精确匹配；`google_enabled=false`、`merchant_consent=false`，无外部地址查询。

备份包括源码归档、安装前相关运行文件、配置快照及完整本机 SQL gzip。位置与摘要见 `outputs/backup.json`、`outputs/local-install.json`；配置前后业务指纹一致，见 `businessDataUnchanged=true`。未通过 UI 提交新订单、扣款、填写真实客户地址，未为了演示伪造访问/付款数据。其他窗口仍可能正常调整其自己的购物车和文件，因此不把全机任意时刻的状态说成静止。

## 公共接口和前端接线契约

### 倒计时

- `GET /wp-json/peptide/v1/campaign-countdown`，匿名只读、private/no-store / Vary Cookie，返回 `{version,serverNow,checkout,product}`。
- 每个位置 DTO 包含 `{version,position,enabled,visible,state,promotionId,title,tiers,starts,ends,target,serverNow}`。`starts/ends/target/serverNow` **全部 UTC epoch 秒，非毫秒**。`tiers` 为原活动 `{min,value,text}`；金额属于原活动单位，不能拿它重写购物车结算。
- 状态 disabled / unconfigured / unavailable / untimed / upcoming / active / ended；无真实截止时间、结束或不可用时隐藏。初始服务端时间接收后按单调时钟老化，60 秒读配置校时，断网不把旧活动复活。
- 客户标签仅对原活动名“黑色星期五 / 黑五”做窄映射 Black Friday / Viernes Negro；未知自定义名保留。后台原活动名不改。
- `PCCampaignCountdown.mount(host,dto,{endpoint})` 返回 `update/refresh/destroy`；`init(root)` 或 `pc:campaign-countdown-mount` 可挂动态节点。商品位置还必须经过当前商品原生 promotion ID 资格检查。

### 商品入口、历史与缓存版本

- `GET /wp-json/vialcove/v1/storefront-entry` 仅返回 `{version:1,release:<64位内容SHA>}`，不返回客户或私人配置。`pageshow.persisted` 且内容版本确实变化、没有未保存输入时才安全刷新；相同版本或网络失败保持当前页面。
- 普通公开旧商品 URL 暂时 302 至 `/?vc_product=<真实ID>#vc-products`；分类使用 `vc_catalog_category`，商品搜索使用 `vc_catalog_q`。EN/ES 标记、合法规格参数及常见归因参数保留。支付回跳、签名 URL、API、编辑器、购物车/结账、写请求不拦截。
- `VialCoveStorefrontEntry.openProduct(id)` 读取既有 `vialcove/v1/home-product/<id>` 原模板，再调用原生呈现器；失败有重试状态。`vc:catalog-restore` 的 detail 为 `{query,categoryIds}`，由原首页目录筛选器消费，不另造交易、筛选和历史栈。
- 发布前缓存且根本没有新脚本的旧 DOM，无法被代码追溯注入；需一次正常新导航后才具备该版本核验。不要据旧 Shop 截图直接断言全部来自缓存，也不要宣称已清了服务器/CDN 缓存。

### 首单券

- 没有新增独立购物车写 API。`VialCoveLiveCart.applyCoupon(code)` 复用同一 client 的 `write('apply-coupon',{code})`，走原生 Woo Store API 与共享 pending/nonce/超时核对。失败不假成功，超时不重发 POST；已应用不重复申请。
- 独立 option `pc_first_order_offer={enabled:bool,coupon_id:int}`；原生装修 type `first_order_offer` / block `vialcove/first-order-offer`，应用入口 `admin.php?page=pc-apps&app=first-order-offer`。编辑器预览不执行申请。
- 成功提示金额取原生该 coupon 的 `totals.total_discount`，明确税前，当前总计取 `totals.total_price`；不由 total×5% 推算。空车只复制；剪贴板失败明确提示，不假称领取成功。
- 首单按本店账户及结账邮箱核验；匿名未有邮箱时只能暂时申请，结账重新核验。已有独享券或活动禁止叠券时拒绝新券并保留原优惠，不悄悄踢掉旧券。

### 邮编

- `POST /wp-json/peptide/v1/postal-assist`，JSON `{address:{country,state,city,address_1,address_2,postcode},allow_external:bool}`，需要同源 Origin/Referer 与 `X-WP-Nonce`，不接受 query/customer ID；返回 `{state,postcode?,source?}`。响应 private/no-store，不回显客户地址或身份。
- 本地匹配只取当前登录客户本人保存的 billing/shipping 地址。US 需有效州；PR 可空州并保留 country=PR。必须完整门牌、街道、城市，单元号等一致且 ZIP 唯一。已有或手动修改 ZIP 不覆盖，未能可靠匹配则 manual。
- 通过原生 Woo Blocks `updateCustomerData({shipping_address:...},true)` / billing_address 持久，仅补 ZIP、保留其他最新字段，不直接改 React 输入值，不建立第二套 fetch 写车。
- 可选 Google Address Validation 需服务账号/API 配额及服务器常量 `PC_GOOGLE_ADDRESS_VALIDATION_KEY`，另需商家启用披露和客户当前页同意；**目前均未开通/未配置密钥**。外部校验还要求地址完整、USPS DPV Y、无地址更正/歧义，才采纳 ZIP。新匿名地址现在仍需手填，不能把适配源码说成已开通全球自动邮编。
- 应用入口 `admin.php?page=pc-apps&app=postal-assist`；option `pc_postal_assist={enabled,google_enabled,merchant_consent}`。

### COA

原生短码 `[pc_report_library]`、服务查询与真实报告文件权威未改。URL 参数 `coa_q / coa_product / coa_from / coa_to / coa_sort / coa_page`，新旧排序均按真实有效 `issued_on` 分年月，未知日期单列，不猜日期。PDF 保留 `/wp-json/peptide/v1/report-library/<id>/file?download=0|1`。技术产品名、原报告标题、批号等不擅自翻译。新样式仅 public-report-library，不覆盖后台报告管理。

## 已执行验证与证据

| 验证 | 实际范围与结果 |
| --- | --- |
| 全批源码检查 | Root 执行的 48 项 lint / syntax / test 命令通过，见 `outputs/source-checks.json`。不把命令数当浏览器用例数。 |
| 印章金额及生命周期 | 34 项安装版 Woo Product / Cart / Tax / display 原方法测试；18 项实际 JS 隔离检查通过。税率/总计准备在内存，未由此创建真实订单。见 `outputs/checkout-savings-review.json`。已修单价精度、含税券口径、动画清理、BFCache、失去标题金额时原生兜底。 |
| 原生叠券整合 | 在真实 WP/Woo 引导下，加载实际已安装活动、券和 hook，独立内存购物车 14/14 通过：原生 5% `$16.50` 与实际黑五 fee `$25` 共用，330→288.50；重复重算不累加，合成老客被首单规则拒绝。严格 SQL guard，无写尝试；产品/meta/券/options 指纹和业务/session 行数未变。见 `outputs/native-coupon-stack.json`。不是实卡扣款或 HTTP 订单流程测试。 |
| 倒计时 | 451 PHP + 27 JS 隔离检查通过。两次本机匿名 EN/ES GET HTTP 200，checkout/product 绑定同 10980 / 原截止时间，private/no-store、无手动语言偏好 Cookie；见 `outputs/countdown-checks.json`、`countdown-customer-label-http.json`。 |
| 商品窗口与入口 | 66 PHP + 41 JS 通过。独审修复脚本迟加载漏挂、重开旧时间复活、无资格 SKU 时钟仍显示；实际 IIFE 与目录事件时序复验。见 `outputs/entry-dialog-checks.json`、`entry-independent-review.md/json`。 |
| COA | 35 PHP + 6 JS 通过，219 份实际公开报告库存核对；实际页面已有桌面及 390px 西语截图。原始报告文件不变。 |
| 首单券与邮编 | 首单 59 PHP + 17 JS；邮编 45 PHP + 15 JS 通过，含读取并离线执行安装版 Woo 原生 updateCustomerData thunk。Google 回执为合成样例，未调用真实外部服务。详见 `work/first-order/verification.json`、`work/postal-assist/verification.json`。 |
| 视觉与独审 | 实际截图 `outputs/coa-desktop-after.png`、`coa-mobile-es-after.png`、`product-mobile-after.png`、`welcome-mobile-after.png`、`checkout-mobile-after.png`。该结账截图是金额口径与黑五标题最终修订前的中间视觉证据，最终口径以 native/client 回归和 Root 随后实际验收为准。独审未见当前明确 P1/P2；已修最终金额兜底。静态 Impeccable public 目标 0 阻断；原 admin DESIGN.md 对公开店面不适用的 advisory 留档，不为清零改品牌。 |

## 后续发布与协作边界

1. 功能提交 `86a5b11` 已完成；Root 拉取并合入同事最新 13 个提交，购物车接口冲突保留双方 `addOne` / `applyCoupon` 及同事推荐清理，整合提交 `9083f96` 已 atomic push 到 main 与 codex/admin-ui-taste-20261103。后续文档收尾提交以 Git 实际记录为准。
2. 服务器部署须把这批 PHP、资源及共享最小挂载一起安装，并核对同事在 `live-cart-experience.js`、原 product-dialog experience 等处的并行改动，不用旧整包覆盖。角色运行目录中未提交的其他工作已保留。
3. 在目标数据库重新确认真实首页、活动和券 ID；后台按同样规则创建/绑定，不能迁移本机 ID 常量或整个开发库。确认真实截止时间、活动允许叠券及首单规则，再开启前台块/倒计时。
4. 本轮未发布正式域名服务器或清 CDN 缓存；未来发布后还需真实域名/缓存版本、支付返回及客户全流程验收。没有用 UI 改造替代支付回调修复，也没有声称实卡支付已验。
5. 邮编已可用的部分是本人已保存地址精确补空；若要新地址自动查 ZIP，用户需提供服务名称/账号配置位置，在后台配置服务端密钥与披露同意，不把密钥发聊天或提交 Git。

### Root 最终实际验收补记

- 英文手机结账 360 / 390 / 430px、1653px 桌面无横向溢出，只有一份可见原生摘要且常驻展开。用户截图案例实际页面回读为商品原价672、商品小计502.60、上方169.40、下方合计224.40、当前原生应付447.60。金额显示改口径后没有改商品价格或 Woo 应付值。
- 最终截图 `D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/outputs/checkout-mobile-final.png` 已替代上表旧结账截图作为交付依据，活动标题为 Black Friday。印章短按压/薄尘动效具备减少动效静态模式。
- 实际旧 `/product/tirzepatide/` 进入首页真实商品窗口，原关闭 X 保留、旧顶端导航移除、黑五档位透明区和图片上方分段倒计时可见，无横向溢出；截图 `product-mobile-after.png`。
- COA 实际219份，桌面三列、390px西语日期分组、编号2610010106搜索为1份BPC157报告及查看/下载入口；未改报告文件或其日期。西语手机截图 `coa-mobile-es-after.png`。
- 首页原生新人券块在服务图标与商品目录之间实际渲染；手机复制按钮得到 Code copied 状态，未在用户浏览器应用券。弹窗计时、关闭与实际共享队列在独立脚本测试覆盖；本次浏览器截图因15秒自动关闭仅显示复制后的卡片，不将其写成完整弹窗视觉录制。
- 合并同事更新后，本批48项 lint/syntax/test命令再次通过；同事新增 catalog-quick-add 与 cart-enrichment-lifecycle 测试也通过。检查在本机，非真实支付或服务器发布验收。

详细模块原理另见本目录 `campaign-countdown-2026-11-05.md`、`storefront-entry-dialog-polish-2026-11-05.md`、`backend-first-order-offer-2026-11-05.md`、`backend-postal-assist-2026-11-05.md`、`backend-coa-public-polish-2026-11-05.md`。本交接无密码、令牌、客户明细或付款链接。
