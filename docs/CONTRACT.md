# 前后端契约

## PAY-HQ-04：支付会话使用总后台认证的原始信任策略（0.4.5）

用户要求永久修复保存可信域名后商城仍拿METHODS旧缓存拦截的问题。HQ的CREATE/STATUS在已创建且URL通过该行原加密hosts快照时，追加可选`checkout_policy:{version:1,reference:<原local_reference>,payment_hosts:[精确域名]}`，包含该会话原冻结网关域名。策略来自会话快照，不取当前账号名单，也不从返回URL自动加域。原有nonce绑定的完整响应HMAC认证该字段，商户参数及密钥不下发。

Bridge严格核对账号、原引用、精确三字段、版本、去重域名列表（1–32项）及HTTPS/公网URL，随后商城将同一策略绑定并保存到准确的central attempt。METHODS的60秒展示缓存不再作为支持本协议的新支付会话的最终可信域名来源。旧HQ缺字段保持原先验证；坏字段、不同订单/账号、非created携带策略均拒绝，不回退开放。

已保存created URL却被商城旧名单拦截的订单，可在既有付款入口通过只读STATUS取同一会话的原始策略；要求原URL字节、type、引用、账号不变，并重验原生未付款状态、付款方式、订单指纹、已持久化账本、完成占用与订单锁。只修商城可信域名快照及版本标记，保留原URL密文、创建占用和金融字段；不重发CREATE，不以当前新配置批量改旧会话。HQ先升级，再升级商城及客户后台；旧Bridge会忽略新增可选字段，原来的校验仍执行。

## PAY-HQ-03：多付款方式（0.4，后续用户决定）

用户明确要求简单“添加支付→填写参数→下发店铺→继续添加”，不做官方服务商目录。`pc_panapay_hub_accounts` 是带revision/CAS的账号集合，默认单例导入为`default`保留开关和原店铺授权；新32hex身份默认all_sites，可独立编辑、开启、停用及归档，最多20个保留身份。（2026-11-06 用户决定取代此默认及 2026-11-03 的“新增默认开启”：新增身份默认关闭、支付方测试账号、不授权任何店铺；已保存身份保持原值。详见 docs/handoffs/stress-test-repairs-2026-11-06.md。）秘密只HQ；当前适配当前文档的网关协议，PayPal等名称不等于另一套官方接口。

新POST `/pc-panapay/v1/hub/methods` 返回 `{version:1,methods:[...]}`，每项精确`account_id,enabled,title,description,test_mode,payment_hosts,payment_icons,iframe_height`。关闭/归档身份以disabledDTO保留旧订单注册；坏账号仅关闭自身，无授权身份拒绝。旧CONFIG精确7字段仅default并保留关闭时可信域名，异常仍走旧失败/旧客户端冻结域名回退。0.3升级0.4必须先HQ后店铺；新Bridge策略失败不回退其他账号。

Gateway/Blocks为`pc_panapay`或`pc_panapay_<32hex>`。订单尝试、HQ引用/请求哈希冻结选择，HQ schema3追加account_id及加密可信域名快照；旧行default/空快照保留。归档或改当前密钥不改在途凭据/通知/URL；同一订单创建与成功唯一保护仍跨全部渠道。原生渠道主库落库核对阻止缓存串单，receipt/通知/完成/ACK匹配冻结ID。配置保存后授权店通过签名策略取得展示和路由配置，商户密钥绝不下发。

## 2026-10-31 PAY-HQ-02：账号绑定与支付展示增量

`peptide-panapay` 0.3 的 HQ 公开策略精确包含 `enabled,title,description,test_mode,payment_hosts,payment_icons,iframe_height`，原五字段新增后两项。`payment_icons` 只接受有序去重的 `american_express,mastercard,visa,discover,apple_pay,paypal`；只是结账标识，非独立渠道开通。`iframe_height` 为 320–1600 整数，默认530，原生尝试创建时冻结，仅实际 iframe 使用。内部 `account_name`、商户及密钥不下发。新 Bridge 兼容旧完整五字段并补无标识/530，拒绝部分升级形状及私密字段；须先升级全部店铺，再升级HQ，旧Bridge不接受新七字段。

账号保存服务端保留已保存的收款开关和店铺授权；独立的授权保存仅消费开关/授权及既有测试模式。密钥空值沿用，权限/POST nonce不变。浏览器返回仍只读、供应商通知及原有单次创建/完成保护不变；没有新增快捷支付或独立 Apple Pay/PayPal 网关。

## 2026-10-31 PAY-HQ-01：中央支付与账号专属入口（最新用户决定）

用户明确要求支付整体迁至总后台：支付方只作收款通道，各店订单和内部账目仍分开；不开发资金分账。用户只分配站点账号密码和专属链接，不需要手工管理站点连接密钥。以下取代早期每店设置商户/支付暂缓的相关约定。

- 同一 `peptide-panapay` 0.2 插件在 HQ（无需 Woo）执行支付配置、创建、支付方通知和持久回写；店铺只有原生 Woo 订单/库存、顾客入口及轻量连接器。总后台菜单 `pchq-payments`；商户配置与跨店查询仅 `manage_options` + POST nonce。
- HQ 使用已接入店铺的不可变 `site_id + 完整uuid + local_reference`，另生成 `PH` 随机中央引用；拒绝重复/歧义 UUID，不按域名或数字订单号选店。每店须授权；新注册默认不开放收款，暂停新收款不抛弃在途结果。
- `/pc-panapay/v1/hub/config|hub/session|hub/session/status` 与 `/pc-panapay/v1/store/event` 为受认证 POST。方法/完整路由/正文哈希/时间/独立随机值均入 HMAC；独立主库唯一 nonce 占用防重放，响应绑定本次 nonce。只用已接受的连接身份，拒绝 pending key；客户 JavaScript 无连接/商户凭据。托管标记持久保存，迁移漏配 HQ 地址时停止新付款，不回退本地商户。
- 支付方表单 POST 至 HQ `/?rest_route=/pc-panapay/v1/hub/notify`，冻结的单次商户凭据验签，持久事件入库后返回纯文本 `ok`。HQ schema2 的 attempts/events 以店内订单唯一创建占用、商户交易唯一及店内订单完成唯一约束保护；事件即支付回写 outbox。成功回调不含金额/币种，金额来自冻结创建快照，不能宣称已核实实结金额。
- 回写绑定原店 `site_id+uuid`，移除/重注册不得改派历史事件。店铺离线保留 pending，独立调度重试；店铺沿用 schema3 的原生持久核对和一次性完成保护。HQ分别展示支付方确认和店铺应用。重复回写不重新请求支付方，也不重复扣库存；核账状态不得自动清占用重放。
- 浏览器返回经 HQ 只读转回原店的绑定令牌入口，不能标已付款。旧独立支付的冻结尝试、配置和旧回调保留以处理在途订单；不迁造历史付款、删表或改写旧账本。新站运维自动复制/激活支付连接器，私有商户配置不进入模板。
- 员工专属 `wp-login.php?pc_staff_entry=...` 入口绑定本站 UUID/账号 ID；它不是免密凭证，仍过 WordPress 密码及认证过滤器。其它账号经该入口拒绝，普通员工通用登录绕过拒绝；总后台管理员入口与买家登录保持。总后台账号 DTO 追加 `staff_entry_url`，不返回密码。

本地原生中央 HTTP23、未知恢复5、原生账号认证18、店铺托管3、真实双进程创建占用10通过；CPT旧支付83断言回归通过。均为隔离测试，支付方响应受控模拟。原本地 HQ/店铺已安装，自动连接与 HQ 真实支付中心页面已核；正式收款仍关闭，公网真实通知、支付方创建/字符串引用/实际结算未通过。生产需真实每分钟系统任务驱动支付回写，不依赖访问量。详见支付部署文档及本次 handoff。

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

浏览器 Cookie REST 需 X-WP-Nonce；下载 URL 的 _wpnonce 由后端生成，登录 Cookie 仍需存在。Woo Store API 写请求另需其 Nonce 或 Cart-Token，由原生 Blocks 处理。账户、购物车、结账及带用户权限的报告不得被公共整页缓存。（2026-11-06：peptide-core 可选首页缓存 Page_Cache 默认关闭，仅服务无 Cookie 的匿名首页请求，浏览器仍收到 private, no-store；见 docs/handoffs/stress-test-repairs-2026-11-06.md。）PHP 公共函数：`peptide_core_get_site_config()`、`peptide_core_get_product_batches($slug,$include_history=false)`、`peptide_core_lookup_batch($batch_no,$include_history=false)`；PHP lookup 不带 HTTP 限流。当前无公共订单 helper，主题用内部 REST 并保留权限检查。

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

16. `timeline`保留旧horizontal/vertical、字段/defaultfalse、3项/ID/自由日期和设备合同，仅新insert editorDefaults auto/collapsible true。itemWidthPx为PC最小宽，有空间均分、超宽原生滚动，phone纵排；nullable rich和独立plain dateTitle/dateText沿null回退/空串抑制，inherited-text不把日期HTML编码。四个device number显式boxmodel布局，不新增Fields类型，极端左右padding仅视觉限40%不回写存储。JS保留fraction游标防亚像素停滞、实际暂停/恢复；折叠完整前3项且无JS全显。合法vc:select-item接管并展开/滚动，防通用fallback误隐藏兄弟，双device DOM按实际可见项处理。五组真实WP及native9/适配3分开记录在backend-timeline-2026-10-30.md；不称像素复制参考645px截断或相同Swiper循环。

17. `contact`新增nullable descriptionHtml/textHtml、显式themeColor、十二固定原创symbol与backgroundFallback。旧三空项/ID/14图标/图片/12–64尺寸保留；null沿plain、空串清除，有效图片优先，选symbol不改文案或href。PC按实际项数均分、phone两列；未绑定为非交互内容，正文自有链接与配置联系链接分开，空heading仍有可见独立出口。旧schema inherit保留共享picture继承；仅新insert用color/171717/white，逐端缺图用底色且消费0–100蒙层。共享Controls仅允许Contact显式覆盖手机图标签，不改media存储/Fields/HTML策略。合法item选择接管而不隐藏兄弟。独立8组与五组真实WP及具体边界见backend-contact-2026-10-30.md；正文插图C尚未实现。

18. `promo_grid`保留旧schema默认及合法值，仅新insert用balanced/黑点；legacy为电脑2:1和手机单列，balanced两端等宽、分类term/name/thumbnail/permalink仍真实动态绑定。仅两个category字段ui=collection-confirm选入pending确认/取消，同值/失效/空分类保留原合同；共享普通category选择不改。dotColor与旧dots分别控制，1–5s仅UI建议不缩1000–20000合法间隔；10点保持24px命中并在窄栏换行。Blocks分类DTO仅label做一次特殊字符解码，value/label/count与权限/查询不变，pcModules JSON使用四HEXflags而React继续纯文本输出。五个真实WP功能scope与Blocks单源增量/八条harness边界分别记录在backend-builder-batch-2026-10-30.md。用户最新要求本批整批完成再统一GitHub，覆盖本批此前逐阶段同步。

19. 共享正文图片C已在Contact的`descriptionHtml`/条目`textHtml`及RichText的`bodyHtml`三个受信服务器字段启用，更新第11、14、17条当时“正文图片未启用”的阶段状态，不扩大普通HTML/code或客户端JSON的权限。图片资格依可信文档/模式/选定发布快照上下文及本地附件当前资格，src由服务器规范化；预览不批准，成功保存才更新批准引用，发布保存精确快照引用。坏图、解析器不可用或含图超模块/条目预算在写前拒绝并保留旧内容；无C原生REST保留原完整树。原生媒体close/select增量为Controls9cd587e8；专属`Layout_Posts_Controller`/Templates注册补上原生`pc_layout`REST读取守卫，单条/集合/后代沿真实文档编辑权限，不凭raw publish或仅登录放行。真实C五组及31项目+6只读核心pin、版本差异、路由/清理/历史网络错误边界统一见backend-builder-batch-2026-10-30.md与其引用的richtext-image-runtime-check.md，不把独立fixture或历史范围累计为最终同版本全套。

20. 29–31六源已受控装入本地，当前32项目+6只读核心pin，实际WP验收仍在进行：`compare`保留旧standard/after画幅/default50，新增natural/before画幅与仅新insert初值39；`hotspots`保留旧standard/图标/方向/坐标，新增plus、独立popupImage和手机dialog，侧栏选项仅显露不抢父焦点；`faq`保留side/stacked、旧openFirsttrue及40项，新增panel/新insert closed，真实主题与Fallback同步处理空行、纯回答和多项展开。新增五个受信C路径为`compare.descriptionHtml`、`hotspots.descriptionHtml`/条目`textHtml`、`faq.descriptionHtml`/条目`answerHtml`，连同第19条共八处；动态carrier复用共同媒体资格/预算，普通HTML、合法旧格式/链接及shared API不变。此条记录当前本地合同，不表示本组真实WP或32 QuickView已完成；QuickView仍为获准任务副本工作，支付、邮件及搜索服务暂缓决定不变。

验证按阶段记录，不混计：Records独立34及控件12不当真WP；原站限定29项已精确清理并与858posts/2555非锁meta基线同指纹；独立WordPress+Woo评价/Index各范围见任务work/reviews-runtime-positive-report.md，Index19组及SQL6/同请求恢复2等有真实前后证据；Links真实API7+UI8通过，取消/权限/状态变化/390/错误恢复均覆盖。共享条目panels/paired和首7基础块属于后续单独阶段，最终真实性/版本/限制以对应报告为准，不以已有定义数代替全量验收。

21. 2026-10-30更新第20条阶段状态：29–31真实WP四域已完成；32产品展示九源及后续真实nested sidebar接线三源已本地受控安装并实际Q1–Q4完成。原生Woo simple/variable form、完整attribute_名字、库存/数量及Store API事实为购买来源；BuyNow仅确认真实加购成功且当前操作仍有效时去既有checkout，保原cart，不创建订单/支付。未知结果只GET校准和人工核对、不盲重POST；同抽屉start/settle epoch防迟到read/mutation回退。公开精选支持publish/publicly_viewable/no password及真实库存，不把hidden catalog公开PDP误作private。仅product_showcase.spots可信previewSelect把真实nested展开传Host，空直属hint由其模块接管，不伪造商品/隐藏兄弟。正式32总37项目+Core6+aux19，第三方均readonly；actual证据、不同版本范围与task失败见backend-builder-batch-2026-10-30.md及product-showcase-runtime报告。后续同日单Host b68返回修复保持总数：未保存Back改用既有wp.components.Modal明确继续/不保存离开，原refresh/close beforeunload与正确back href保留；不自动保存/发布/丢弃。四项真实WP验证及旧数据保护见builder-back-navigation-runtime报告。当前live可信C仍八处，33–37及P01 task副本不是已交付合同；AI、支付、SMTP与外部搜索联调仍按用户暂缓，整批积木完成后统一GitHub。


22. 2026-10-30更新阶段：33–37已真实WP四域与57项受影响既有smoke闭合。当前受信C为12：在原八处基础上，仅加info_list.items.textHtml、logo_list.descriptionHtml、donation_tip.descriptionHtml、text_block.bodyRichHtml；new shadow null继承oldbodyHtml/空串清，旧ordinary HTML不迁移不扩大。nav旧WP walker保留，新store/picker复用真实菜单/Core edit权限与单取确认，未新增API或菜单写权；logo三新模式保旧合法列数/IDs；donation_tip只有服务端聚合适配，无provider公开隐藏，无收款。P01七源本地已装但实际WP正在验：旧card合同保留、新insert detail，独立subtitle字段走原Woo nonce/edit-product保存链，同原Quick唯一operation/nonce/unknown/reconcile引擎的inline host，不复制购买系统。正式46primary/Core10/aux19及两份额外Woo管理员只读依赖，版本/实际边界见本轮handoff。P02新C13仍仅任务副本，不是已安装合同；全部70积木完成后才统一GitHub，外部服务暂缓不变。


23. 2026-10-30更新阶段：P01实际三域及本人精清已闭合，独立subtitle与原Woo nonce/edit_product保存链、唯一购买engine inline adapter实际行为见featured-product-runtime报告。P02四源已审查/备份/受控本地安装，视频商品新insert showcase、旧manual/legacy合同和旧空items不变；只追加video_products.settings.descriptionHtml为精确global C13，null沿plain/空串清，未放开其它HTML。公开有效product+真实video附件才显示，原生媒体控制播放/静音/进度/循环，自动切换依真实ended，编辑器/少动态仅阻自动播放，真实PDP出口无购买引擎。当前46/Core10/aux19/Woo2，P02实际WP两域仍待验，不将fixture或参考空src当真播放。P03及C14仅私有任务未安装。整70后统一GitHub及外部服务暂缓不变。


24. 2026-10-30阶段更新：P02实际两域/精清已闭合，真实Layout preview上下文已由其局部三谓词修复，C13真实媒体/保存闭合。P03仅新collection分支已受控安装47primary/Core10/aux19/admin2/查询readonly8，实际三域待验；唯一新增C14是product_waterfall.descriptionHtml，旧for_module/Quick唯一engine/legacy合同保持。公共分页GET仅签名category/批数/页大小上下文、只读/force_login与每批公开资格，固定stride+1probe、真实主题SSR卡，失败保卡可重试，末端及无JS有真分类链接。P04/P05的C15/C16和个人history API仅task候选。详见本轮handoff与catalog-waterfall-runtime后续报告，不将fixture当真实WP。


25. 2026-10-30阶段更新：第24条P03实际pending已由catalog-waterfall-runtime三必要域及精清闭合，C14真媒体/SaveGET/无损拒绝、实际Woo分页/签名GET/故障重试/公开资格、追加变量原Quick/两端/noJS通过。正常Abort/device实际与更强ignoreAbort仅native证据分列，不承诺目录并发快照。正式47/Core10/aux19/admin2/query8保持FDFF字节；P04/P05/P06和C15–17仍task候选，不将其个人history cookie/API说成已live。


26. 2026-10-30阶段更新：P04/P05/P06三个Commerce源已审查/备份/受控安装，47primary/Core10/aux19/admin2/query8及cookieHistory只读3；C15/16/17仅三个精确global nullable字段，actual WP三域仍待验。P04独立主推公开hidden-PDP与catalog grid资格分开，真实term/原卡/原Quick；P05新签名100项cookie仅真实PDP跟踪，私有no-store只读GET仅当前cookie+total、不接受ids，public空壳/无JS隐藏且旧24链完整不变；P06新明确term圆图/实效label/独立1–8列，旧自动fallback保持。C15手机长rich图片裁切最终CSS在task窄修后才安装，不冒称fixture为真WP。P07/P08与C18/C19/共享复制metadata修复仅task未安装。


### P04–06 actual accepted; P07/P08 installed (2026-10-30 Codex)

P04/P05/P06 three necessary actual WP domains passed. ComingSoon exposed an early-history/cache defect, corrected only new signed-history timing to real pre-template include; old24 and recorder/query/cookie bytes remain. A/C retain 06eb epoch, B uses corrected4008; normal Back was not bfcache. Final97 source/QA hashes and protected data matched, owned database fixtures/MU/tokens removed. Six closed task profiles were retained after a policy-blocked deletion; no retry. Original685 remains untouched.

P07/P08 are now locally installed as four existing files (Commerce PHP/CSS/JS and Controls). Formal catalog-scroll-navigation-rebased/work/integration/freeze-pin.json88764825..., candidate4ebddf95..., PHP15e484fe..., 47primary +52readonly =99 unique. Exact inherited P05 correction, scoped C18/C19, true collections-index route, and triple-opt-in nested-copy IDs; old functionality preserved. Root backups/6LFhunk reconstruction/fresh dependencies/PHP1JS2 passed. Actual WP scroll/navigation/media/Host validation is pending with the sole QA owner; no claim of completed integration. P09/P10 c645 candidate remains task-only, not installed. Reference48/70, currentP13. All70 builder work completes before one Git sync batch; deferred external services remain blank.


### P07/P08 actual accepted; P09/P10 installed (2026-10-30 Codex)

P07/P08 necessary actual WP three domains and exact DB/MU/token cleanup complete, production/QA99 hashes and protected media/data/Original matched. True native mobile parent copy/child Host selection, actual30s/pause/reduced/focus resize/noJS, true links/routes and C18/C19 passed. ComingSoon category-body and untested31/document.hidden limits remain explicit. Ten closed task profiles are retained; no retry of earlier policy-blocked deletion.

Root installed only three existing Commerce sources for P09 collection products and P10 product carousel after independent c645/eac4 review. Formal collection-products-carousel-integration/work/freeze-pin.json2c7c9648..., 47primary+53readonly=100 unique, five LF hunks/exact inverse/fresh dependencies/backups/PHP1JS1 passed. Original Registry/theme/Controls/P05/P07/P08 behavior and precise C20/C21 scope preserved; new theme-template dependency is readonly. P09 had no reference full-width setting, so none invented; P10 keeps its confirmed control. Actual WP validation remains pending with sole QA; reference48/70 currentP13, later tabs remain task-only. Whole70 batch Git and deferred external-service policy remain.


## M3 系列与商品目录（2026-11-10，F3，HQ-05 / HQ-06 / FE-07 / NF-12）

这一节由 F3 维护。系列 = 一套商品和价格 + 一套模板 + 一套营销活动 + 一套运费。系列定义存在总后台，店铺只记自己属于哪个系列和已同步到的版本号。金额一律用美分整数；时间是 UTC 秒（和总后台现有表一致）。

### 数据

- 总后台表 `pchq_series`：`id, slug UNIQUE, name, status(active|archived), master_site_id, catalog_ver, template_ver, campaign_ver, shipping_ver, shipping(JSON), created_at, updated_at`。升级类 `PeptideHq\Series_Db`，版本选项 `pchq_m3_db`，可重复执行。
- `pchq_sites` 新增列：`series_id`（索引）、`buyer_name`（投手）、`onboarding_state`、`series_sync`（这家店是否接受系列同步，现有店默认 0，新注册的店默认 1）。升级时建“系列 1”（slug `series-1`），把没有系列的店全部归入。
- `pchq_series_products`：`series_id, sku, name, payload(JSON，就是下面的商品对象), price_cents, compare_cents, status(active|removed), position, ver`；`UNIQUE(series_id, sku)`。
- `pchq_series_campaigns`：`series_id, kind, item_key, config(JSON), active, ver`；`UNIQUE(series_id, kind, item_key)`。
- 店铺选项 `peptide_core_series`：`{series_id, slug, name, master, catalog_ver, template_ver, campaign_ver, shipping_ver, synced_at}`。读取用 `PeptideCore\Series_Sync::state()` / `::series_id()` / `::is_master()`。`master = true` 表示本店是系列的母版店：商品内容在这家店编辑，再由总后台导入，所以母版店的商品不锁定，同步时只接收价格。
- 店铺商品元 `_pc_series_sku`（系列内的商品键）、`_pc_series_managed`（`1` = 由系列管理，店铺后台只读）；订单元 `_pc_series_id`（下单时店铺所属的系列，新订单第一次保存前写入）。

### 总后台 PHP 接口（别的模块可以调用）

- `PeptideHq\Series::all()`、`::get($id)`、`::choices()`（开店表单下拉：ID => 名称）、`::default_id()`。
- `PeptideHq\Series::site_map()` / `::site($site_id)`：`{series_id, buyer_name, onboarding_state, series_sync}`；`::sites_of($series_id)`。
- `PeptideHq\Series::assign($site_id, $series_id)`、`::set_buyer($site_id, $name)`、`::set_onboarding($site_id, $state)`（M9 向导进度）、`::set_sync($site_id, $on)`。
- `PeptideHq\Series::reserve($host, $series_id, $buyer_name = '')`：**请日常会话在开店页面（`hq/admin/class-provision-page.php`）加一个必选的“系列”下拉（选项来自 `Series::choices()`）和可选的“投手”输入，提交开店任务时调用它**，参数是新店的管理地址主机名（`sNNNN.…`）或顾客域名。店铺注册进来时按预留归入系列；没有预留的归入默认系列。
- `PeptideHq\Series_Campaigns::replace($series_id, $items)`（整体替换并把 `campaign_ver` 加一）、`::items($series_id)`。
- `PeptideHq\Series_Push::queue_series($series_id)`（给系列里每家店排一次同步）、`::lagging()`（店铺 ID => 落后的部分，可用于巡检钩子 `pchq_monitor_checks` 和告警 `series_out_of_sync`）。

### 总后台 → 店铺：`POST /peptide/v1/hq/series/sync`

签名校验沿用 `Hq_Connector::verify`。请求体：

```json
{
  "series_id": 1,
  "slug": "series-1",
  "name": "系列 1",
  "template_ver": 2,
  "master": false,
  "dry_run": false,
  "catalog":   { "ver": 3, "source_host": "vialcovepeptides.com", "products": [ /* 商品对象 */ ] },
  "campaigns": { "ver": 1, "items": [ { "kind": "coupon", "key": "welcome10", "active": true, "config": { } } ] },
  "shipping":  { "ver": 1, "plans": [ /* 运费方案 */ ], "rules": { "combine": "sum", "sort": "price" } }
}
```

- `series_id`、`slug`（小写字母、数字、连字符）每次必带；店铺据此写 `peptide_core_series`。店铺换了系列时，本地三个内容版本号归零。`template_ver` 只做记录（模板内容仍由 `/hq/design` 下发）。
- `catalog`、`campaigns`、`shipping` 三部分都可以不带；不带 = 不动。带了但 `ver` 不大于店铺本地版本 = 跳过。所以同一个请求重复发送，结果不变。
- `master: true`（这家店是系列的母版店）：商品部分只改价格，不新建、不下架、不锁定；总后台不给母版店发活动。
- `dry_run: true`：店铺不改商品、运费、活动和系列状态，返回差异报告（见“返回”）。试运行只是比较，带了哪部分就比哪部分，不看版本号。（唯一会写的是图片校验码的缓存 `_pc_series_sha256`。）
- 一个请求里后面的部分出错（比如运费方案在这家店无效）时，前面已经同步好的部分和系列基本信息照样记下来，然后返回错误。
- 两边都忽略看不懂的字段。同一时间只处理一个同步请求，冲突时返回 409 `pc_series_busy`，总后台稍后重试。

**商品对象**（`catalog.products[]`，也是 `GET /hq/catalog-export` 导出的格式）：

```json
{
  "sku": "VC-BPC157",
  "wc_sku": "VC-BPC157",
  "type": "variable",
  "name": "BPC-157",
  "slug": "bpc-157",
  "status": "publish",
  "description": "<p>…</p>",
  "short_description": "",
  "categories": ["Peptides"],
  "menu_order": 0,
  "attributes": [ { "name": "Specifications", "options": ["5mg", "10mg"], "visible": true, "variation": true } ],
  "default_attributes": { "specifications": "5mg" },
  "images": [ { "url": "https://…/a.webp", "sha256": "…", "name": "a.webp", "alt": "" } ],
  "meta": { "_pc_spu": "VC-BPC157", "_pc_product_subtitle": "…" },
  "price_cents": 0,
  "compare_cents": 0,
  "stock_status": "instock",
  "variations": [
    { "key": "VC-BPC157-5MG", "sku": "VC-BPC157-5MG", "attributes": { "specifications": "5mg" },
      "price_cents": 4999, "compare_cents": 5999, "stock_status": "instock", "enabled": true,
      "image": null, "meta": { "_pc_vials_per_sales_unit": "10" } }
  ]
}
```

- `sku` 是系列内的商品键：取主店商品的 `_pc_spu`，没有则商品 SKU，再没有则 `slug:<别名>`。店铺按 `_pc_series_sku` → `_pc_spu` → SKU → 别名的顺序找本地商品，找不到就新建。
- `price_cents` 是成交价，`compare_cents` 是划线价（0 = 没有）。`type: simple` 时用商品自己的价格字段；`variable` 时价格在 `variations[]` 里，款式键 `key` = 款式 SKU，没有 SKU 时是 `a:<属性的哈希>`。
- `wc_sku` 是商品在 WooCommerce 里真正的 SKU（可以为空）；`sku` 只用来配对。
- 同步后商品带 `_pc_series_managed = 1`，店铺后台不能修改或删除（同步、恢复和命令行脚本除外）。做法是拦住“改商品”的入口（商品编辑页的保存和删除、快速编辑、批量编辑、款式的增删改、WooCommerce REST 的改和删），**不改权限**，所以评价、专辑、批次 COA 等别的功能照常。别的模块如果有自己改商品的入口，请先问 `PeptideCore\Series_Sync::is_locked( $product_id )`。系列里没有的商品（包括本店自己加的）改为草稿，不删除；系列里没有的款式停用，不删除。
- `manage_stock`（可选，商品和款式上都有）：导出时表示这个商品在本店按数量跟踪库存。店铺接收时不采用它；自己按数量跟踪库存的商品不比较、也不改库存状态。
- 促销价只有正在生效时才算成交价；设了开始时间还没到的促销不会带到别的店。同步写入的价格不带促销时间。
- 随商品同步的元：`_pc_spu`、`_pc_product_subtitle`、`_pc_specs`、`_pc_vials_per_sales_unit`、`_pc_pricing`、`_pc_is_demo`（款式只有后两项里的 `_pc_vials_per_sales_unit`、`_pc_pricing`）。其他商品元留在店铺本地。
- 图片按 `sha256` 去重，只允许从 `source_host`（https）下载，单张最大 15 MB。描述里指向母版店的绝对链接换成本店地址。
- 属性一律按“自定义属性”同步（全局属性会被导出成自定义属性）。库存只同步有货 / 无货状态，不同步数量。COA 批次和报告暂不随系列同步（仍用“店铺复制”）。
- 一次请求最多处理约 200 秒；没做完（主要是首次下载图片）时返回 `complete: false`，不记版本号，总后台 30 秒后再发同一个请求接着做。全部商品处理完之后才下架多余的商品。

**运费方案**（`shipping.plans[]`）：`{ name, regions: [ { country: "US", states: [] } ], rates: [ { name, rule: "subtotal"|"quantity"|"weight", min, max|null, fee_cents } ] }`，`min` 含、`max` 不含。店铺用它替换“通用”运费方案，按商品单独设置的方案不动。默认两档（FE-07）：不满 $1000 收 $45，满 $1000 收 $15；门槛和金额在系列页可改，**初始值待平台所有者确认**。

**活动**（`campaigns.items[]`）：外形固定为 `{ kind, key, active, config }`；`kind` 取 `promotion / coupon / ship_discount / first_order / exit_intent / wheel / upsell / progress_bar`，`key` 是同类型内不重复的小写代号，`config` 的内容由 M4 定义（F3 原样保存和转发）。**请 F2 在自己的类里接这四个钩子**：

| 钩子 | 签名 | 说明 |
| --- | --- | --- |
| `pc_series_apply_campaigns`（过滤器） | `( $result, array $items, int $ver, bool $dry_run )` | 用 `$items` 整体替换本店的系列活动。成功返回 `true` 或摘要数组；`$dry_run` 时不写入，返回差异数组；失败返回 `WP_Error`。保持返回 `null` = 没有模块接手，店铺不记 `campaign_ver`，总后台下次还会再发 |
| `pc_series_export_campaigns`（过滤器） | `( array $items )` | 在母版店导出当前活动，返回上面外形的数组。总后台“从母版店导入活动”时调用 |
| `pc_series_snapshot`（过滤器） | `( array $snapshot )` | 同步前存快照：把恢复活动需要的内容放进 `$snapshot['campaigns']` |
| `pc_series_restore`（动作） | `( array $snapshot )` | 恢复快照时把 `$snapshot['campaigns']` 写回去 |

`PeptideCore\Series_Sync::manages( 'campaign' )` 为 true 时活动已由系列接管，店铺后台不应再给创建入口（`'catalog'`、`'shipping'` 同理）。

**返回**：

```json
{
  "series":   { "series_id": 1, "slug": "series-1", "name": "系列 1", "catalog_ver": 3, "template_ver": 2, "campaign_ver": 1, "shipping_ver": 1, "synced_at": 1794300000 },
  "applied":  { "catalog": { "created": 1, "updated": 5, "unpublished": 2, "same": 40 }, "campaigns": "applied", "shipping": "applied" },
  "complete": true,
  "diff":     { }
}
```

- `applied` 每部分：没带 = `null`；版本不新 = `"skipped"`；活动没有模块接手 = `"unhandled"`。`applied.catalog` 另有 `errors`（最多 10 条，某个商品写入失败的原因）。
- `complete: false` 表示内容太多没做完（主要是图片），总后台 30 秒后接着同一个请求再发。
- `diff` 只在 `dry_run` 时返回：`{ catalog_ver, summary: { same, changed, create, unpublish }, create: [ { sku, name } ], unpublish: [ { id, sku, name } ], changed: [ { sku, name, id, fields: [ { field, variation?, store, series } ] } ], shipping: { changed }, campaigns: <M4 的差异> }`。

### 其他店铺接口

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| `/peptide/v1/hq/series/state` | GET | `{ series: <peptide_core_series>, snapshots: [ { id, at, from, to } ] }` |
| `/peptide/v1/hq/catalog-export` | GET | 母版店导出：`{ version: 1, source_host, exported_at, products: [], shipping: { plans, rules }, campaigns: [] }`，只导出已发布的商品和“通用”运费方案 |
| `/peptide/v1/hq/series/restore` | POST `{ ver }` | 恢复到某次同步前的快照（`ver` 为 0 用最近一份；否则先找“把商品升到这个版本的那次同步”之前存的快照，再按快照编号找）。店铺属于哪个系列不回退，只回退商品、运费、活动和它们的版本号。返回 `{ snapshot, series, counts: { restored, unpublished, missing, failed } }` |

店铺命令行：`wp peptide series status`（系列状态和快照）、`wp peptide series restore [<ver>] [--yes]`、`wp peptide series export --file=<路径>`、`wp peptide series diff --file=<目录文件> [--out=<路径>]`（只比较，不改）。

**快照**：每次真正改动前自动存一份（商品、运费、系列状态；活动由 M4 经 `pc_series_snapshot` 放进来），存在选项 `pc_series_snapshot_<编号>`，清单在 `pc_series_snapshots`，保留最近 3 份。快照存不进去时这次同步不做任何改动。

### 上线顺序与开关

- 先发店铺端（接口就位），再开总后台。店铺没有这个接口时总后台记“程序太旧”，6 小时后再试，不报错。
- 功能键（`pc_hq_capabilities`，请 F1 汇总进功能键表）：`series.sync => 1`（支持 `/hq/series/sync`、`/hq/series/state`、`/hq/series/restore`、`/hq/catalog-export`）、`series.catalog => 1`（能接收商品）、`series.shipping => 1`（能接收运费）。总后台有 `Client::supports()` 时只对声明了 `series.sync` 的店调用；还没有时靠“没有这个接口”的回应判断。
- 巡检类型（`pchq_monitor_checks`，请 F1 登记）：`series_out_of_sync`（系列内容未同步：上一次同步失败，或者落后超过 30 分钟）。只对声明了 `series.sync`、已归入系列、而且至少尝试同步过一次的店报告。
- 现有店默认不接受系列同步。首次同步商品前，总后台对每家店做一次 `dry_run` 差异报告，平台所有者逐店确认后才打开开关并下发；之后的版本自动下发。报告必须是针对当前商品版本的，过期的要重新生成才能确认。店铺换系列后要重新确认。

### 模板按系列下发（HQ-06）和店铺复制

- `PeptideHq\Design_Push` 的每个方法多了一个可选的系列参数（`settings / save_settings / sites / master_id / job / latest / templates / studio / start`），不传时用“当前选中的系列”（`Design_Push::current_series()`，在系列管理里点“去模板库”切换，按管理员记）。**日常会话的模板库页面不用改**：只有一个系列时行为和以前完全一样。
- 默认系列沿用原来的选项名（`pchq_design`、`pchq_design_job`、`pchq_design_latest` …），其他系列是 `pchq_design_s<编号>…`、`pchq_design_job_s<编号>`。自动下发、新店下发、后台逐店下发对每个系列分别处理；整套下发一次，系列的 `template_ver` 加一。
- `PeptideHq\Store_Clone::request()` 只允许在同一个系列的店铺之间复制（错误码 `pchq_clone_series`）；复制提交后总后台会重新把系列信息发给目标店铺。

### 数据表登记（给 F1 的脱敏快照）

| 表 / 选项 | 有没有顾客资料或密钥 | 建议 |
| --- | --- | --- |
| `pchq_series`、`pchq_series_products`、`pchq_series_campaigns` | 没有（商品、价格、运费、活动配置） | keep |
| `pchq_sites` 新增列 `series_id`、`onboarding_state`、`series_sync` | 没有 | keep |
| `pchq_sites.buyer_name` | 投手姓名（内部人员） | scrub：改成“投手 1 / 2 …” |
| 总后台选项 `pchq_series_push_state`、`pchq_series_reserved`、`pchq_series_sources`、`pchq_series_confirmed`、`pchq_series_diff_<系列>_<店铺>`、`pchq_design_s*` | 没有（`pchq_series_reserved` 里有投手姓名和域名） | keep；`pchq_series_reserved` 清空 |
| 店铺选项 `peptide_core_series`、`pc_series_snapshots`、`pc_series_snapshot_<编号>`、`pc_series_media` | 没有（快照里是商品和运费） | keep |
| 店铺商品元 `_pc_series_sku`、`_pc_series_managed`，附件元 `_pc_series_sha256`，订单元 `_pc_series_id` | 没有 | keep |


## M10 订单汇总、报表与退款（2026-11-10，F3，HQ-13 / OR-03）

这一节由 F3 维护。

### 数据

- 总后台表（升级写在 `PeptideHq\Orders`，版本选项 `pchq_m10_db`）：
  - `pchq_orders`：`site_id, store_order_id, order_number, series_id, status, paid, is_test, currency, total_cents, refunded_cents, refund_state(none|partial|full), refund_sync, refund_error, created_at, paid_at, order_day, email_hash, utm_source, ad_id, reported_at, updated_at`；`UNIQUE(site_id, store_order_id)`。
  - `pchq_refunds`：`id, site_id, store_order_id, amount_cents, refunded_on, note, created_by, created_at, voided_at, voided_by`。每次标记一行；撤销只写 `voided_*`，不删行。
  - `pchq_ad_spend`：`site_id, day, spend_cents, source(manual|api), updated_at, updated_by`；`PRIMARY KEY(site_id, day)`。
  - `pchq_daily` 新增列 `gross_cents`、`refunded_cents`；有已标记退款的日期，`revenue` 改为净额。
- 店铺订单元：`_pc_hq_reported`（最近一次上报成功的内容指纹和时间）、`_pc_refund_cents`（已标记的退款总额，美分）、`_pc_refund_state`（`none / partial / full`）。**投手订单页（F5）请用这两个元显示“已退款 / 部分退款”；店铺侧销售额（F4 的 `Data\Metrics`）请减去 `_pc_refund_cents`。**

### 店铺 → 总后台：`POST /peptide-hq/v1/orders/report`

签名同 `/mail`（店铺密钥 + `X-PC-HQ-Site`）。每批最多 50 条；按 `(site_id, store_order_id)` 覆盖写入，重复上报结果不变。

```json
{ "orders": [ {
  "store_order_id": 1234, "order_number": "1234", "status": "processing", "paid": true, "is_test": false,
  "currency": "USD", "total_cents": 14999, "created_at": 1794300000, "paid_at": 1794300100, "order_day": "2026-11-10",
  "series_id": 1, "email_hash": "<sha256(小写邮箱)>", "utm_source": "facebook", "ad_id": "1202…"
} ] }
```

- 返回 `{ "accepted": [1234], "rejected": [ { "store_order_id": 0, "code": "invalid" } ] }`。
- `paid` = 订单处于 WooCommerce 的已付款状态；`is_test` = 测试单（收件信息全填 1、测试付款方式或含演示商品）。报表只算 `paid = 1 且 is_test = 0`。
- 不上报姓名、邮箱明文、电话、地址。`refunded_cents` 由总后台自己按 `pchq_refunds` 计算，不采用店铺上报的值。
- 店铺端：付款和状态变化时异步上报（钩子 `pc_hq_order_report`）；每小时补报近 48 小时内容有变化但没报成功的订单（`pc_hq_order_reconcile`）。没付过款、也从没上报过的订单不上报。
- 请求体里另有一个 `nonce`（随机值，只为了让每次请求的签名不同），总后台忽略它。
- `ad_id` 取下单那次访问落地时查询串里的 `ad_id`，没有就取 `utm_content`。
- **和 F4 的约定：** `PeptideCore\Data\Metrics::order_fact( \WC_Order )`（F4 那节的签名）存在时，上报的 `paid / is_test / total_cents / paid_at` 取它的 `paid / test / gross_cents / paid_at`；它还没合进来时 F3 用同口径的本地实现（WooCommerce 已付款状态 + `Test_Orders` / `Overview::is_test_order`）。
- 功能键（请 F1 汇总）：`orders.report => 1`（店铺会上报订单）、`orders.refund => 1`（支持 `/hq/order-refund` 和 `/hq/orders/digest`）。
- 巡检类型（请 F1 登记）：`order_report_lag`（订单上报落后：近两天店铺每日汇总的已付款订单数比已上报的多，而且持续超过 2 小时）。只对声明了 `orders.report` 的店报告；巡检里不向店铺发请求。

### 总后台 → 店铺

| 接口 | 方法 | 请求 | 行为 |
| --- | --- | --- | --- |
| `/peptide/v1/hq/order-refund` | POST | `{ order_id, refunded_cents, state, note }` | 只写 `_pc_refund_cents`、`_pc_refund_state` 和一条订单备注；**不创建 WooCommerce 退款对象**，不改订单状态。内容没变时不重复加备注 |
| `/peptide/v1/hq/orders/digest` | GET `from`、`to`（UTC 秒，按付款时间，可不带） | — | `{ count, total_cents, refunded_cents, test_count, currency }`，用来核对“总后台订单合计 = 各店合计” |

### 历史订单回填

店铺命令 `wp peptide orders backfill [--dry-run] [--since=YYYY-MM-DD]`：先试运行（只出报告：订单数、金额、按月分布、测试单数、缺 `_pc_series_id` 的订单数），确认后正式运行（补 `_pc_series_id` 并分批上报）。可重复执行。

### 退款标记（只有总后台管理员）

- `PeptideHq\Refunds::mark( $site_id, $store_order_id, $amount_cents, $refunded_on, $note, $user_id )`、`::void( $refund_id, $user_id )`。金额必须大于 0，且不超过订单金额减已标记的退款。
- 每次标记或撤销后：重算 `pchq_orders.refunded_cents / refund_state` → 重算 `pchq_daily` 净额 → 回写店铺；店铺连不上时记为待回写，每 10 分钟重试。
- 已回传给广告平台的购买事件不撤回。

### 报表口径（HQ-13）

订单数 = `paid = 1 且 is_test = 0` 的订单；销售额（净额）= `total_cents − refunded_cents`；ROAS = 净销售额 ÷ 广告花费（`pchq_ad_spend`，先手填）；时间按付款时间，日期边界用总后台时区。三级（系列 → 投手 → 店铺）都由同一批店铺行往上加，合计相等。访客数用 `pchq_daily.visitors`（店铺时区的日期），只作参考。弃单率还没有：总后台没有弃单数据，要等店铺把 F4 的 `Metrics::abandoned()` 放进每日汇总（`/hq/summary` 归 F1）之后再加一列。

`pchq_daily` 的净额：轮询每次用店铺的每日汇总覆盖 `pchq_daily`（那时 `gross_cents`、`refunded_cents` 回到 0，`revenue` 是毛额），F3 在轮询之后（`pchq_poll` / `pchq_poll_one` 优先级 20，以及店铺页“立即刷新”之后）按下单日把已标记的退款扣掉。所以概览页的销售额也是净额，不用改概览页。

### 数据表登记（给 F1 的脱敏快照）

| 表 / 选项 | 有没有顾客资料或密钥 | 建议 |
| --- | --- | --- |
| `pchq_orders` | 没有姓名、邮箱、电话、地址；`email_hash` 是邮箱的 SHA-256，`ad_id`、`utm_source` 是广告来源 | scrub：`email_hash` 置空（或重新加盐哈希） |
| `pchq_refunds` | `note` 是平台所有者手写的备注，可能提到顾客 | scrub：`note` 置空 |
| `pchq_ad_spend`、`pchq_daily` 新增列 `gross_cents`、`refunded_cents` | 没有 | keep |
| 总后台选项 `pchq_order_lag_since` | 没有 | keep |
| 店铺订单元 `_pc_hq_reported`、`_pc_refund_cents`、`_pc_refund_state` | 没有 | keep |
## M6 / M7：指标入口、漏斗规则与像素（2026-11-10，F4「数据」会话）

本节只由 F4 修改。口径见 `docs/metrics.md`；进度和未接入的报表见 `docs/handoffs/m6-metrics.md`、`docs/handoffs/m7-pixels.md`。
状态：代码已提交在分支 `claude/m6-metrics-db7036`，还没有合进主线、没有发布。F3、F5 先按下面的签名用假数据开发。

### 1. `PeptideCore\Data\Metrics`（只读，店铺端）

约定：时间段 `[from, to)`，Unix 秒；金额是美分整数；比率是百分数（2 位小数），分母为 0 时是 `null`。不看开关，随时可以调用。

| 函数 | 返回 |
| --- | --- |
| `Metrics::window( string $preset, ?int $now = null ): array` | `today / yesterday / 7d / 30d`（店铺时区）→ `{from, to, bucket}` |
| `Metrics::summary( int $from, int $to ): array` | 一个时间段的全部指标，见下 |
| `Metrics::preset( string $preset, ?int $now = null ): array` | `summary( window( $preset ) )`，多一个 `preset` |
| `Metrics::orders( int $from, int $to ): array` | `{orders, gross_cents, refund_cents, sales_cents, aov_cents, test_orders}` |
| `Metrics::visitors( int $from, int $to ): int` | 访客数 |
| `Metrics::paid_visitors( int $from, int $to ): int` | 支付成功访客数 |
| `Metrics::conversion_rate( int $from, int $to ): ?float` | 支付成功访客 ÷ 访客 |
| `Metrics::funnel( int $from, int $to ): array` | `{unit:'visitors', counts:{visitors,viewed,carted,checkout,address,shipping,payment,paid}, layers:[{key,count,step_rate,visitor_rate}×8]}`，每层不大于上一层 |
| `Metrics::abandoned( int $from, int $to ): array` | `{count, open, failed, emailed, recovered, recovered_cents, rate}`，结果缓存 5 分钟 |
| `Metrics::daily( int $from, int $to ): array` | 按店铺时区自然日，每天 `{date, visitors, paid_visitors, conversion_rate, orders, gross_cents, refund_cents, sales_cents, aov_cents}` |
| `Metrics::order_fact( \WC_Order $order ): array` | 一张订单的事实，见下 |
| `Metrics::order_facts( int $from, int $to ): array` | 付款时间在时间段内的已付款订单的事实（含测试单，`test = true`） |
| `Metrics::paid_orders( int $from, int $to ): array` | 和 `order_facts()` 同一批订单，但返回 `\WC_Order` 对象（含测试单，调用方用 `is_test_order()` 排除） |
| `Metrics::tail_steps( array $evidence, bool $paid ): array` | 一次访问在漏斗后四层走到了哪几步（纯函数）：`$evidence` 是这次访问里有没有 `address / shipping / payment` 事件，`$paid` 是有没有算数的已付款订单；返回 `address / shipping / payment / paid` 里走到的那几步 |
| `Metrics::is_test_order( \WC_Order $order ): bool` / `is_paid_order()` / `paid_at()` | 测试单 / 收到过支付成功通知 / 付款时间（秒） |
| `Metrics::sum_orders( array $facts ): array` | 把事实加总（纯函数），返回同 `orders()` |

`summary()` 的 key（保证存在，后续只会增加）：

```
version, from, to, currency,
visitors, paid_visitors, conversion_rate,
orders, gross_cents, refund_cents, sales_cents, aov_cents, test_orders,
abandoned, abandoned_open, recall_emailed, recall_recovered, recall_rate, recovered_cents,
funnel
```

`order_fact()` 的 key：

```
id, status, paid, test, counted (= paid && !test), paid_at, created_at,
gross_cents, refund_cents (= _pc_refund_cents，不超过 gross), net_cents, wc_refund_cents (只用于对账),
currency, visitor, visit, buyer
```

- **F5 投手首页**：五个核心数字用 `Metrics::preset( 'today' | 'yesterday' | '7d' )` 的 `visitors / orders / sales_cents / conversion_rate / abandoned`；待办里的“未跟进弃单数”用 `abandoned_open`。
- **F3 订单上报和报表**：每张订单上报 `order_fact()` 里的 `counted / paid_at / gross_cents / refund_cents`，总后台按同样的规则汇总（`counted` 的才算订单；销售额 = `gross_cents − refund_cents`；归属日 = `paid_at` 的店铺时区日期）。总后台回写 `_pc_refund_cents` 之后店铺端的销售额自动跟着变，不需要通知 F4。每日汇总可以直接取 `Metrics::daily()`。

### 2. 开关 `PeptideCore\Data\Metrics_Switch`

- 每家店一个选项 `peptide_core_m6_mode`：`off`（默认）/ `shadow` / `on`；过滤器 `peptide_core_m6_mode` 可以覆盖。
- `Metrics_Switch::mode()`、`off()`、`shadow()`、`on()`、`set( $mode )`、`pick( $metric, $legacy, callable $modern, array $context = [], float $tolerance = 0 )`、`log()`、`reset_log()`。
- 影子和开的时候，新旧不一致写进选项 `peptide_core_m6_shadow_log`，并触发 `do_action( 'peptide_core_m6_shadow_diff', string $metric, array $diffs, array $context )`（F1 的巡检可以挂）。
- 功能声明（F1 的 `pc_hq_capabilities`，格式 功能键 => 版本号）：`m6.metrics => '1'`（这家店有 `Data\Metrics`，版本 = `Metrics::VERSION`）、`m7.pixel_acceptance => '1'`（有服务端 AddPaymentInfo、像素验收和 `/admin/pixel-test`）。只说明代码在不在，不说明开关是哪一档。
- 总后台如果要远程换档：改这个选项即可（需要 F1 在连接器里提供写选项的通道，F4 不改连接器）。
- 对账日志里的指标名：`overview.totals`、`overview.recovery`、`operations.kpis`、`reports.test_order`、`reports.paid_orders`、`reports.conversion`、`reports.visitor_funnel`（含义见 `docs/metrics.md` 第 5 节的表）。
- `Data\Metrics_Overlay` 是 F4 的报表类内部用的（按开关在旧算法和 `Metrics` 之间取值），别的模块不要调用它，直接用第 1 小节的 `Metrics`。
- **开关会影响到的、不归 F4 的调用方**：`core/class-overview.php` 和 `core/class-hq-ai.php` 读 `Data\Visitor_Funnel::period()`、`Data\Operations::period()`。开关开着时它们拿到的是新口径的数字（和概览页、数据页一致），关着时和以前一样；这两个文件不用改。
- 新增的选项（没有新表）：`peptide_core_m6_mode`、`peptide_core_m6_shadow_log`（只有指标名和数字）、`peptide_core_m6_funnel_anomalies`（按天的计数）、`peptide_core_px_acceptance`（验收码和时间）、`peptide_core_pixels_buyer_platforms`。都不含顾客资料和密钥，脱敏快照里可以原样保留。验收期间浏览器上报的事件记在现有的 `pc_px_log` 里（`platform = browser`，只有事件名和 event_id）。

### 3. 漏斗推进规则 `PeptideCore\Tracking\Funnel_Guard`（FE-05）

- `Tracker::raise_stage( $visit_id, $stage, $order_id = 0 )` 的签名不变。开关是 `on` 时，升到 4（下单）或 5（支付成功）之前要求同一次访问里已经有 `checkout_address_completed`，否则不推进到 4、5：最多记到 3（进入结账），订单号仍然挂到访问上。
- 新动作：`do_action( 'peptide_core_tracking_address_completed', int $visitor_id, int $visit_id, int $new )`，在 `POST /track/events` 新保存了填地址事件之后触发，每批一次；`$new` 是这一批里新保存了几条。
- `Funnel_Guard::counters( int $days = 7 ): array`（按店铺日期的异常计数）、`Funnel_Guard::anomalies( int $from, int $to ): array`（准确数和访问编号）。
- 前端协议没有变化，主题不用改。

### 4. 数据页对投手（ST-05）

- 判断投手：`PeptideCore\Data\Audience::is_media_buyer( ?int $user_id = null )` = 用户有角色 `pc_media_buyer` 且没有 `manage_options`；过滤器 `peptide_core_is_media_buyer`（参数：结果、用户编号）可以改写。**需要 F5**：创建角色 `pc_media_buyer`，并给它 `manage_woocommerce`（数据页、概览页、像素页的权限检查都是这一项）。
- 顶层菜单 slug 仍然是 `pc-data`。投手只有两个子页：`pc-data-funnel-sources`（漏斗 + 来源）、`pc-data-products-landing`（商品 + 落地页），每页用页签（地址栏 `tab=funnel|sources`、`tab=products|landing`）切换原来的两个报表；其余数据子页对投手不注册，直接访问会被拒绝。管理员（含总后台一键登录）仍然看到全部 12 个子页，另外也能打开这两个合并页（不显示在菜单里）。
- F5 的菜单白名单里“数据”保留 `pc-data` 即可；投手点“数据”会进 `pc-data-funnel-sources`。投手如果直接打开 `admin.php?page=pc-data`，页面只显示两个合并页的入口（`Workspace_Shell` 只在 `pc-overview` / `pc-data` 两个地址启动，投手的入口不是这两个地址之一时不受影响）。
- 这一版是“两页、每页两个页签”，不是同屏合并（同屏要改 `assets/data.js` 的挂载方式，等有浏览器环境再做）。

### 5. 像素与 CAPI（FE-09，M7）

- 新接口 `POST /wp-json/peptide/v1/pixel-event`，JSON `{ "name": "add_payment_info" }`，公开（和 `/pixel-view` 一样，受 `Pixels::allowed()` 约束）。返回 `{ events: [ … ] }`：服务端生成事件并用同一个 `event_id` 发 Conversions API，浏览器拿到后用这个 `event_id` 发像素。接口失败时浏览器退回原来只发浏览器事件的做法。
- 触发时机：结账页顾客选了支付方式，或点了下单（先到的那个），每个页面一次。
- 像素页对投手只显示 Facebook；其他平台的卡片不显示也不提交（已保存的设置原样保留）。总后台要给某家店打开其他平台：选项 `peptide_core_pixels_buyer_platforms`（平台 key 的数组，默认 `[ 'facebook' ]`）。
- 验收：`wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php start|check|stop|test`（`PeptideCore\Pixels\Acceptance`），核对 `pc_px_log` 里六类事件。验收期间带验收码的浏览器把发出的事件报到 `POST /peptide/v1/pixel-check`（公开，验收码过期即拒绝）。操作清单见 `docs/guides/m7-pixel-acceptance-checklist.md`。
- **给 F5 的向导第 4 步**：`POST /wp-json/peptide/v1/admin/pixel-test`（需要 `manage_woocommerce` 和 REST nonce），或 PHP 里直接 `\PeptideCore\Pixels\Server::test_event()`。无参数，HTTP 200，返回 `{ ok: bool, pixel: string, message: string, reason: '' | 'no_pixel', test_code: bool, results: [ { pixel, ok, code, message } ] }`；`ok = true` 就是“已通”。`pixel` / `message` 是给向导直接显示的：第一个没通的像素（都通时是第一个像素）和一句中文原因（通了是空字符串）；`results` 是每个像素的明细。向导状态由 F5 自己写，这个接口不写任何向导数据。
- `assets/pixels*.js` 不在第 13.2 节的清单里，但只属于像素模块，由 F4 维护。

### 6. 需要别的会话配合的事

| 谁 | 事情 | 状态 |
| --- | --- | --- |
| F5 | 角色 `pc_media_buyer` 带 `manage_woocommerce`；菜单白名单保留 `pc-data` | 待 F5 |
| F3 | 订单上报和总后台汇总按 `order_fact()` 的规则；回写 `_pc_refund_cents` | 待 F3 |
| F1 | 登记功能键 `m6.metrics`、`m7.pixel_acceptance`；测试条目已追加到 `tests/data-observations-suites.json` 末尾（`module: M6 / M7`）（现在 11 条）；发布前在沙盒把开关切到 `shadow` 观察。F4 的用例已经在本机跑过（PHP 8.3.36），默认套件 59 通过 / 3 失败，剩下 3 个不归 F4 | 待 F1 |
| 日常会话 | `core/class-overview.php` 不用改：概览页的数字由 `Admin\Overview_Page` 按开关用 `Metrics` 覆盖。之后再改概览页、数据页、访客页、像素页请先在聊天室找 F4 | 知会 |
## M9 投手后台 / M11 已购回访 / M14 前台主题（F5，2026-11-10 起）

本节由 F5 会话维护。标“需要配合”的条目请归属会话实现；没实现之前 F5 的代码按“未就绪”处理，不会报错。

### M9-1 投手角色（`PeptideCore\Staff_Buyer`，ST 总则）

- 角色 `pc_media_buyer`（显示名“投手”）。角色本身只存 `read`、`view_admin_dashboard`。
- **按账号开关，默认全部关闭**：给销售账号（`pc_staff`）加上这个角色 = 投手版；去掉 = 恢复按模块勾选。原角色和 `pc_staff_modules` 不动，所以总后台“销售账号”列表、专属登录入口、停用开关照常工作。
  - 代码：`Staff_Buyer::set( int $user_id, bool $on, bool $must_change_password = false )`（返回 `true` 或 `WP_Error`；管理员账号和非销售账号会被拒绝）。
  - 店铺后台：员工管理页面（`admin.php?page=pc-staff`，仅管理员）每行的“后台版本”开关，或表单里的“投手版后台”勾选。
  - 命令行：`wp peptide buyer list`、`wp peptide buyer on <账号> [--must-change-password]`、`wp peptide buyer off <账号>`、`wp peptide buyer default [on|off]`。
  - 选项 `pc_media_buyer_default`（值 `1`）：这家店新建的销售账号直接是投手版并要求首次登录改密码。现有店铺没有这个选项；新店由开店流程写入。
- 判断：`Staff_Buyer::is_buyer( $user )`、`Staff_Buyer::current()`（当前登录的人）。管理员永远不算投手。`Staff::is_staff()` 对投手仍为真，`Staff::granted()` 对投手固定返回 `orders`、`data`。
- 投手的 6 个菜单：首页 `pc-buyer-home`、订单 `wc-orders`（只读）、弃单 `pc-recovery`、数据 `pc-data*` 和 `pc-visitors*`、广告设置 `pc-buyer-ads`、店铺信息 `pc-store-info`；另有开店向导 `pc-onboarding`。
- 其余后台地址对投手一律 403（`index.php`、`pc-overview` 转到首页，`profile.php` 转到店铺信息）。`admin-ajax.php` 只放行 `heartbeat`、界面偏好、订单预览和按顾客筛选；`admin-post.php` 只放行投手版页面自己的表单；对未登录访客开放的动作不受影响。
- 能力按请求临时给（`Capabilities::BUYER_SCOPES`）：订单页给订单的查看能力，弃单 / 数据 / 访客页给 `manage_woocommerce`、`view_woocommerce_reports`。REST 只有下面的接口给能力，其他接口上投手没有任何店铺能力：
  - 只读（GET）：`/peptide/v1/data/*`、`/peptide/v1/overview*`；
  - 读写：`/peptide/v1/track/admin/*`（实时访客和 AI 分析）、`/peptide/v1/admin/pixel-test`、`/peptide/v1/admin/ad-link`、`/peptide/v1/admin/recovery/*`、`/peptide/v1/admin/buyer-home`。
- **给投手用的新页面或新接口**：请告诉 F5 加进名单（`Staff_Buyer::classify()`、`REST_READ` / `REST_ANY`），否则投手打不开。权限检查继续用 `manage_woocommerce` 即可。
- 对 F4「数据页对投手」一条的答复：角色已经有了，但 `manage_woocommerce` **不是常驻能力**，只在上面名单里的页面和接口上有。F4 的 `Audience::is_media_buyer()`（看角色）可以照常用；投手的两个数据子页 `pc-data-funnel-sources`、`pc-data-products-landing` 都在 `pc-data*` 之内，不用改名单。F4 做的“像素页投手模式”目前投手进不去（`pc-system` 不在名单里），投手用的是 F5 的“广告设置”页（只管第一个 Facebook 像素）；要让投手管理多个像素时再把 `pc-system&section=pixels` 和 `pc_pixels_save` 加进名单。
- 功能声明（F1 的 `pc_hq_capabilities`）：`m9.media_buyer => 1`（投手角色和开关）、`m9.onboarding => 1`（向导和 `Onboarding::store_status()`）。
- `Staff_Buyer::can_use()`：管理员，或者已经改过密码的投手。投手版的数据接口用它做权限判断；`can_view()` 只表示能打开页面（没改密码的投手只能看到向导）。
- 订单页除了 `action`，还拦截不带 `action` 就会执行写操作的参数：`delete_all`（清空回收站）、`claim-lock`（接管编辑锁）。
- 营销活动（F2 的约定）：`Admin_Menus::add_menus()` 已改为用 `Marketing::can_view()` 和 `Marketing::menu_cap()`（F2 的代码合进来之前沿用原判断）。投手**没有**拿到 `pc_view_campaigns`，营销页面也不在投手的名单里；PRD 要求的“只读的当前活动”放在投手首页，读 `\PeptideCore\Pricing\Campaigns::running()`。F2 如果希望投手能打开只读的营销页面，告诉 F5 把 `pc-promotions`、`pc-coupons`、`pc-shipping-discounts` 加进名单。

需要配合：

| 归属 | 内容 | 没做之前 |
| --- | --- | --- |
| F1（`core/class-hq-connector.php`） | `/hq/staff` 的账号数据加 `media_buyer`（`Staff_Buyer::is_buyer()`）；`staff_save` 把请求里的 `media_buyer`（布尔）原样放进传给 `Admin\Staff_Page::save()` 的数组（不带这个键 = 不改）。若按 NF-05 H 删除 `/hq/staff`，改为在新的账号接口里带上 | 只能在店铺的员工管理页面或命令行切换 |
| 日常会话（总后台“销售账号”页面） | 显示“投手版 / 按模块”，提供切换 | 同上 |
| F1 / 日常会话（开店流程） | 新店写入选项 `pc_media_buyer_default = 1` | 新店账号仍是按模块，需要手动切 |
| 日常会话（`core/admin/class-recovery-page.php`） | `\PeptideCore\Staff_Buyer::current()` 为真时不输出“立即扫描”“作废”按钮（服务端已拦截 `pc_recovery_scan`、`pc_recovery_void`，只是界面整洁） | 按钮可见，点了返回 403 |
| F4（`Tracking\Tracker::is_staff()`、`Pixels::allowed()`）、F2（`Exit_Intent`） | “是不是员工”的判断加上 `\PeptideCore\Staff::is_staff( $user_id )`。投手在前台没有 `manage_woocommerce`，现在只按能力判断会把投手自己的访问算成访客、给他发像素事件和弹窗。只影响前后台同域名的环境；生产是分开的域名，员工在前台本来就是匿名的 | 同域名环境（沙盒、本地）里投手自己的浏览会进数据 |

### M9-2 开店向导和广告设置（`Admin\Onboarding`，ST-01、ST-06）

- 页面：`admin.php?page=pc-onboarding`（向导，`&step=password|store|pixel|verify|link`）、`admin.php?page=pc-buyer-ads`（广告设置 = 第 3、4、5 步放在一页）。两个页面都不在原来的菜单里，能力 `pc_view_buyer_admin`（投手和管理员都有）。
- 5 步：修改密码 → 店铺信息 → 广告像素 → 验证像素 → 生成投放链接（可选）。前 4 步做完才算完成；没完成时投手的每个页面顶部都有红色提示条“还差 N 步就可以投广告”。
- 状态：
  - 用户资料 `pc_must_change_password`（值 `1`）：下次登录只能打开向导改密码。切到投手版时带 `--must-change-password`、新建账号、管理员或总后台重置密码时写入；本人改完密码后删除。
  - 选项 `pc_onboarding`（按店铺，不自动加载）：`store`（店铺信息保存过的时间）、`pixel_test`（`pixel`、`ok`、`at`、`message`）、`link`（生成过投放链接的时间）。开发文档 3.3 写的是用户资料 `pc_onboarding`，这里改成按店铺记：一家店一个投手，管理员替投手做也算，总后台巡检也只需要店铺级的结果。
  - 其余按真实数据判断：店名和 WhatsApp 非空、第一个 Facebook 像素有 ID 和令牌、测试通过且测的是当前像素（换像素要重新验证）。
- 给总后台巡检用：`\PeptideCore\Admin\Onboarding::store_status()` 返回 `array{steps:array<string,bool>,remaining:int,complete:bool,current:string}`。
- 生成投放链接（F5 已实现）：`POST /peptide/v1/admin/ad-link`，参数 `landing`（必须是本店地址）、`campaign`（可空）、`source`（`facebook` / `instagram`），返回 `{ url }`。链接固定带 `utm_source`、`utm_medium=paid`、`utm_campaign`、`utm_term`、`utm_content`；广告系列名不填时用 Facebook 动态参数 `{{campaign.name}}`、`{{adset.name}}`、`{{ad.name}}`。

需要配合：

| 归属 | 内容 | 没做之前 |
| --- | --- | --- |
| F4（M7，`core/class-pixels.php` / `core/pixels/class-server.php`）**F4 已在自己分支实现** | `POST /peptide/v1/admin/pixel-test`：权限 `manage_woocommerce`，不需要参数；向第一个 Facebook 像素发一条测试事件并判断结果。不论通没通都返回 HTTP 200：`{ "ok": true|false, "pixel": "<像素 ID>", "message": "<未通时给投手看的中文原因>" }`；没填像素或令牌时也返回 `ok:false` 加原因。F5 在 `rest_request_after_callbacks` 里读这个返回并写入 `pc_onboarding.pixel_test`，F4 不需要调用 F5 的任何代码 | 页面提示“验证功能还没上线”，向导停在第 4 步，红条不会消失 |
| F4（可选） | `Pixels::set_facebook_pixel( string $pixel_id, string $token ): void`：设置主 Facebook 像素（令牌为空 = 沿用原令牌），其余设置不动 | F5 用 `Onboarding::merge_pixel()` 直接改 `peptide_core_pixels` 里 `platforms.facebook.pixels` 的第一项，其他内容原样写回 |
| F4（M6 归因） | 落地页参数里的 `utm_campaign`、`utm_term`、`utm_content` 需要进归因，数据页才能按广告系列 / 广告组 / 广告拆分（现在访客表只存 `utm_source`、`utm_medium`） | 只能按来源拆分 |
| F1（`/hq/status`） | 状态里带上 `Onboarding::store_status()`，供总后台巡检“向导是否完成” | 总后台看不到向导进度 |

### M9-3 投手首页（`Admin\Buyer_Home`，ST-02）

- 页面 `admin.php?page=pc-buyer-home`；数据接口 `GET /peptide/v1/admin/buyer-home?range=today|yesterday|7d`（投手或管理员），返回排好格式的 5 个数字、统计时间、未跟进弃单数；每个时间段缓存 60 秒。
- 待办来源：向导没做完、支付域名没登记、未跟进弃单数。

需要配合：

| 归属 | 内容 | 没做之前 |
| --- | --- | --- |
| F4（M6，已按 F4 的约定接好） | 首页用 `\PeptideCore\Data\Metrics::preset( 'today' \| 'yesterday' \| '7d' )` 的 `visitors`、`orders`、`sales_cents`、`conversion_rate`、`abandoned`，待办用 `preset( '7d' )` 的 `abandoned_open`。**只在这家店的指标开关 `Metrics_Switch::mode()` 是 `on` 时才用**，这样首页和数据页任何时候都是同一个口径 | 开关是 `off` / `shadow`，或 F4 的代码还没合进来：沿用 `Overview::range_data()` 和 `Recovery_Items::list()` 的统计（真实数据，不是假数据） |
| 日常会话（M5）/ F1 | 店铺选项 `pc_pay_domain_missing`：总后台发现这家店的收银台域名不在名单里时写 `1`，登记好后删除（也可以用过滤器 `pc_pay_domain_missing` 返回布尔值） | 这一条待办不会出现 |
| 日常会话（M8） | `Recovery_Items::uncontacted_count(): int`（未标记“已联系”的待召回 / 召回中顾客数），或者在 `Recovery_Items::list()` 的每一项里带 `contacted_ms` | 首页把全部待召回 / 召回中的顾客都算作未跟进 |

### M9-4 订单只读和店铺信息（`Admin\Admin_Menus`、`Admin\Store_Settings`，ST-03、ST-07）

- 投手的订单列表去掉勾选框、批量操作和行内按钮，增加三列：来源广告（订单上的 `_wc_order_attribution_utm_*`）、运单号（见 M11 的 `Buyer_Status::shipment()`）、退款状态（`_pc_refund_state` 优先，没有时按订单已退金额）。订单页面隐藏保存、改价、退款、作废、加备注。
- 店铺信息页面 `admin.php?page=pc-store-info`：投手能改对外英文品牌名、客服 WhatsApp、客服邮箱和自己的密码；后台店铺名称（`blogname`）和域名只读。
  - 对外英文品牌名（平台所有者 2026-11-10 确认可改）：选项 `pc_public_brand`（2–40 个英文字符，可留空），读取用 `\PeptideCore\Admin\Store_Settings::public_brand()`。主题的 `vc_brand_name()` 已经读它（网页标题、Logo 替代文字、页脚版权）；留空时和以前一样。**不写 `blogname`**：那是总后台管理的内部名称，11-08 已定不上前台、不反向同步。
  - WhatsApp、邮箱写 `peptide_core_settings.support` 里的 `whatsapp`、`email`，其余字段不动（保存时从 `Settings::all()` 出发，不会把别的开关关掉）。
- 订单导出（PRD ST-03 提到）这一版没做。

需要配合：

| 归属 | 内容 | 没做之前 |
| --- | --- | --- |
| 日常会话（`core/class-mail-outbox.php`） | `Mail_Outbox::brand()` 先取 `\PeptideCore\Admin\Store_Settings::public_brand()`，非空就用它，否则照旧按域名推 | `vialcove*` 域名的店邮件落款仍按域名显示，和投手填的品牌名可能不一致 |

### M11 已购回访和查订单（`PeptideCore\Buyer_Status`、`PeptideCore\Order_Lookup`，FE-10、FE-11）

- **按店开关，默认关**：选项 `pc_buyer_recognition`，值 `on` 才生效（过滤器 `pc_buyer_recognition_enabled` 可以覆盖）。关着时不写 cookie、不静默弹窗、不加载前台脚本，两个接口都按“不是已购 / 没找到”回答。第一次改成 `on` 时自动建一个 `/track-order` 页面（内容是短代码 `[pc_track_order]`，页面 ID 记在选项 `pc_track_order_page`；已有同名页面就用它）。
- cookie `pc_buyer`：`v1.<订单号-订单号…>.<签发时间>.<HMAC-SHA256>`，最多 10 张订单，1 年，HttpOnly，SameSite=Lax，HTTPS 下带 Secure。密钥是 `wp_salt( 'pc_buyer' )`。里面没有顾客资料。
- 什么时候写：顾客带着订单密钥回到店铺而且订单已付款（`/?pc_paid=<订单>&pc_key=<密钥>`，或 WooCommerce“订单已收到”页面带 `key`）；查订单核对成功且订单付过款。测试单（`Test_Orders::is_test_order()`）不写。
- 接口（都是公开的，返回 `Cache-Control: no-store, private`）：
  - `GET /peptide/v1/buyer/status` → `{ "buyer": false }`，或 `{ "buyer": true, "order": { "number", "placed", "status", "status_label", "items": [ { "name", "quantity" } ], "more_items", "shipment": { "carrier", "number", "url" } } }`。只看 cookie 里最近的一张订单；`status` 取 `awaiting_shipment` / `shipped` / `refunded` / `cancelled` / `unpaid`。不返回姓名、邮箱、电话、地址。前台等 800 毫秒，没等到就当新客。
  - `POST /peptide/v1/order-lookup`，JSON `{ "email", "order" }` → `{ "found": true, "order": {…同上} }`，或 `{ "found": false, "message" }`（HTTP 200；订单不存在、邮箱不符、测试单都是同一句提示）。每个 IP 每分钟 5 次（成功失败都算），超过返回 429。IP 的取法同 `Api\Rest_Controller::client_ip()`。
- 运单：`Buyer_Status::shipment( \WC_Order $order )` 返回 `array{carrier,number,url,shipped,shipped_at}`。先读订单元数据 `_pc_shipment`（`carrier`、`number`、`url`、`shipped_at`，发货对接 OR-01 写入），没有再读 WooCommerce 订单履约（`Shipping_Tracking::customer_view()`）；都没有而订单是 `completed` 时算已发货但没有运单号。投手的订单列表也用这个函数。
- 静默营销弹窗：用 F2 提供的过滤器 **`pc_marketing_popups_suppressed`**（见 M4 一节）。已购顾客在前台时 `Buyer_Status` 让它返回 `true`；带 cookie 的访客本来就不走首页缓存，所以在服务端判断，新访客没有任何额外请求。同时在页面 `<head>` 里输出 `window.pcBuyer = { known: true, suppressPopups: true }` 并调用 `window.pcMarketingQuiet.refresh()`（存在时）。F5 没有调用 `pcMarketingQuiet.suppress()`：那会另写一个一年的 cookie，店铺把已购回访关掉以后弹窗仍然不出来。
- 功能声明：`m11.buyer_status => 1`。
- 没有新数据表。新选项：`pc_buyer_recognition`、`pc_track_order_page`。

需要配合：

| 归属 | 内容 | 没做之前 |
| --- | --- | --- |
| F2（离开挽回、首单券条、转盘、活动倒计时）**F2 已在自己分支实现** | 弹窗读 `\PeptideCore\Pricing\Popups::suppressed()`。F5 已经挂好过滤器，两个分支合到一起就生效 | F2 的分支合进来之前，已购顾客仍然会看到这些弹窗；物流卡片和查订单不受影响 |
| 日常会话（`Campaign_Announcement`、`Modules\Product_Broadcast` 等 F2 没覆盖的弹出内容） | 同样读 `Popups::suppressed()`。付款成功弹窗（`Paid_Popup`）不属于营销弹窗，不用改 | 这些内容对已购顾客照常出现 |
| F3 / 发货对接（OR-01，暂缓） | 发货后把 `carrier`、`number`、`url`、`shipped_at` 写进订单元数据 `_pc_shipment`；退款标记写 `_pc_refund_state`（`full` 时卡片显示 Refunded） | 物流卡片显示“Awaiting shipment”，或显示 WooCommerce 订单履约里的运单 |
| M14 主题（F5，已做） | 页脚上方输出 “Track your order”（`vc_track_order_entry()`），只在开关打开且有查订单页面时出现。发货邮件里的链接可以带 `?email=…&order=…` 预填 | — |
| F1（开关） | 试点店打开：`wp option update pc_buyer_recognition on`（或总后台远程写选项）。回退：改回 `off` | — |

### M14 前台主题（`wp-content/themes/peptide-site/`，FE-02、NF-06）

平台所有者 2026-11-10 确认：Codex 不再修改主题，主题由 F5 接手（`AGENTS.md` 已更新）；Q5 选“保留弹窗，换地址”。别的会话要改主题里的东西，在这里提，F5 来做。

- **FE-02 商品 / 专辑地址**（`inc/entry-address.php`、`assets/entry-address.js`）
  - 按店开关：选项 `pc_entry_address`，值 `on` 才生效，**默认关**（过滤器 `vc_entry_address_enabled` 可覆盖）。关着时不加载脚本，行为和以前一样。功能声明 `m14.entry_address => 1`。
  - 服务器端的入口没有变：`/product/<slug>/` 和专辑链接仍然 302 到首页（`/?vc_product=<ID>`、`/?vc_catalog_category=<ID>`），**已投放的 `/?vc_product=` 链接照常可用，没有加任何 301**。首页缓存、落地页统计（`vc_visitor_entry_product_context()`）都不受影响。
  - 只改地址栏：商品弹窗打开时显示商品固定链接；专辑落地后显示专辑固定链接；关掉弹窗恢复页面自己的地址（去掉 `vc_product`）。写进地址栏的每个地址都是服务器本来就接受的入口，刷新或分享都会回到同一个画面。`utm_*`、`fbclid` 等其他参数原样保留。
  - 只用 `History.prototype.replaceState` 替换**当前**历史记录的地址，不增删历史记录，不动 `live-commerce-navigation.js` 的弹窗层状态。
  - 已知限制：分享出去的商品地址被社交平台抓取预览时，拿到的仍是首页的标题和图片（服务器把它转到了首页）。
- **NF-06 使用声明**：文字只来自店铺设置 `use_statement_html`（`vc_use_statement_markup()`），主题不自带任何措辞。输出位置：每个页面页脚上方（购物车、结账页除外，原有），以及**商品弹窗内容末尾**（新增）。店铺没配置时两处都不输出，`Launch_Check` 会提示。
- **品牌名**：`vc_brand_name()` 先读 `Store_Settings::public_brand()`，没填用默认的 `VialCovePeptides`。
- **落地参数**：`assets/visitor-adapter.js`、`assets/visitor-tracking.js` 现在随 `utm_source`、`utm_medium` 一起上报 `utm_campaign`、`utm_term`、`utm_content`（各 1–120 个字符；不合规、重复、为空或是没被替换的 `{{…}}` 时只丢这一项）。服务端目前会忽略这三项，不报错。
- 政策页定稿内容：等平台所有者提供，还没做。

需要配合：

| 归属 | 内容 | 没做之前 |
| --- | --- | --- |
| F4（`Tracking\Attribution`、来源报表） | 服务端白名单放开 `utm_campaign`、`utm_term`、`utm_content` 并入库，来源报表加这三个维度 | 这三项被服务端丢弃，数据仍只能按来源拆 |
| F4（M7 像素） | 开关打开后地址栏会变。脚本用的是原生 `History.prototype.replaceState`，正常不会触发 Facebook 像素“地址变化自动发 PageView”；请在沙盒用事件管理工具确认打开商品弹窗时没有多出 PageView | — |
| F1（开关） | 试点店打开：`wp option update pc_entry_address on`；回退：改回 `off` | — |

## CR 素材归因（2026-11-11 起，打地基 + 线 1–4）

需求见《素材库与广告归因模块 需求文档》（claude.ai 文档，2026-11-11）。所有广告的“网址”填首页域名，只靠网址参数区分素材；拿不到 Meta API，素材由投手登记、花费由投手导入 CSV。打地基已建好表、页面入口、接口和校验函数；各线只在自己的文件里写，见 `docs/handoffs/cr-foundation.md`。

### 网址参数和广告名

- **素材专属链接（2026-11-12，优先）**：投手在“广告设置”页上传素材（图文为主，视频传封面、视频文件可选），得到这个素材的专属投放链接：落地页 + `cr=素材编号&` + 下面的固定模板（`Contract::creative_query()`，`Onboarding::ad_link( $landing, '', '', $code )`）。一个素材一条链接，投到多少广告系列都用同一条；换落地页只换前半段，编号不变。识别素材时 **`cr` 优先，其次广告名开头的编号**（`Contract::code_from_params()`），广告名不再必须按规范写。`cr` 属于 `Contract::PARAMS` 白名单（最长 16），也算页面缓存的追踪参数。素材表加 `video_id`（`Schema::VERSION` = 2）。素材库页只做汇总和对比，不再有登记入口。

- Meta“网址参数”固定模板（`PeptideCore\Creatives\Contract::URL_TEMPLATE`）：
  `utm_source={{site_source_name}}&utm_medium=paid&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&placement={{placement}}&ad_name={{ad.name}}`
- 访问上保存的参数白名单：`Contract::PARAMS`。有效 = 平台是 fb/ig/msg/an、medium 是 paid、三个 ID 都是 6–25 位数字（`params_valid()`）；广告预览带来的 `{{ad.id}}` 原文算无效。
- 来源分类 `source_class`：`paid`（参数有效）/ `fb_untagged`（没有 utm，但带 fbclid、来源页是 facebook.com / instagram.com、或在 FB / IG 内置浏览器）/ `other`。
- 素材编号：`C` + 至少 4 位数字，每家店独立计数（`code_for(n)`）；没登记的素材统计时用 `UNREG`。
- 广告名：`C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1`。编号在最前；`属性-取值` 小写字母数字；`v数字` 是版本；看不懂的属性放 custom。拆分 / 生成：`Contract::parse_ad_name()` / `build_ad_name()`。**素材的属性以素材库登记为准**，名字里的属性只是方便人看。
- 必填属性 `hook`、`fmt`、`angle`，可选 `lang`。词表初版 `Contract::DEFAULT_VOCAB` = 总后台 `Creatives::DEFAULT_VOCAB`（测试保证一致）。

### 店铺端数据

- 访客追踪表升到 v5（`Tracking\Schema::DB_VERSION = '5'`，列已加好，写入归线 1）：
  - `pc_track_visits` 新列：`utm_campaign, utm_term, utm_content(=ad_id), placement, ad_name, creative_code, has_fbclid, source_class, params_valid, ip, geo_region, geo_city, geo_lat, geo_lon, isp, asn, ip_type, timezone, language, in_app`；索引 `ad_time(utm_content,start_ms)`、`creative_time(creative_code,start_ms)`。
  - `pc_track_visitors` 新列：`first_visit_id`（只在为 0 时写）、`last_paid_visit_id`（每次 `source_class = paid` 的新访问更新）。
  - 带着和当前访问不同的广告 ID 进站时开新访问（线 1）。
- 素材表（`Creatives\Schema`，版本选项 `peptide_core_creatives_db`，后台打开时自动建表）：
  - `pc_ad_creatives(code UNIQUE, attachment_id, attrs JSON, custom JSON, lang, note, status active|archived, created_by, created_at, updated_at)`
  - `pc_ad_ads(ad_id PK, code, ad_name, adset_id, campaign_id, first_seen_ms, last_seen_ms)`：某个 ad_id 第一次出现时写入，编号来自广告名。
  - `pc_ad_daily_spend(ad_id + day PK, spend_cents, impressions, link_clicks, ad_name, import_id)`：CSV 导入按 (ad_id, day) 覆盖。
  - `pc_ad_spend_imports(id, filename, user_id, created_at, day_from, day_to, rows_total, rows_saved, errors)`
  - `pc_ad_attribute_vocab(attr + value PK, label, required, active, position, ver)`：总后台下发后整体覆盖；ver = 0 是初版。
- 后台页面（能力 `Staff_Buyer::CAP_VIEW`）：`pc-creatives`（素材库，线 2）、`pc-creatives-spend`（花费导入，线 2）、`pc-creatives-board`（素材看板，线 4）。投手菜单“素材”在“数据”和“广告设置”之间；管理员在“营销活动 → 广告素材”。
- 投手可提交的 admin-post 动作：`pc_creative_save`、`pc_creative_spend_import`、`pc_creative_export`（`Creatives\Module::ACTION_*`，已加入 `Staff_Buyer::POST`）。
- REST 前缀预留：`peptide/v1/creatives/...`，权限用 `CAP_VIEW`，不需要店铺能力。

### 店铺 → 总后台

签名同订单上报（`X-PC-HQ-Site / Time / Sign`，总后台 `Mail::verify`）。请求体都带 `nonce`。

- `POST /peptide-hq/v1/creatives/report`：一天一次（店铺时区的前一天），补登记或补导入后重报受影响的日期（整天覆盖）。
  ```json
  {"nonce":"…","day":"2026-11-11","tz":"America/New_York","untagged":{"visitors":7},
   "rows":[{"code":"C0012","attrs":{"angle":"purity","fmt":"vid","hook":"lab","lang":"en"},"custom":{},"thumb":"https://…",
            "intent":{"high":3,"mid":9,"low":23,"invalid":5},"visitors":40,"valid_visitors":35,"add_to_cart":4,"checkout":2,
            "cashier":2,"orders":1,"sales_cents":15900,"spend_cents":2350,"impressions":3100,"link_clicks":52}]}
  ```
  店铺端用 `Contract::report_payload()` 生成，总后台用 `Creatives::clean_report()` 校验，两边按 `tests/fixtures/creatives/report-sample.json` 测。最多 500 行；同一天同一编号只留最后一行；计数非负整数，缺的补 0；`thumb` 只收 https。回应 `{day, stored, rejected}`。写入（线 3）：同一家店同一天整天覆盖；写失败返回 500，原有数据不动。
- `POST /peptide-hq/v1/creatives/spend`：`{"nonce":"…","days":[{"day":"2026-11-11","spend_cents":12345}]}`，1–62 天，写进 `pchq_ad_spend`（`source = csv`）；0 = 当天没花费（删掉那一行）。回应 `{saved: [日期…]}`。已实现。
- `GET /peptide-hq/v1/creatives/vocab`：`{"ver":1,"items":[{"attr":"hook","value":"lab","label":"检测报告","required":true,"position":0}]}`。店铺发现 ver 变大时整体覆盖本地词表。已实现。

### 总后台数据

- `pchq_creative_daily(site_id + day + code PK, attrs, custom, thumb, visitors, valid_visitors, intent_high/mid/low/invalid, add_to_cart, checkout, cashier, orders, sales_cents, spend_cents, impressions, link_clicks, reported_at)`
- `pchq_attribute_vocab(attr + value PK, label, required, active, position, updated_at)`，版本号选项 `pchq_attribute_vocab_ver`（改词表加一）。
- `pchq_ad_spend.source` 新增取值 `csv`（`Orders::set_spend()`）。

### 联调后补充（2026-11-11）

- **每日上报口径**（线 4，`Creatives\Daily_Report`）：店铺时区的前一天，按“当天最近来源”归到素材；有效访客 = 进站访客去掉意向分层为“无效”的；加购 / 进结账 / 进收银页 / 订单按访问的 `stage` ≥ 2 / 3 / 4 / 5。总后台对比页的比率都用有效访客做分母。每小时检查一次，失败按退避重试，501 / 网络错误重试，400 丢弃并记日志。
- **重报**：`Daily_Report::rereport( $days )`。素材归位后（`do_action( 'pc_creatives_relinked', $ad_id, $code )`）重报这条广告带来访客的日期；花费导入后（`do_action( 'pc_creatives_spend_imported', $days, $import_id )`）重报导入涉及的日期。由 `Creatives\Module` 接起来。
- **补登记**会把 `pc_track_visits.creative_code` 一起改成新编号（`WHERE utm_content = ad_id`）；`pc_ad_ads` 由采集（线 1）和花费导入（线 2）两处补，都只在没有这条广告时插入。
- **页面缓存**：`placement`、`ad_name` 和 `utm_*` 一样算追踪参数，带模板参数的广告链接也能用首页缓存。
- **店铺端选项 / 定时任务**：`peptide_core_creatives_spend_pending`（待推送的花费日期）、`peptide_core_creatives_vocab_sync`（词表同步状态）、`pc_creatives_site_versions`（站点版本号）、`pc_creatives_board`（看板阈值）；定时任务 `pc_creatives_vocab_sync`、`pc_creatives_spend_push` 和每日上报的小时任务。
- **总后台选项**：`pchq_creatives_checkpoint`、`pchq_creatives_exports`（检查点导出）；词表版本 `pchq_attribute_vocab_ver` 只在有改动时加一。
- **IP 地理库**：生产需要把 GeoLite2-City / GeoLite2-ASN（或 DB-IP lite）放进 `uploads/peptide-geoip/` 或用常量指定；没有库时地理网络列为空，不报错。DB-IP lite 需要署名。
- **已知缺口**（不影响上线，后续补）：`ip_type` 目前只判断 `vpn / hosting / mobile / residential`，意向分层里“企业 / 高校 IP”一条暂时用不上；前端没有滚动事件，“没有滚动”这条无效规则按“看不出”处理；访问表没有国家列，看板从 `page_view` 事件取国家；个人数据导出还没包含 IP 等新列（合规层面用户决定后期再做）。

## M4 营销与价格引擎（F2 价格营销会话；需求 NF-02 优惠部分、HQ-07；2026-11-14 发布准备时更新）

**发布时的原则（2026-11-14，以这一段为准）**：平台所有者原话“以后遇上类似的问题，优先用站点已经存在的，因为那个是我亲自加的，而不是用你这块后面自己写的，你的是没经历过测试的”。所以凡是和线上已有功能重叠的（满减、优惠码、运费折扣、赠品 / 捆绑、积分券、首单优惠、离开挽回 / 转盘、活动倒计时、营销后台的权限），一律以线上的行为为准，M4 的实现都关在开关后面：

- 价格引擎：`pc_pricing_engine_mode`（默认 `off`）；
- 按系列接收营销活动：`pc_series_campaigns_mode`（默认关）；
- 营销弹窗静默：`pc_marketing_quiet_mode`（默认关，2026-11-14 新加，见下面第 1 点）；
- 投手只读、系列活动只读的那几道加固：只读规则 `Marketing::rules_active()`。投手角色上有 `pc_view_campaigns`（M9 新版才加）或者打开了按系列接收活动时，才算生效（见第 4 点）。

M4 不带任何 bug 修复：离开挽回统计表用的是主线 `f5a0b2d`（日常会话的修法，已经上线），M4 自己的版本删掉了。逐项差异、比对结果、测试和回退办法见 `docs/handoffs/m4-release.md`。以后打开任何一个开关都要平台所有者先定；打开以后和线上的结果不一致时，改 M4 去对齐线上。

本节由 F2 维护，其他会话只读。代码在 `wp-content/plugins/peptide-core/includes/pricing/`，说明见 `docs/handoffs/m4-pricing-engine.md`。

### 一、M4 对外提供

**1. 营销弹窗“静默”开关（给 M11 已购回访用）**

**按店开关，默认关**（2026-11-14 加）：选项 `pc_marketing_quiet_mode`，值是 `on` 才生效；`wp-config.php` 里的常量 `PC_MARKETING_QUIET_MODE` 优先。关着的时候，下面说的东西全都不起作用：`Popups::suppressed()` 永远为假，页头不输出脚本，活动倒计时接口原样返回。首单券条、离开挽回 / 转盘、活动倒计时和线上完全一样，过滤器返回真也一样（比如打开了已购回访的店里的老顾客）。打开要平台所有者先同意（这是功能变化），然后 `wp option update pc_marketing_quiet_mode on`。打开以后才声明功能键 `marketing.quiet`。

过滤器名：**`pc_marketing_popups_suppressed`**。开关打开、并且静默时：首单券条、离开挽回弹窗、抽奖转盘、活动倒计时不出现；满减、优惠码、赠品、运费折扣等价格优惠照常生效，WELCOME5 等券仍然可以手动输入使用。

| 方式 | 用法 | 说明 |
| --- | --- | --- |
| 服务端过滤器 | `add_filter( 'pc_marketing_popups_suppressed', fn( bool $on ) => $on \|\| 是已购顾客 );` | 默认值：浏览器带 cookie `pc_marketing_quiet=1` 时为真。读取用 `\PeptideCore\Pricing\Popups::suppressed()` |
| 前台脚本 | `window.pcMarketingQuiet.suppress( { days: 365 } )`、`.release()`、`.suppressed()`、`.refresh()`、`.onChange( fn )` | 内联在页头，早于各弹窗脚本。**只在这一次请求需要静默时输出**（过滤器为真），所以调用前先判断 `window.pcMarketingQuiet` 存在；要在不静默的页面上也能调用，见下面的 `pc_marketing_quiet_script`。`suppress()` 会写 cookie `pc_marketing_quiet=1`（不填 days 时只到关闭浏览器），下一页一打开就静默，不闪 |
| 前台标记 | `window.pcBuyer = { suppressPopups: true }` 后调用 `window.pcMarketingQuiet.refresh()` | 和开发文档 5.3 FE-10 的写法兼容；在各弹窗脚本运行前设好则不用 refresh |
| 事件 | `document` 上的 `pc:marketing-quiet`，`detail.suppressed` | 状态变化时派发；同时 `<html>` 加 / 去类名 `pc-marketing-quiet` |

页头脚本什么时候输出（2026-11-12 改）：这一次请求静默时（`<script id="pc-marketing-quiet">`，约 1.9KB，带 `window.pcMarketingQuietServer=true`）。不静默的页面不输出，和合入 M4 之前逐字节相同——开关都关着的店、整页缓存里的页面都是这样。只有浏览器才知道要不要静默的模块（要在不静默的页面上调用 `suppress()`）用 `add_filter( 'pc_marketing_quiet_script', '__return_true' );` 让每个前台页面都带上这段脚本；现在没有模块这样用。

约定：带 `pc_marketing_quiet` 或 `pc_buyer` 这类 cookie 的请求不走首页整页缓存（Page_Cache 现有规则），所以缓存里的页面永远是不静默的版本。活动倒计时的接口 `peptide/v1/campaign-countdown` 在静默时两处位置都返回 `visible: false`。

**2. 统一价格计算入口**

```php
$input  = \PeptideCore\Pricing\Cart_Reader::read( WC()->cart ); // 只读，不改购物车
$result = \PeptideCore\Pricing\Engine::price( $input );          // 纯计算
```

`$result`（金额一律是“分”）：

- `entries[]`：优惠明细。每条 `source`（`promotion` 满减 / `coupon` 优惠码 / `first_order` 首单券 / `loyalty` 积分券 / `recommendation` 加购推荐券 / `exit_intent` 离开挽回或转盘发的券 / `gift` 赠品 / `bundle` 捆绑 / `shipping_discount` 运费折扣 / `manual_discount` 手动订单链接里店主配的优惠）、`id`、`label`、`amount_cents`（正数 = 省了多少）、`stacking`（能否和优惠码、满减、赠品、捆绑同时用）。
- `rejected[]`：这一次没生效的优惠和原因（如 `no_stack_with_promotion`、`first_order_only`、`replaced_by_coupon`、`manual_order`）。
- `totals`：`items_subtotal_cents`、`coupon_discount_cents`、`items_total_cents`、`fees_cents`（满减和手动优惠，负数）、`shipping_original_cents`、`shipping_cents`、`total_cents`。
- `supported` / `unsupported[]`：引擎算不了的情况（价格含税、别的插件加的费用或价格过滤器、非原生的优惠码类型）。
- 税：引擎不算税，`total_cents` 不含税。

这是只读的解释和核对用入口，**不要用它的结果去改购物车**；顾客实际支付的金额仍以 WooCommerce 购物车为准。

**3. 价格引擎的按店开关**

- 选项 `pc_pricing_engine_mode`：`off`（默认）/ `shadow` / `on`。`wp-config.php` 里 `define( 'PC_PRICING_ENGINE_MODE', 'off' );` 优先，用于紧急关闭。
- 总后台接口（签名校验同其他 `/hq/*`，用 `Hq_Connector::verify`）：
  - `GET peptide/v1/hq/pricing-engine` → `{ mode, locked, engine, summary: { days, totals: { <mode>: { compared, matched, mismatched, skipped, errors } }, recent: [ { id, created_gmt, kind, mode, legacy_cents, engine_cents, detail } ] } }`
  - `POST peptide/v1/hq/pricing-engine`，请求体 `{ "mode": "shadow" }` → 同上；档位不合法或被常量锁定时 400。
- 命令行：`wp peptide price-engine status | mode <off|shadow|on> | log [--limit=20] | clear`。

**4. 营销活动的权限和只读（HQ-07 店铺端；2026-11-13 平台所有者定：投手可以看只读列表，只读页就是最终做法）**

下面这几个方法的**名字、参数和含义已经和 F5 定下来，不再改**（F5 的菜单和拦截按它们写的）：

| 方法 | 含义 | 谁在用 |
| --- | --- | --- |
| `\PeptideCore\Admin\Marketing::can_view(): bool` | 当前登录的人能不能看营销活动。为真：有 `edit_shop_coupons` 的人、有只读权限 `pc_view_campaigns` 的人（投手的这项权限由 M9 存在投手角色上）。**不看这次请求是哪个页面**，在任何后台页面上问结果都一样，所以可以直接用来决定菜单里出不出现入口 | F5：`Admin_Menus::add_menus()`、`Staff_Buyer::campaigns_visible()` |
| `Marketing::can_manage(): bool` | 能不能新建 / 编辑 / 启停 / 删除。为假：投手（过滤器 `pc_marketing_can_manage` 也不能把投手放开）；营销活动已由系列接管而当前用户没有 `manage_options`。为假而 `can_view()` 为真 = 看到的是只读列表 | F5：`Staff_Buyer::campaigns_read_only()`；F2 的全部保存入口 |
| `Marketing::menu_cap(): string` | `add_menu_page()` 第三个参数用的能力名：能改的人 `edit_shop_coupons`，其余（只读的人）`pc_view_campaigns`。先问 `can_view()` 再注册 | F5：`Admin_Menus::add_menus()` |
| `Marketing::read_only_gate(): bool` | 三个营销页面的入口都先过它。不能改的人：带 `new` / `edit` 的地址、非 GET 的请求返回 403；其余输出只读页并返回 true | F2 的三个页面 |
| `Marketing::view_url(): string`、`Marketing::VIEW_PAGE` | 只读入口的地址 `admin.php?page=pc-promotions`。投手只需要这一个入口（另外两个页面给只读的人的是同一张列表） | F5 的投手菜单可以用 |
| `Marketing::is_buyer( $user = null ): bool` | 是不是投手：带 `pc_media_buyer` 角色、不是管理员。和 `Staff_Buyer::is_buyer()` 口径一致，F2 的代码单独在场时也能判断 | F2 内部 |
| `Marketing::rules_active(): bool`（2026-11-14 加） | M4 的只读规则有没有生效：投手角色上有 `pc_view_campaigns`，或者打开了按系列接收活动。为假时，`can_manage()` 不额外排除投手，下面“三层”也都不起作用，和 M4 上线前一样 | F2 内部（F5 不用调） |
| `Marketing::VIEW_CAP` = `pc_view_campaigns`、`Marketing::BUYER_ROLE` = `pc_media_buyer` | 常量 | 双方 |

**哪些页面有只读视图（F5 按这张表放行，其余仍然 403）**

只读列表实际用到的页面只有三个，名单在代码里是 `Marketing::pages()`：

| 地址（`wp-admin/admin.php?page=…`） | 是什么 | 有没有只读视图 | 投手 |
| --- | --- | --- | --- |
| `pc-promotions` | 满减活动。**只读入口**（`Marketing::VIEW_PAGE`、`Marketing::view_url()`），投手菜单里的“营销活动”指向它 | 有 | 放行不带操作参数的 GET |
| `pc-coupons`、`pc-shipping-discounts` | 优惠码、运费折扣 | 有，和上面是同一张列表 | 同上（放不放行都不影响内容，投手只需要一个入口） |
| `pc-apps` | 应用中心首页（归日常会话） | 没有 | 403 |
| `pc-apps&app=exit-intent` | 离开挽回 / 转盘的设置页 | 没有。只有可编辑的表单；它的内容在只读列表的“离开挽回弹窗 / 抽奖转盘”卡片里 | 403（F2 这边 `Marketing::deny_buyer_settings()` 也拦） |
| `pc-apps&app=first-order-offer` | 首单优惠的设置页 | 没有。内容在只读列表的“首单优惠”卡片里 | 403（同上，另有 `First_Order_Offer_Page::access()`） |
| `pc-promotions-product-offers` | 赠品 / 捆绑（页面归日常会话） | 没有。内容在“满额赠品 / 捆绑组合”卡片里 | 403（页面要 `edit_shop_coupons`，投手没有） |
| `pc-promotions-subscribers`、`pc-promotions-campaigns` | 订阅者、邮件群发（不归 F2） | 没有 | 403（同上） |
| `pc-exit-intent`、`pc-first-order-offer`、`pc-marketing` | **代码里没有这三个页面**（`pc-first-order-offer` 只是一个样式文件的登记名）。离开挽回和首单优惠的设置页是上面 `pc-apps&app=…` 那两行 | 没有 | 403（WordPress 对不存在的页面本来就拒绝，不用单独处理） |
| `edit.php?post_type=shop_coupon`、`post-new.php?post_type=shop_coupon`、`post.php?post=<优惠码>` | WooCommerce 原生的优惠码页面 | 没有 | 403（投手没有优惠码能力） |

也就是说：**投手只需要放行 `pc-promotions`（外加同一张列表的 `pc-coupons`、`pc-shipping-discounts`）的 GET，别的营销相关页面都没有只读视图，一律 403。** 离开挽回、首单优惠、赠品 / 捆绑现在在跑什么，投手在只读列表里都看得到，不需要进它们的设置页。

关于 `pc_view_campaigns`（2026-11-14 改回，**以这一段为准**）：`can_view()` 只认这项权限，不再对投手角色本身为真。原因：M4 要单独先上线（发布顺序 F4 → F2 → F3 → F5），线上投手角色上还没有这项权限，这样 M4 上线时线上投手看到的东西一点不变；等 F5 的 M9 新版把这项权限加到投手角色上（`Staff_Buyer::role_caps()`，F5 分支上已经写好）、并把上表第一、二行放进投手的名单，只读页才对投手出现。所以投手看不看得到只读页，取决于两件事都在：角色上有这项权限，F5 的名单放行。（`773eae8`–`245e277` 之间的版本曾让 `can_view()` 对投手角色本身为真，没有上过线。）2026-11-13 主线并入素材归因之后的现状：主线里的 M9 还是没有放行营销页面的较早版本，所以“主线 + M4”上投手打开 `pc-promotions` 是 403；F5 分支上放行它的提交和主线有 3 个文件冲突（`class-staff-buyer.php`、`buyer-admin.css`、`m9-buyer-scope-pure.php`，素材归因也改了投手菜单），要 F5 合主线后才能进候选。

**投手改不了任何营销活动：服务端的三层**（哪一层单独在都拦得住，不靠隐藏按钮）

这三层，以及 `can_manage()` 里排除投手那一条，**只在只读规则生效时起作用**（2026-11-14 起，按“和线上重叠的以线上为准”的原则）。判断用 `Marketing::rules_active(): bool`：投手角色 `pc_media_buyer` 上有 `pc_view_campaigns`，或者 `pc_series_campaigns_mode` 是 `on`。规则没生效时（M4 单独上线、线上的投手角色还没有这项权限），投手能做什么完全由线上已有的权限和 M9 决定，和 M4 上线前一样。F5 的 M9 新版给角色加上这项权限后，下面三层自动生效，F5 那边不用再调什么。

1. **能力**：投手没有任何优惠码相关的能力（`Marketing::buyer_caps()`，挂在 `user_has_cap` 上，优先级 9999，后台、admin-ajax、REST 每个请求都生效）。满减活动、赠品 / 捆绑的文章类型用的也是这组能力。所以 WooCommerce 原生的优惠码列表、编辑页、快速编辑、批量操作，REST 接口 `wc/v3/coupons`（查、建、改、删、批量），以及所有先查 `edit_shop_coupons` 的入口（赠品 / 捆绑页面的保存和删除、首单优惠）都拒绝，即使这个账号原来勾过“营销”模块或被误加了店长角色。别的能力（订单、数据等）不动。
2. **入口**：F2 名下 12 个提交入口（满减 / 优惠码的保存、启停、批量、页面设置；运费折扣的保存、启停、批量；首单优惠）都先过 `Marketing::guard()` 或 `can_manage()`，在校验 nonce 之前就返回 403。离开挽回 / 转盘的保存（`admin_post_pc_app_exit_save`）F2 在优先级 0 先拦一道 403。三个营销页面带 `new` / `edit` 或非 GET 返回 403。离开挽回 / 首单优惠的设置页（`pc-apps&app=…`）没有只读视图，投手打开直接 403（`Marketing::deny_buyer_settings()`）。
3. **保存方法本身**：`Product_Offers::store()`、`Exit_Intent::save()` 对投手返回错误；赠品 / 捆绑的文章对“登录了但不能管理营销活动的人”删不掉（`pre_delete_post` / `pre_trash_post`）。没有登录用户的调用（系列下发、总后台的应用开关、定时任务）不受影响。

F5 的拦截（`Staff_Buyer::classify()`，见 M9 一节）排在这些前面，是第四层。

**只读页“当前在跑的活动”**（给投手写广告文案用）

- 每个活动一张卡片：**条件（顾客要做到）** 和 **优惠（顾客能得到）** 两栏，下面是哪些商品、哪些顾客、顾客怎么用（优惠码还写了广告链接带券的写法 `?pc_coupon=码`）、和别的优惠能不能一起用、次数限制、有效期、网站上的写法（顾客在网站上看到的英文）。
- 页面上**没有表单、按钮、链接，不调任何接口**；不显示内部编号、顾客邮箱、访客编号、转盘的中奖概率。投手看不到“来源”一行（不是投手的只读用户能看到是系列下发还是本店自建）。
- 以后只读页如果要加带 `action` 参数的筛选或要调接口，先告诉 F5（投手的名单里没有营销相关的接口）。
- 数据：`\PeptideCore\Pricing\Campaigns::running()`，每条 `kind`、`id`、`name`、`rule`、`ends`（UTC 秒，0 = 长期）、`source`（`series` / `store`），原有这六项不变；新增 `plain`（大白话：`kind`、`pairs`（[条件, 优惠] 的列表）、`scope`、`who`、`how`、`stack`、`limits`、`period`、`site`）。`Campaigns::grouped()` 按只读页的顺序分组。文字由 `Pricing\Campaign_Text` 生成。投手首页上读 `running()` 的地方不受影响。

**系列下发的活动**：`Marketing::locked( $kind, $id )`（`promotion` / `coupon` / `ship_discount`）：这条活动是不是系列统一下发的。**带系列标记的活动对所有人只读（管理员也一样）**：三个营销页面的编辑、保存、启停、批量，WooCommerce 原优惠券编辑页，首单优惠配置页，离开挽回的保存（`Exit_Intent::save()`，登录用户）都会拒绝；总后台的应用开关（没有登录用户）仍可关掉弹窗。要改只能在总后台改系列活动。


**5. 按系列接收营销活动（已实现，`\PeptideCore\Pricing\Series_Campaigns`，接 M3 的四个钩子）**

**按店开关，默认关**（2026-11-14 加）：选项 `pc_series_campaigns_mode`，值 `on` 才接收；`wp-config.php` 的常量 `PC_SERIES_CAMPAIGNS_MODE` 优先。关着时四个钩子都原样放行：系列同步里的活动部分和 M4 上线之前一样记 `unhandled`、`campaign_ver` 不记；带系列标记的活动不锁（`Marketing::locked()`、`Series_Campaigns::setting_locked()` 都为假）；`Series_Campaigns::managed()` 为假（店铺后台不因此变只读）；不声明 `series.campaigns`。打开：`wp option update pc_series_campaigns_mode on`；关回去：`wp option update pc_series_campaigns_mode off`（已经下发的活动保留原样，只是不再锁、不再接收新的）。要等总后台的系列活动编辑界面和平台所有者决定之后再打开，下面的规则都是“打开以后”的行为。

M3 定的外形：`{ kind, key, active, config }`。M4 定的 `config`（没写的字段用该功能的默认值；看不懂的字段忽略）：

| kind | 写到哪里 | config |
| --- | --- | --- |
| `promotion` | 满减活动 | `name`（≤50 字，必填）、`type`（`spend_amount` / `spend_percent` / `qty_amount` / `qty_percent` / `nth_percent` / `bundle_price` / `buy_x_free_y`）、`tiers: [ { min, value } ]`（最多 5 档）、`unlimited`、`start` / `end`（UTC 秒，0 = 不限）、`scope`（`all` / `categories` / `products`）、`categories: [ 专辑名称或别名 ]`、`products: [ 系列商品键 sku ]`、`sort`、`exclude_sale`、`with_coupons`、`public`、`text` |
| `coupon` | WooCommerce 优惠码 + 本插件的规则 | `code`（必填）、`description`、`type`（`percent` / `fixed_cart` / `fixed_product`）、`amount`、`start` / `end`、`usage_limit`、`usage_limit_per_user`、`limit_usage_to_x_items`、`individual_use`、`free_shipping`、`exclude_sale_items`、`minimum_amount`、`maximum_amount`、`products` / `excluded_products: [ sku ]`、`categories` / `excluded_categories: [ 专辑 ]`、`strategy`（`stack` 默认 / `no_stack` / `replace`）、`first_order`、`min_spend`、`min_qty`、`public`、`public_title`、`public_text` |
| `ship_discount` | 运费折扣 | `name`（必填）、`mode`（`fixed` / `stages`）、`kind`（`amount` / `percent`）、`value`、`start` / `end`、`stages: [ { start, end, value } ]`、`member_day`、`member_days: [ 1–7 ]` |
| `first_order` | 首单优惠（一家店一份） | `coupon`：绑定哪张优惠码（同一批里的 `coupon`，或本店已有的）。这张券必须是按比例 5%、`first_order: true`、`usage_limit_per_user: 1`、`strategy: stack`、不是 `individual_use`、不含 `free_shipping` |
| `exit_intent` | 离开挽回弹窗（一家店一份） | `type`（`coupon` / `message`）、`title`、`text`、`button`、`decline`、`color`、`coupon`（优惠码）、`link`、`desktop_exit`、`mobile: [ back / scroll / return / idle / bottom ]`、`idle_seconds`、`min_seconds`、`pages`、`audience`、`frequency_days` |
| `wheel` | 同一个弹窗的转盘类型 | 同上的触发和文字字段，另有 `spin_button`、`win_title`、`win_text`、`lose_text`、`prizes: [ { label, coupon, weight } ]`（2–8 个）。`exit_intent` 和 `wheel` 同时只能启用一个 |
| `upsell`、`progress_bar` | — | **这一版不接**，结果里标 `unsupported`（见第二部分给日常会话的第 4 条） |

规则：

1. **引用**：商品用系列商品键（按 `_pc_series_sku` → `_pc_spu` → SKU → `slug:<别名>` 找），专辑用名称或别名。找不到的记在结果的 `missing` 里；指定的商品 / 专辑一个都找不到时这条标 `skipped`。
2. **标记和只读**：满减、优惠码带 meta `_pc_series_campaign = "<kind>/<key>"`；运费折扣、首单、离开挽回是各自设置里的 `series_key`。带标记的对所有人只读（见第 4 点）。
3. **全量对齐**：带标记但这次没下发的改为停用，不删除（订单上还引用）。本店自己建的活动不动。首单优惠和离开挽回一家店只有一份：系列下发时接管，停用时还原成接管前本店自己的设置。
4. **冲突**：和本店自建且启用的满减（同范围、时间重叠）或同码的自建优惠券冲突时，不覆盖本店的，这条标 `conflict`，其余照常。
5. **整体检查**：任何一条内容不合规（`error`）就一条都不写，`pc_series_apply_campaigns` 返回 `WP_Error( 'pc_series_campaigns_invalid' )`（HTTP 422，`data.report` 里有每条的原因），店铺不记 `campaign_ver`。`skipped` / `conflict` / `unsupported` 不算错误。
6. **幂等**：内容和启用状态都没变的条目标 `unchanged`，不重复写。
7. **返回 / 差异**（`applied.campaigns` 和 `diff.campaigns` 同一个外形）：`{ summary: { <状态>: 条数 }, items: [ { kind, key, status, message?, missing? } ] }`。下发时的状态：`applied` / `unchanged` / `disabled` / `skipped` / `conflict` / `unsupported`；试运行时是 `create` / `update` / `unchanged` / `disable` / `skipped` / `conflict` / `unsupported` / `error`。
8. **导出**（母版店，`pc_series_export_campaigns`）：未结束的满减、非个人的优惠码（不含积分兑换券、限定邮箱的券、已过期的券）、非指定顾客的运费折扣、首单优惠、离开挽回。本店自建的条目的 `key`：满减 `p<ID>`、优惠码用码本身（不合规时 `c<ID>`）、运费折扣 `s<ID>`、首单和弹窗 `main`。
9. **快照 / 恢复**：快照里记“当时接管着的系列活动”（`$snapshot['campaigns'] = { ver, items, store }`）；恢复 = 按那份清单重新对齐（之后新下发的停用，当时在跑的按当时的内容恢复）。快照里没有 `campaigns` 时恢复不动活动。
10. 店铺选项 `pc_series_campaigns`（不自动加载）记当前接管着的清单和接管前本店自己的首单 / 弹窗设置。

**6. 功能键（请 F1 汇总进功能键表）**

| 功能键 | 版本 | 含义 |
| --- | --- | --- |
| `pricing.engine` | 1 | 支持价格引擎三档开关和 `/hq/pricing-engine` |
| `marketing.quiet` | 1 | 营销弹窗静默已打开。**只在这家店打开了 `pc_marketing_quiet_mode` 时才声明**（2026-11-14 起） |
| `campaigns.readonly` | 1 | 投手只读、系列下发的活动只读已生效。**只在 `Marketing::rules_active()` 为真时才声明**（2026-11-14 起） |
| `series.campaigns` | 1 | 能接收系列下发的营销活动。**只在这家店打开了 `pc_series_campaigns_mode` 时才声明**，总后台据此只给打开了的店发活动 |

**7. 数据表和选项登记（给 F1 的脱敏快照）**

| 表 / 选项 | 有没有顾客资料或密钥 | 建议 |
| --- | --- | --- |
| `{prefix}pc_m4_shadow_stats`（每天每档的核对次数） | 没有 | truncate |
| `{prefix}pc_m4_shadow_log`（不一致 / 出错 / 跳过的明细，最多 300 条） | 没有邮箱、姓名、地址；有商品 ID、数量、单价、优惠码（积分券只记 ID） | truncate |
| 选项 `pc_pricing_engine_mode`、`pc_series_campaigns_mode`、`pc_marketing_quiet_mode`、`pc_m4_db`、`pc_series_campaigns` | 没有 | keep（快照导入沙盒后建议把三个开关设回 `off`） |
| 优惠码 / 满减的 meta `_pc_series_campaign`、`_pc_series_campaign_hash` | 没有 | keep |

两张表是懒建的（第一次有记录要写时才建，版本选项 `pc_m4_db`），关着时不存在。版本号在而表不在时（整店复制会造成这种情况，见下面“给 F1”第 9 条）第一次写入会自动补建，查看和清空当作“还没有记录”。离开挽回的统计表 `{prefix}pc_app_stats`（版本选项 `peptide_core_app_stats_db`，快照里已登记为 keep）用的是主线 `f5a0b2d` 的修法（日常会话，已上线）：`Exit_Intent::installed()` 仍然只看版本号，`record()` 写入时如果报“表不存在”，就建表再写一次。M4 原来的版本（让 `installed()` 同时检查表在不在）2026-11-14 合主线时删掉了，以线上为准。

**8. 沙盒 / 本地站点上的验证脚本（真实 WordPress + WooCommerce）**

```bash
M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-shadow-walkthrough-native.php   # 影子档和开档走查
M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-series-campaigns-native.php     # 按系列接收活动
M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-buyer-readonly-native.php       # 投手对营销活动只读（2026-11-13）
M4_WALKTHROUGH=sandbox M4_COMPARE_OUT=/tmp/x.json wp eval-file wp-content/plugins/peptide-core/tests/m4-release-compare-native.php   # 发布前比对：线上代码和 M4 各跑一次，比两个文件
```

这几个脚本都会临时建数据、结束时删除并恢复选项；域名里不带 `sandbox` / `localhost` / `local` / `test` / `staging` 时拒绝运行。完整步骤见 `docs/handoffs/m4-sandbox-walkthrough.md`。

### 二、需要其他会话配合

**给日常会话**

1. **加购推荐被手动订单关掉了——已修复（主线 `bd4e355`，2026-11-12，日常会话）。** 下面是当时的问题和修法，留作记录；F2 同步后在本机真实站点上确认三档下 `supported_hooks()` 都是 true。主线从 `1fcfea5`（11-10）起，`Manual_Orders::register_hooks()` 在每个请求上往 `woocommerce_before_calculate_totals`（9999）、`woocommerce_cart_calculate_fees`（99）、`woocommerce_coupon_is_valid`（9999）加了回调；加购推荐的报价只认白名单里的回调，于是 `Activity\Quote_Cart::supported_hooks()` 恒为 false，`Activity\Recommendations` 一律隐藏，`Api\Activity_Listener_Controller` 返回 409。F2 在本机真实站点（WordPress 7.1.4 + WooCommerce 11.2.1，主线 976c1fa）上确认：摘掉这三个回调后恢复为 true。优惠码那一半（`Recommendation_Coupon` 的白名单）F2 已改；**`activity/class-quote-cart.php` 请改两处**：

```php
// unsupported_hook() 的 $allow：
'woocommerce_cart_calculate_fees'      => array( Promotions::class . '::apply_to_cart', \PeptideCore\Manual_Orders::class . '::apply_fees' ),
'woocommerce_before_calculate_totals' => array( Product_Offers::class . '::sync_cart', \PeptideCore\Manual_Orders::class . '::apply_prices' ),

// supported() 逐行检查的地方：手动订单链接的购物车不报价（报价不走 apply_fees，算出来的满减和实际不一致）
if ( ! $product instanceof \WC_Product || array_key_exists( Product_Offers::CART, $item ) || ! empty( $item['pc_manual'] ) || ... ) { return false; }
```

   核对办法：任一店 `wp eval 'var_dump( \PeptideCore\Activity\Quote_Cart::supported_hooks() );'` 应为 `true`。2026-11-12 修复前 F1 在生产 6 家店只读查过，全部是 `false`；修复上线后日常会话报告 6 家店和 6 套后台都是 `true`（F2 没有在生产上核实）。

2. 以后往这些钩子上加回调前先看 `Quote_Cart::unsupported_hook()` 的清单：`woocommerce_cart_calculate_fees`、`woocommerce_before_calculate_totals`、`woocommerce_after_calculate_totals`、`woocommerce_calculated_total`、`woocommerce_coupon_*`、`woocommerce_product_get_price` 等。
3. `admin/class-product-offers-page.php`（赠品 / 捆绑）和 `admin/class-apps-page.php` 里离开挽回的设置页：界面上对 `! Marketing::can_manage()` 的人隐藏保存入口（服务端 `Product_Offers::store()` 已拒绝；离开挽回由系列下发时 `Exit_Intent::save()` 会拒绝登录用户）。2026-11-13 起 F2 在自己的文件里又补了三处，你的文件不用改：`Product_Offers_Page::delete()` 只查了 `edit_shop_coupons`，现在不能管理营销活动的登录用户删不掉赠品 / 捆绑（`Product_Offers::guard_delete`，挂在 `pre_delete_post` / `pre_trash_post` 上；这时你的页面会照常跳回列表，但文章还在）；`admin_post_pc_app_exit_save` 上 F2 在优先级 0 对投手直接返回 403；投手没有任何优惠码相关的能力，所以 `Product_Offers_Page` 的保存和删除对投手都是 403。
4. 加购推荐和进度条的设置（`Activity\Store`）现在只能由登录的管理员保存（`Store::save()` 检查 `can_manage()`）。要让系列下发 `upsell` / `progress_bar`，需要一个不依赖登录用户的写入入口（比如 `Store::apply_series( array $config )`），其中活动和优惠码用代号引用。有了以后 F2 接上。
5. 手动订单（`class-manual-orders.php`）和运费预览（`class-ship-preview.php`）直接用到 M4 的这些方法，以后删旧算法时 F2 会保留它们的签名：`Promotions::discounts()`、`Promotions::active_ids()`、`Promotions::FEE_META`、`First_Order_Offer::coupon()`、`Shipping\Discounts::applicable()`、`Shipping\Discounts::discounted()`。引擎已经认识手动订单链接的购物车（店主配的价格、运费、自定义优惠、是否带店铺满减 / 首单）。如果手动订单的配置再加影响金额的字段，请告诉 F2。
6. `tests/smoke-shipping-plans.php` 加了一处（开头 4 行）：本次运行里用 `pre_option_peptide_core_shipping_discounts` 当作没有店铺的运费折扣。原因：真实店铺的数据里有运费折扣时，第 6 步的方案运费 30 / 45 / 110 会被再打折，用例在沙盒基线里因此失败（29 / 2）。方案本身的逻辑没有问题，`Shipping\Plans` 没有动。

**给 F1（发布、连接）**

1. 功能键见上面第 6 点（已经按 `功能键 => 版本号` 接在 `pc_hq_capabilities` 上）；数据表登记见第 7 点。
2. 巡检（**F1 已实现**，2026-11-12，F1 分支 `d2f05cf`）：`/hq/status` 的 `modules.pricing_engine` 带 `\PeptideCore\Pricing\Runtime::status_brief()` 的结果（`{ mode, today: { compared, matched, mismatched, skipped, errors } }`，只读两个选项和一张小表，没有记录时不查表）；总后台巡检类型 `pricing_mismatch`（显示名“价格引擎核对不一致”），店铺声明了 `pricing.engine` 且带了简报才报，`mode` 不是 `off` 且今天 `mismatched` 或 `errors` 大于 0 时告警，`off` 或恢复一致时关闭。F2 核对过：字段和 `status_brief()` 的返回一致。`status_brief()` 的返回外形以后只加不改。
3. 总后台给每家店一个三档开关（调 `POST /hq/pricing-engine`），只对声明了 `pricing.engine` 的店显示——**还没有人做**（F1：要动总后台页面，不在 F1 名下）。在那之前用命令行或常量切档位。
4. 沙盒走查：按 `docs/handoffs/m4-sandbox-walkthrough.md`。
5. 建议把 `\PeptideCore\Activity\Quote_Cart::supported_hooks() === true` 加进发布前的 critical-path 检查（见给日常会话的第 1 条：这次就是没人发现）。
6. 测试登记（2026-11-13：又追加了一条 `m4-campaign-text-pure`，共 6 条；第三个 `*-native.php` 是 `m4-buyer-readonly-native.php`）：5 条纯测试已按 `"status": "module-gate", "module": "M4"` 写在 suites 文件末尾；两个 `*-native.php` 要在装好 WooCommerce 的站点上用 `wp eval-file` 跑，不在纯测试编排里。
7. **冒烟基线里归 F2 的 5 个用例已处理**（2026-11-12，提交 `692f00c` 起）：`smoke-exit-intent`、`smoke-marketing`、`smoke-promotions`、`smoke-pricing`、`smoke-shipping-plans`。根因和预期结果见 `docs/handoffs/m4-sandbox-walkthrough.md` 第 2b 步。**F1 已在沙盒重跑（候选 `c5fb1d8`，F2 `27b3c37`）：5 个加 `smoke-data-apps` 全部通过。** 它们在不含本分支的主线上仍会失败，所以 `tests/smoke-suites.json`（在 F1 分支上，F2 没有动）先保持 `skipped` 并注明“F2 合进主线时改回 gate”——本分支合进主线的那次发布里请一并改回。F4 名下的 `smoke-data-apps`（13 / 8）和 `smoke-exit-intent` 是同一个根因，2026-11-14 起由主线 `f5a0b2d` 的修法解决（M4 不再带自己的修复，`smoke-exit-intent` 也恢复成主线的版本，37 / 0），本机 21 / 0。
8. **生产只读核对（6 家店各两条命令）——F1 已核对（2026-11-12），结果见下**：

```bash
wp db query "SHOW TABLES LIKE '%pc_app_stats'"
wp option get peptide_core_app_stats_db
```

   **2026-11-14 更新：这一条已经由线上解决，M4 不再带这个修复。** F1 11-14 核对，6 家店都已经有这张表，但都是 0 行。F2 查过：计数代码在线上主线 `24d1671` 上能写进去；0 行最可能是弹窗没打开过（默认关），员工自己测也不计数。分辨办法见 `docs/handoffs/m4-release.md` 第 9 节。 日常会话在主线 `f5a0b2d` 修好并上线（`record()` 遇到表不存在就建表重试），五家子店的表已经建好（见 `docs/handoffs/production-rollout-plan-2026-11-10.md` 的上线记录）。M4 合主线时用了这份线上的代码，自己的版本删掉了；M4 上线不会再因为这张表让任何店的数据变化。下面是当时的记录。

   第一条为空而第二条是 `1` 的店：离开挽回 / 转盘的弹出、领取、抽奖、订单数一直没有记上（弹窗和优惠码本身不受影响，订单上的标记和备注也照常写）。（当时的说法：M4 合进去以后，这家店下一次弹窗事件会自动补建表并开始计数，之前的数字补不回来。）F2 当时是从沙盒基线的失败项反推的（在本机把表删掉、版本号留着，`smoke-exit-intent` 和 `smoke-data-apps` 的失败项和沙盒完全一样）。

   **F1 的核对结果**：s0001 表在、版本号 1（正常）；s0006 / s0007 / s0008 / s0009 / s0010 表不在、版本号 1——这五家店的离开挽回数据从开店起没有记上。不想等 M4 发布的话，现在就可以在这五家店各执行一次 `wp eval '\PeptideCore\Exit_Intent::install();'`：只建这一张表（`dbDelta`，可重复执行），线上现有的代码就有这个方法；F2 在本机用主线 `bd4e355`（不含 M4）试过，执行后计数恢复。这是生产上的写操作，要平台所有者同意、由 F1 执行。
9. **整店复制会留下“版本号在、表不在”**：`tools/deployment/provisioning/clone.py` 整表复制 `wp_options`，再把目标店原有的 `*_db` / `*_version` 等选项放回去（`KEEP_OPTION_PATTERN`）；目标店原来**没有**的那些，来源店的值就留了下来，而对应的表不在复制范围里。懒建表的模块（第一次写入才建表）都会中招。建议在第 5 步之后删掉“匹配 `KEEP_OPTION_PATTERN`、目标店原来没有”的选项，让各模块按自己的流程重新建表。F2 名下的两处（`pc_app_stats`、M4 的核对日志表）已经改成不依赖这一点；其他懒建表的模块 F1 在生产 6 家店查过（`pc_recovery`、`pc_mail_outbox`、`pc_email_log`、`pc_px_log`、`pc_activity`）：表和版本号都在，没有中招。复制脚本的修正 F1 记成了自己的待办（M1-5 之后）。

**给 F4（数据）**

1. `smoke-data-apps`（基线 13 / 8）失败是因为沙盒里 `pc_app_stats` 表不在而版本号在：用例开头的 `if ( ! Exit_Intent::installed() ) { Exit_Intent::install(); }` 被版本号骗过去了。**2026-11-14 更新**：F2 原来改 `Exit_Intent::installed()` 的做法已经撤掉，改用主线 `f5a0b2d` 的线上修法（`installed()` 仍然只看版本号，`record()` 遇到表不存在就建表重试）。`Data\Apps` 里 `installed()` 的含义和线上一样。你的用例和 `Data\Apps` 不用改；本机在“表不在”的库和干净的库上都是 21 / 0。

**给 F3（系列）**

1. 四个钩子已接，`config` 的定义见上面第 5 点。总后台的系列活动编辑界面请按这份字段做（或先用 JSON 文本框）。`Series_Campaigns::replace()` 存之前如果想先检查，可以在一家店上用 `dry_run` 看 `diff.campaigns`。
2. 用你分支上的 `Series_Sync::sync()` 在本机真实站点走过一遍（试运行、下发、同版本跳过、对齐、`restore()`、不合规返回错误），结果符合约定。
3. 系列活动的标记用的是 `_pc_series_campaign`（值 `kind/key`），没有用你商品上的 `_pc_series_managed`，两者不冲突。
4. `kind` 里 `upsell`、`progress_bar` 这一版标 `unsupported`（不算错误，`campaign_ver` 照记）。如果你希望“有不支持的条目时不记版本、等店铺升级后重发”，告诉 F2 改成返回错误。
5. 母版店：按你的约定总后台不给母版店发活动；F2 这边母版店也不算被接管，店铺后台照常可以建活动。

**给 F5（投手后台、已购回访）**

1. 投手角色名用 `pc_media_buyer`，给它只读权限 `pc_view_campaigns`，**不要**给 `edit_shop_coupons`。你已经做了（权限存在角色上）。**这项权限必须在**：2026-11-14 起 F2 的 `can_view()` 只认它（M4 先上线时线上投手不能因此有任何变化），角色上没有它，投手就看不到只读页。
2. `Admin_Menus::add_menus()` 里营销活动菜单的显示条件用 `Marketing::can_view()`、权限参数用 `Marketing::menu_cap()`。你已经做了。判断入口的方法以本节第一部分第 4 点的表为准，签名不再改。
3. 已购回访用服务端过滤器 `pc_marketing_popups_suppressed`（你的 `Buyer_Status::mute` 已经这样做）。页头的 `window.pcMarketingQuiet` 现在只在静默的请求里输出，你现有的写法 `if ( window.pcMarketingQuiet && window.pcMarketingQuiet.refresh )` 不用改；不要在不静默的页面上无条件调用它。**2026-11-14 起，静默还要这家店打开 `pc_marketing_quiet_mode` 才生效**（见第一部分第 1 点）。没打开时，已购老顾客照样看得到首单券条、离开挽回 / 转盘、活动倒计时，和现在线上一样；你那边的过滤器、`window.pcBuyer` 写法都不用改。打不打开由平台所有者定，由 F1 按店执行。
4. **投手看营销活动的分工**（和你 M9 一节“投手看营销活动”那一条对应）：只读页面和所有保存入口的拒绝归 F2，菜单、页面标题和投手的地址名单归 F5。2026-11-13 F2 把两个分支在本机合起来（只在临时目录里合，没有动任何分支；你的分支取到 `dcda12f`），用 `wp peptide buyer on` 开出来的真投手账号在浏览器里走了一遍：`pc-promotions` / `pc-coupons` / `pc-shipping-discounts` 是 200 的只读页；新建 / 编辑地址、向页面 POST、9 个 admin-post 提交、WooCommerce 的优惠码页面、赠品 / 捆绑页面、应用中心和离开挽回设置，共 22 个地址全部 403；换成管理员看同一个地址是原来可以管理的列表。合并后的代码上你的 `m9-buyer-campaigns-pure`（87）、`m9-buyer-scope-pure`（249）、`m9-buyer-orders-pure`（33）和 F2 的 `m4-campaigns-readonly-pure`（当时 72 项，之后加到 77 项）、`m4-campaign-text-pure`（44 项）全过。代码文件合并没有冲突（只有 `docs/CONTRACT.md` 和 `tests/data-observations-suites.json` 两个都在末尾追加的文件要手工取并集）。
5. 你的投手页面上方的开店向导提示条（“继续设置”）会出现在只读页标题下面，这是 WordPress 把提示挪到标题后面的结果，F2 的卡片里没有任何链接。
6. **投手首页的“当前在跑的活动”卡片（2026-11-14 F2 复查发现，请你或日常会话定）**：主线里的 `admin/class-buyer-home.php` 只要发现 `Pricing\Campaigns::running()` 存在就显示这张卡片。所以 M4 单独上线时，线上投手的首页就会多出它，而营销只读页要等 M9 新版才出现，两者不同步，也打破了“M4 上线一点不变”。本机实测：同一份数据，线上代码没有卡片，M4 有（7 个活动）。建议在 `render_campaigns()` 开头加 `if ( ! current_user_can( 'pc_view_campaigns' ) ) { return; }`，让卡片和只读页一起等这项权限。F2 不改你的文件，`Campaigns::running()` 的签名和返回也不变。如果所有者同意 M4 上线时就显示这张卡片，这条作废。

### 三、历史

2026-11-10 的版本里这一节第三部分是“系列活动下发的约定（待 F3 定稿）”，当时设想按数字 `id` 和 `_pc_series_managed` 标记。F3 定稿后改为按 `kind + key` 识别、标记用 `_pc_series_campaign`，以本节第一部分第 5 点为准。
