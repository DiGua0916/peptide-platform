# 后端方案 v2（BE-01 修订）

- 任务：BE-01（v2 修订）
- 作者：Claude（claude/backend）
- 日期：2026-10-11
- 状态：**方案稿，供 Codex 评审**。路由和字段**均未实现、未发布**。
- 与 v1 的关系：本文只写**变化的部分**；没有提到的内容（报告版本与状态规则、错误格式、私有文件、演示数据标注、内容红线、本地验证方式等）沿用 [backend-plan-v1.md](backend-plan-v1.md)。

## 0. 触发修订的用户决定（2026-10-11，地瓜在 Claude 对话中确认）

| 问题 | 用户回答 | 对后端的影响 |
| --- | --- | --- |
| 比赛演示还是真实运营 | **之后要真实运营，需要在线下单** | 引入 WooCommerce；v1 的“首版不用 WooCommerce”作废 |
| 产品类别、客户、真实资料 | **都有**（具体内容尚未提供） | 数据结构按真实数据设计，开发期仍先用演示数据；需要导入通道 |
| 本地环境、截止时间 | 不了解 LocalWP；截止时间不用管 | 继续建议 LocalWP（见 G 节），需用户同意后安装；计划不按截止时间压缩 |

这些决定尚未写入共享的 docs/REQUIREMENTS.md，需要集成负责人（Codex）同步。

---

## B'. 架构：采用 WooCommerce

**结论：** 商品、购物车、结账、订单、库存、顾客账户全部使用 WooCommerce；插件 peptide-core 只做 WooCommerce 没有的部分：**批次、检测报告、订单与批次的关联、报告权限、合规确认**。不另建商品或订单系统（AGENTS.md 的要求）。

```
主题 peptide-site (Codex)
  ├─ 商品列表 / 详情 / 购物车 / 结账 / 我的账户：WooCommerce 模板或 Store API
  └─ 批次、报告、批次查询：peptide-core 的接口或 PHP 函数
          │
插件 peptide-core (Claude)
  ├─ 批次 pc_batch、报告 pc_report（自定义文章类型，沿用 v1）
  ├─ 商品附加字段（规格表、演示标记）——挂在 WooCommerce 商品上
  ├─ 订单行 ↔ 批次关联（发货时由管理员指定）
  ├─ 报告权限：公开 / 登录 / 审核 / 已购该批次
  ├─ Store API 扩展：在 WooCommerce 商品数据里附加批次与报告摘要
  ├─ peptide/v1：批次、报告下载、批次查询、站点配置
  └─ 结账合规确认（文案由用户提供，可配置）
          │
WooCommerce（商品、库存、购物车、结账、订单、顾客、邮件）
WordPress 核心（用户、角色、媒体）
```

技术要点：

1. 插件头声明 `Requires Plugins: woocommerce`，没有 WooCommerce 时不加载业务功能，后台给出提示。
2. 声明兼容 WooCommerce 高性能订单存储（HPOS）；订单只通过 WooCommerce 的 CRUD 接口（如 `wc_get_order()`）读写，不直接查订单表。
3. 不修改 WooCommerce 源码；只用它公开的钩子、Store API 扩展机制和模板覆盖。
4. 浏览器只用 Store API（`/wp-json/wc/store/v1/...`）。**WooCommerce REST API v3 的密钥（consumer key / secret）和支付网关密钥绝不出现在前端。**
5. 支付：开发期只用 WooCommerce 自带的线下支付方式（如银行转账）或支付插件的测试模式；**不接真实收款**。选择真实支付渠道前，需要用户确认该渠道接受此类商品（见 I 节）。

---

## C'. 数据模型变化

### C'1. 关系

```
WooCommerce 商品（product，可为可变商品）── 商品分类 product_cat
      │ 1
      ▼ n
pc_batch（批次）── batch_no 全局唯一
      │ 1
      ▼ n
pc_report（检测报告，版本 / 状态 / 访问级别同 v1）

WooCommerce 订单 ── 订单行 ── _pc_batch_id（发货时指定，可空）
```

### C'2. 商品：改用 WooCommerce 商品

- v1 的 `pc_product` 和 `pc_category` **取消**，改用 WooCommerce 的 `product` 和 `product_cat`。
- WooCommerce 自带的：名称、slug、描述、图片、价格、SKU（对应 v1 的 `catalog_no`）、库存、分类、上下架。
- **包装规格**（如不同毫克数）【建议】用可变商品：属性 `pa_pack_size`，每个规格是一个变体，各自有价格、SKU、库存。具体规格集合等用户提供真实产品后确定。
- peptide-core 在商品上附加的字段（存为商品元数据，后台在商品编辑页加面板）：

| 字段 | 类型 | 必选 | 说明 |
| --- | --- | --- | --- |
| is_demo | bool | 是 | 演示商品标记 |
| specs | {key,label,value,unit\|null}[] | 否 | 规格表，同 v1；具体规格项按真实产品确定 |
| documents | {title,url,type}[] | 否 | 公开资料（非检测报告） |

- `price_display` 字段取消：价格由 WooCommerce 管理和显示。
- 仍然**不设**用途、剂量、功效类字段。

### C'3. 批次：`product_id` 指向 WooCommerce 商品

- `pc_batch.product_id` = WooCommerce 父商品 ID（不是变体 ID）。
- 新增可选字段 `variation_ids: int[]`：此批次适用于哪些包装规格；为空表示适用于全部规格。
- 新增 `status`：`active` / `depleted`（已售完，前台仍可查询报告）。
- **库存不再放在批次上。** 可售库存只以 WooCommerce 商品 / 变体的库存为准，避免两份库存数据不一致。v1 的 `stock` 字段取消。
- 【假设】只有一个发货仓（美国仓），WooCommerce 的单一库存即代表美国仓。若需要多仓库分别计库存，需另行评估（WooCommerce 本身不支持多仓）。

### C'4. 订单与批次的关联（新增）

- 每个订单行增加元数据 `_pc_batch_id`（可空）。
- **由管理员在发货时指定**这一行实际发出的批次（订单编辑页的下拉框，只列出该商品 / 该规格的 active 批次）。下单时不让顾客选批次【建议，简化库存逻辑】。
- 指定后：
  - 顾客在“我的账户 → 订单详情”中看到每行商品的批次号，并可下载该批次已发布的报告。
  - 报告权限新增一条：**订单所有者可以下载自己订单中批次的报告**，即使该报告是 registered / approved 级别。`locked_reason` 不变，可下载时 `access_reason` 为 `order_owner`。
- 订单状态完全使用 WooCommerce 的（待付款、处理中、保留、已完成、已取消、已退款、失败）。v1 的询价“履约状态”不再需要。

### C'5. 询价：降为可选

在线下单是主流程。v1 的询价（`pc_inquiry`，含幂等、限流、蜜罐设计）**保留设计但推迟**，仅当需要“大批量 / 定制报价”时再做（见阶段表 BE-07）。

---

## D'. 接口变化（未发布）

### D'1. 商品、购物车、结账：使用 WooCommerce

| 用途 | 来源 | 说明 |
| --- | --- | --- |
| 商品列表、搜索、分类筛选 | WooCommerce Store API `GET /wc/store/v1/products` 或主题模板 | 由 Codex 选择模板渲染或 Store API |
| 商品详情 | 同上；或模板中 `wc_get_product()` | 按 slug 取商品的具体方式在实现时核实 |
| 购物车、结账 | Store API `/wc/store/v1/cart`、`/checkout`，或 WooCommerce 结账区块 / 模板 | 按 Store API 的要求携带 nonce 或 Cart-Token |
| 我的订单 | WooCommerce“我的账户”页面 | peptide-core 在订单详情中附加批次与报告 |

**Store API 扩展【建议】：** peptide-core 在 Store API 的商品数据里附加命名空间为 `peptide_core` 的字段：

```json
{
  "id": 101,
  "name": "Demo Peptide A",
  "slug": "demo-peptide-a",
  "prices": { "...": "WooCommerce 原有字段" },
  "extensions": {
    "peptide_core": {
      "is_demo": true,
      "specs": [ { "key": "appearance", "label": "Appearance", "value": "Demo value", "unit": null } ],
      "batch_count": 2,
      "report_state": "available"
    }
  }
}
```

`report_state` 是该商品所有公开批次中“最好的”状态（available > restricted > pending > none），用于列表页显示“有报告”标记。

### D'2. peptide/v1 接口清单（修订）

| # | 方法与路径 | 用途 | 权限 | 与 v1 相比 |
| --- | --- | --- | --- | --- |
| 1 | GET `/site` | 演示模式、用途声明、合规确认文案 | 公开 | 去掉询价相关字段，增加 `checkout_ack` |
| 2 | GET `/products/{slug}/batches` | 某商品的公开批次与报告 | 公开 | 替代 v1 的 `/products` 和 `/products/{slug}` |
| 3 | GET `/batches/lookup?batch_no=` | 按批次号查询 | 公开（限流） | 不变；附带的 `product` 改为 WooCommerce 商品摘要 |
| 4 | GET `/reports/{id}/file` | 报告下载 | 按报告访问级别，或订单所有者 | 增加订单所有者规则 |
| 5 | GET `/me/orders/{order_number}/batches` | 我的某个订单各行的批次与报告 | 订单所有者 | 新增；主题若直接用“我的账户”模板，可改用 PHP 函数 |
| — | POST `/inquiries`、GET `/me/inquiries` | 询价 | — | 推迟到 BE-07 |

返回字段、错误格式、`report_state` 规则、报告状态行为与 v1 的 C3、C4、D1 节相同。

`GET /site` 示例：

```json
{
  "demo_mode": true,
  "demo_notice": "This site is a demonstration. Product, batch and report data are synthetic.",
  "use_statement_html": null,
  "checkout_ack": { "enabled": false, "label_html": null }
}
```

`checkout_ack` 为结账前的确认勾选项，文案由用户提供；`enabled=false` 时前端不显示。

`GET /me/orders/{order_number}/batches` 示例：

```json
{
  "order_number": "1024",
  "items": [
    {
      "product": { "slug": "demo-peptide-a", "name": "Demo Peptide A" },
      "variation_label": "Demo pack",
      "quantity": 2,
      "batch": {
        "batch_no": "DEMO-A-2401",
        "is_demo": true,
        "report_state": "available",
        "reports": [ { "id": 3001, "title": "Demo Report (watermarked)", "can_download": true, "download_url": "/wp-json/peptide/v1/reports/3001/file", "access_reason": "order_owner" } ]
      }
    },
    {
      "product": { "slug": "demo-peptide-b", "name": "Demo Peptide B" },
      "variation_label": null,
      "quantity": 1,
      "batch": null
    }
  ]
}
```

`batch: null` 表示尚未指定批次（未发货），前端显示“发货后可查看批次”。

错误：401 未登录、404 订单不存在或不属于本人（不区分）、500。

---

## E'. 角色变化

WooCommerce 自带角色可以直接对应 v1 的建议角色，不再自建 `pc_buyer`、`pc_manager`：

| v1 建议 | v2 对应 | 补充 |
| --- | --- | --- |
| 采购用户 `pc_buyer` | WooCommerce `customer`（结账时注册或自助注册） | 是否允许自助注册在 WooCommerce 设置中决定 |
| 已审核采购用户 | `customer` + 用户元数据 `pc_buyer_approved` | 不变 |
| 目录管理员 `pc_manager` | WooCommerce `shop_manager` | 给它加上 `pc_manage_batches`、`pc_assign_batches` 能力 |
| 管理员 | `administrator` | 不变 |

v1 的权限表其余部分不变；新增“订单所有者可下载本单批次报告”。

---

## F'. 最小可演示流程（修订）

1. 访客浏览商品列表，看到演示标记和“有报告”标记（Store API + 扩展字段）。
2. 进入商品详情，查看规格、包装规格和价格，以及批次列表与报告（一个可下载、一个“登录后可查看”）。
3. 加入购物车 → 结账（测试支付方式，不真实扣款）→ 注册账户 → 下单成功。
4. 管理员在后台看到订单，为每一行指定批次，并把状态改为“已完成”。本地环境的邮件被 LocalWP 的邮件捕获工具拦截，不会真正发出。
5. 顾客在“我的账户 → 订单”里看到每行商品的批次号，并下载该批次的报告（包括原本仅登录可见的报告）。
6. 任何人在批次查询页输入批次号，都能查到对应的批次和公开报告。

---

## F'2. 分阶段计划（修订）

| 编号【建议】 | 内容 | 前置条件 |
| --- | --- | --- |
| ENV-01 | 安装 LocalWP，在 D 盘建后端开发站点，安装 WooCommerce（装在站点里，不进 Git），用目录联接挂载 peptide-core | **用户同意安装 LocalWP** |
| BE-02 | 插件骨架：依赖检查、HPOS 兼容声明、批次 / 报告自定义文章类型、商品附加字段面板、能力授予、私有文件目录、`wp peptide demo seed / purge`（含演示 WooCommerce 商品） | ENV-01 |
| BE-03 | 只读接口：Store API 扩展、`/site`、`/products/{slug}/batches`、`/batches/lookup`、`/reports/{id}/file`；PHP 读取函数 | BE-02 |
| BE-04 | 订单行指定批次（后台）、`/me/orders/{n}/batches`、“我的账户”订单详情附加批次与报告、订单所有者报告权限 | BE-03 |
| BE-05 | 结账合规确认项（可配置，记录在订单上）、测试支付方式配置说明、本地邮件捕获验证 | BE-04 |
| BE-06 | 真实数据导入：商品用 WooCommerce 自带的 CSV 导入；批次 / 报告用 `wp peptide import` 读取 CSV 和报告文件 | 用户提供真实资料 |
| BE-07（可选） | 大批量询价（v1 设计） | 用户需要时 |

---

## G'. 本地环境补充说明

**LocalWP 是什么：** WordPress 本身只是一套网站程序，要运行它还需要 PHP、MySQL 数据库和网页服务器。LocalWP（软件名为 Local）是一个免费的桌面工具，把这些组件打包在一起，让你在自己电脑上点几下就能建出一个只有本机能访问的 WordPress 网站，用于开发和测试，不会发布到互联网。它不是 WordPress 的替代品，而是运行 WordPress 的本地环境。

- 发行方：WP Engine；可通过 winget 安装（包名 `Flywheel.Local`，当前版本 10.1.2，许可：专有免费软件）。
- 自带邮件捕获工具（开发期的订单邮件不会真正发出）和 WP-CLI。
- 站点目录建议放在 `D:\Codex\Sites\`（v1 G1 节）。程序本身可能默认装在 C 盘，安装时再看能否改到 D 盘。

---

## I. 真实运营前的合规与上线清单（新增，需用户负责）

在线销售多肽类商品，在不同国家 / 地区可能受到药品、化学品或进口相关的监管，支付渠道也可能把此类商品列为受限类目。**这些我无法替你判断**，上线前需要由你（或专业人士）确认：

1. 每个商品在目标销售国家的法律分类，以及是否允许在线销售给目标客户。
2. 支付服务商是否接受此类商品（在其书面政策或客户经理处确认）。
3. 运输、进出口和美国仓库发货的限制。
4. 网站条款、隐私政策、退款政策等页面文本。
5. 是否需要在结账时让顾客确认某项声明（技术上已预留 `checkout_ack`，文案由你提供）。
6. 是否需要限制可销售的国家（WooCommerce 设置中可配置“销售地区”）。

后端只提供技术开关，不替你写合规声明，也不预设商品用途。

---

## H'. 关键问题（修订后，最多三个）

1. **是否同意在本机安装 LocalWP？**（免费，通过 winget 安装，程序可能装在 C 盘，网站数据放 D 盘）——这是 ENV-01 和之后所有实现工作的前提。
2. **目标客户和销售国家**：主要卖给企业 / 机构，还是个人？卖到哪些国家？——决定结账必填字段（如公司名、税号）、是否开放自助注册、报告默认访问级别、销售地区设置。
3. **真实资料什么时候能提供？** 首批商品清单（名称、包装规格、价格）、批次号、检测报告文件。格式不限（Excel / PDF 均可），到时我做导入。开发期先用标注“Demo”的演示数据，不影响进度。
