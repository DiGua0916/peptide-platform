# Peptide Core（后端业务插件）

负责人：Claude。方案见 docs/handoffs/backend-plan-v1.md 与 backend-plan-v2.md。

状态：BE-02、BE-03、BE-04、BE-04b 已在本地 WordPress 7.1.3 + WooCommerce 11.2.1（PHP 8.3.29）中运行并通过冒烟测试（2026-10-12）。后台界面尚未人工点验。

## 本地开发站点

- LocalWP 站点 `vialcovepeptides.com`，目录 `D:\Codex\Sites\vialcovepeptidescom`，本地地址 `http://vialcovepeptidescom.local`。
- 本插件目录通过目录联接挂载到站点的 `wp-content/plugins/peptide-core`，代码只在仓库这一份。
- 报告私有目录：`D:\Codex\Sites\vialcovepeptidescom\app\private\peptide-core`（wp-config.php 中的 `PEPTIDE_CORE_PRIVATE_DIR`，位于网站根目录之外）。
- 已开启 `WP_DEBUG` 与 `WP_DEBUG_LOG`（日志写入 `wp-content/debug.log`，不在页面显示）。
- 冒烟测试（需先 `wp peptide demo seed`，只用于开发站点）：
  - `wp eval-file wp-content/plugins/peptide-core/tests/smoke-save-logic.php`（后台保存逻辑，9 项）
  - `wp eval-file wp-content/plugins/peptide-core/tests/smoke-read-api.php`（只读接口与报告权限，18 项）
  - `wp eval-file wp-content/plugins/peptide-core/tests/smoke-order-batches.php`（订单与批次关联，18 项）
  - `wp eval-file wp-content/plugins/peptide-core/tests/smoke-workbench.php`（后台工作台，13 项）

## 依赖

- WordPress 6.5+、PHP 8.1+
- WooCommerce（插件头 `Requires Plugins: woocommerce`；未启用时插件只显示提示，不加载功能）

不使用 Composer 或其他第三方库。

## 目录

| 文件 | 作用 |
| --- | --- |
| peptide-core.php | 插件入口、HPOS 兼容声明、启用钩子 |
| uninstall.php | 删除插件时移除能力和设置；**不删除**批次、报告和报告文件 |
| includes/class-post-types.php | 批次 `pc_batch`、报告 `pc_report`（不公开，后台在“产品”菜单下） |
| includes/class-meta.php | 所有元数据键与注册（主题不要直接读这些键） |
| includes/class-capabilities.php | 批次 / 报告能力，授予 administrator 和 shop_manager |
| includes/class-batches.php | 批次号规范化、格式校验、唯一性检查 |
| includes/class-reports.php | 报告版本号、发布时取代旧版本、删除时清理文件 |
| includes/class-private-files.php | 报告文件私有存储与上传校验 |
| includes/admin/ | 商品 “Peptide data” 面板、批次 / 报告编辑页、列表列 |
| includes/cli/ | `wp peptide demo seed / purge` 与演示 PDF 生成 |

## 报告文件存放位置

- 推荐在 wp-config.php 中定义网站根目录之外的路径：
  `define( 'PEPTIDE_CORE_PRIVATE_DIR', 'D:/Codex/Sites/peptide-backend/app/private/peptide-core' );`（示例，按实际站点路径修改）
- 未定义时使用 `uploads/peptide-core-private`，并写入 `.htaccess` 禁止访问。**Nginx 不读取 .htaccess**，用 Nginx 时必须定义上面的常量。
- 报告文件不进 Git。

## 演示数据

```
wp peptide demo seed
wp peptide demo purge --yes
```

创建 2 个演示商品（Demo Peptide A / B）、3 个批次（DEMO-A-2401、DEMO-A-2402、DEMO-B-2401）和 2 份带 “DEMO – NOT A REAL TEST REPORT” 水印的演示报告。所有记录都有演示标记；`purge` 只删除带演示标记的记录。

## 前台接口（BE-03，已实现）

| 接口 | 说明 |
| --- | --- |
| `GET /wp-json/peptide/v1/site` | 演示模式、演示提示、用途声明、结账确认项 |
| `GET /wp-json/peptide/v1/products/{slug}/batches?include_history=0\|1` | `{ product, batches[] }`，只含已发布且公开的批次 |
| `GET /wp-json/peptide/v1/batches/lookup?batch_no=` | 单个批次 + `product`；每个 IP 每 10 分钟最多 60 次 |
| `GET /wp-json/peptide/v1/reports/{id}/file` | 校验权限后输出文件；已登录用户的 `download_url` 自带 `_wpnonce` |
| Store API `GET /wp-json/wc/store/v1/products` | 每个商品附加 `extensions.peptide_core`：`is_demo`、`specs`、`batch_count`、`report_state` |

主题 PHP 函数：`peptide_core_get_site_config()`、`peptide_core_get_product_batches( $slug )`、`peptide_core_lookup_batch( $batch_no )`（定义在 includes/functions.php）。

错误格式：`{ code, message, data: { status, fields? } }`。错误码：`pc_invalid_param`（400，`fields` 值为 `required` / `invalid_format`）、`pc_login_required`（401）、`pc_forbidden`（403）、`pc_not_found`（404，不区分“不存在”和“不可见”）、`pc_report_withdrawn`（410）、`pc_rate_limited`（429，带 `retry_after`）。

## 订单与批次（BE-04，已实现）

- 后台订单编辑页新增“批次分配”面板：每个商品行可选同一商品、已发布、在售且适用该规格的批次；保存后写入订单行元数据 `_pc_batch_id`，并在订单上建立批次索引，同时自动写一条订单备注。需要 `pc_assign_batches` 能力（administrator、shop_manager 已有）。
- 订单所有者规则：用户在已付款订单（`wc_get_is_paid_statuses()`，默认 processing / completed）中收到过某批次，就可以下载该批次已发布的报告，`access_reason` 为 `order_owner`；仅员工可见的报告除外；批次被设为隐藏也照样可以下载。
- `GET /wp-json/peptide/v1/me/orders/{order_number}/batches`：未登录返回 401；订单不存在或不属于本人返回 404。未指定批次的行 `batch` 为 null。订单号目前就是订单 ID。
- “我的账户 → 订单详情”的商品行下显示批次号和可下载的报告链接；订单邮件只显示批次号，不带链接。
- 插件版本升级时（`peptide_core_version` 变化）自动补授新增的能力。

## 后台工作台（BE-04b，已实现）

后台首页的“VialCove 工作台”面板（有 edit_pc_batches 或 pc_assign_batches 能力的用户可见），放在第一列最上面：待指定批次的已付款订单（processing）、缺少已发布报告的公开批次、草稿报告数量、演示模式与演示数据数量、常用入口。同时移除 WordPress 的“快速草稿”和“活动与新闻”面板。品牌名取自设置项 `brand_name`（默认 VialCove）。

## 尚未实现

- 结账确认项（BE-05）、真实数据导入（BE-06）。
- 支付与物流：按用户安排暂时搁置，先由 Codex 设计界面。
