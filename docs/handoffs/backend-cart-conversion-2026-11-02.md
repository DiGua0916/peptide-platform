# 购物车转化：后端与前端接入交接

2026-11-02 后续更新：用户已授权把“活动监听”的批准效果直接装入插件，现有真实购物车组件已接通，见 [安装与验收](backend-activity-listener-install-2026-11-02.md)。以下旧记录中活动监听“仍待前端UI接入”的部分由该交接覆盖；加购反馈和最近浏览的原分工没有被扩大为本轮已实装。

2026-11-02 · Codex · CART-CONVERSION-01。需求来源为 `D:/Codex/Tasks/2026-11-02/product-dialog-design-demo/outputs/购物车转化插件_后端需求_20261102.txt`。本轮复用 WooCommerce 的购物车和已有活动监听，新增应用配置与浏览器最近浏览入口；不建立第二套加购、价格、促销或客户画像系统。

本文件先记录已经核实的源码契约及仍需前端接入的部分；末尾的验收栏区分只读审查、隔离测试与真实页面验证。后端存在接口不代表正式页面的动效、布局或弹窗记录已经接通。

## 职责与现有数据

商品继续以盒价、实际规格和盒数显示；不新增每瓶价格，不推荐用途组合，不默认加购。前端的独立 Demo 位于 `D:/Codex/Tasks/2026-11-02/product-dialog-design-demo/outputs/demo`，本机 17863 预览只用于设计验收。17863 与 WordPress 不同端口也属于不同源，真实历史接口应在同源 WordPress 页面联调，不为 Demo 放宽同源保护或复制登录凭据。正式代码不能把 Demo 的本地购物袋、模拟进度和历史存储适配器当成真实交易或浏览记录。

三项内容分别为：已有“活动监听”、新增“加购反馈”配置、扩展同一份签名最近浏览记录。前端窗口负责 UI、动效、弹窗和请求生命周期；本后端不代改主题、现有 Quick View 交互脚本或前端工作树。

## 活动监听：直接复用已完成契约

- 管理：`admin.php?page=pc-apps&app=activity-listener`。
- 公开配置：`GET /wp-json/peptide/v1/activity-listener`。
- 当前会话进度：原生 `GET /wp-json/wc/store/v1/cart` 的 `extensions.peptide_core.activity_listener`。
- 完整契约与实际已有验证见 [backend-activity-listener-2026-11-02.md](backend-activity-listener-2026-11-02.md)。本轮不另建档位、金额计算或加购 API，不替运营选择或应用活动。

使用服务端 `actualDiscount/discountApplied/nodes/next/progressPercent/stateKey/revision/promotionRevision`。金额用最小货币单位字符串，件数用整数；最终优惠来自 Woo 已结算费用。某节点门槛达到，不等于已经获得正优惠；不能用页面划线价或浏览器自己计算的金额触发成功。

仅在购物车页显示，默认挂载隐藏；`visible=true` 后才渲染。首份状态、重复响应、刷新和规则版本变化先建立基线，不庆祝；减量/失效撤销成功样式。一次跨多个档位只反馈一次。减少动态模式、隐藏或卸载时停止效果。有限档位完成后不归零，上不封顶不能伪装永久完成。

**用户本轮较新的明确决定覆盖附件旧视觉建议：**不要营销大标题或说明文案，以客户自己的当前进度、距离下一档差额、门槛与优惠标记为核心；达标时要能看清真正跳出的小笑脸和小烟花，不能过小过短。具体大小、节奏、布局由前端实现，仍须遵守减少动态和不遮挡购买操作。此决定不改变 `nodes/next` 等后端 DTO，也不将独立预览误称正式主题已接入。

## 加购反馈：配置协议

应用标识为 `cart-feedback`，管理入口为 `admin.php?page=pc-apps&app=cart-feedback`。配置 v1 的完整对象：

```json
{
  "version": 1,
  "enabled": true,
  "successDurationMs": 1400,
  "bagPulseEnabled": true,
  "successText": "Added",
  "pendingText": "Adding…"
}
```

这些是默认值，不是已往某个站点保存的新设置。`successDurationMs` 只接受 800–2200 的整数；两项文本规范化为空白合并后的纯文本，分别 1–60 个 Unicode 字符，不接受 HTML。开关必须为布尔值；完整替换须包含所有字段，未知字段或不完整对象拒绝，不默默补出一个启用状态。减少动态、原位停留和错误显示是固定保护，不提供运营关闭开关。

| 路由 | 行为 |
| --- | --- |
| `GET /peptide/v1/cart-feedback` | 本站已应用公开配置，附 `configId/revision/updatedAt`；不含草稿或客户购物车 |
| `GET /peptide/v1/cart-feedback/admin` | 授权读取草稿、已应用配置、编辑修订和默认值 |
| `PUT /peptide/v1/cart-feedback/admin` | `{config,expectedRevision}`，仅保存草稿 |
| `POST /peptide/v1/cart-feedback/publish` | `{config,expectedRevision}`，原子保存并应用 |

管理接口须登录、有 `manage_woocommerce`，平台员工另须 `apps` 授权，且提供 `X-WP-Nonce` 的 `wp_rest` nonce。JSON 请求上限 8 KiB，拒绝跨站、客户或购物车选择参数。草稿与已应用快照分开，CAS 修订冲突返回 409；同一已应用配置重复提交不增加公开修订。公开响应 `Cache-Control: no-store`，存储异常时 API 报错、服务端 helper 保守关闭；原有 Woo 加购仍可使用。

## 原生加购：核实结果及前端真实缺口

已只读核实生产插件 `includes/modules/class-showcase-quick-view.php` 和 `assets/showcase-quick-view.js`，以及前端工作树 `wp-content/themes/peptide-site/assets/cart-drawer.js`、`home-quick-view.js`：

1. 当前 Quick View 已使用 `GET /wc/store/v1/cart` 和 `POST /wc/store/v1/cart/add-item`，同源凭据、`cache:no-store`，从原生购物车响应刷新 `Nonce`，写请求提供该 nonce；登录时另附 WP REST nonce。
2. 提交时已有操作快照：父商品 `parentId`、实际 `payload.id`（简单商品或选中变体）、`payload.quantity`、变体属性、所属视图 token 和 `mode`。客户提交后切换规格，不能改写已发请求的快照。
3. 成功分支要求原生响应 HTTP 201、合法的完整购物车形状，以及选中 SKU 在响应购物车中存在且数量有效。完整响应已有 `items/items_count/totals/extensions`；无需新增另一条加购接口或再次调用 add-item 来获得反馈。
4. 同一脚本已经阻止 `pending/checking/unknown` 时重复提交，且用本地 mutation generation 避免把过期回包当最新购物车。普通读取不属于 mutation；抽屉改数量、删商品也会触发同名购物车事件。

现有成功事件的准确结构是：

```js
document.dispatchEvent(new CustomEvent('vc:cart-updated', {
  detail: { source: 'showcase', mutation: true, stale: false, cart }
}));
```

它目前**没有** `requestId/action/productId/variationId/quantityAdded`。私有 `op.token` 是视图生命周期信息，不是公开 mutation ID，也不是服务端幂等凭证。`drawer` 来源的删改和不确定状态也使用此事件；`added_to_cart` jQuery 事件还可能只为兼容刷新而发出。仅订阅一个事件名、`mutation:true` 或购物袋计数上涨，不能严格关联“当前按钮的这次加购成功”。

前端须在**原来唯一写请求**的生命周期分配本地 `requestId`，让主按钮与底部固定按钮共享同一操作锁、提交快照和完成 promise。可以直接将已校验的原生提交 promise 传给 `createAddFeedback.run()`，或在原事件上向后兼容追加以下元数据：

```js
{
  source: 'showcase',
  requestId,               // 本次本地提交唯一标识，开始/成功/失败使用同一个值
  action: 'add',
  productId,               // 父商品
  variationId,             // 简单商品为 0
  quantityAdded,           // 此次提交盒数，非回包中该 SKU 的累计数量
  mutation: true,
  stale: false,
  cart                    // 已校验的原生 201 回包
}
```

此结构是**需要前端落实的接入约定**，不是本轮后端已修改现有事件的事实。不需要服务器回显或另存这个 ID；它只防止 UI 串单，不提供网络层幂等。任何页面脚本都能构造 DOM 事件，因此客户端事件只用于呈现，不作服务器成功、支付、库存或分析入账凭证。真实加购统计继续由现有原生 Woo 服务端成功钩子负责。

### 并发、错误和超时

- 在请求完成前，两个按钮共同忙碌且不可重复发写请求；数量/规格以提交快照为准。请求标识、视图 token 和当前操作都匹配，才对当前控件显示短暂 Added；对已关闭或切走的弹窗不重开、不抢焦点。
- 购物袋计数始终使用当前权威购物车的 `items_count`，不能用商品行数或先在本地加数量。不要用库存、价格或推荐接口间接触发加购。
- `source:'drawer'`、`action:'remove'/'quantity'`、`mutation:false`、`stale:true`、纯 GET 和无关 `requestId` 均不能触发本次 Added。新旧响应倒序时丢弃过时 UI 结果，再只读同步最新购物车。
- 网络中断/超时不代表写入失败；不可自动重发 add-item。先 GET 当前购物车供客户核对，保留明确“不确定是否已加入”的信息，核对成功也不能把一份纯 GET 伪装成原 POST 成功。
- 现有脚本只对明确的原生 missing/invalid nonce 拒绝进行一次刷新后重试；这是服务端已拒绝请求的特殊路径，不应扩大为任意 4xx/5xx/超时重试。
- 错误必须显示且可恢复操作，应用关闭/配置读取失败只关闭美化反馈，不得吞掉 Woo 错误或阻止原购买流程。反馈不自行导航购物车/结账；原有明确 Buy it now 行为独立，不能由一个泛用成功事件误触发导航。
- 本地 epoch 只协调同一页面中已接入的来源，不是跨标签页或跨设备的全局购物车版本；存在外部并发时依赖再次读取原生购物车，不能声称整个系统事务串行。

## 最近浏览：同一记录与接口约定

应用标识为 `recently-viewed`，管理入口 `admin.php?page=pc-apps&app=recently-viewed`。默认配置：

```json
{
  "version": 1,
  "enabled": true,
  "title": "Recently viewed",
  "maxVisible": 2,
  "maxStored": 20,
  "retentionDays": 30,
  "excludeCartProducts": true
}
```

前台最多 2 个；按最近浏览时间倒序，父商品去重。`maxVisible/maxStored/retentionDays` 分别只接受 1–2、1–20、1–30 的整数，标题为最多 100 字符的纯文本，完整配置须包含所有字段。应用配置与浏览历史分开：配置是全站已应用快照，历史只属于当前浏览器，不保存姓名、邮箱、IP、订单或新的客户画像。变体切换不新增多条父品，最近变体只作显示线索，真正选购仍须原生服务端校验。

已有底层为签名 HttpOnly Cookie `pc_recent_history_v1` 及 `recent_products` 的 history 展示。扩展读旧 v1、写有界 v2 同一 Cookie，让完整商品页与弹窗共用，不建历史表或另一份 Demo 存储。旧 v1 条目迁移保留原 issue time，不以迁移时间伪造新浏览。原有未签名 `pc_recent_products` 是遗留兼容记录，不作为新购物车列表的可信来源；新 clear 只清除签名历史，保留该旧 24 项兼容 Cookie，不宣称清空了其它追踪或所有旧组件数据。

当前源码路由如下；配置管理与 CartFeedback 相同，采用管理员权限、WP REST nonce、草稿/已应用快照与 CAS：

| 路由 | 行为 |
| --- | --- |
| `GET /peptide/v1/recently-viewed` | 公开应用配置，不含客户历史 |
| `GET /peptide/v1/recently-viewed/admin` | 授权配置管理读取 |
| `PUT /peptide/v1/recently-viewed/admin` | 完整 `{config,expectedRevision}` 保存草稿 |
| `POST /peptide/v1/recently-viewed/publish` | 授权保存并应用 |
| `GET /peptide/v1/recently-viewed/items` | 仅当前浏览器列表和短效写 token；不得查询其它客户 |
| `POST /peptide/v1/recently-viewed/record` | `{productId,variationId?}`；仅弹窗实际成功载入后调用 |
| `POST /peptide/v1/recently-viewed/clear` | `{}`；清除本站当前浏览器历史 |

匿名历史写入需要同源请求与 `X-PC-Recent-Token`，不能以任意客户 ID、IP、站点 ID 或客户端历史数组选择/覆盖别人。`GET items` 返回独立的 `writeTokens.record`、`writeTokens.clear` 和 Unix 秒 `tokenExpiresAt`；有效期 1800 秒，token 绑定操作、POST 方法、签名 Cookie 的浏览器标识及当前站点。HMAC 上下文包含 blog ID 与本站 URL，避免多站共享 salt 时跨店重放。旧 v1 签名不含本站上下文，仅为兼容读取；新 v2 和新写 token 都绑定本站。Cookie 为 host-only、本站 home 路径、HttpOnly、SameSite=Lax，HTTPS 下 Secure；最多 4096 字节。私有响应包括错误响应均为 `private,no-store` 和 `Vary: Cookie, Origin`，不把个性化商品 HTML 或写 token 放进共享页面缓存。

浏览器使用 `credentials:'same-origin'`、`cache:'no-store'`；已登录的 WP REST 请求还须沿用现有 `X-WP-Nonce`，否则 WordPress 原生 cookie REST 认证可能将请求当成匿名。`X-PC-Recent-Token` 不代替登录 nonce。`force_login=true` 的匿名 record/items 返回 401，不先输出受限商品。现有 `peptide_core_recent_history_enabled=false` 过滤器同样约束新记录、列表和旧 history 积木；关闭后不再为新访客初始化记录 Cookie，已有合法 Cookie 仍可取得清除 token。

列表 DTO 的固定顶层为 `version/revision/enabled/title/visible/items/count/storage/writeTokens/tokenExpiresAt`，`storage` 为 `browser_cookie`。每个 item 是：`productId/name/permalink/image{id,url,alt}/variationId/variationLabel/price{minor,text}/regularPrice{minor,text}/currency/currencyMinorUnit/priceIsFrom/purchasable`。金额 minor 为整数字符串，文本为纯文本；父品未指定变体时 `priceIsFrom=true`，当前价和原价取同一合资格 SKU，不拼接不同变体的最低价。旧变体不可用时可退为父品选项入口，`variationId=0`，不静默替客户选购另一个变体。

最小接入顺序（所有 URL 用本站 `rest_url` 或 helper 生成）：

```text
// 先 GET /peptide/v1/recently-viewed/items，取得当前 Cookie 对应的 tokens。
// 真实弹窗加载成功后，仅记录当前父商品及已确认规格；不发送价格、客户或购物车 ID。
POST /peptide/v1/recently-viewed/record
Content-Type: application/json
X-PC-Recent-Token: <items.writeTokens.record>
{"productId":123,"variationId":456}

// 用户清除，使用 clear 专用 token，不接受 record token 替代。
POST /peptide/v1/recently-viewed/clear
Content-Type: application/json
X-PC-Recent-Token: <items.writeTokens.clear>
{}
```

上面的 ID 仅说明结构，不是已创建的商品。record 成功只返回 `recorded:true/productId/variationId`，不代表已加购。clear 成功返回 `cleared:true/visible:false/items:[]/count:0/writeTokens:null`；随后要记录新浏览，先重新 GET items 取得新上下文和 token。

常见失败状态：400 为非法对象、字段、大小或 ID；403 为同源/用途 token 无效或过期；401 为强制登录保护；404 为商品/规格不可公开或不可购；409 为应用关闭（管理保存时也用于 CAS 冲突，按错误 code 区分）；429 为 Cookie 中的一分钟写入窗口达到 20 次；503 为存储/读取失败或无法读取当前购物车过滤范围。失败不触发加购、不生成假的最近商品，前端保留原购买入口。

无 Cookie 时应先串行读取列表初始化当前浏览器上下文，再使用响应中的对应 token 记录；不要并发初始化多个种子。record/clear 按当前浏览器串行，防晚到响应覆盖新 Cookie。浏览器拒绝持久化时前端可退为当前会话的本地 ID/时间队列，再用公开目录现值呈现；必须注明退化模式，不能写入正式 Demo 假购物袋或阻断购买。

记录与列表均重新核对公开、无密码、可见、可购商品；变体须真正属于该父品且可用。排除当前购物车内父品，删除/隐藏/不可购内容不可借 Cookie 泄漏。返回商品名称、图片、链接、规格和当前服务端价格/币种/小数位；不能永久保存上次价格、读客户端价格或把变体 ID 当父品直接查询。没有记录或过滤后为空就隐藏，少于 2 个就少显示，不补虚假推荐。

只在商品弹窗**成功载入真实商品**后 record；悬停、预加载、进入视口、编辑器预览不算，前端不得在这些生命周期调用记录。重复打开可更新同父品时间。完整商品页继续走同一服务，写 Cookie 后保留既有 private/no-store 和 DONOTCACHEPAGE 保护。应用关闭仍可清除已有合法历史；过期 Cookie 不得重新延长旧条目的年龄。Cookie 中的频率窗口只能是有界的尽力限制，删除或重放旧 Cookie 可重置，不能声称强防刷或全局事务记录。跨标签页并发/旧响应覆盖属于无服务端历史表方案的限制，前端不能宣称清除请求可撤销所有在途请求。

## 源码审查与实际验收

已实际完成：只读附件、旧活动监听契约、原生 Quick View 请求与回包、抽屉变更事件、完整商品页/签名 history 底层；审查新 Cart_Feedback Config/Store/controller、Recent Config/Store/History/controller 及旧 history 委托，确认没有新增加购写 API。未修改主题、Quick View JS、数据库、Cookie、浏览器或 Git。

已指出并在源码复核修正：Recent 完整替换、站点与用途绑定 token、变量价与原价同 SKU 配对、旧 history 停用过滤器、变体 active/password/完整属性守卫。未见剩余阻断性代码问题。现有 `vc:cart-updated` 的 requestId/action/商品快照仍是前端待接；不能从泛用事件推定本次成功，后端没有代改这一事件。

独立审查与下面的执行验证分别记录，未将阅读源码当作实测。实际验证样例仅存在隔离内存或连接内 TEMP 表。

证据任务目录：`D:/Codex/Tasks/2026-11-02/cart-conversion-backend/{work,outputs}`。


### 完成范围与验证结果（Codex，2026-11-02）

本轮改动为 Peptide Core 的 17 个源码文件及本交接：新增 cart_feedback Config/Store、CartFeedback controller/后台页/JS/CSS；新增 Recent Config/Store/History、RecentlyViewed controller/后台页/JS/CSS；Plugin、Apps_Page、functions 接线，旧 Commerce_Blocks 的签名 history 读写委托共享 History。活动监听与原生 Woo 加购没有重新实现；没有新加购 API，没有改主题、Quick View、17863 前端 Demo 或前端工作树。

两个新应用均默认启用、revision 0；仅阅读默认值不写配置。关闭、改时长或保存条数等自定义操作仍须明确保存并应用。配置损坏时 API 503，effective 强制关闭，不能以默认 true 掩盖故障。页面入口为 `admin.php?page=pc-apps&app=cart-feedback` 和 `admin.php?page=pc-apps&app=recently-viewed`。公开 helpers 为 `peptide_core_cart_feedback_config/url()`、`peptide_core_recently_viewed_config/url()`。

- 加购反馈：53/53 真实 WP/REST/MySQL 配置检查；21/21 后台 DOM 状态与 8/8 PHP 加载/权限检查。
- 最近浏览：66/66 真实 WP/REST/MySQL 配置检查（实际 bootstrap 注册路由）；8/8 后台关键 DOM 状态、8/8 PHP 加载/权限检查；204 条 History/controller 隔离断言；21/21 原生 WP/Woo History 检查，真实 getter 与 TEMP posts/postmeta/options，未创建永久商品或执行真实加购。
- 原生验证全部使用连接内 TEMP 表；永久隔离 guard 未改，业务/访客计数、原应用配置不变，History 原生验证还核对全 options 指纹。未出现非 TEMP SQL 写入尝试。原生 CAS 是受控交错验证，不冒称多进程并发压测或完整多站网络测试。
- HTTP 实测：原站两个公开配置均 200/defaults/revision0，无草稿私有字段；两个 admin 匿名读取均 401。独立 QA HTTP 同源空浏览器 items 200、items=[]、返回两个写 token，Set-Cookie 含 HttpOnly/SameSite=Lax；跨源 items 403。响应为 private/no-store。输出回执没有保存 Cookie、token 或客户身份。
- QA 永久 visitor-only 守卫继续禁止 record/clear 等 POST；没有为网页端正向写验证而放宽它。正向 record/clear 已由原生 CLI 临时样例与隔离 controller 检查覆盖，不能描述成同源浏览器完整写流程验收。
- 真实 IAB 中文 QA 后台：两个应用默认值成功加载，799ms 和 maxVisible=3 被本地拒绝并保留输入，恢复正确输入后离开；没有提交应用配置。1280px 无整页横向溢出。截图在 outputs/cart-feedback-admin.png 与 recently-viewed-admin.png；13PHP/2JS 语法及 diff 检查通过；17 个源码 SHA 与 QA 完全一致。

活动监听独立预览在 `D:/Codex/Tasks/2026-11-02/cart-activity-listener/outputs/cart-activity-preview.html`。最新用户要求已实际调整：去掉营销标题/说明，只保留当前金额、到下一档差额、门槛与优惠标记；节点跨档弹出笑脸与小烟花，提供预览专用“播放达标动画”。它只使用本地示例金额，没有调用真实站点，不代表正式客户动画已接入。

前端剩余：围绕同一原生 Woo 请求接按钮状态和 mutation 本地关联；仅弹窗成功加载后调用 History，串行处理初始化/记录/清除；在真实站点同源联调 cookie/token/WP nonce、无存储退化、库存/超时/跨标签竞态、320/390/430px 与减少动态。保留盒价/规格/盒数，不增加每瓶价格或默认加购。

证据与备份：`D:/Codex/Tasks/2026-11-02/cart-conversion-backend/outputs` 的 native/fixture/HTTP/源码审查回执与最终 DELIVERY；旧源修改前在 `work/source-backup`，QA 覆盖前在 `work/qa-backup`。Git 提交与实际 remote SHA 写入 DELIVERY 及项目主记忆；不提交数据库、Cookie、私有测试运行文件或截图素材。
