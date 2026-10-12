# 前后端契约

状态：2026-10-12 INT-01 集成基线，peptide-core **0.3.0 / ecec833**。接口已在 LocalWP 开发站点实现。设计沿革见后端方案 v1 的 C/D、v2 的 C'/D'；本文件记录当前代码，包括后续增补。变更先写角色交接，再协调更新本文件。

后续基线：PAY-SHIP-01 的后端提交 3aecb8f 增加测试商务配置命令和后台提醒，公共接口形状未变。

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

最后一条路由参数目前按 **WooCommerce 数字订单 ID** 查找，返回 order_number 为展示编号字符串。未启用自定义编号插件时二者一致，将来需显式适配展示编号。

## 站点设置

```json
{"demo_mode":true,"demo_notice":"This site is a demonstration. Product, batch and report data are synthetic.","use_statement_html":null,"checkout_ack":{"enabled":false,"label_html":null}}
```

- demo_notice 为纯文本；demo_mode=false 时返回 null。主题仅在开启演示时显示，转义文字，空提示仍保留简短演示标识。
- use_statement_html 为经 wp_kses_post 清理的 HTML 或 null；主题再次清理后显示，空值不生成空模块。
- checkout_ack.label_html 保留早期字段名，实际是**纯文本**。空文案时 enabled 不生效。brand_name 仅后台使用，不在 /site 返回。
- 管理入口：WooCommerce → VialCove 设置。仅有 pc_manage_settings 的管理员可操作。

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

## 支付物流边界

前端使用原生区块的支付/配送选项、价格、错误和加载状态；标识和业务值由 WooCommerce/网关提供，不在主题硬编码支付标识或运费。

当前本地测试配置（3aecb8f，由 Claude 配置，Codex 浏览器复验）：

- 只销售和配送至 US，国家选项只有 United States。
- `wp peptide demo commerce` 开启测试配置：区域 United States (test shipping)，一条 flat_rate，费用 0，名称 Test shipping – rates to be confirmed。
- 支付 ID 为 `cod`，名称 Test payment – no money is charged。是 WooCommerce 货到付款方式用于开发演示，不对接支付商、不扣款；测试订单进入 processing。
- 浏览器原生区块提交成功后跳 WooCommerce order-received；确认字段快照保留当时文案及 UTC 时间；顾客与管理员邮件仅由本机 Mailpit 接收。
- `wp peptide demo commerce --disable` 关闭测试付款、删除测试区域，但保留美国销售范围。正式上线前需要关闭/替换测试配置。

正式收款、真实运费/税费、具体配送政策、Terms/Privacy 链接和正式邮件服务另行配置与验证。本地通过不代表真实支付商或生产物流已就绪。
