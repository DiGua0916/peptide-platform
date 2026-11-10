# M7 像素与 CAPI：交接（F4「数据」会话）

2026-11-10 · 分支 `claude/m6-metrics-db7036`（和 M6 同一条分支，提交 `20bb890` 及之后；开发文档里的 `claude/m7-pixels` 没有单独建）。
对应需求：FE-09、ST-06，以及 ST-01 第 4 步要用的测试接口。没有合进主线，没有发布。
纯逻辑用例已经在本机跑过并通过（见文末）；**没有连过 Facebook，`assets/pixels.js` 的改动没有在浏览器里验证过**，真正的验收要等发布到沙盒之后按清单做。

## 核对现有代码的结论

任务说明里写“代码已具备同一 event_id 去重、`_fbc` / `_fbp`、服务端 Purchase”。逐个事件对了一遍，六类事件里有两类不满足“浏览器 + 服务器”：

| 事件 | 浏览器 | 服务端 | event_id | 结论 |
| --- | --- | --- | --- | --- |
| PageView | 每个页面 | **默认不发**（统计设置里“服务端也回传 PageView”默认关） | 同一个 | 要逐店打开开关（配置项，没有改默认值） |
| ViewContent | 商品页 / 首页商品弹窗 | 发 | 同一个 | 具备 |
| AddToCart | 加购后从队列取 | 发 | 同一个 | 具备 |
| InitiateCheckout | 结账页 | 发 | 同一个 | 具备 |
| AddPaymentInfo | **只在顾客改了支付方式时**发，event_id 在浏览器里生成 | **没有** | 无法一致 | **缺**，这次补上 |
| Purchase | 下单成功页，`order_<订单号>` | 订单首次进入处理中 / 已完成 / 保留时发 | 同一个 | 具备 |

`_fbc`（由 `fbclid` 生成，90 天）、`_fbp`、下单时把 IP / UA / fbp / fbc 存到订单、邮箱电话哈希、GPC 和退出链接，都在，没有改。

## 这次改了什么

1. **服务端 AddPaymentInfo**（`includes/class-pixels.php`、`assets/pixels.js`）
   - 新接口 `POST /peptide/v1/pixel-event { name: "add_payment_info" }`：服务端按购物车生成事件，用同一个 event_id 回传 Conversions API，再把事件返回给浏览器发像素。
   - 触发时机从“改了支付方式”扩成“选了支付方式，或点了下单，先到的那个，每页一次”（PRD 7.2：选定支付方式并提交）。**这会让 AddPaymentInfo 的数量比以前多**——以前只有改过默认支付方式的顾客才有。
   - 接口失败、购物车为空、同一个购物会话 60 秒内已经发过：浏览器退回原来的做法（只发浏览器事件，event_id 自己生成），不会不发。
   - 接口只读购物车，不往 WooCommerce 会话里写东西（避免和同时进行的下单请求互相覆盖会话）；60 秒的限制用瞬态实现。
2. **验收**（`includes/pixels/class-acceptance.php`、`tests/m7-pixel-acceptance-smoke.php`、`docs/guides/m7-pixel-acceptance-checklist.md`）
   - `start` 生成验收码和带码的店铺链接；用这个链接打开店铺的浏览器，每发出一个 Facebook 像素事件就上报事件名和 event_id（`POST /peptide/v1/pixel-check`），记进 `pc_px_log`（`platform = browser`）。
   - `check` 逐个事件核对：服务端发送成功、浏览器发过、event_id 一致；Purchase 还要求 event_id 是 `order_<订单号>`。同时检查配置（有没有令牌、关联域名是不是本店、服务端 PageView 开没开）。
   - 普通访客的页面配置里没有上报地址，不多发任何请求。
3. **发送测试事件**（`includes/pixels/class-server.php::test_event()`、`POST /peptide/v1/admin/pixel-test`、像素页按钮）：同步发一条测试 PageView，返回每个像素通不通。F5 的开店向导第 4 步调这个。
4. **像素页对投手只显示 Facebook**（`includes/admin/class-pixels-page.php`）：其他平台的卡片对投手不输出、不提交；`Pixels::save()` 对没提交的平台原样保留已保存的设置（以前表单里没有的平台会被清空，所以必须一起改）。管理员看到的和以前一样（Facebook 展开、其余折叠）。

## 需要平台所有者做的

按 `docs/guides/m7-pixel-acceptance-checklist.md` 逐店做。像素 ID 和访问口令由投手或平台所有者在店铺后台填，开发不经手。
清单第 3–6 步依赖这条分支的代码，要等发布之后。

## 风险和没做的

- `pixels.js` 是每个页面都加载的脚本，改动集中在结账页的监听和验收上报两处；没有浏览器环境，**没有实测**。发布到沙盒后第一件事是在手机上走一遍下单，看控制台有没有报错（`localStorage.pcPixelsDebug = '1'` 会打印每个事件）。
- `pixel-event` 在 REST 请求里调用 `wc_load_cart()` 读购物车。区块结账下应该没问题，但没有实测；如果读不到购物车，接口返回空列表，浏览器退回旧做法，不会断。
- 没有改“服务端也回传 PageView”的默认值：打开后每次页面浏览多一次到 Facebook 的服务端请求（走后台队列），是否全部店默认打开请平台所有者定。
- TikTok 等其他平台没有动。
- 已标记退款的订单不回撤已发的 Purchase（开发文档 6.3 的既定做法），没有改。

## 和 F5 对上的两件事（来自它的笔记）

- 向导第 4 步读 `/admin/pixel-test` 返回值里顶层的 `ok / pixel / message`：已经加上（`pixel` 是第一个没通的像素，都通时是第一个像素；`message` 是一句中文原因，通了为空），原有的 `reason / test_code / results` 保留。
- 投手现在进不去“系统设置 → 广告像素”（F5 的投手权限名单里没有这一页），投手用的是 F5 自己的“广告设置”页，只管第一个 Facebook 像素。所以这次做的“像素页对投手只显示 Facebook”目前没有入口；要不要让投手管多个像素由平台所有者定，定了之后 F5 把这一页和 `pc_pixels_save` 加进名单即可。

## 实际做过的检查（2026-11-10，本机 PHP 8.3.36）

- `php -l` 全部通过；`node --check assets/pixels.js` 通过。
- `m7-pixel-acceptance-pure`（32 条断言）：验收的判断逻辑（含一家店两个像素、店里有别的访客流量）、验收码、浏览器上报的限制、配置检查。通过。
- `m7-pixels-pure`（31 条断言）：加载真正的 `Pixels`、`Pixels\Server`、`Pixels\Acceptance`，只把 WordPress、WooCommerce 和网络换成桩。通过。其中证明了：
  - 服务端发给 Conversions API 的 AddPaymentInfo 的 `event_id` 和返回给浏览器的是同一个；带上了 fbp、fbc、IP、UA 和测试事件代码；写进了发送记录。
  - 一分钟内第二次请求不回传也不返回事件；购物车为空、顾客退出回传、GPC、员工都不发。
  - 这个接口不往购物会话里写东西。
  - 投手只提交 Facebook 时，其他平台的像素和令牌原样保留；不带保留名单时会被清空（所以保留名单是必须的）。
  - 普通访客的浏览器配置里没有验收上报地址，访问令牌不会出现在浏览器配置里。
  - “发送测试事件”四种情况（已通、Facebook 拒绝、没填令牌、没有像素）的返回格式。
- 没有做：真实的 Facebook 请求、浏览器里的 `pixels.js`、`wc_load_cart()` 在真实 REST 请求里的行为。
