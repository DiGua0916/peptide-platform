# 前后端契约

状态：2026-10-12 INT-01 集成基线，peptide-core **0.3.0 / ecec833**。接口已在 LocalWP 开发站点实现。设计沿革见后端方案 v1 的 C/D、v2 的 C'/D'；本文件记录当前代码，包括后续增补。变更先写角色交接，再协调更新本文件。

后续基线：PAY-SHIP-01 的后端提交 3aecb8f 增加测试商务配置命令和后台提醒，公共接口形状未变。

2026-10-22 增补（peptide-core 0.11.0–0.12.0，Codex 已按这些接口完成前端对接 / 提议同步）：菜单、结账条款、公开优惠码三组 PHP 公共函数和 `?pc_coupon=` 行为；访客追踪测试版接口（详细协议见 `docs/handoffs/tracking-protocol.md`）；满减活动（0.12.0）。

## 数据与职责

- WooCommerce 是商品、变体、价格、库存、购物车、顾客和订单的唯一来源。使用 Store API `/wc/store/v1` 及原生 Blocks 购物车/结账。
- peptide-core 管理 `pc_batch` 批次、`pc_report` 报告、受保护文件、访问判断、订单行批次关联和站点设置。批次不另建库存台账。
- 主题消费公共接口，不读取 `_pc_*` 元数据、不拼私有文件地址、不复刻订单。方案 v1 的自建产品由 v2 替换，询价目前未实现。

## peptide/v1 路由

本地前缀 http://vialcovepeptidescom.local/wp-json/peptide/v1；实际地址用 WordPress `rest_url()` 生成。

| 请求 | 权限与返回 |
| --- | --- |
| GET `/site` | 公开站点设置 |
| GET `/products/{slug}/batches?include_history=0` | 已发布商品的公开批次；`{product,batches}`；include_history=1 包含可见的已替代报告 |
| GET `/batches/lookup?batch_no=…` | 精确查询；返回批次对象并附 product；每 IP 每 10 分钟 60 次 |
| GET `/reports/{id}/file` | 授权后 PDF 文件流；错误为统一 JSON |
| GET `/me/orders/{order_number}/batches` | 已登录订单顾客；未登录 401，不存在或非所有者 404 |
| GET `/track/session` | 公开；追踪状态 `{protocol,enabled,test_mode,tracked,config}`，不返回访客编号 |
| POST `/track/events` | 公开、无需 nonce；追踪关闭 403；批量上报，逐条 ack / rejected（见下文“访客追踪”） |
| GET `/track/admin/live`、`/track/admin/visitors/{id}`、`/track/admin/visitors/{id}/cart`、`/track/admin/visitors/{id}/visits`、`/track/admin/visits/{id}`、`/track/admin/archive`、`/track/admin/stats` | manage_woocommerce；后台页面专用，主题不调用。具体说明见下表 |
| GET/POST `/menus/{id}` | edit_theme_options；后台菜单编辑器专用，主题用下面的 PHP 函数 |

最后一条路由参数目前按 **WooCommerce 数字订单 ID** 查找，返回 order_number 为展示编号字符串。未启用自定义编号插件时二者一致，将来需显式适配展示编号。

## 站点设置

```json
{"demo_mode":true,"demo_notice":"This site is a demonstration. Product, batch and report data are synthetic.","use_statement_html":null,"checkout_ack":{"enabled":false,"label_html":null}}
```

- demo_notice 为纯文本；demo_mode=false 时返回 null。主题仅在开启演示时显示，转义文字，空提示仍保留简短演示标识。
- use_statement_html 为经 wp_kses_post 清理的 HTML 或 null；主题再次清理后显示，空值不生成空模块。
- checkout_ack.label_html 保留早期字段名，实际是**纯文本**。空文案时 enabled 不生效。brand_name 仅后台使用，不在 /site 返回。
- 管理入口：店铺设置 → 系统设置 → 其他设置 → VialCove 设置（2026-10-23 起；地址仍是 admin.php?page=pc-settings）。仅有 pc_manage_settings 的管理员可操作。

## 商品、批次与报告

Store API 商品的 `extensions.peptide_core`：

```json
{"is_demo":true,"specs":[{"label":"Example","value":"Demo value","unit":null}],"batch_count":1,"report_state":"available"}
```

价格、币种、变体、库存采用 Woo 原始返回。batch_count 为公开批次数；商品状态取公开批次中的最高级别 available > restricted > pending > none。

商品摘要：`{id,slug,name,is_demo}`。
批次字段：`id,batch_no,product_id,variation_ids[],status,is_demo,manufactured_on,retest_or_expiry_on,origin_note,report_state,reports[]`。可选日期和说明为空时为 null。
报告字段：`id,batch_id,title,type,version,status,is_current,access,is_demo,issued_on,issuer_name,summary,file,can_download,download_url,access_reason,locked_reason,superseded_by`。

批次号统一格式为 3–40 位字母、数字和连字符，服务端规范化；批次 status 为 active / depleted。后台 visibility 为 public / hidden，由接口过滤，不另带 stock 对象。报告 type 为 coa / hplc / ms / other，status 为 draft / published / superseded / withdrawn，access 为 public / registered / approved / admin。version 为整数，同批次同类型递增；可选 issued_on、issuer_name、summary、superseded_by 均可为 null。

- file 仅可下载时为 `{mime,size_bytes}`，否则 null。不暴露内部路径。
- download_url 为绝对地址，登录时带 `_wpnonce`。**完整使用原地址**，仅做 HTML URL 转义；不去掉或重建 nonce，不缓存跨用户结果。
- can_download=false 时 URL=null，显示登录/审批/暂无资料，不生成无效下载按钮。
- access_reason 为 public / registered / approved / staff / order_owner 或 null；locked_reason 为 login_required / approval_required 或 null。
- WooCommerce 已付款状态的订单归属可授予 order_owner，不能覆盖仅员工报告。所有者可访问其已关联隐藏批次的获准报告。
- 草稿、撤回及当前用户不应知道的仅员工报告不在列表返回；旧版本仅 include_history=1 时列出。访客看不到隐藏批次。

| report_state | 含义 |
| --- | --- |
| available | 至少一份当前报告对当前用户可下载 |
| restricted | 有可见当前报告，但当前用户无法下载 |
| pending | 无当前可下载/受限报告，批次标记或草稿表示整理中 |
| none | 没有可展示的报告 |

## 订单批次与结账确认

```json
{"order_number":"1024","items":[{"product":{"slug":"example","name":"Example"},"variation_label":null,"quantity":1,"batch":null}]}
```

items 按 WooCommerce 商品订单行迭代顺序返回，不按商品 ID 合并；商品删除时 slug 可为 null。已分配时 batch 为 `{batch_no,is_demo,report_state,reports}`。当前没有 line_item_id；主题补充未分配提示时按订单行顺序映射，数量或接口异常时不猜测状态。

后端在账户订单详情商品行下输出 `.pc-order-batch` / `.pc-order-batch__report`。主题提供样式；未分配显示 `Batch information will be available after shipment.`；取消、失败或退款订单不暗示将发货。邮件只有批次文字，不附需要当前登录的下载链接。

启用确认后，区块结账自动生成 Additional Checkout Field **peptide-core/checkout-ack**，order 位置、checkbox 类型、必填。Store API 结账载荷：

```json
{"additional_fields":{"peptide-core/checkout-ack":true}}
```

漏传/false 由服务器拒绝（400）。主题保留原生提交流程，不自行提交、不预勾选。后端保存 `_pc_checkout_ack={label,accepted_at}` 原文和 UTC 时间快照，主题不操作它。经典 shortcode 结账未适配，不能替换 Blocks。

## 错误、认证与缓存

统一错误：`{code,message,data:{status,fields?,retry_after?}}`。

| 状态 | 示例 / 处理 |
| --- | --- |
| 400 | pc_invalid_param，字段 required / invalid_format；结账另由 Woo 返回 rest_missing_callback_param / rest_invalid_param |
| 401 | pc_login_required，引导登录 |
| 403 | pc_forbidden；WordPress nonce 校验也可返回 403 |
| 404 | pc_not_found，不存在和不应公开的对象不区分 |
| 410 | pc_report_withdrawn，已撤回 |
| 429 | pc_rate_limited，读取 retry_after 提示重试 |

浏览器 Cookie REST 需 X-WP-Nonce；下载 URL 的 _wpnonce 由后端生成，登录 Cookie 仍需存在。Woo Store API 写请求另需其 Nonce 或 Cart-Token，由原生 Blocks 处理。账户、购物车、结账及带用户权限的报告不得被公共整页缓存。PHP 公共函数：`peptide_core_get_site_config()`、`peptide_core_get_product_batches($slug,$include_history=false)`、`peptide_core_lookup_batch($batch_no,$include_history=false)`；PHP lookup 不带 HTTP 限流。当前无公共订单 helper，主题用内部 REST 并保留权限检查。

## 菜单、结账条款与公开优惠码

- `peptide_core_get_menu( 'header'|'footer' )`：
  - 返回 `null` 表示这个位置没有指定菜单，主题显示自己的默认链接；返回 `[]` 表示指定了空菜单，什么都不显示。
  - 最多两级，每一项有：`id, title, description, type, object_id, url, target('' | '_self' | '_blank'), rel, attr_title, style, icon(null | {url,width,height,alt}), current, children`。
  - `type` 取 home / shop / category / product / page / post / cart / account / orders / login / register / lost_password / search / coupons / coupon / custom / none。`none` 时 url 为空字符串，只作为标题。
  - `style` 是插件整理好的行内样式，主题仍需 esc_attr。网址在显示时实时生成；已删除或未发布的对象，以及不再公开的优惠码，不会出现。
- `peptide_core_get_checkout_terms()`：`[{id,title,url}]`，只包含已发布并勾选“设置为结账条款”的页面。主题只加链接，不改 Additional Checkout Fields 的必选确认。
- `peptide_core_get_public_coupons()` / `peptide_core_get_public_coupon( $code )`：
  - 每张优惠码的字段：`id, code, title, text, type, amount, value, free_shipping, minimum_spend(_text), maximum_spend(_text), expires, expires_text, uses_left, limit_per_customer, individual_use, exclude_sale_items, products, categories, excluded_products, excluded_categories([{id,name,url}]), url, apply_url`。
  - 2026-10-23 新增（COUPON-02）：`eligible_spend(_text)`、`eligible_items`（只按适用商品计算的门槛）、`first_order`、`strategy`（no_stack / stack / replace，和满减活动的关系）、`sort`、`starts`、`starts_text`。`code` 按商家输入的大小写显示（WooCommerce 内部存小写，输入不区分大小写）。还没开始的优惠码不公开。
  - 限定邮箱、过期、用完、未发布的优惠码不会出现；不返回邮箱限制等内部信息。单张查询不可用时返回 null。
  - 主题可以用 `template-parts/coupons/list.php`（`$args['coupons']`）和 `detail.php`（`$args['coupon']|null`、`$args['list_url']`）接管 `[pc_coupons]` 页面。
- `?pc_coupon=CODE`（任意前台地址）：插件用 302 跳回去掉参数的同一地址。
  - 购物车满足条件时立刻使用这张优惠码；否则先记在 WC 会话里，加入商品或修改数量后自动再试。
  - 过期、用完、未发布的优惠码直接放弃。
  - 提示文字使用 WooCommerce 的通知，由主题负责显示。

## 满减活动

- `peptide_core_get_promotions()`：公开展示、进行中或即将开始的活动。
  - 字段：`id, title, text, status(active | upcoming | ended), basis(spend | quantity), reward(amount | percent), tiers([{min,value,text}]), starts, ends(ISO | null), starts_text, ends_text, scope(all | categories | products), categories, products([{id,name,url}]), exclude_sale, with_coupons, url`。
  - `peptide_core_get_promotion( $id )` 查单个活动，不公开或已停用时返回 null；已结束的仍然返回，status=ended。
- `peptide_core_get_promotion_progress()`：`{applied:null | {id,title,tier,discount,discount_text,url}, next:null | {id,title,basis,remaining,remaining_text,reward_text,message,url}}`。
  - Store API 购物车的 `extensions.peptide_core.promotion` 是同样的数据，可以直接用于区块购物车和抽屉。
- `peptide_core_get_product_promotions( $product )`：这个商品参加的、进行中的公开活动。
- 优惠由插件在购物车里加负数费用（不计税），名称就是活动标题，订单费用行记着活动 ID。主题只负责显示，不计算金额。
  - 2026-10-23 起（PROMO-02）：每个商品只参加一个活动（指定商品 > 指定专辑 > 全部商品），不同商品可以参加不同活动，所以**一份购物车可能有多笔 `pc-promo-<id>` 费用**。
  - `progress()` 的 `applied` 仍是优惠最多的那个活动；有优惠时另有 `applied_all`（全部生效的活动，同样的字段）和 `discount`（合计金额）。
  - 活动字段新增 `type`（spend_amount / spend_percent / qty_amount / qty_percent / nth_percent / bundle_price / buy_x_free_y）、`unlimited`（上不封顶）、`sort`（活动页商品排序）。`basis` / `reward` 保留：basis=quantity 表示按件数，reward=percent 表示打折。
  - 活动详情页（`?promo=ID`）的插件默认样式会在下面列出参加活动的商品（WooCommerce `[products]`，按活动设置的排序）。
- `[pc_promotions]` 活动页面：第一次公开活动时自动建好 Offers 页面，`?promo=ID` 显示详情。主题可以用 `template-parts/promotions/list.php`（`$args['promotions']`）和 `detail.php`（`$args['promotion']|null`、`$args['list_url']`）接管。
- 菜单链接类型新增 `promotions`（活动列表页）和 `promotion`（活动详情页）。活动结束或不再公开时，这一项不显示。

## 访客追踪（测试版）

- 默认关闭，开启方式是后台“访客数据”或 `wp peptide tracking enable`。开启后，插件让 `vialcove_visitor_observer_config` 返回 `{enabled:true,test_mode:true,protocol:1,endpoint,heartbeat_ms:15000,idle_ms:60000,batch_max:20}`。
- 身份：
  - 访客由服务器在页面加载时发 HttpOnly cookie `pc_vid`，前端读不到，也不需要生成；
  - 30 分钟没有事件时开始新的访问；
  - 标签页由前端放在 `sessionStorage['vc_tab_id']`，作为整批的 `tab_id` 发送；
  - 登录后由服务器关联账号；员工账号不追踪。
- 上报 `{protocol:1,tab_id,sent_at,events:[{event_id,page_instance_id,seq,type,occurred_at,page,data}]}`，每批最多 20 条、64 KB。
  - 返回 `{ack:[], rejected:[{event_id,code,retry}], server_time}`。重复的事件也会 ack，`retry:false` 的应该丢弃。
  - 整批错误：400 / 403（disabled、staff）/ 413 / 429（Retry-After）。
  - 字段白名单、在线 / 空闲 / 离线口径、乱序和时钟规则见协议文件。
- 加购成功、下单、支付成功 / 失败、登录、注册由服务器根据 WooCommerce 事件记录，前端只上报“尝试”。
- 后台读取接口（VISITOR-CART-01 / VISITOR-ARCHIVE-01，peptide-core 0.15.0）：

| 接口 | 说明 |
| --- | --- |
| live | 只列在线（active）、空闲（idle）和暂离（away）的访客，离开的进归档。每位访客带 `cart:{status,count,subtotal_text,updated_ms}` |
| visitors/{id} | 增加 `presence`，访问历史只返回第一页，附 `has_more` / `next_cursor` |
| visitors/{id}/cart | 购物车明细，直接读 Woo 会话 |
| visitors/{id}/visits | `?cursor=&limit=`，访问历史分页 |
| visits/{id} | `?cursor=&limit=&heartbeats=0\|1` 时间线分页，心跳在服务器端过滤 |
| archive | `?cursor=&limit=&from=&to=&device=&q=`，已结束的访问；每项 `{visit, visitor, cart, reason: exit_signal\|timeout, left_ms, last_page}` |

- 购物车状态：active / empty 来自当前有效的 Woo 会话；expired 表示会话已失效，只剩历史快照；unknown 表示还没有绑定会话。小计是税前、优惠码前的商品金额，不含运费。
- 离站推定：
  - 至少一个标签页的最后信号在 45 秒内为 active / idle（hidden 但仍有心跳也算 idle）；
  - 45 秒内没有信号、也没有全部离开为 away；
  - 全部最近的标签页都离开页面且之后 10 秒没有信号为 left（归档，离页信号）；
  - 30 分钟没有信号为 gone（归档，超时推定）。
  - 这些都是推定，不是精确的关站时间。归档只是查询视图，不删除也不复制数据，访客回来后重新出现在实时列表。

## 支付物流边界

前端使用原生区块的支付/配送选项、价格、错误和加载状态；标识和业务值由 WooCommerce/网关提供，不在主题硬编码支付标识或运费。

当前本地测试配置（3aecb8f，由 Claude 配置，Codex 浏览器复验）：

- 只销售和配送至 US，国家选项只有 United States。
- `wp peptide demo commerce` 开启测试配置：区域 United States (test shipping)，一条 flat_rate，费用 0，名称 Test shipping – rates to be confirmed。
- 支付 ID 为 `cod`，名称 Test payment – no money is charged。是 WooCommerce 货到付款方式用于开发演示，不对接支付商、不扣款；测试订单进入 processing。
- 浏览器原生区块提交成功后跳 WooCommerce order-received；确认字段快照保留当时文案及 UTC 时间；顾客与管理员邮件仅由本机 Mailpit 接收。
- `wp peptide demo commerce --disable` 关闭测试付款、删除测试区域，但保留美国销售范围。正式上线前需要关闭/替换测试配置。

正式收款、真实运费/税费、具体配送政策、Terms/Privacy 链接和正式邮件服务另行配置与验证。本地通过不代表真实支付商或生产物流已就绪。

## 店铺设置与系统设置的前台影响（2026-10-23，Claude）

都由插件通过 WooCommerce 原生机制生效，主题不需要读取新的接口。

- **运费**：店铺设置 → 物流设置里的方案，以全局运费方式 `pc_plans` 提供原生区块的配送选项。
  - 运费 ID 形如 `pc_plans:<方案>-<运费>`，多方案合并时为 `pc_plans:combined`。
  - 只要有一个方案选择了区域，WooCommerce 原有运费区域给出的运费就会被去掉。
  - 运费 meta 带 `_pc_icon`（图标地址，可为空），主题需要时可以显示。
- **税费**：税费方案写入 WooCommerce 自己的税率表，结账仍是原生税额行；“启用税费”默认关闭。
- **交易条款**：开启后，结账页每个方案是一个必选附加字段 `peptide-core/terms-<id>`（location=order，checkbox），区块渲染出的 input id 为 `order-peptide-core-terms-<id>`。
  - `assets/terms.js` 在复选框下面插入 `.pc-terms__links`（条款链接），点开 `dialog.pc-terms__dialog`。
  - “我的账户”的注册 / 登录表单里是 `.pc-terms` 复选框（`pc_terms[<id>]`），由服务端校验。
  - 样式在 `assets/terms.css`，尽量中性，主题可以覆盖。
  - 订单 meta `_pc_terms` 保存当时的条款快照。
- **基础配置**：
  - 前台搜索可以额外匹配 SKU / 分类 / 标签；
  - 排序菜单用 `woocommerce_catalog_orderby`（新增 `title`、`title-desc`、`date-asc`）；
  - 默认选中第一个有货的款式；售罄款式可以隐藏；
  - 默认发送 `X-Frame-Options: SAMEORIGIN`；
  - 强制登录时跳到账户页；打烊使用 WooCommerce coming soon；
  - Favicon 存在 `site_icon`，主题目前固定输出自己的图标。
- **邮件链接**：
  - `?pc_recover=` / `?pc_unsub=`（弃单提醒）不变；
  - 新增 `?pc_optout=`（购物发现退订）、`?pc_verify=`（注册邮箱验证，跳回账户页并显示提示）。

## 营销活动（2026-10-23，Claude，MARKETING-01）

后台的 WooCommerce“营销”菜单换成“营销活动”（满减活动 / 优惠码 / 运费折扣）。前台影响都走 WooCommerce 原生机制：

- **优惠码规则**由 `woocommerce_coupon_is_valid` 检查，加购、计算总价、经典结账和区块结账都会检查，错误信息就是 WooCommerce 的优惠码提示：开始时间、全局首单（按账户和结账邮箱）、只算适用商品的满金额 / 满件数、“不叠加满减活动”（购物车有满减优惠时不能用）。“替换满减活动”的优惠码一用，满减费用就消失。
- **运费折扣**在 `woocommerce_package_rates`（优先级 120，物流方案之后）直接改运费金额，税按比例减少；区块结账显示的就是折扣后的运费，没有单独的折扣行。运费行 meta 带 `_pc_ship_discount`（折扣名称）和 `_pc_ship_original_cost`（原运费），主题需要时可以显示“原价 / 折后”。
- 运费缓存的包裹数据里多了 `pc_ship_discounts`（当前可用的折扣），换邮箱或活动开始、结束时会重新计算运费。

## 应用：离开意图增收（2026-10-23，Claude，APP-EXIT-01）

后台“应用中心 → 离开意图增收”。开启后插件在前台加载 `assets/exit-intent.js` 和 `assets/exit-intent.css`，主题不需要接入。

- **弹窗**：`<dialog class="pc-exit-dialog">`，里面是 `.pc-exit.pc-exit--coupon | --wheel | --message`。
  - 子元素：`__close`、`__image`、`__title`、`__text`、`__code`（`code`、`__copy`）、`__wheel`（`svg.__svg`、`__pointer`）、`__btn`、`__decline`、`__result`。
  - 主色是 CSS 变量 `--pc-exit-color`。默认样式尽量中性，主题可以覆盖。
- **触发**：电脑端鼠标移出页面顶部；手机端可以多选：按返回键、快速上滑、切到别的 App / 标签页后回来（`visibilitychange`）、一段时间没有操作、看到页面底部；页面打开时已有其他 `dialog[open]` 时不弹。员工不会看到。
  - **返回键**：访客第一次点页面（`pointerup` / `touchend` / `click` / `keydown`）时，插件用 `history.pushState( { pcExit: 1 } )` 加一条同地址的记录；`popstate` 回到原记录时先弹窗，不能弹时自动 `history.back()`。只拦一次。主题如果自己用 `pushState` / `popstate`（例如筛选），请忽略 `state.pcExit` 为 1 的记录。
- **订单标签**：订单列表（HPOS）搜索 `exitintent` 会列出用了弹窗优惠码的订单（meta `_pc_exit_intent`），订单里还有一条备注。
  - 频率记在 localStorage / sessionStorage 的 `pcExitIntent`。
  - 管理员打开任意前台地址加 `?pc_exit_preview=1` 可以预览。
- **请求**：`?wc-ajax=pc_exit_event`、`pc_exit_claim`、`pc_exit_spin`，都是公开请求（和 WooCommerce 加购一样）。
  - 抽奖由服务端按权重抽，每个购物会话只能抽一次。
  - 优惠码用 Coupons::remember 用到购物车；购物车为空时先记下，加入商品后自动使用。
- **订单**：用了弹窗给的优惠码时，订单 meta `_pc_exit_intent` 记着这个码。
