# Peptide Core（后端业务插件）

负责人：Claude。方案见 docs/handoffs/backend-plan-v1.md 与 backend-plan-v2.md。

状态：BE-02 已在本地 WordPress 7.1.3 + WooCommerce 11.2.1（PHP 8.3.29）中启用并通过冒烟测试（2026-10-12）。后台界面尚未人工点验。

## 本地开发站点

- LocalWP 站点 `vialcovepeptides.com`，目录 `D:\Codex\Sites\vialcovepeptidescom`，本地地址 `http://vialcovepeptidescom.local`。
- 本插件目录通过目录联接挂载到站点的 `wp-content/plugins/peptide-core`，代码只在仓库这一份。
- 报告私有目录：`D:\Codex\Sites\vialcovepeptidescom\app\private\peptide-core`（wp-config.php 中的 `PEPTIDE_CORE_PRIVATE_DIR`，位于网站根目录之外）。
- 已开启 `WP_DEBUG` 与 `WP_DEBUG_LOG`（日志写入 `wp-content/debug.log`，不在页面显示）。
- 冒烟测试：`wp eval-file wp-content/plugins/peptide-core/tests/smoke-save-logic.php`（需先 `wp peptide demo seed`，只用于开发站点）。

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

## 尚未实现

- 前台接口（BE-03）：Store API 扩展、`peptide/v1` 只读接口、报告下载。
- 订单行与批次关联（BE-04）、结账确认项（BE-05）、真实数据导入（BE-06）。
- 支付与物流：按用户安排暂时搁置，先由 Codex 设计界面。
