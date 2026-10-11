# 后端方案 v1（BE-01）

- 任务：BE-01
- 作者：Claude（claude/backend）
- 日期：2026-10-11
- 状态：**方案稿，供 Codex 评审**。本文中的路由、字段和返回格式**均未实现、未发布**，不能直接调用；正式契约以集成后更新的 docs/CONTRACT.md 为准。

标注约定：

- 【已定】来自 REQUIREMENTS.md / AGENTS.md 的已确认事项。
- 【建议】Claude 给出的默认方案，可被用户或 Codex 否决。
- 【假设】为推进方案做出的假设，信息到位后需复核。
- 【待定】必须等用户提供信息才能定。

---

## A. 背景、现状与职责复述

- 【已定】面向海外的多肽独立站，用于省级技能大赛；平台为自托管 WordPress。业务背景（以色列实验室、中国源头厂家、美国仓库）由用户提出，具体资料和合作证明尚未提供。
- 【已定】Codex 负责主题 `wp-content/themes/peptide-site/` 与前端交互；Claude 负责业务插件 `wp-content/plugins/peptide-core/`、数据、权限、接口。
- 【已定】当前仓库只有协作文档和两个 README 占位目录；没有 WordPress、没有插件代码、没有数据库。
- 【待定】比赛规则、产品类别与用途、目标客户、是否真实运营、是否需要交易、品牌、本地环境。
- 【建议】在比赛规则未知时，后端以“可演示、可替换数据源、不编造事实”为原则：所有演示记录带 `is_demo` 标记，接口结构不依赖具体产品类别。

内容红线（来自 AGENTS.md，后端在数据层落实）：

- 不设“用途 / 剂量 / 功效”类字段；不预设科研或人体用途。用途说明作为站点级可配置文本，由用户提供内容。
- 实验室、厂家、检测机构名称字段允许为空；为空时前端不显示，不用占位机构名填充。
- 演示报告文件必须带明显水印（如 “DEMO – NOT A REAL TEST REPORT”），不生成看似真实的检测结论数值。

---

## B. 后端架构与 WooCommerce 取舍

### B1. 总体架构【建议】

```
浏览器 ──(页面请求)──> 主题 peptide-site (Codex)
   │                       │  调用插件提供的 PHP 读取函数做服务端渲染（SEO 友好）
   │                       ▼
   └──(fetch /wp-json)──> 插件 peptide-core (Claude)
                           ├─ 数据模型：自定义文章类型 + 文章元数据 + 分类法
                           ├─ 服务层：查询、权限判断、校验（REST 与 PHP 共用）
                           ├─ REST 控制器：命名空间 peptide/v1
                           ├─ 后台：列表列、编辑元框、询价状态管理
                           ├─ 私有文件：报告文件受控下载
                           └─ WP-CLI：演示数据导入 / 清除
                           ▼
                     WordPress 核心（用户、角色、媒体、数据库）
```

要点：

1. **服务层单一出口**。REST 和主题用的 PHP 函数调用同一组服务函数，返回同样形状的数组，避免两套逻辑出现权限差异。
2. **不另建后端框架、不加 Composer 依赖**（首版只用 WordPress 自带能力）。将来若加 PHPUnit / PHPCS，属于开发依赖，单独说明并经同意。
3. **主题不直接读元数据键**。元数据键是插件内部实现，主题通过插件函数或 REST 取数，便于后续迁移到 WooCommerce 或自定义表。

### B2. 存储选型【建议】

| 方案 | 优点 | 缺点 | 结论 |
| --- | --- | --- | --- |
| 自定义文章类型（CPT）+ 元数据 | 自带后台界面、媒体关联、修订、导出、REST 基础；学习和演示成本低 | 复杂查询性能一般；关联需自己维护 | **首版采用** |
| 自定义数据表 | 结构清晰、查询快 | 需写迁移、后台界面全部自建 | 数据量大或需复杂报表时再考虑 |
| 直接使用 WooCommerce 商品 | 商品、库存、订单现成 | 引入交易体系，见 B3 | 视 B3 决定 |

### B3. WooCommerce 是否必要

**结论【建议】：首版不引入 WooCommerce，采用“询价（RFQ）”流程；保留切换路径。**

理由：

1. Codex 提出的首版流程是“检索 → 规格与批次报告 → 询价 → 后台处理 → 状态查询”，其中没有购物车、在线支付、实时扣库存，WooCommerce 的核心价值用不上。
2. 产品、批次、报告的关系（一品多批、一批多报告、报告有版本和访问级别）WooCommerce 本身不提供，无论用不用它都要在插件里自建。
3. 引入交易会带来税费、运费、退款、隐私、支付合规等一整套要求。多肽类商品在支付通道中**可能**属于需额外审核的类目——这一点我没有核实，若走真实运营需用户向支付服务商确认。
4. 比赛演示阶段，减少一个大型依赖能降低环境搭建和调试成本。

**何时应改用 WooCommerce【待定】**：比赛评分或用户明确要求“在线下单 / 支付 / 订单管理”时。届时做法：

- 产品主体改为 WooCommerce 的 `product`，`pc_batch.product_id` 改指向 Woo 商品 ID；批次、报告、询价模型不变。
- 支付只用沙盒 / 测试模式，不接真实收款。
- 因为主题只经由插件服务层取数，切换主要影响插件内部，接口字段保持不变（`product.source` 字段标识来源）。

---

## C. 数据对象与字段

### C1. 关系

```
pc_category (分类法) ──< pc_product (产品)
                               │ 1
                               │
                               ▼ n
                          pc_batch (批次)  ── batch_no 全局唯一
                               │ 1
                               ▼ n
                          pc_report (检测报告，含版本)
                               │ 引用
                               ▼
                          私有文件（附件）

pc_inquiry (询价) ── items[] 引用 product_id，可选 batch_id
               └── user_id（可空，访客提交时为空）
```

- 一个产品多个批次；一个批次多份报告（不同类型，或同一类型的多个版本）。
- 报告**只挂批次**，不直接挂产品。产品级的通用资料（如说明书类文件）用产品的 `documents` 字段，与“检测报告”区分。
- 所有记录的 `id` 是 WordPress 文章 ID，**不同环境（后端开发库、前端开发库、集成库）之间会不同**。前端 URL 和代码中请用 `slug`（产品）和 `batch_no`（批次），不要硬编码数字 ID。

### C2. 产品 `pc_product`【建议】

| 字段 | 类型 | 必选 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 文章 ID，只读 |
| slug | string | 是 | URL 标识，唯一 |
| name | string | 是 | 产品名 |
| status | enum | 是 | `publish` / `draft`；前端只会拿到 publish |
| is_demo | bool | 是 | 演示数据标记，前端需显示 Demo 标识 |
| source | enum | 是 | `native`（插件 CPT），将来可为 `woocommerce` |
| short_description | string\|null | 否 | 纯文本 |
| description_html | string\|null | 否 | 已经服务端过滤的 HTML |
| categories | {slug,name}[] | 否 | 分类法 `pc_category`；空数组表示未分类 |
| specs | {key,label,value,unit\|null}[] | 否 | 通用规格键值表；具体规格项【待定】（取决于产品类别） |
| catalog_no | string\|null | 否 | 内部货号 |
| image | {url,alt,width,height}\|null | 否 | 特色图片 |
| documents | {title,url,type}[] | 否 | 公开资料（非检测报告） |
| price_display | enum | 是 | 首版固定 `inquiry`（不显示价格）；`fixed` 预留给将来 |
| batch_count | int | 是 | 可公开批次数 |
| updated_at | string(ISO 8601, UTC) | 是 | |

明确不设的字段：用途、剂量、功效、适应症、评价、虚构认证。

规格项说明【假设】：若产品为常见多肽目录形式，常见规格可能包括序列、分子式、分子量、纯度规格、外观、储存条件；这些放入 `specs`，由后台逐项填写，不在代码里写死，以免产品类别确认后返工。

### C3. 批次 `pc_batch`【建议】

| 字段 | 类型 | 必选 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | |
| batch_no | string | 是 | 全局唯一；格式校验：字母数字和 `-`，3–40 位 |
| product_id | int | 是 | 所属产品 |
| visibility | enum | 是 | `public` / `hidden`；hidden 只在后台可见 |
| is_demo | bool | 是 | |
| manufactured_on | date\|null | 否 | |
| retest_or_expiry_on | date\|null | 否 | 名称和含义【待定】（取决于产品类别） |
| origin_note | string\|null | 否 | 来源说明；为空时不显示，不填虚构厂家 |
| stock | object\|null | 否 | 见下；无数据源时为 null，前端不显示库存 |
| report_state | enum | 是 | 由服务端计算：`available` / `restricted` / `pending` / `none` |
| reports | Report[] | 是 | 当前用户可见的报告列表（可为空数组） |

库存 `stock`【假设：首版仅手工维护或演示】：

```json
{ "status": "in_stock | low | out | unknown", "location": "US | CN | null", "source": "manual | demo", "updated_at": "2026-10-11T08:00:00Z" }
```

- 不做实时库存；`source=demo` 时前端须标注演示。
- 美国仓 / 中国厂的发货时效**不设字段**，等有真实来源再加。

`report_state` 计算规则：

| 值 | 条件 | 前端建议文案 |
| --- | --- | --- |
| available | 至少一份当前用户可下载的已发布报告 | 显示报告列表 |
| restricted | 有已发布报告，但当前用户权限不足 | “登录后可查看” / “需审核后可查看” |
| pending | 批次标记“报告待上传”，或只有草稿报告 | “报告整理中” |
| none | 没有任何报告 | “暂无报告” |

### C4. 检测报告 `pc_report`【建议】

| 字段 | 类型 | 必选 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | |
| batch_id | int | 是 | |
| title | string | 是 | |
| type | enum | 是 | `coa` / `hplc` / `ms` / `other`；具体类型集合【待定】 |
| version | int | 是 | 同批次同类型从 1 递增 |
| status | enum | 是 | `draft` / `published` / `superseded` / `withdrawn` |
| is_current | bool | 是 | 同批次同类型中最新的 published 版本 |
| access | enum | 是 | `public` / `registered` / `approved` / `admin` |
| is_demo | bool | 是 | 演示报告文件必须带水印 |
| issued_on | date\|null | 否 | |
| issuer_name | string\|null | 否 | 只填真实机构；演示数据为 null |
| summary | string\|null | 否 | 文字说明；**不填虚构检测数值** |
| file | {mime,size_bytes}\|null | 否 | 不暴露服务器路径 |
| can_download | bool | 是 | 按当前用户计算 |
| download_url | string\|null | 否 | 不可下载时为 null |
| locked_reason | enum\|null | 否 | `login_required` / `approval_required` / null |
| superseded_by | int\|null | 否 | 被哪一版取代 |

状态行为：

| 情况 | 列表中的表现 | 下载接口 |
| --- | --- | --- |
| draft | 不出现（批次可能显示 pending） | 404（不暴露存在性） |
| published + 有权限 | 出现，可下载 | 200 文件流 |
| published + 无权限 | 出现，`can_download=false`，带 `locked_reason` | 401 或 403 |
| superseded（旧版本） | 默认不出现；请求 `include_history=1` 时出现并标注旧版本 | 与 published 相同的权限判断，响应头带 `X-PC-Report-Superseded: 1` |
| withdrawn | 不出现 | 410 |

文件存储：

- 报告文件不放在公开可直接访问的 URL 下。【建议】放到私有目录（优先 Web 根目录以外，路径由常量 `PEPTIDE_CORE_PRIVATE_DIR` 配置；开发环境退而放在 `uploads/peptide-core-private/` 并配置禁止直接访问，文件名随机）。
- 所有下载都走 `/reports/{id}/file`，由插件做权限判断后输出。
- 上传文件不进 Git（.gitignore 已排除 uploads）。

### C5. 询价 `pc_inquiry`【建议】

存储为非公开 CPT（后台可见、前台不可查询）。

| 字段 | 类型 | 必选 | 校验 / 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 仅后台和本人可见 |
| reference | string | 是 | 服务端生成，如 `INQ-20261011-7K3P`，对外展示用 |
| items | Item[] | 是 | 1–20 项 |
| items[].product_id | int | 是 | 必须是已发布产品 |
| items[].batch_id | int\|null | 否 | 若提供，必须属于该产品且为 public |
| items[].quantity | number | 是 | > 0，上限可配置 |
| items[].unit | enum | 是 | 首版 `mg` / `g` / `kg` / `unit`；集合【待定】 |
| contact.name | string | 是 | 1–100 字 |
| contact.email | string | 是 | `is_email()` 校验 |
| contact.company | string\|null | 【待定】 | B2B 场景建议必填；目标客户确认后决定 |
| contact.country | string | 是 | ISO 3166-1 alpha-2 |
| contact.phone | string\|null | 否 | |
| message | string\|null | 否 | ≤ 2000 字，纯文本 |
| privacy_consent | bool | 是 | 必须为 true；隐私说明文本由用户提供 |
| idempotency_key | string(UUID) | 是 | 前端每次打开表单生成一个，重试时沿用 |
| status | enum | 是 | 见下 |
| status_history | {status,at,customer_note\|null}[] | 是 | 本人可见 |
| internal_note | string\|null | 否 | **仅后台**，任何前端接口都不返回 |
| user_id | int\|null | 否 | 登录提交时记录 |
| ip_hash | string | 是 | 仅用于限流，存哈希不存原始 IP，不对外返回 |
| created_at / updated_at | string | 是 | |

状态流转【建议】：

```
submitted → in_review → quoted → accepted → preparing → shipped → completed
     └───────────┴──────────┴── closed（任一阶段可关闭）
```

- `preparing / shipped / completed` 为“履约状态”，首版只做手工更新和展示，不接物流接口【待定：是否需要】。
- 状态只能由管理员在后台改；前台只读。

重复提交与防滥用：

1. **幂等键**：相同 `idempotency_key` 24 小时内再次提交，返回第一次的结果（200，同一 reference），不新建记录。
2. **限流**：同一 IP 哈希每小时最多 5 次、同一邮箱每小时最多 5 次（可配置），超过返回 429。
3. **蜜罐字段** `website`：非空则静默按成功形态返回但不入库（不给机器人反馈）。
4. **CSRF**：主题输出 `wp_rest` nonce，请求头 `X-WP-Nonce`；登录用户必需，访客也建议携带。
5. 人机验证（验证码）首版不做，真实运营前再评估。

通知：首版不发邮件（BE-01 约束）；新询价在后台列表显示并计数。邮件通知列为后续可选阶段。

---

## D. 给前端的接口草案（未发布）

### D1. 通用约定

- 基础路径：`/wp-json/peptide/v1`（命名空间【建议】，首个实现任务前与 Codex 确认）。
- 格式：JSON，UTF-8；时间为 ISO 8601 UTC；日期为 `YYYY-MM-DD`。
- 认证：WordPress Cookie + `X-WP-Nonce`（`wp_rest`）。不使用应用密码或任何浏览器可见的密钥。
- 分页：请求 `page`（默认 1）、`per_page`（默认 12，最大 48）；响应体内带分页信息，同时保留 WordPress 的 `X-WP-Total` / `X-WP-TotalPages` 头。
- **空结果不是错误**：返回 200 和空 `items`。
- 错误统一为 WordPress `WP_Error` 形态：

```json
{ "code": "pc_invalid_param", "message": "Human readable message", "data": { "status": 400, "fields": { "contact.email": "invalid_email" } } }
```

| HTTP | code | 含义 | 前端建议状态 |
| --- | --- | --- | --- |
| 400 | pc_invalid_param | 输入格式错误；`data.fields` 给出字段级原因码 | 表单字段报错 |
| 401 | pc_login_required | 需要登录 | 引导登录 |
| 403 | pc_forbidden | 已登录但权限不足 | 权限不足提示 |
| 403 | rest_cookie_invalid_nonce | nonce 过期（WordPress 内置） | 刷新页面重试 |
| 404 | pc_not_found | 资源不存在或不可见 | 404 / 未找到 |
| 503 | pc_inquiry_disabled | 后台关闭了询价功能 | 暂停服务提示 |
| 410 | pc_report_withdrawn | 报告已撤回 | 报告不可用 |
| 429 | pc_rate_limited | 提交过于频繁；`data.retry_after` 秒 | 稍后再试 |
| 500 | pc_server_error | 服务端错误，不含内部细节 | 通用错误 + 重试 |

字段级原因码（`data.fields` 的值）：`required`、`invalid_email`、`invalid_country`、`too_long`、`out_of_range`、`not_found`、`batch_mismatch`、`consent_required`。前端据此本地化文案。

### D2. 接口清单

| # | 方法与路径 | 用途 | 权限 | 阶段 |
| --- | --- | --- | --- | --- |
| 1 | GET `/site` | 站点级配置：演示模式、用途声明文本、询价开关、单位列表 | 公开 | P2 |
| 2 | GET `/products` | 产品检索与列表 | 公开 | P2 |
| 3 | GET `/products/{slug}` | 产品详情（含可见批次与报告） | 公开 | P2 |
| 4 | GET `/batches/lookup?batch_no=` | 按批次号查询 | 公开（限流） | P2 |
| 5 | GET `/reports/{id}/file` | 报告下载 | 按报告 access | P2 |
| 6 | POST `/inquiries` | 提交询价 | 公开（限流 + 幂等） | P3 |
| 7 | GET `/me/inquiries` | 我的询价列表 | 登录用户 | P4 |
| 8 | GET `/me/inquiries/{reference}` | 我的询价详情与状态历史 | 本人 | P4 |

后台的增删改走 wp-admin 界面，**首版不开放写入类 REST 给前端**（询价提交除外）。

### D3. 详细说明与模拟示例

以下示例数据全部为虚构的演示数据，名称刻意使用 “Demo”，不对应任何真实产品或机构。

**1) GET /site**

```json
{
  "demo_mode": true,
  "demo_notice": "This site is a demonstration. Product, batch and report data are synthetic.",
  "use_statement_html": null,
  "inquiry_enabled": true,
  "units": ["mg", "g", "kg", "unit"],
  "max_items_per_inquiry": 20
}
```

`use_statement_html` 为 null 时前端不显示用途声明区块（内容等用户提供）。

**2) GET /products?q=&category=&page=1&per_page=12**

请求参数：`q`（≤ 100 字，匹配名称 / 货号 / 规格值）、`category`（分类 slug）、`has_report`（`1` 只看有可下载报告的产品，可选）、`page`、`per_page`。

```json
{
  "items": [
    {
      "id": 101,
      "slug": "demo-peptide-a",
      "name": "Demo Peptide A",
      "is_demo": true,
      "source": "native",
      "short_description": "Synthetic demonstration record.",
      "categories": [{ "slug": "demo-category", "name": "Demo Category" }],
      "catalog_no": "DEMO-001",
      "image": null,
      "price_display": "inquiry",
      "batch_count": 2,
      "updated_at": "2026-10-11T08:00:00Z"
    }
  ],
  "page": 1,
  "per_page": 12,
  "total": 1,
  "total_pages": 1
}
```

状态：200 有结果 / 200 空 `items` / 400 参数非法（如 `per_page=500`） / 500。

**3) GET /products/{slug}?include_history=0**

在列表字段基础上增加 `description_html`、`specs`、`documents`、`batches`：

```json
{
  "id": 101,
  "slug": "demo-peptide-a",
  "name": "Demo Peptide A",
  "is_demo": true,
  "source": "native",
  "description_html": "<p>Synthetic demonstration record.</p>",
  "specs": [
    { "key": "appearance", "label": "Appearance", "value": "Demo value", "unit": null }
  ],
  "documents": [],
  "price_display": "inquiry",
  "batches": [
    {
      "id": 2001,
      "batch_no": "DEMO-A-2401",
      "is_demo": true,
      "manufactured_on": null,
      "retest_or_expiry_on": null,
      "origin_note": null,
      "stock": { "status": "unknown", "location": null, "source": "demo", "updated_at": "2026-10-11T08:00:00Z" },
      "report_state": "available",
      "reports": [
        {
          "id": 3001,
          "title": "Demo Report (watermarked)",
          "type": "coa",
          "version": 1,
          "status": "published",
          "is_current": true,
          "access": "public",
          "is_demo": true,
          "issued_on": null,
          "issuer_name": null,
          "summary": null,
          "file": { "mime": "application/pdf", "size_bytes": 182340 },
          "can_download": true,
          "download_url": "/wp-json/peptide/v1/reports/3001/file",
          "locked_reason": null,
          "superseded_by": null
        }
      ]
    },
    {
      "id": 2002,
      "batch_no": "DEMO-A-2402",
      "is_demo": true,
      "manufactured_on": null,
      "retest_or_expiry_on": null,
      "origin_note": null,
      "stock": null,
      "report_state": "restricted",
      "reports": [
        {
          "id": 3002,
          "title": "Demo Report (watermarked)",
          "type": "coa",
          "version": 1,
          "status": "published",
          "is_current": true,
          "access": "registered",
          "is_demo": true,
          "issued_on": null,
          "issuer_name": null,
          "summary": null,
          "file": null,
          "can_download": false,
          "download_url": null,
          "locked_reason": "login_required",
          "superseded_by": null
        }
      ]
    }
  ],
  "updated_at": "2026-10-11T08:00:00Z"
}
```

状态：200 / 404（不存在或未发布）/ 500。无权限下载的报告 `file` 为 null（不暴露文件信息）。

**4) GET /batches/lookup?batch_no=DEMO-A-2401**

返回单个批次对象（结构同上）并附 `product: {id, slug, name, is_demo}`。

状态：200 / 400（格式不合法）/ 404 `pc_not_found`（不存在或 hidden，二者不区分）/ 429 / 500。

**5) GET /reports/{id}/file**

成功返回文件流（`Content-Type` 为实际类型，`Content-Disposition: attachment`）。错误按 C4 表格：401 / 403 / 404 / 410 / 500，返回 JSON 错误体。前端用普通链接或 `fetch` 均可；若用 `fetch` 需带 nonce。

**6) POST /inquiries**

请求：

```json
{
  "items": [
    { "product_id": 101, "batch_id": 2001, "quantity": 5, "unit": "g" }
  ],
  "contact": {
    "name": "Demo Buyer",
    "email": "buyer@example.com",
    "company": "Example Co.",
    "country": "US",
    "phone": null
  },
  "message": "Please provide a quotation.",
  "privacy_consent": true,
  "idempotency_key": "4b0f7d4e-2a7b-4e6c-9a43-7c1d5e2f9a10",
  "website": ""
}
```

成功（201 新建；幂等重放为 200，内容相同）：

```json
{
  "reference": "INQ-20261011-7K3P",
  "status": "submitted",
  "created_at": "2026-10-11T08:30:00Z",
  "replayed": false
}
```

错误：400（`data.fields` 字段级原因）/ 503 `pc_inquiry_disabled` / 429 / 500。

响应中**不返回**询价 ID、邮箱回显以外的个人信息、内部备注或 IP 信息。

**7) GET /me/inquiries?page=1** 与 **8) GET /me/inquiries/{reference}**

```json
{
  "reference": "INQ-20261011-7K3P",
  "status": "quoted",
  "status_history": [
    { "status": "submitted", "at": "2026-10-11T08:30:00Z", "customer_note": null },
    { "status": "in_review", "at": "2026-10-11T09:00:00Z", "customer_note": null },
    { "status": "quoted", "at": "2026-10-12T02:00:00Z", "customer_note": "Quotation sent by email." }
  ],
  "items": [
    { "product": { "slug": "demo-peptide-a", "name": "Demo Peptide A" }, "batch_no": "DEMO-A-2401", "quantity": 5, "unit": "g" }
  ],
  "created_at": "2026-10-11T08:30:00Z",
  "updated_at": "2026-10-12T02:00:00Z"
}
```

状态：200 / 401 未登录 / 404（不存在或不属于本人，不区分）/ 500。

### D4. 主题侧 PHP 读取函数（服务端渲染用）【建议】

供主题模板直接调用，返回与 REST 相同形状的数组；失败返回 `WP_Error`：

- `peptide_core_get_products( array $args )`
- `peptide_core_get_product( string $slug )`
- `peptide_core_get_site_config()`

主题应先 `function_exists()` 检查，插件未启用时显示“数据不可用”状态而不是报致命错误。

### D5. 浏览器可见与服务端秘密的边界

| 可以出现在前端 | 只在服务端 |
| --- | --- |
| 已发布产品、public 批次、当前用户可见报告的元信息 | 数据库口令、盐值、任何 API 密钥（wp-config.php，已 gitignore） |
| `wp_rest` nonce | 报告文件的服务器路径、私有目录位置 |
| 本人的询价 reference、状态、客户可见备注 | `internal_note`、`ip_hash`、他人的询价、询价 ID |
| 演示标记 | 用户邮箱列表、后台 URL 之外的管理信息 |

---

## E. 角色与权限建议

以下角色中，**访客和管理员是 WordPress 已有概念；“采购用户”与“目录管理员”是建议，尚未确认**。

| 能力 | 访客 | 采购用户 `pc_buyer`【建议】 | 已审核采购用户【可选】 | 目录管理员 `pc_manager`【可选】 | 管理员 |
| --- | --- | --- | --- | --- | --- |
| 浏览已发布产品、public 批次 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 下载 access=public 报告 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 下载 access=registered 报告 | — | ✓ | ✓ | ✓ | ✓ |
| 下载 access=approved 报告 | — | — | ✓ | ✓ | ✓ |
| 提交询价 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 查看自己的询价与状态 | — | ✓ | ✓ | ✓ | ✓ |
| 维护产品 / 批次 / 报告 | — | — | — | ✓ | ✓ |
| 查看全部询价、改状态、写内部备注 | — | — | — | ✓ | ✓ |
| 插件设置、清除演示数据 | — | — | — | — | ✓ |

实现方式【建议】：自定义能力 `pc_manage_catalog`、`pc_manage_inquiries`、`pc_view_registered_reports`、`pc_view_approved_reports`、`pc_manage_settings`；“已审核”用用户元数据 `pc_buyer_approved` 表示，由管理员在用户资料页勾选。插件启用时添加角色和能力，删除插件时（uninstall）清理。

账号来源【假设】：首版不开放自助注册（需用户同意后才开启 WordPress 注册），演示用采购账号由管理员在后台创建。

---

## F. 最小可演示流程与分阶段计划

### F1. 最小可演示流程（MVP）【建议】

1. 访客进入产品列表，搜索 “Demo”，看到带 Demo 标识的产品（接口 2）。
2. 进入产品详情，看到两个批次：一个报告可下载（水印演示 PDF），一个显示“登录后可查看”（接口 3、5）。
3. 通过批次号查询页输入 `DEMO-A-2401`，定位到同一批次（接口 4）。
4. 选择批次提交询价，拿到 reference；重复点击提交不会产生两条记录（接口 6）。
5. 管理员在后台询价列表看到新记录，改为 `in_review` → `quoted`，填写客户可见备注。
6. 采购用户登录后，在“我的询价”看到状态时间线（接口 7、8），同时可以下载第 2 步中受限的报告。

这条流程同时演示了：一品多批、报告权限分级、空 / 受限状态、服务端校验、幂等、防滥用、角色权限。

### F2. 分阶段开发清单

| 阶段 | 编号【建议】 | 内容 | 前置条件 | 交付与验证 |
| --- | --- | --- | --- | --- |
| P0 | BE-01 | 本方案 | — | 本文档 |
| P1 | BE-02 | 插件骨架：主文件、CPT / 分类法 / 元数据注册、角色能力、后台编辑元框（批次挂产品、报告挂批次）、私有文件目录、WP-CLI `wp peptide demo seed / purge` | ENV-01 本地 WordPress 可运行；Codex 评审本方案 | 插件可启用 / 停用；后台可录入一品多批多报告；演示数据可一键导入和清除 |
| P2 | BE-03 | 只读接口 1–5 + PHP 读取函数 + 统一错误格式 | P1 | 每个接口的成功 / 空 / 400 / 401 / 403 / 404 用 curl 或 WP-CLI 验证并记录 |
| P3 | BE-04 | 询价提交：校验、幂等、限流、蜜罐、后台列表与状态流转、状态历史 | P2 | 重复提交、超限、字段错误的实际请求结果 |
| P4 | BE-05 | 采购用户：我的询价、受限报告、已审核标记 | P3；用户确认角色方案 | 不同角色账号的访问结果对比表 |
| P5 | 可选 | 邮件通知（测试 SMTP / 本地邮件捕获）、隐私数据导出与删除钩子、WooCommerce 适配、多语言 | 用户决定 | 按需求定 |

前端可并行的事情：P2 未完成前，Codex 可按 D3 的模拟示例做静态假数据联调；P2 完成后替换为真实接口。建议把 D3 示例在集成时放入共享的模拟数据目录（共享文件，需集成负责人决定位置）。

---

## G. 本地运行与验证建议

### G1. 运行环境【建议】

| 方案 | 适合度 | 说明 |
| --- | --- | --- |
| **LocalWP（推荐）** | 高 | 图形界面，Windows 友好；站点目录可设到 D 盘；自带 PHP / MySQL / 邮件捕获 / WP-CLI shell |
| wp-env（Docker） | 中 | 配置可提交到仓库、可复现；但需要 Docker Desktop，占用和安装位置需额外处理 |
| XAMPP 手动安装 | 低 | 配置步骤多，不易复现 |

建议版本：当前 WordPress 稳定版；PHP 8.2 或 8.3；MySQL 8.0 或 MariaDB 10.6+。具体版本在 ENV-01 中记录。

目录建议（均在 D 盘）：

```
D:\Codex\Sites\peptide-backend\      后端开发站点（独立数据库）
D:\Codex\Sites\peptide-frontend\     前端开发站点（独立数据库）
D:\Codex\Sites\peptide-integration\  集成站点（main 分支代码）
```

插件目录用 Windows 目录联接把仓库代码挂进站点，例如：

```
mklink /J D:\Codex\Sites\peptide-backend\app\public\wp-content\plugins\peptide-core D:\Codex\Projects\peptide-platform-worktrees\backend\wp-content\plugins\peptide-core
```

这样代码只有一份，在 Git 里；站点的数据库和上传文件留在站点目录，不进 Git。

### G2. 验证方式【建议】

不新增依赖即可做到的：

1. `php -l` 检查每个 PHP 文件语法。
2. WP-CLI：`wp plugin activate peptide-core`、`wp peptide demo seed`、`wp post list --post_type=pc_batch`。
3. 用 curl 调用每个接口，记录成功、空结果和各类错误的实际响应（写入交接记录）。
4. 用 2–3 个不同角色的测试账号核对权限表。
5. 打开 `WP_DEBUG` 与 `WP_DEBUG_LOG`，确认无警告。

需要同意后才引入的开发依赖（可选）：PHPUnit + WordPress 测试套件（自动化测试）、PHP_CodeSniffer + WordPress Coding Standards（代码规范）。

### G3. 本次检查中发现的环境问题

- 本机 PowerShell 中找不到 `git` 命令（PATH 中没有，常见安装位置也没有）。因此本次无法执行 `git status` 和提交。建议用户安装 Git for Windows，或告知 Codex 使用的 Git 路径。

---

## H. 关键问题（最多三个）

1. **项目定位与交易范围**：这是只用于比赛演示，还是之后要真实运营？比赛是否要求“在线下单 / 支付”的演示？
   — 决定是否引入 WooCommerce、是否需要邮件通知和隐私合规模块。默认按“比赛演示 + 询价流程、不接支付”推进。
2. **产品类别与目标客户**：首批大约是哪几类多肽产品？卖给谁（机构采购 / 企业 / 个人）？用户能否提供真实的批次和检测报告，还是全部使用演示数据？
   — 决定 `specs` 规格项、报告类型、询价是否要求公司名、报告默认访问级别、用途声明内容。默认按“B2B、全部演示数据、报告分 public / registered 两级”推进。
3. **本地环境与时间**：是否同意使用 LocalWP（站点放 D 盘）作为本地 WordPress 环境，并安装 Git for Windows？比赛的提交截止时间是什么时候？
   — 决定 ENV-01 和 BE-02 能否开始，以及 P3 之后的阶段是否需要压缩。
