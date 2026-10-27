# 访客追踪接收协议

VISITOR-ANALYTICS-TEST-01 · protocol 1 · peptide-core 0.11.0 · 2026-10-22 · Claude

> **2026-10-27 起是正式版（TRACKING-LIVE-01）**：配置里 `test_mode:false, mode:"live"`，主题要改为不再要求 test_mode；新数据 is_test = 0。
> 开关、不要追踪的浏览器（GPC / 退出链接，403 `pc_track_declined`）、保存期限、隐私请求见 CONTRACT“访客追踪”和第 8 节。协议本身（事件格式、ack、限流）没有变。

本文件是已经实现、测试过的接口，不是草案。代码位置：`includes/tracking/`、`includes/api/class-tracking-controller.php`。测试：`tests/smoke-tracking.php`（45 项）。

## 1. 开关与前端加载

- 默认关闭。开启方式：
  - 后台“访客数据 → 实时访客”里的“开启测试追踪”；
  - 或者命令 `wp peptide tracking enable`，关闭用 `disable`，查看用 `status`，清空测试数据用 `purge --yes`。
- 开启后，插件接管主题的过滤器 `vialcove_visitor_observer_config`（优先级 20），返回：

```json
{"enabled":true,"test_mode":true,"protocol":1,
 "endpoint":"http://vialcovepeptidescom.local/wp-json/peptide/v1/track/events",
 "heartbeat_ms":15000,"idle_ms":60000,"batch_max":20}
```

主题现在的加载判断（enabled + test_mode、排除员工和预览）可以保持不变。建议把 `endpoint`、`heartbeat_ms`、`idle_ms` 用 `wp_add_inline_script` 交给适配器，再传给 `start({heartbeatMs, idleMs})`。

## 2. 身份（前端不用生成访客或会话编号）

| 层级 | 谁生成 | 规则 |
| --- | --- | --- |
| 访客 | 服务器 | 前台页面加载时发 `pc_vid` cookie：HttpOnly、SameSite=Lax、一年有效，32 位十六进制。JS 读不到它，同一浏览器的所有标签页共用。只用于统计，不能当登录凭证。 |
| 访问 | 服务器 | 同一访客 30 分钟没有任何事件后，下一次事件开始新的访问。 |
| 标签页 | **前端适配器** | `sessionStorage['vc_tab_id']`，没有时生成 UUID 并保存。同一标签页内换页面不变，新标签页是新的值。sessionStorage 不可用时可以省略，服务器会用 page_instance_id 代替。 |
| 页面实例 | 前端（已有） | `page_instance_id`、`seq`，保持不变。 |
| 账号 | 服务器 | 登录时（wp_login）关联当前访客；之后每批请求也会根据登录 cookie 补关联。同一账号在别的设备上是另一个访客，但会互相关联。 |

不需要单独的初始化请求。`GET /track/session` 只用来查询状态，返回 `{protocol, enabled, test_mode, tracked, config}`：
- `tracked=false` 表示员工账号；
- 浏览器没有访客 cookie 时，这个请求会补发；
- 不返回访客编号本身。

请求要带 cookie：同源 `fetch` 默认会带，`credentials: 'same-origin'`。**不需要 X-WP-Nonce。**

## 3. 上报：POST `/peptide/v1/track/events`

请求体是 JSON。`Content-Type` 用 `application/json` 或 `text/plain` 都可以，服务器读原始请求体。每批最多 20 条，最大 64 KB。

```json
{
  "protocol": 1,
  "tab_id": "3b1d…（sessionStorage 的 vc_tab_id）",
  "sent_at": "2026-10-22T10:27:55.120Z",
  "events": [
    {
      "event_id": "uuid",
      "page_instance_id": "uuid",
      "seq": 1,
      "type": "page_view",
      "occurred_at": "2026-10-22T10:27:55.071Z",
      "page": {"page_type": "product", "path": "/product/x/", "product_id": 123, "product_name": "…"},
      "data": {"cause": "navigation", "visibility": "visible", "activity": "active", "last_activity_age_ms": 10}
    }
  ]
}
```

事件就是你现在队列里的记录，原样发送即可。另外两个字段：
- `tab_id`：放在整批外层；
- `sent_at`：每次发送时的 `new Date().toISOString()`。服务器用它校正浏览器时钟：校正后的时间 = occurred_at + (服务器收到时间 − sent_at)。

**成功响应 200：**

```json
{"protocol":1,
 "ack":["uuid-1","uuid-2"],
 "rejected":[{"event_id":"x","code":"bad_event_id","retry":false}],
 "server_time":"2026-10-22T10:27:55Z"}
```

适配器建议返回：

```js
{ackEventIds: [...res.ack, ...res.rejected.filter(r => !r.retry).map(r => r.event_id)]}
```

- `ack`：已保存。**重复发送的事件也在 ack 里**（只存一次），可以放心重试。
- `rejected` 且 `retry:false`：永远不会被接受，应该从队列删除。代码有：
  - bad_event_id、bad_page_instance_id：ID 需要 8–64 位，只能是字母、数字和短横线；
  - unknown_type；
  - bad_time；
  - too_old：校正后早于 24 小时前。
- `rejected` 且 `retry:true`：服务器暂时出错，可以重试。代码是 server_error。

**整批错误**（统一错误格式 `{code, message, data:{status}}`，这时没有 ack，整批按你的退避规则重试或丢弃）：

| 状态 | code | 处理 |
| --- | --- | --- |
| 400 | pc_track_bad_request | 不是 JSON，或者没有 events 数组。不要重试。 |
| 403 | pc_track_disabled | 追踪已关闭。建议 stop()。 |
| 403 | pc_track_staff | 员工账号。建议 stop()。 |
| 413 | pc_track_too_large | 超过 20 条或 64 KB。拆小再发。 |
| 429 | pc_track_rate_limited | 每个访客 5 分钟最多 600 条，每个 IP 最多 3000 条。看 `Retry-After` 响应头或 `data.retry_after`（秒）。 |

**离页发送**：建议用 `fetch(endpoint, {method:'POST', body, keepalive:true, credentials:'same-origin'})`。这样能读到响应时仍然按 ack 处理，读不到时不算 ack。也可以用 `navigator.sendBeacon(endpoint, new Blob([body], {type:'text/plain'}))`。按你的规则，sendBeacon 返回 true 不算 ack。

## 4. 服务器接受的字段（白名单，其余字段丢弃）

- **page**：
  - `page_type` 取 home / shop / product / cart / checkout / account / order_received / order_pay / content，其他值按 content 处理；
  - `path` 只保留路径，去掉 query 和 hash，最长 500；
  - `product_id`、`product_name`（最长 120）只在 product 页保留。
- **data**：

| type | data 字段 |
| --- | --- |
| page_view | cause, visibility(visible\|hidden), activity(active\|idle), last_activity_age_ms |
| heartbeat / visibility_change | visibility, activity, last_activity_age_ms |
| page_exit | persisted(bool), visibility, activity, last_activity_age_ms |
| product_view | product_id |
| cart_view | source |
| checkout_view / checkout_submit_attempt | （无） |
| variant_select | product_id, attribute(≤60), value(≤120) |
| add_to_cart_attempt | product_id, variation_id, quantity |
| cart_open / cart_close | source |
| cart_change_attempt / cart_change_response | source, action, item_id, quantity |
| video_play / pause / progress / seek / end | video_id(≤120), module_id(≤60), position_s, duration_s, watched_s, autoplay(bool), playback_origin(user\|autoplay\|unknown) |

数字必须大于等于 0；字符串会清理并截断。服务器不保存 IP（只用它的哈希值做限流）和 User-Agent 原文（只保留设备类型和浏览器名）。

## 5. 状态口径（服务器时间）

- **标签页状态**：
  - 在线（active）：最后信号 ≤ 45 秒，并且 visible、没有空闲；
  - 空闲（idle）：最后信号 ≤ 45 秒，但 hidden 或 idle；
  - 离线（offline）：超过 45 秒没有信号，或者最后一个事件是 `page_exit` 且之后 10 秒没有新信号。
- **访客状态**：取各标签页里最在线的一个。
- **后台标签页**：你的心跳在 hidden 时不发，所以切到后台的标签页约 45 秒后显示为离线，最后状态停在“hidden”。这和你说的“page_exit 不等于离线”一致。
- **乱序**：每个标签页保存最后一个事件的发生时间。更早发生、更晚到达的事件照样存进时间线，但不会覆盖当前状态，只算作在线信号。
- **视频**：每个标签页保存最后一个视频事件的状态（playing / paused / ended / seeking）和 position_s、watched_s。新的 page_view 会清空。

## 6. 服务端事实（前端不用、也不应该上报）

| type | 来源 | 去重 |
| --- | --- | --- |
| add_to_cart | `woocommerce_add_to_cart`，经典购物车和 Store API / Blocks 都会触发 | 每次成功加购记一次 |
| order_placed | 经典结账 `woocommerce_checkout_order_processed`；区块结账 `woocommerce_store_api_checkout_order_processed` | 每个订单一次 |
| payment_success | `woocommerce_payment_complete`，或订单第一次进入已付款状态 | 每个订单一次 |
| payment_failed / order_status | `woocommerce_order_status_changed` | 每个订单、每个状态一次 |
| login / register | `wp_login` / `user_register` | — |

下单时，订单上会记下访客和访问编号（`_pc_track_visitor`、`_pc_track_visit`）。之后支付网关的回调没有浏览器 cookie，也能关联回原来的访问。

**访问阶段**只升不降：1 看过商品 → 2 加购成功（服务端）→ 3 进入结账 → 4 下单 → 5 支付成功。

## 7. 后台（我这边已完成）

- “访客数据 → 实时访客”：每 5 秒刷新；每个标签页一行，显示当前页面、状态和视频。
- 访客详情：访问历史、同一账号的其他设备、时间线（前端事件 + 服务端事实，按发生时间排序，可以隐藏心跳）。
- “转化统计”：按日期和设备筛选的漏斗、按天统计、商品浏览和加购。
- 接口 `/track/admin/*`，需要 manage_woocommerce。

## 8. 已知边界

- 访客 cookie 在页面加载时由 PHP 发放。以后如果开启整页缓存，需要改成第一次上报时发放（接口已经支持：没有 cookie 时会补发）。
- 迟到的事件会记到它到达时的那次访问里，不会按发生时间回填到更早的访问。
- ~~没有隐私同意、保存期限和删除管理界面~~（2026-10-27 已补：不追踪 GPC / 退出链接的浏览器；保存期限 30–365 天，每天清理；接入 WordPress 的导出 / 抹除个人数据；隐私政策指南里有参考文字）。美国店铺不弹同意框；以后如果面向要求事先同意的地区，需要再加同意框。
- 员工账号（manage_woocommerce / manage_options）完全不追踪。测试请用顾客账号或无痕窗口。
