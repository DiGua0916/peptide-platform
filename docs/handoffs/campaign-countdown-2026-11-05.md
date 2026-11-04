# 活动倒计时 · 2026-11-05 · Codex

独立实现结账红色胶囊横条和商品弹窗透明外层分格倒计时，复用已有 Promotions 与 Campaign_Announcement。没有新建优惠、改结算规则、设置日期、改数据库或部署。

## 本模块文件

- `wp-content/plugins/peptide-core/includes/class-campaign-countdown.php`
- `wp-content/plugins/peptide-core/includes/admin/class-campaign-countdown-page.php`
- `wp-content/plugins/peptide-core/assets/campaign-countdown.css`
- `wp-content/plugins/peptide-core/assets/campaign-countdown.js`
- `wp-content/plugins/peptide-core/tests/campaign-countdown-pure.php`
- `wp-content/plugins/peptide-core/tests/campaign-countdown-client.cjs`

## 根代理接线

1. Plugin boot 加 `Campaign_Countdown::register_hooks();` 和 `Admin\Campaign_Countdown_Page::register_hooks();`。前者注册公开只读 GET 与 enabled 时在 `wp_enqueue_scripts` 提前加载资源，支持之后动态打开商品弹窗。
2. Apps_Page 注册 `campaign-countdown`，标题“活动倒计时”，图标用已有 Dashicons clock，`on` 取 `Campaign_Countdown::settings()['enabled']`。对应 render 路由调用 `Campaign_Countdown_Page::render()`。页面/save 自行复用 Activity\Store::can_manage()，不是只凭菜单显示。
3. 结账优惠卡下、订单摘要前：输出 `Campaign_Countdown::render('checkout')`；商品播报下、主图前输出 `render('product')`。它们各只输出一个 hidden mount，已在早期 enqueue 的 JS 负责按有效活动渲染，缺日期不会预先闪出假数字。
4. 动态弹窗自己创建 host 时：class 为 `pc-campaign-countdown pc-campaign-countdown--product`，`data-pc-campaign-countdown="product"`。调用 `PCCampaignCountdown.mount(host, productDto, {endpoint: publicRestUrl})`。方法返回 `{update, refresh, destroy}`；更换/移除弹窗时可 `destroy()`，移除节点也会在一秒内自动清理。PHP 标准 mount 渲染后可调用 `PCCampaignCountdown.init(dialogRoot)` 或派发 `pc:campaign-countdown-mount`，event.detail.root 指向 dialogRoot。
5. 若希望公开 REST 内的 native tier text 也走当前西语 gettext，在 Storefront_Translation::public_rest_route 的 peptide allowlist 增加 `campaign-countdown`；计时标题 EN/ES 标签由本模块 JS 按 PCStorefrontI18n.language 或 HTML lang 选择。

## 设置、同源活动与候选

独立选项 `pc_campaign_countdown_v1`：`{version:1,enabled:false,promotionId:0,checkout:true,product:true}`。没有额外 duration/deadline 字段。后台只在明确、nonce 验证通过且有应用管理权限的 POST 更新此选项，不会修改原优惠活动。

`Campaign_Countdown::candidates(int $selected=0)` 只读最多 300 条已发布、公开、无密码活动，返回 id/title/status/starts/ends/tiers/hasDeadline；如当前选择超出列表仍会单独检查该 ID。可据实际 title、ID 和 tiers 确认 Black Friday，不自动选择第一项或猜名称。原生活动的公开状态/档位/时间保持权威。

所有黑五位置应由根代理显式使用同一个选择的 promotionId；首页 announcement 如仍绑定其他活动，应在整合时据用户确认的同一 ID 关联，不能复制数额或日期。本实现没有静默改首页/活动监听/主题配置。

后台展示真实截止时间（店铺时区）和活动编辑链接。未设置结束时间可以保存设置，但前台 state=untimed、visible=false；应在原活动编辑页设置真实时间后展示。不会自行设置黑五年份、日期或倒数长度。已结束/停用/私有/草稿活动不允许作为已启用设置保存。

## 公共 DTO / 时钟

`Campaign_Countdown::for_position('checkout'|'product', ?array $settings=null, ?int $now=null)`：

```json
{"version":1,"position":"checkout","enabled":true,"visible":true,"state":"active","promotionId":37,"title":"来自原活动","tiers":[{"min":300,"value":25,"text":"来自原活动"}],"starts":1793899000,"ends":1793993787,"target":1793993787,"serverNow":1793900000}
```

上例仅演示结构，不是保存的活动数据。`starts`/`ends`/`target`/`serverNow` **全部是 UTC Unix epoch 秒**，不是毫秒。`target` 未开始时等于 starts，开始后等于 ends。ends 唯一来自原活动 config.end；计时不会改变 native 活动状态或结算。

states：disabled / unconfigured / unavailable / untimed / upcoming / active / ended。不可用的非公开活动不泄露 ID、标题、档位或时间。结构损坏的活动档位/日期 fail closed。

`GET /wp-json/peptide/v1/campaign-countdown` 返回 version/serverNow/checkout/product 两位置 DTO；匿名只读，响应 private/no-store/Vary Cookie；不创建 session，不访问购物车、订单或追踪表。前端同源 no-store GET：首次校时、60 秒刷新、visibility/pageshow 重校；8 秒超时，断网仍按已知绝对 deadline 前进。用 performance.now() 锚定服务端时钟并补偿半个请求往返时间。刷新不会重置。到开始时间切为同活动截止时间，到结束隐藏；倒计时不是支付是否减免的承诺，优惠资格仍以原生购物车结算为准。

客户标题仅对原生名称 `黑色星期五` / `黑五` 做窄标签映射：跟随实际 `Storefront_Translation::code()`，英语 `Black Friday`、西语 `Viernes Negro`。未知自定义名称原样；后台 `candidates()` 和数据库原活动名不改。PHP 回归使用实际语言类 getter 验证两语言、两位置、未知名称、后台候选和原记录保持；不往共享翻译字典插入内容。

根代理 CAS 安装新类后，实际本机匿名 GET 两语言各一次：EN/ES 均 HTTP 200，checkout/product 都是原活动 10980、原截止时间 1796038061，标题分别为 `Black Friday` / `Viernes Negro`。响应 private/no-store、没有手动偏好 Set-Cookie。证据 `D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/outputs/countdown-customer-label-http.json`；没有访问购物车、提交订单或修改配置。本次类 SHA `6cd0dfcea8751fee7084a6dc088c22d01f720cbc4c60d261691a75bdd7b4535e`，PHP 451 项与 JS 27 项通过。

## 视觉与验证边界

结账沿用已存在首页公告红 `#ae213a`、白字胶囊。商品位置透明外层，白色分格、深红数字。tabular numerals；秒变动无循环弹跳，reduced-motion 禁止动画；非交互计时无需额外点击靶区，后台 label/select/保存至少 44px。手机媒体查询之外增加组件 container query，避免桌面窄订单侧栏溢出。

Impeccable craft floor 已应用。detector 只有 DESIGN.md advisory（该文件明确只约束 admin，公开店面红色胶囊按用户指定和既有公告品牌；小型计时字号/深红分格为局部组件），没有阻断项。未改 DESIGN.md。

独立测试实际加载 Promotions、Campaign_Announcement、倒计时类与实际 admin POST handler；WordPress 函数边界为内存替身，拒绝真实写入。最终计数与 SHA 见 `D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/outputs/countdown-checks.json`。JS 测试实际模块、单调时钟/状态/EN-ES/文字注入/生命周期/同源边界；没有真实购物车或支付请求。

响应式离线 fixture：`D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/work/countdown/countdown-responsive-fixture.html`，真实模块资源内联，360/390/430/1440 × EN/ES，顶部自报 iframe layout overflow。为 synthetic 活动和时间。根代理正在统一操作 CUA，本模块没有另动浏览器或宣称真实页面已验；整合后仍由根代理统一验证手机与真实挂载位置。未 commit、push、部署或修改任何真实配置。
