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

## 访客追踪（正式版，2026-10-27 TRACKING-LIVE-01；原测试版 VISITOR-ANALYTICS-TEST-01）

- 默认关闭，开关在后台“访客数据 → 实时访客”最上面（或 `wp peptide tracking enable / disable`）。开启后，插件让 `vialcove_visitor_observer_config` 返回 `{enabled:true,test_mode:false,mode:"live",protocol:1,endpoint,heartbeat_ms:15000,idle_ms:60000,batch_max:20}`。
  - **主题需要配合**：现在主题只在 `test_mode === true` 时启动（`inc/visitor-tracking.php` 和 `assets/visitor-adapter.js`），要改成 `enabled === true && protocol === 1` 就启动，不再要求 test_mode。
  - 不要追踪的浏览器（发出 GPC 信号且设置里遵守，或打开过 `?pc_px_optout=1`）：过滤器保持主题的默认值（enabled:false）；`POST /track/events` 返回 403 `pc_track_declined`（和 disabled、staff 一样，整批丢弃）；`GET /track/session` 返回 `tracked:false, config:null`。
  - 新数据 `is_test = 0`；测试版留下的数据（is_test = 1）可以在后台单独删除。
  - 保存期限（30 / 90 / 180 / 365 天，默认 180）每天自动清理；WordPress 的“导出 / 抹除个人数据”包含顾客的访问记录（按邮箱找账号和订单）。
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

## 广告像素（2026-10-24，Claude，PIXELS-01）

后台“店铺设置 → 系统设置 → 广告像素”（`pc-system&section=pixels`）。填了任意平台的 ID 后，插件在前台页脚加载 `assets/pixels.js`（handle `peptide-core-pixels`，配置 `window.pcPixels`），主题不需要接入。

- **平台**：Facebook、Google Analytics（GA4）、Google Ads、Google Tag Manager、Bing、Yahoo、Pinterest、TikTok、Snapchat、快手、Taboola、BIGO Ads、VK、Yandex Metrica、Outbrain、Criteo、X（Twitter）、MediaGo、NewsBreak、AppLovin、SmartNews、Reddit、OpenAI。每个平台用的是它官方文档里的安装代码；填在像素表格里的像素可以绑定一个域名（留空 = 所有域名）。
- **统一事件**：`page_view`、`view_home`、`view_list`、`view_item`、`search`、`view_cart`、`add_to_cart`、`begin_checkout`、`add_payment_info`、`place_order`、`purchase`、`sign_up`、`login`。插件把它们换成各平台的事件名，平台没有对应事件的就不发；X 的事件要在后台填好每个事件的事件 ID 才发。
  - 页面事件由服务端按页面类型给出：首页、商品列表 / 分类、商品页、搜索、购物车、结账；购买和下单只在订单完成页出现一次（订单 meta `_pc_px_browser`）。
  - 加购、注册、登录发生在请求里，先存进 Woo 会话：
    - Store API 的购物车响应带 `extensions.peptide_core.pixel_events`（数组，取出后清空），`pixels.js` 包装了 `fetch`，自动读 `/wc/store/` 的响应；
    - 经典加购（jQuery `added_to_cart`）之后，`pixels.js` 请求 `POST ?wc-ajax=pc_px_queue` 取回。
  - 结账页第一次选择付款方式时发 `add_payment_info`（input 的 name 是 `payment_method` 或含 `payment-method`）。
  - 主题自己的交互可以调用 `window.PeptidePixels.track( event )`。事件格式：`{name, id, items:[{id, spu, sku, name, internal, category, variant, price, quantity}], value, items_value, currency, search?, list?, order_id?}`；`id` 是去重用的事件编号，同一个事件不要换编号。
- **服务端回传**：Facebook（Conversions API）和 TikTok（Events API）的像素填了 access token 时，浏览商品、搜索、购物车、结账、加购、注册、登录和购买（订单变成已付款时）也由服务器经 Action Scheduler 发一份，和浏览器事件用同一个事件编号，平台只算一次。token 只在服务器上，`window.pcPixels` 里没有。
- **不回传**：员工（除非勾了测试用选项）；浏览器发出 GPC 信号（默认遵守）；打开过任意前台地址加 `?pc_px_optout=1` 的浏览器（cookie `pc_px_optout`，一年；`=0` 取消）。主题可以在页脚或隐私页放一个“不要出售或分享我的个人信息”链接指向 `/?pc_px_optout=1`，打开后显示一条 WooCommerce 成功提示。
- **检查**：浏览器里 `localStorage.pcPixelsDebug = '1'` 时，每个事件和发给了哪些平台都打印在控制台。

## 首页SEO 和活动专题页（2026-10-27，Claude，照 YY 后台）

- **首页SEO**（店铺装修 → 首页SEO，`PeptideCore\Seo`）：插件在首页的 `wp_head` 输出 meta description、meta keywords、og:type / og:site_name / og:title / og:description / og:url、twitter:card / title / description，并用 `pre_get_document_title` 替换首页的 `<title>`（没填 SEO 标题时保留主题的标题）。
  - 按访问的域名选“域名指定模板”，没有就用“默认模板”。
  - 支持变量 `{current_domain}`。
  - 主题不要再输出这些标签，以免重复。
- **活动专题页**（店铺装修 → 活动专题页，`PeptideCore\Topics`，文章类型 `pc_topic`）：
  - 网址 `/topics/<别名>/`；改别名后旧网址 301 跳到新网址（WordPress 的 _wp_old_slug）；草稿看不到。
  - 内容是区块编辑器的正文；简短描述 = 摘要；封面 = 特色图片。
  - 模板：主题的 `single-pc_topic.php`，没有时用 `page.php`。现在用的是 page.php，Codex 可以以后做专门的模板。
  - “展示专题标题”关掉时，`the_title` 在专题页主循环里返回空（page.php 的 h1 会是空的，模板里可以判断 `get_post_meta( $id, '_pc_topic_show_title', true ) === '0'` 不输出 h1）。
  - 专题页有自己的 SEO（标题、描述、关键词），没填时用标题和摘要；有封面时输出 og:image。

## 域名管理（2026-10-27，Claude，YY-DOMAINS-01，照 YY 后台）

店铺装修 → 域名管理（`PeptideCore\Domains` + `Admin\Domains_Page`），选项 `peptide_core_domains`。

- **多个域名打开同一个店铺**：访客打开的是已绑定的域名时，插件把 `home` / `siteurl` 换成这个域名（`option_home` / `option_siteurl` 过滤），页面里的链接留在这个域名上。没绑定的域名、IP 地址不变。主题生成链接请继续用 `home_url()` 等函数，不要写死域名。
- **主域名**就是 WordPress 的网站地址。后台切换主域名会改 `home` / `siteurl`；只有“已连接”的域名能切换，wp-config 里写死 WP_HOME 时不能切换。旧网站地址留在列表里，照样能用。
- **检查**：`GET /?pc_domain_check=任意值` 在 `init` 最早返回纯文本校验码（本站给这个域名算的 HMAC），不经过主题、追踪和跳转。服务器请求 http 和 https（验证证书），返回校验码才算“已连接 / HTTPS 生效”。新域名 7 天内每小时检查（cron `pc_domains_check`），之后每天。7 天没连上只显示“未连接”，不自动删除。
- **前台跳转**（`template_redirect` 优先级 -10，只对 GET / HEAD，不对后台、REST、admin-ajax、wc-ajax、预览）：
  - 设置了“域名跳转”的域名 301 到目标域名的同一路径（目标只能是已绑定、自己不跳转的域名）。
  - HTTPS 生效的域名，http 访问 301 到 https。判断 https 时也认 `X-Forwarded-Proto` 和 Cloudflare 的 `CF-Visitor`，避免灵活 SSL 下循环跳转。
- 主题装修的“域名指定模板”和首页SEO 的域名列表会列出这里绑定的域名。

## 博客、素材管理、操作记录、员工管理（2026-10-27，Claude，YY-BACKEND-01，照 YY 后台）

### 博客（`PeptideCore\Blog` + `Admin\Blog_Admin`）
- WordPress 文章 = 博客，分类 = 博客专辑。后台菜单“博客 → 博客管理 / 博客专辑”。
- 网址：
  - 博客列表 `/blogs/`（文章类型 post 的归档，`is_post_type_archive('post')`；模板顺序 archive-post.php → archive.php → index.php）；
  - 博客 `/blogs/<别名>/`（旧的 `/<别名>/` 301 过来）；
  - 博客专辑 `/news/<别名>/`（分类归档，category.php → archive.php → index.php）。
- 列表第一页把置顶的博客放在最前面（插件处理，主题照常循环）。
- 简短描述 = 摘要，封面 = 特色图片。作者：填了“作者”时 `the_author` 返回这个名字。
- 关联商品：`Blog::related_products( $post_id )` 返回已发布商品 ID。主题没有 `add_theme_support( 'pc-blog-products' )` 时，插件在正文后输出 `<section class="pc-blog-products">` + WooCommerce `[products]` 列表；主题想自己排版就声明这个支持。
- 博客专辑的封面、顶部描述、底部描述：`Blog::term_extras( $term_id )` 返回 `{cover, top, bottom}`（HTML 已清理）。
- SEO：插件在博客和博客专辑页输出 title / description / keywords / og（和首页SEO 相同的方式），有封面时输出 og:image。
- 访问量：博客 `_pc_views`（文章 meta），博客专辑 `_pc_views`（分类 meta），员工和预览不算。

### 素材管理（`PeptideCore\Media_Library` + `Admin\Media_Page`）
- 后台菜单“后台设置 → 素材管理”。素材就是 WordPress 媒体库。
- 分组是附件上的私有分类 `pc_media_group`。
- 采集：把地址排进队列（选项 `peptide_core_media_queue`），用 WordPress 的安全下载（不下载内网地址），定时任务 `pc_media_collect` 每次处理 5 个。来源地址存在附件 meta `_pc_source_url`。

### 操作记录（`PeptideCore\Activity_Log` + `Admin\Activity_Page`）
- 表 `{prefix}pc_activity`，保留 365 天（每天清理，cron `pc_activity_prune`）。只记后台人员（管理员、员工等）在浏览器里的操作；命令行和网站自己发出的请求不记。
- 查看权限 `pc_view_activity`：有 manage_options 的人自动有；员工勾“后台设置”时有。

### 员工管理（`PeptideCore\Staff` + `Admin\Staff_Page`）
- 后台菜单“店铺设置 → 员工管理”，只有管理员（create_users）能用。
- 员工角色 `pc_staff`，勾选的模块存在用户 meta `pc_staff_modules`。模块有 orders / products / customers / marketing / data / blog / design / settings / backend / apps。
- 勾选的模块通过 `user_has_cap` 临时给能力；没勾的菜单不显示，打开对应后台页面返回 403（不认识的后台页面也拒绝）。
- 停用（`pc_staff_disabled`）的员工不能登录，已登录的会被退出。
- 新的后台页面请告诉 Claude 归到哪个模块（`Staff::module_for()`），否则员工打不开。

## 总后台（2026-10-27，Claude，HQ-01）

用户要求：一个销售一个店铺，店铺都是用户开的，一定连着总后台。账号在总后台管理，店铺后台不要“用户”菜单，也不要连接页面。

- **总后台**是单独的一个 WordPress，装新插件 `peptide-hq`（同一个仓库的 `wp-content/plugins/peptide-hq/`，说明见它的 README）。店铺上不装。
- **店铺自动接入**：
  - 店铺的 `wp-config.php` 里有 `PEPTIDE_HQ_URL` 和 `PEPTIDE_HQ_SECRET` 时，peptide-core 自己生成连接密钥，向总后台注册（`POST ?rest_route=/peptide-hq/v1/register`）。
  - 注册在这些时候发生：进后台时、每天一次、密钥变了时。
- **总后台调用店铺**：`?rest_route=/peptide/v1/hq/status | summary | login | staff | staff/delete`。每次请求都用店铺的连接密钥做 HMAC 签名；时间、签名、重放都会检查。
  - **一键登录**：店铺发一个 2 分钟内有效、只能用一次的链接 `/?pc_hq_login=…`，登录成最早的管理员。
  - **顾客个人信息**：不经过这些接口。
- **店铺后台的变化**：
  - 没有“用户”菜单；
  - “员工管理”不在菜单里，改到总后台的“销售账号”。两个页面的地址还在。
  - 规则仍然是店铺的 `Staff`（pc_staff + 勾选菜单）。
- **前后台彻底隔开**（2026-10-27 用户要求：平台的操作都放到总后台，店铺后台不让销售看到）。
  - **服务器 IP**：在总后台分配，通过 `config` 接口下发；店铺“域名管理”只显示，不能改。销售可以自己管理域名（店铺装修权限）。
  - **搜索引擎收录、访客追踪的开关和保留天数**：只在总后台设置。店铺“实时访客”页只显示当前状态。
  - **平台页面不在店铺后台的菜单里**：插件、主题、更新、系统状态、计划任务、站点健康、WooCommerce 设置、VialCove 设置、WordPress 常规 / 阅读 / 固定链接 / 隐私 / 讨论、个人数据导出 / 抹除、用户。
    - 这些页面从总后台“平台工具”一键打开；店铺“系统设置”只剩基础配置、广告像素、邮件通知、交易条款。
    - 顶部工具条去掉了 WordPress 标志、更新、评论、新建用户；WordPress 的更新提示不显示。
  - 店铺有固定编号（uuid），换主域名后总后台仍认得。
- **对主题没有影响。**


## 客户后台多页面装修、积分与收录（2026-10-28，Codex，CUSTOMER-ADMIN-ALL-01-CONTRACT）

- Templates文档类型为home/header/footer/global/product/shop/category/collections/topic/custom/search/coupon/promotion/blog/article/account/not_found；资源文档键为product_ID/custom_ID/topic_ID/article_ID/category_ID。document_id第三参false仅读；公开页不自动创建文档。复制模板包含资源子文档。原草稿/发布快照模型保留。
- Design_Pages连接真实预览URL、对象装修入口和前台模板接管；cart/checkout先于预览覆盖被排除，继续Woo Blocks。商品、账户布局发布需要恰好一份两设备均可见context_content，页头/页脚/全局禁止原生正文。嵌套原生模板时保存恢复原WP_Query对象及post/product，抑制内层主题chrome和重复hooks；外层head/footer正常执行。
- Layout REST保存/发布/撤销/丢弃支持revision，值涵盖草稿内容、时间及发布快照状态；每文档MySQL命名锁串行化，revision不一致返回409。legacy modified参数仍兼容，当前编辑器一律传revision。
- Site_Design添加site_header/site_footer/site_styles/context_content，singleRender且禁止复制。主题header/footer先检查原生正文渲染guard，再消费已发布部件；未配置时走原主题。商品卡调用peptide_core_product_card_extras($product)。全局样式未发布不影响现有站点；字体从插件本地加载。
- Customer_Segments以用户meta保存标签/等级，营销优惠资格读取真实当前用户，不接受客户端声称的用户ID。Loyalty使用pc_points_accounts/pc_points_ledger事务表、行锁、唯一事件键及订单生命周期锁；金额按币种最小单位精确兑换。pc-points为账户端点。选项peptide_core_loyalty的enabled/redeem_enabled/notify_enabled默认false，不回补启用前历史订单。账本为余额/返还权威，已返还兑换券不可再花；退款可形成待抵扣债务，后续赚分先冲债。顾客管理入口归customers，营销订阅入口归marketing。
- 收录工具提供验证、GSC授权与URL检查、IndexNow提交及历史，仅可处理本站公开内容；密钥加密存储并掩码。接受提交不等于已收录。本地.local拒绝生产提交。用户明确AI暂缓，生成/应用路由及供应商配置不可用，旧操作403；不得把内部适配器视为已交付AI功能。
- 12组555项回归、57PHP/6JS语法、4个新增管理员HTTP入口和7个公开URL检查通过；未完成内置浏览器点击/移动端目视、真实邮件投递、支付或搜索服务联调。


## 商品/运营装修积木与订阅（2026-10-28，CUSTOMER-ADMIN-ALL-01，Codex）

- Registry 原始定义扩展点 `peptide_core_layout_definitions($definitions)` 在补齐 type/block/version、通用显示/间距字段之前执行；渲染扩展点 `peptide_core_module_html($html, $module, $context, $device)` 以 null 表示继续主题模板/Fallback，以字符串表示已处理，空字符串在非 editor 上下文隐藏空积木。定义中的 `singleRender=true` 保留一份 DOM，设备固有字段仍由该模块的响应式输出消费，避免订阅及原生正文表单重复。
- `Modules\Commerce_Blocks::register_hooks()` 增加 33 种商品/运营积木：单品、视频商品、轮播/滚动、商品/专辑/专题选项卡、实际销量排行、最近浏览、瀑布流、专辑展示/导航、价格属性比较；真实营销卡、公开优惠码/满减/运费折扣、订阅、公告、真实评论、博客/专题、伙伴与社交链接、YouTube 和商品搜索。商品/价格/销量/库存读 WooCommerce；优惠嵌入复用 Coupons、Promotions、Shipping\Discounts，不复制折扣计算。没有符合条件的公开记录时隐藏或在编辑器说明，不造评论、销量和促销期限。
- 商品卡优先复用主题 `vc_product_card()`；插件 fallback 卡消费 action `peptide_core_product_card_extras($product)`，卡片 HTML 可用 `peptide_core_commerce_product_card_html($html, $product)` 过滤。新选项卡支持键盘选择；轮播/公告支持手机滑动、上一组/下一组、暂停、reduced-motion；`vc:select-item` 接装修条目定位。最近浏览使用本浏览器 HttpOnly cookie `pc_recent_products`，只记公开商品，最多 24 条、30 天，无历史不展示；带历史 cookie 的页禁止整页缓存。
- 真实评论仍属于 WooCommerce 的 product review，只展示已审核且商品公开可见的记录。附件 meta 为 `_pc_review_images`（图片附件 ID 数组）、`_pc_review_video`（视频附件 ID）、`_pc_review_featured`（1/0）。原生评论编辑页提供媒体/精选设置，保存校验 `edit_comment`、专用 nonce 和附件 MIME；不创建或伪造顾客评论。
- `Subscriptions::register_hooks()` 管理表 `{prefix}pc_subscriptions`；`Subscriptions::render_form($settings)` 输出订阅表单。提交入口为 `POST /peptide/v1/subscriptions`，无 JS 时为 `admin-post.php?action=pc_subscribe`（登录/未登录均有入口）。必须有明确且未预勾的同意、有效邮箱、nonce、签名的来源/同意文案上下文；另有蜜罐和按邮箱/IP 限流。只保存 pending，确认/退订密钥仅存哈希。确认邮件只经现有 `wp_mail`，失败明确提示并保留 pending，不假称发送成功或订阅成功。
- 邮件确认/取消地址为 `/?pc_subscription=confirm|unsubscribe&subscriber=<id>&token=<opaque>`。GET 只显示确认操作表单；携带 nonce 的明确 POST 才激活或退订。确认令牌 48 小时有效、使用后失效；退订可取消 pending 或 active。编辑器/装修预览不输出可提交订阅表单，不发送邮件。
- `Admin\Subscriptions_Page::register_hooks()` 注册营销子页 `pc-promotions-subscribers`，权限 `edit_shop_coupons`，归属 marketing。名单含来源和同意时间/文案，提供筛选、CSV 导出、显式确认后的批量删除；导出/删除校验能力和 nonce，CSV 防公式注入，不导出密钥。没有群发功能，未自动启用真实装修。中文补充翻译位于 `languages/customer-commerce-zh_CN.php`（157 条），仅由中文 locale loader 消费。


## 装修交互约束补充（2026-10-28，Codex，CUSTOMER-ADMIN-UI-01-CONTRACT）

- peptide-core 0.28.1：装修菜单操作必须在confirm通过后才卸载触发按钮，兼容后台确认框的取消后点击重放；discard/unpublish经实际点击确认会发送REST并更新状态。
- 商品轮播hover、focus、用户暂停独立管理；减少动态效果在每次移动和计时器执行时实时检查。轮播容器建立position:relative包含块以限制定位子元素，不通过全页隐藏overflow掩盖问题。
- 手机页头搜索以页头容器定位，弹层和Logo按可用空间收缩；商品组件长编号可换行。独立本地Edge45交互+4响应式专项通过，未实测真实手机手势，不等于用户IAB已被接管。无REST/持久化契约变化。


## 客户后台第二批契约（2026-10-28，Codex，CUSTOMER-ADMIN-ALL-02，0.29.0）

- 用户最新决定优先：评价来源/编辑标记、邮箱、内部备注、操作记录仅后台；公开评论不输出。支付、外部邮件发送和搜索连接全部留空/关闭；AI、收藏、正式A/B继续暂缓。本条更新早期“来源公开”、订阅“没有群发”及旧剩余清单。
- Reviews仍以Woo原生comment_type=review保存。买家POST admin-post.php?action=pc_review_submit（登录和匿名均注册），校验商品nonce、原生登录/购买策略、星级、同意及文件类型/限额，统一pending。后台Reviews_Page的pc-product-reviews归products，需要edit_products+moderate_comments，逐商品edit_post及逐评价edit_comment。来源meta _pc_review_source，内部历史_pc_review_changes最多20条；既有来源不能借编辑冒充买家，verified仅匹配登录账户邮箱与真实订单。
- 自主上传媒体在Private_Files的review-media目录、webroot之外，附件归属_pc_review_owner，随机名_pc_review_file；GET/HEAD ?pc_review_media=<id>&key=<HMAC>校验仍被该评价引用、审核/公开商品或作者/管理权限，支持Range，全部no-store，撤审/移除立即失效。新建至多5图+1视频/合计30MB，旧已存在更多图片可完整保留，超限新选择明确拒绝。失败媒体保存不得意外审核旧评价；删除只清理本评价拥有的私有附件。普通媒体库复用不提升员工edit_posts能力。
- source_html($comment,$admin_context=false)默认空，true仍验证can_manage。公开原生评价与Commerce_Blocks均不传true。Commerce评价renderer消费Site_Design::review_settings()的layout/autoplay/interval/order/minimum_rating/show_rating/limit/video_ratio，保留合法草稿预览与发布隔离。
- Site_Design新增heading/body字体字重、heading/menu字间距、detailTitleSize、pageGutter、secondaryMenuId、mobileNav尺寸及可选转场；无新配置时兼容旧fontFamily/默认输出。转场默认关闭，尊重reduced-motion，跳过cart/checkout/account、下载、锚点、跨域及签名预览。
- Site_Tools和Admin Site_Tools_Page注册pc-site-tools（settings映射），需要manage_options+unfiltered_html，不给pc_staff提升权限。peptide_core_site_tools / peptide_core_virtual_files / peptide_core_support_phrases采用revision与锁；代码总开关和各条默认关闭。虚拟文本仅/site-files/、ads.txt/app-ads.txt/.well-known/security.txt白名单与受保护路由拒绝，非任意磁盘文件/PHP，GET/HEAD/ETag/304、POST405及nosniff。
- Loyalty通知schema3：attempts/next/lease/claim，最多5次，退避60/300/1800/7200秒；5分钟维护修复queued排程和过期sending领取，不重复积分账本。传输已接受后进程崩溃、尚未标记sent存在重发窗口，不承诺外部exactly-once。Launch_Check识别Shipping\Plans takeover并校验实际启用范围/费率，不再只看Woo原生zones。
- 本批20套836PASS/0FAIL；实际WordPress Edge19项、独立装修harness12项分别报告。backend PHP77/JS12与frontend PHP3语法、两工作树diff通过，原业务指纹不变。未接管用户IAB、未实测实体手机/正式支付SMTP搜索、未生产部署。邮件活动和高级套餐详细接口附下。

### 邮件活动接口

邮件营销活动实现与接口（2026-10-28，Codex，供root合并，不直接修改主文档）

新增：includes/class-email-campaigns.php、includes/admin/class-email-campaigns-page.php、assets/email-campaigns-admin.js、languages/customer-campaigns-zh_CN.php、tests/smoke-email-campaigns.php。

挂载 Email_Campaigns::register_hooks() / Admin\Email_Campaigns_Page::register_hooks()；营销子页 pc-promotions-campaigns，cap edit_shop_coupons。已有 Staff::module_for 的 pc-promotions 前缀自动归属 marketing，不增加订单/数据/设置权限。

自有表 {prefix}pc_email_campaigns / {prefix}pc_email_campaign_deliveries。发送总开关 pc_email_campaigns_enabled 默认关闭。草稿可视编辑（WordPress wp_editor）、无发送预览；安排发送后内容固定为发送快照。支持店铺时区定时、暂停/恢复（保留未来计划时间）、取消、每批最多20条、已知失败人工重试最多3次。主状态 draft/scheduled/running/paused/cancelled/completed。任务 hook pc_email_campaign_batch，使用WordPress单次cron，只操作本活动事件。

安排时从Subscriptions快照选 active + confirmed_at + consent_text/consent_at 的收件人，可按完整来源路径精确筛选；每条发送前再核资格，已退订/删除/无同意直接skipped。campaign+subscriber唯一键、原子认领与活动锁避免重复发送。发送中断或异常导致结果不明记uncertain，单独显示待核对，不自动重发；已知wp_mail=false记failed，可人工重试。sent仅表示邮件传输已接受，不能当作投递成功、到达收件箱或阅读。

正式安排需已启用发送、有效Woo发件邮箱/名称、店铺实体地址/城市。只走现有wp_mail；正文自动附店铺名、实体地址、同意说明、签名退订链接和List-Unsubscribe头。退订URL ?pc_campaign_unsubscribe=<id>&token=<opaque>：GET只显示确认表单，POST需nonce；成功撤回Subscriptions状态并使其他排队活动发送前跳过。链接绑定当前订阅记录及退订哈希，重订阅后的新同意有新链路。无任何默认群发或外部邮件服务配置。

实际最终smoke-email-campaigns：33 PASS / 0 FAIL；覆盖内存配置过滤、发送关闭/无效发件拒绝、HTML清理/预览、受众同意与未来定时、暂停/恢复/取消、失效与重放退订、实际匿名退订GET/无nonce POST403/有效POST、分批20条、失败重试、未知状态不重试、并发锁、权限及真实营销后台HTTP编辑/预览/保存、data/settings读取与保存403。邮件和本hook所有排程均在测试进程拦截，拒绝任何非本轮example.invalid收件人。仅创建/清理本轮唯一source的订阅、活动、投递和临时员工；没有改真实sender/address设置、没有真实发信或活动出逃至cron，没有触原订单54/55或现有装修。

语法：core/Admin/test/翻译4PHP lint、admin JS node --check均通过；新增66条msgid中文映射完整。日志 D:\Codex\Tasks\2026-10-28\new-chat\work\store-admin-qa\smoke-email-campaigns.php.log。外部SMTP/邮箱送达及真实浏览器可视编辑/活动操作尚未实测，勿把HTTP与mock传输结果写成实际投递成功。

### 高级赠品与套餐接口

2026-10-28 Codex · 高级赠品与商品/变体套餐（可供 root 合并 CONTRACT/handoff）

新增文件：
- wp-content/plugins/peptide-core/includes/class-product-offers.php
- wp-content/plugins/peptide-core/includes/admin/class-product-offers-page.php
- assets/product-offers.css、assets/product-offers-admin.js
- languages/customer-product-offers-zh_CN.php（78 条补充翻译）
- tests/smoke-product-offers.php
均在 D:/Codex/Projects/peptide-platform-worktrees/backend 下；没有直接编辑 boot、Staff、核心菜单、版本、原 Commerce_Blocks、主题、文档或记忆。

接口：Product_Offers::register_hooks() / Admin\Product_Offers_Page::register_hooks()。CPT pc_product_offer，配置 meta _pc_product_offer。营销子页 pc-promotions-product-offers，权限 edit_shop_coupons，现 Staff 的 pc-promotions 前缀自然归 marketing。后台可新建、编辑、启停、设置时间和删除；新建默认停用草稿。商品/具体变体搜索为本页面自有 wp_ajax_pc_offer_products，校验营销能力和 search-products nonce，仅列已发布 SKU，不借产品编辑权限。固定套餐每槽一个 SKU；变体套餐每槽配置多个真实 SKU 选项，至少 2 槽、最多 12 槽。

前台：短代码 [pc_product_offer id="123"]，并在相关组件商品详情页展示活动。表单只提交 offer、槽位 choices 和完整套餐组数，使用 admin-post 的 pc_offer_add、对应 nonce；商品 parent/variation 属性和价格均由服务器取 Woo 数据，不信任客户端价格。测试明确 POST price=0.01 后实际原生购物车仍保存服务器计算的 52.20。

赠品：真实独立商品或具体变体 SKU，以付费商品金额/件数为门槛；gift 行不计自己的门槛；每车最多采用最新且符合条件的一个赠品活动。条件失效、过期、库存不足时自动移除；数量不能由买家调大。加套餐会替换自动赠品，先释放旧 gift 的真实库存再用原生 add_to_cart；失败时重新同步恢复仍有资格的 gift。库存及独售约束聚合普通 cart、各槽、不同套餐群与赠品，尊重实际变体的 stock_managed_by；不接受缺货/回补订单作为赠品或套餐库存承诺。

套餐：固定完整总价或按 Woo 当前商品价格的百分比优惠。仍是原生组件购物车/订单行，不创建另一套商品或订单。私有 cart 字段 _pc_offer 保存 offer/kind/group/slot/version/signature，外部 add-to-cart 不能注入；规则变化或签名无效不会保留折扣价。数量按完整组同步，移除/原生撤销恢复按整组；直接删槽/改数量时，经典 checkout 和 Store API cart_errors 都拒绝不完整套餐。价格按货币 minor units 用 largest remainder 分摊，各行非负且合计等于目标，然后除以真实数量交由 Woo 算税和订单金额。

默认互斥政策已在后台及买家文案直说：高级赠品/套餐不与优惠券或原有七类 Promotions 叠加；有 coupon 拒加套餐，套餐在车时拒 coupon；选择 coupon 会移除自动 gift。旧 Promotions 正在覆盖组件/赠品时拒绝对应高级优惠。没有更改原七类促销算法。订单行保留 _pc_offer_offer、_pc_offer_kind、_pc_offer_group、_pc_offer_slot 及可读活动名称；库存、税费、结账和退款仍由 Woo 所有。

实际验证：smoke-product-offers.php 最终 55 PASS / 0 FAIL，日志 D:/Codex/Tasks/2026-10-28/new-chat/work/store-admin-qa/smoke-product-offers.php.log。含阈值/赠品失效与过期、真库存、固定与真实变体套餐、重复总计、签名/价格伪造、完整数量/移除/undo、StoreAPI 校验、coupon/旧活动互斥、原生 pending 订单来源；4 同价槽合计0.02、多数量、0/3位精度；独售 SKU 跨槽/普通车/另套餐/赠品；旧gift库存只剩1时套餐替换成功、失败后恢复。
实际 HTTP：marketing 页面/商品搜索 200，保存只创建 disabled draft；公开加套餐 invalid nonce403、valid nonce302且持久化两条签名组件和正确服务器总价；data/settings 读写管理均403。测试限定本轮合成商品/变体、offer、coupon、员工和未付款订单；内存 session+独立 WC_Cart，HTTP cart 属于唯一临时员工，finally 精确删其会话/商品/活动/订单/账号并恢复原对象。pre_wp_mail 全拦截，无真实付款、不改订单54/退款55、原商品/设置/装修。
语法/静态：4 PHP 文件 lint、独立 JS node --check，通过；翻译覆盖 78/78。未声称浏览器真实触摸/支付网关/生产库存并发压力验收，也没有启用或配置真实业务活动。root 可继续做隔离浏览器验收与总集成。


## 商品评价按主商品管理（2026-10-29，Codex，REVIEWS-BY-PRODUCT-01，0.29.1）

- pc-product-reviews无product参数时显示可管理的post_type=product目录（含0评价，20/页、名称搜索）；不列product_variation、trash或auto-draft。有product时只管理该主商品，GET variation ID规范为parent；仅edit ID兼容推导商品。
- load-<submenu hook>在发送admin-header前校验上下文，render直接调用仍验证；详情要求edit_post，编辑另要edit_comment及comment_post_ID一致。新增表单hidden product，Admin save禁止编辑时更换归属；moderate要求可编辑真实商品，POST product若存在必须一致。重定向由固定本地URL及白名单return_status/return_search/return_paged构造。
- 主商品数量与评价汇总分开查询，0评价也存在；统计顶层comment_type IN ('','review')、comment_approved IN ('0','1')。二级WP查询用局部comments_clauses精确兼容空类型（type__in会去空），只作用带pc_review_product_list及匹配post_id的本次count/rows查询，finally移除；不纳入普通comment/reply，不改历史数据/评分模型。
- catalog使用主商品原生查询与权限，详情使用既有Reviews服务；4套137项回归和独立Edge17项通过。真实规格评价继续按Woo绑定父商品；直接绑variation的异常历史数据不在本次迁移范围。来源隐私/外部三项留空决定保持。


## 装修积木后台绑定修复（2026-10-29，Codex，BLOCK-INTEGRATION-FIX-01，0.30.0）

- Commerce_Blocks definitions/render连接Content_Blocks与Review_Blocks；新增product_offer，Registry73。优惠三列表selection=auto/manual，records repeater的settings.record为ID；announcement mode=manual/bound，bindings每项activityType及promotion/coupon/shippingDiscount/offer ID。promotion_card保留promotion，新增activityType=promotion/product_offer及offer。默认保旧，手选无资格不回自动。
- Fields type=record只保正整数ID或null，deleted/inactive不依赖options清空；resource白名单由Modules\Records管理。AJAX action pc_layout_records需Templates::CAP_EDIT及pc_layout_records nonce，term/paged/selected查询；20项分页，补回页外selected，不可用给占位；公开renderer单独校验真实type/state/资格。无营销权只见customers=all的运费元数据；旧inline产品列表无private/隐藏/密码商品。原product清理语义保留。
- Review_Blocks reviewSource=all/product/current，reviewProduct主商品ID；无绑定/非商品current空输出。精确顶层已审空type/review、公开商品，无密码；不固定200候选，按20–48页面查至limit。模块limit24、global reviewLimit正值可到100。来源、邮箱、备注、编辑标记不输出，保留全局草稿/发布/预览。
- Content_Blocks四类型持真实绑定：blogCategory record blog_category；posts repeater.post record post；campaign_pages source=all/manual，pages.page record topic。配置过的失效/禁用手选空输出；不存在分类不退全店；手选序与limit有效。topic_tabs.page同改record。
- Commerce_Search init早于Woo查询删product GET空min/max（含数组），不删0；前端formdata同样去空，不disable输入。复制优先clipboard、HTTP execCommand并清临时域；失败才选中并提示manual。搜索5GET与浏览器4项实际验证。
- 高级优惠公开复用Product_Offers真实form，预览/editor没有form/applyURL，singleRender防重复；人群资格仍由原引擎判定，定向页禁共享缓存，邮箱限制券继续非公开。默认优惠互斥政策未变。
- 实际9套302项、真实WordPress Edge21项/11Ajax200、复制搜索4项与5GET通过；语法90PHP13JS、diff通过，fixture与真实状态保护见验收报告。不包含外部三项/实体手机/生产部署；原模板不自动替换。


## 后台展示层（2026-10-29，Codex，ADMIN-UI-REDESIGN-01，0.31.0）

新增独立Workspace展示层与商品/概览/全屏装修覆盖资源；HQ独立workspace/login资源。原REST、表单字段name/id、nonce、数据模型及权限契约不变。PEPTIDE_ADMIN_UI_DISABLED=true可停用新展示层，默认启用；此常量不提供权限或写库能力。staff页补注册隐藏admin_page别名，CAP仍create_users，原菜单隐藏决定保持。用户已明确授权两套后台全UI改版与先备份，参考资料/实际验证/数据保护与限制见双方handoff及验收报告；没有启用外部支付/邮件/搜索或发布店铺装修。


## 后台黑白与导航（2026-10-29，Codex，ADMIN-UI-MONOCHROME-02，0.31.1）

展示层改黑白、交互动效尊重减少动画；访客表通过局部class/data-label响应实际容器，原数据/节点/委托事件不变。HQ相同自适应。Woo原生wc-admin无path/根path、setup-wizard及profiler回自有概览（员工取已有home_url），普通customers/analytics路由不改；自动安装向导跳转过滤，未把profile或外部服务虚假标为完成。导航不增加权限或开放新接口；API/订单/支付/装修保存发布模型保持。实际验证与数据指纹见handoff和本轮报告。

### 2026-10-29 · Codex：管理后台三项运维实施中（HQ-OPS-01）

- 用户明确订单先手工填写物流单号，批准前次清单最后三项：统一插件/主题升级回滚、自动创建部署新店、定时备份与恢复。本轮不扩展统一订单/商品报告同步/通用装修分发。三项外部服务仍留空。
- 已做两站源码/私有文件和两库完整备份，ZIP CRC及SQL完成/SHA核对；备份在 D:/Codex/Tasks/2026-10-29/hq-operations/work/backup，尚不把恢复演练写为完成。
- 共享接口提案并进入实施：独立peptide-ops稳定MU加载器，私有存储和HMAC/replay保护，CLI后台任务；HQ新增Operations/运维页面，统一排队/阶段进度/明确错误，升级预检+整站回退点+原目录文件回滚；新店由受控部署profile限定源码、目录、数据库和域名；备份文件+当前前缀数据库，恢复前校验归属/完整性并保留当前连接。
- 生产服务器未提供，先实现并实际验收本机独立新站；远程agent/profile接入口预留且未配置不得假成功。不自动购买主机或改DNS/TLS。当前开发联接目录阻止自动覆盖，所有写入升级/恢复演练使用全新独立数据库及真实文件目录，原未提交代码/用户草稿保留。
- 运维页延续用户黑白灰、现有本地字体和响应式，自写分区表单及任务列表；不另装UI包。任务执行、现场恢复、后续服务器配置的实际结果另记。未commit/push，无CRK绑定。


### 2026-10-29 · Codex：管理后台三项运维完成（HQ-OPS-01-RESULT）

- 本聊天用户明确订单物流先手填单号，批准的最后三项已实现：统一插件/主题升级与文件回滚、受控独立新店创建/自动接入、完整备份恢复和每日计划。旧只读审计中这三项“未开发”由本条更新；统一订单/商品报告同步/通用装修分发不属于本次。支付/外部邮件/搜索保持空，AI/收藏/正式A-B暂缓。
- HQ0.3.0新增“站点运维”（pchq-operations）五页签；独立peptide-ops0.1.0由稳定MU加载，HMAC+HQ绑定/防重放、私有文件账本、独立CLI排队/进度/幂等，避开HQ单线程HTTP阻塞。黑白灰响应式，无新UI包。当前业务core0.31.1未改。
- 升级先验ZIP/路径/版本/依赖和目标，保存整站备份及原组件文件，目录交换和双健康核验；正常失败自动复位。新站独立库/数据库用户、随机私有凭据、空商品订单、不复制旧客户数据，健康UUID、自动注册及HQ签名回连都验证。完整备份含本站前缀DB、文件/私有报告；恢复前完整回退点，SHA/CRC/本站身份与DB绑定校验，保留当前配置/网址/HQ/运维入口，故障回退及中断人工保护通过。下载包CLI预生成+短时一次票据，保留清理有明确结果/失败提示。
- 当前店（HQ id2）真实手动完整备份和今日定时备份均完成，2份下载包已准备。每天北京时间03:00保留7份定时备份，手动/升级/恢复保护快照不自动删；本机private config opt-in隐藏Python每60秒CLI检查，真实tick code0。电脑需开机；重启后打开HQ拉起，正式环境需系统cron，不是机器服务/公网托管备份。
- 实际证据：Archive79/Junction5/Runtime27/真实Release30；备份安全43/真实DB恢复24/调度33/导出20；建站预检23/站点进程10/本机scheduler8。独立Edge原始18项、实际表单8项、权限4项、清理后6项均通过且JS0；HMAC等8、导出传输4、保留错误/幂等5另通过。真实3个定时点清到2，保留4个手动/保护点；16PHP/3Python/1JS语法及diff通过。各套范围不同，不把stub称生产验证，不重计旧历史套。
- 原15项业务option、314原商品/模板/装修记录及385装修元数据与操作前SQL全同；原HQ身份/地址/加密密钥全同且签名连接成功。公开商品/规格/订单0，用户草稿10950保留。2个隔离真实站的进程/库/DB用户/目录/私有资料/HQ记录均清理，QA账号/会话/cookie/profile/上传包清理；实时保留原店2个成功任务。两条任务目录测试junction清理被自动审批 blocked by policy 拒绝，停止尝试并留在work/releases/junction-cases，非站点目录。
- 当前开发联接目录会阻止自动升级/恢复覆盖工作树，实体目录隔离站已验。文件回滚不逆第三方DB迁移，掉电/强杀后的致命插件可能需服务器入口恢复；正式服务器、域名DNS/HTTPS尚未配置和公网验收。无真实交易/生产压力/实体手机测试，不声称上线。未commit/push，无CRK或跨聊天消息，保留所有原未提交代码。
- 文件：backend新增peptide-ops插件/工具/README，HQ Operations/Backup_Schedules/Operations_Scheduler/Operations_Page及CSS/JS，HQ boot/版本；两站runtime wp-config加私有路径/代理配置和稳定MU入口。正式服务器配置说明在peptide-ops/README.md，不把密钥写进记忆或Git。私有操作前备份D:/Codex/Tasks/2026-10-29/hq-operations/work/backup。
- 验收报告D:/Codex/Tasks/2026-10-29/hq-operations/outputs/管理后台运维三项验收_2026-10-29.txt及JSON；原始报告在work/backups、releases、provision和browser相关JSON。下一步按用户提供新品图/资料上新或配置正式服务器；不自动恢复旧演示记录。

### 2026-10-29 · Codex：Impeccable 后台重设计实施中（IMPECCABLE-ADMIN-01）

用户要求安装impeccable并重新设计UI，本聊天沿用多肽记忆，范围为客户后台与管理后台，黑白/自适应/顺滑及业务保留约束持续有效。已使用官方skill-installer从pbakaus/impeccable的5e745ace7e151feb2a93d360d0281fe39448b857安装.agents/skills/impeccable到用户Codex skills，4.8.0；自动context引擎下载未完成及checksum失败，已告知直接读已有项目资料继续，未安装自动hook或宣称引擎成功。已读SKILL/new-work/operate/craft-floor，并依据现有确认事实补PRODUCT.md。

已完成backup-v2：店铺/HQ公开目录、两套私有资料（排除进程锁）4ZIP CRC/SHA及两库SQL完成核对；backup与hq-private.zip早期不完整文件不作为有效恢复点。路径D:/Codex/Tasks/2026-10-29/impeccable-admin-redesign/work/backup-v2。尚未改展示层或业务，不把计划写成完成。

实施采用轻导航/黑主操作、紧凑商务工作台；直接整理owner CSS而非堆第三张皮肤，减少重复标题和卡片套卡。root共用框架/令牌，子任务各自独占特殊页与HQ/登录CSS；最终串联独立浏览器两轮批量验收、独立审查及指纹核对。无需新UI依赖，不改业务、公开装修、Woo路由保护及已有表格响应式；保留原未提交改动。方向合约在任务work/direction-contract.md，最终结果另记，无CRK绑定。


### 2026-10-29 · Codex：Impeccable 后台重设计完成（IMPECCABLE-ADMIN-01-RESULT，core0.32.0/HQ0.3.1）

- 用户要求安装impeccable再重新设计UI，已从官方pbakaus/impeccable的5e745ace7e151feb2a93d360d0281fe39448b857安装4.8.0到C:/Users/JingYue/.codex/skills/impeccable。原生context引擎下载未完成，受控停止后部分文件校验失败，按SKILL的fallback告知并直接读取项目资料。未安装hook，不宣称自动context/concept/detector/schema检查通过；本轮为文档指导、实际浏览器及独立审查。后续消息可用已安装技能。
- 已重设计两后台展示层：220px浅色导航/黑色当前项与主操作、52px工作区头、14px正文/13px控件、紧凑表格/分区。客户概览指标摘要及业务优先、商品标题/详情/发布区、装修行式积木及属性、访客页面和两登录统一；HQ工作台/运维也同步。直接整理9份owner CSS，2份JS只改反馈时长及扩展原导航保护，无新UI包；用户黑白配色与减少动画、自适应要求延续，公开店铺发布装修未改。
- 先备份：有效work/backup-v2共4ZIP+2SQL，CRC/SHA及完成标记通过，私有进程锁排除。早期work/backup及backup-v2/hq-private.zip不完整尝试不作为恢复点。未对本轮手工归档重复恢复演练，不能套用前轮ops格式恢复点。
- 实测两轮各98页面/尺寸检查，JS0；独立审查3项问题后修复，手机header回归又做6项定向检查，header y46–98、概览首内容114/商品首字段263且滚动不遮挡。同一新上下文reviewer最终ship，范围仅原3视觉问题及header回归。原生交互最终20、原生导览关闭另1、装修13均通过；20积木及dirty不变、不保存发布。初始路由8，后续补晚期wp_redirect/History后服务端9/History4通过，customers/analytics不受影响。3PHP/2JS语法、9CSS平衡及diff通过。
- 无外部拦截交互复现Woo晚期/History跳向导，已在原保护上扩展，仅旧Home/Setup/Profiler导航，不写profile已完成。另“规格页点不了”实为原生产品导览pointer-events锁，真实点“知道了”后可操作，未用CSS绕过；媒体隐藏DOM的测试断言已纠正。这些限制和早期证据保留，不把中间失败改写为从未出现。
- 清前SQL核对15业务option、314原商品/模板/装修记录及385装修元数据全同，用户草稿10950保留、订单/公开商品0。精确清理3临时账号、11个本人空草稿、会话/cookie；未恢复演示数据。原未提交代码保留，无commit/push、无CRK。
- 新增backend根PRODUCT.md、DESIGN.md、.impeccable/design.json；本地文档结构/引用核对通过，官方引擎/schema/panel-render未运行。业务core0.32.0/HQ0.3.1，ops仍0.1.0。当前本机运行，不称正式上线/生产压力/实体手机验收；支付/邮件/搜索空及其他暂缓决定不变。
- 报告D:/Codex/Tasks/2026-10-29/impeccable-admin-redesign/outputs/Impeccable后台重设计验收_2026-10-29.txt/JSON；最终审查截图round1（最后修正已覆盖）、初版before-review-fixes、全量第二轮round2，原始功能/指纹/审查在work。原本运维每日03:00 Asia/Shanghai保留7份计划未改。后续按用户反馈微调或收到新品资料上架，不自动接外部服务。


### 2026-10-29 · Codex：侧边栏点击/展开状态修复（ADMIN-MENU-STATE-01）

修复两后台侧边栏点击/展开后的深色与蓝色状态：原 workspace 当前父项自身设为黑色，WordPress Modern 更具体的选择器又覆盖子菜单背景及焦点文字。改在所属 CSS 里覆盖完整导航路径，保留原生菜单开关、定位、键盘可达性和业务逻辑。

菜单面板 #fafafa；展开父项 #eaeaea；当前子项 #e5e5e5；hover #f0f0f0；文字 #171717；键盘 focus-visible #737373 内侧轮廓。折叠浮层标题、收起按钮、移动菜单开关同步中性化，窄屏图标文字间距修正。主操作按钮仍为黑色。当前 core0.32.0/HQ0.3.1不变，资源已有filemtime缓存版本。

实际 Edge 最终52/52定向断言通过，JS错误0：营销优惠码/满减/运费折扣和HQ店铺/运维真实菜单点击，1600/1280/850/390视口、hover、current、键盘焦点、折叠浮层、展开关闭、标题/按钮/移动开关颜色及窄屏文字间距。截图复核后修掉初版42项断言漏掉的原生蓝色浮层标题和收起按钮，扩为48项。新上下文审查又发现HQ手机首行被品牌栏遮挡及折叠浮层底色漂移；已修正HQ移动导航top46px（品牌底98、首行顶112）和浮层var(--vc-nav)。补两站垂直几何/命中检查及各自摘要，总52断言（含两条详细位置+对应摘要），复验通过。早期误用shop_coupon定位导致超时，改为真实pc-coupons菜单后完成；不把中间失败写成从未发生。

新上下文独立审查仅针对侧边栏组件，结论见menu-finish-review.txt。未重跑全后台98项、未做实体手机/其他浏览器或生产压力验收。本机运行，不称正式上线。

两站两轮共4个临时验证账号/会话/私有cookie文件已清理，没有新增草稿；选定业务设置、装修内容/元数据、商品与订单指纹前后相同。无需保存设置、发布装修或重建演示数据；本轮未改PHP/JS或公开店铺。未commit/push，无CRK绑定。

修改：backend的peptide-core/assets/admin-workspace.css、peptide-hq/assets/hq-workspace.css；PRODUCT.md、DESIGN.md、.impeccable/design.json同步最新导航规范。原CSS备份在任务work/customer-before.css和hq-before.css；只读差异在outputs/menu-style.patch。

报告及截图：D:/Codex/Tasks/2026-10-29/admin-menu-state-fix/outputs/menu-verification.json、store-1280-current.png、store-folded.png、store-390-open.png、hq-1280-current.png等。下一步仅按用户反馈微调或继续已授权项目工作；支付、邮件、搜索留空及其他暂缓决定不变。

### 2026-10-30 · 装修接口增量（STORE-BUILDER-REDESIGN-01，已验证阶段）

本段记录本轮已实现/验证的接口边界，不表示70参考或73本地积木已全量改造交付。用户已授权完整装修UI/设置/实际渲染重做，原业务、旧字段与默认、引用ID、合法设备数据和草稿/发布分离持续保留。变更提案和分阶段实测已先记录docs/handoffs/backend.md；后续按用户授权在每个已验证阶段提交并推送GitHub，仍未部署。

1. `pc_layout_records` 保留旧resource/term/paged/selected调用；`selected_ids`为正整数CSV去重最多100，`metadata_only`仅原始字面`1`启用元信息读取。每个结果仍经原资源/权限资格；缺失引用明确unavailable，不自动换成全站。review资源只搜索可公开母商品主评价，支持SKU精确归母品、媒体/精确星级过滤后分页，不返回邮箱/IP/内部来源备注。权限与nonce不放宽。
2. 新 `pc_layout_link_targets` 是仅认证GET AJAX，`Templates::CAP_EDIT`和同名nonce；type为route/product/category/page/post/实际公开topic白名单。`mode=list`支持term（UTF-8最多100字符）/paged（1–1000），固定20目标/页；`mode=resolve`以type/id在确认前重新核验公开/发布/无密码/商品可见资格。DTO仅type/id/key/label/url，response为options+more或target；不创建Page/options，不提供客户、订单或营销私有记录。400参数、403权限/nonce、404目标不可用、405方法和503读取故障有明确恢复路径，不返回SQL诊断。
3. 通用link仍是既有`{label,href,target}`；选择目标仅把其真实permalink快照写到href，不覆盖label/target，也不保存动态跟踪目标的ID。type=url仅`ui:site-link`显式启用站内选择器；其他素材/视频URL不受影响。新选择器不能生成不存在的路由，取消/关闭/失败/晚到响应不得更改原值。手填允许协议继续由既有Fields处理，不接纳javascript/data。
4. `Review_Index`只在运行时提供公开评价列表：正常/comments路径，plain permalink或已有同名资产时使用`?pc_review_index=1`，保留现有资产/访问和robots保护，无建页或flush rewrite。签名keyset绑定用途、范围、筛选与有效期；固定12条，列表每次最多240个候选对象。统计最多600个候选对象加末尾最多1条ID-only耗尽探测，完整才显示准确总数/均分/五星分布，否则unknown；不是首屏统计，也不是假0。逐页重新检查当前审核和商品资格；正常功能关闭/无评价是200空态，真实列表SQL失败为503/可重试，统计单独失败保留正常评论但统计unknown。读取失败须能恢复，不能复用失败空缓存冒充正常空结果。
5. `Review_Replies`在原有审核/商品编辑权限下用独立nonce创建/编辑/回收/恢复真实child comment；服务端决定管理员身份，parent须正整数且属当前商品主评价，保留并发指纹/锁/幂等。公开DTO仅id/content/date/datetime。内部来源/备注/编辑历史不转成公开回复；不自动外发邮件、不把child reply计入商品主评价数。
6. Registry内部`card_list`（块名`vialcove/card-list`）的`settings.display`追加`masonry`合法值，保留`grid`/`carousel`和旧默认；CSS columns按列向下流动。新增展示与轮播控件只消费原合法设备列数、gap、媒体及条目ID/href，不迁移数据；旧desktop6/mobile5/gap120等合法组合保留，极端窄屏仅在本块内部有界横滚。无JS仍输出原卡片/链接并可原生滚动，不把初始化脚本当内容来源。该阶段的五组真实设置/REST/签名iframe验收及独立fixture边界见backend-card-list-2026-10-30.md。
7. Registry内部`video_text`的块名是`vialcove/video-text`。新增设备字段`heightPreset`使用PC/手机像素预设、`screen`、`custom`完整白名单；默认custom继续原heightPx，旧600/420与九位置不改，新插入才采用550/300和四个播放flags=false。标题同key仍是纯字符串，textarea真实换行经escape再安全br；可选nullable descriptionHtml保留null继承/空字符串清除。只在本块file且关闭auto/原生controls时增加真实manual播放/暂停、状态与无JS媒体出口；现有普通video helper不改。
8. 共享select的`uiOptionsByDevice`仅筛选显示既有schema选项，合法当前值在偏好子集之外仍显示，不能扩server白名单。只有显式top-level deviceUI=paired、原nested形状合法且父safe guard通过才同屏配对；每次原单field回调只改一个slot，mobile reset仅写null。numeric旧路径、条目/sparse/坏形状原单设备回退和服务器设备合同不改。18与该共享增量合批的五组实际WP结果见backend-video-text-2026-10-30.md及对应运行报告，不以独立fixture累计冒称全量。

9. `slogan`新增独立条目`symbol`，仅none及40个固定原创SVG的白名单；原`icon`图片对象及合法尺寸保留，有效图片优先。全局`descriptionHtml`和条目`textHtml`为nullable，null沿用旧纯文案、空字符串明确清空。手机轮播使用原生滚动和真实分页/键盘增强，关闭后纵排；不新增自动播放，禁JS仍有SSR内容和原生横滚。增强态只跟随当前项自然高度并禁止内部纵滚。六组真实WP和独立fixture边界见backend-slogan-2026-10-30.md。

10. `counter`的数字字段仅由可信服务器定义显式启用precision6/step.000001，允许有限±999999999，拒绝非finite并保留旧正整数；其他number字段默认整数/两位规则不改。SSR locale文字与raw data-count分离，signed fractional动画完成后恢复原SSR原文。新增独立symbol保留原icon及image优先，nullable描述仍按null/空串区分；列数原1–6保留，无gapPx字段。实际四组范围见backend-counter-2026-10-30.md。
11. 共享richtext新增主动源码草稿Apply/Cancel、编辑全屏及本地帮助，不扩大HTML/URL白名单。未应用/取消/原样Apply不写继承null；合法旧特殊链接留在原源码保护路径。设备切换含同值时丢弃待应用草稿，明确Apply才更新当前slot。全屏同节点/焦点循环排除editable内部被浏览器跳过的链接；实际四目标及7cbd定向Tab修复范围见backend-richtext-2026-10-30.md。颜色与正文媒体尚未启用。

12. `gallery.settings.display`追加rail，原grid默认保留，仅新插入editorDefaults选择rail。新增descriptionHtml/captionHtml沿用nullable合同，原caption和列数/数量/ID/媒体/href不迁移；caption已有内部链接时不再外套itemhref。横向rail的真实range与原生scroll双向关联，无JS仍SSR/原生滚动，不新增自动播放或lightbox。五组真实WP及版本范围见backend-gallery-2026-10-30.md，不能据此声称MediaText15已获本轮实际主题验收。

13. `custom_html`完整preview的原始代码信任改按当前服务端actor的`unfiltered_html`，不再由文档旧`_edit_last`给未保存payload背书。editor仍强制KSES；public保留原saved-editor判断与发布快照来源，代码存储、角色、nonce、user/document绑定短期preview token均不改。低权限预览已保存管理员代码同样被过滤；当前有能力的actor包括直接doc0预览仍合法。三组真实WP回归、原确认问题和未证实边界见backend-color-trust-2026-10-30.md；其ColorB另行四组验收，见第14条。MediaText15旧主题缺口已由backend-media-text-2026-10-30.md真实三组另行关闭。

14. 共享富文本颜色仅由可信服务器字段`type=html,ui=richtext`选入；客户端JSON声明不授予策略，普通HTML/template/code和number precision不变。仅新增严格HEX/整数RGB→canonical span color，旧class/格式/合法链接保持，不放开任意CSS/img。22色/Custom/选中文字Remove与原生Undo是一笔事务；null继承/空串明确清除/取消/原样Apply及同值设备切换保持。折叠选区和SPAN包block结构操作明确拒绝且0emit；不设未来键入颜色。四组真实WP及独立fixture边界见backend-color-trust-2026-10-30.md；此增量更新第11条当时颜色未启用的历史状态，正文图片仍未启用。

15. `custom_html.html`保持code类型/默认，仅显式ui=expanded-code选入独立原始代码控件。扩大dialog有pending副本，Apply同步核base值/fieldkey/device scope再单字段提交；Cancel/Close/Escape/原样Apply不写，外部同值scope/换块卸载清pending。原始模型CRLF不因无编辑打开应用回写，真正textarea编辑采用浏览器值语义；不调用richtext清理或增加代码执行授权。三组真实WP及modal外部inert的测试方法边界见backend-custom-html-editor-2026-10-30.md；原预览信任与发布边界不变。

验证按阶段记录，不混计：Records独立34及控件12不当真WP；原站限定29项已精确清理并与858posts/2555非锁meta基线同指纹；独立WordPress+Woo评价/Index各范围见任务work/reviews-runtime-positive-report.md，Index19组及SQL6/同请求恢复2等有真实前后证据；Links真实API7+UI8通过，取消/权限/状态变化/390/错误恢复均覆盖。共享条目panels/paired和首7基础块属于后续单独阶段，最终真实性/版本/限制以对应报告为准，不以已有定义数代替全量验收。
