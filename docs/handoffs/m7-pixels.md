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
| Purchase | 下单成功页，`order_<订单内部编号>`（11-11 起主要是付款成功弹窗页，见下面“2026-11-12 复核”） | 订单首次进入处理中 / 已完成 / 保留时发；11-11 起另有每 5 分钟的补发 | 同一个 | 具备 |

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

## 2026-11-12 复核：合并主线之后的购买事件

主线（日常会话，`0bd38f6`）加了两样东西，都在 F4 名下的 `includes/class-pixels.php` 里，合并时原样保留：付款成功弹窗页（`?pc_paid=`）发浏览器端的购买事件；每 5 分钟补发漏掉的服务端购买。另外 `e0bc6ca` 把新订单的显示订单号改成了 `hang-数字`。

逐条核对的结论（用例 `tests/m7-purchase-dedupe-pure.php`，24 条断言）：

| 问题 | 结论 |
| --- | --- |
| 浏览器购买、付款时的服务端购买、补发任务，event_id 是不是同一个 | 是。三处都走 `Pixels::order_event()`，event_id = `order_` + `$order->get_id()` |
| 订单号改成 `hang-` 之后 event_id 会不会跟着变 | 不会。event_id 用内部编号；`hang-` 号只进事件的 `order_id`（`get_order_number()`），浏览器和服务端两边一致 |
| 付款时那一次和补发任务会不会各发一条 | 共用订单上的 `_pc_px_server` 标记。**有一个很窄的时间差**：付款请求是先改订单状态、再打标记；补发任务如果正好在这中间读到订单就会再发一条，而两条服务端事件 Meta 不保证去重。已改：补发任务不碰付款不到 10 分钟的订单（`Pixels::CATCHUP_GRACE`） |
| 弹窗页刷新、弹窗页之后又进了“订单已收到”页，浏览器会不会发两次 | 不会，共用 `_pc_px_browser` 标记 |
| 测试单 | 主线在弹窗页和补发任务里跳过了测试单，但付款时的服务端回传和“订单已收到”页没有跳过——测试单会只有服务端一路。已改：四条路用同一个判断 `Pixels::is_test_order()`，测试单都不发 |
| 带 `pc_paid` 的页面会不会被页面缓存存下来、把购买事件发给别的访客 | 不会。`Page_Cache` 只缓存没有参数或只有广告参数的地址 |

没有改主线的行为：弹窗页发浏览器购买、每 5 分钟补发、6 天的时间窗、订单备注的文字都和主线一样。

同一天按 F5 的请求改了一处：`Pixels::allowed()` 和 `Tracker::is_staff()` 现在也认员工角色（`Staff::is_staff()`）。投手在前台没有 `manage_woocommerce`，以前他自己逛店铺会被算成访客、也会触发像素；“员工访问也回传”勾上时不受影响。

留意的两点（没有改）：

- 补发任务每次最多看 50 张订单（最近 6 天付款的，按下单时间从新到旧）。一家店 10 分钟内付款超过 50 单时，被打断的订单可能排不进这 50 张。现在的单量远没到，先记在这里。
- 补发任务对“没有记下顾客浏览器信息”的订单只打标记不发送，这是主线的设计（和付款时一致）。

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
