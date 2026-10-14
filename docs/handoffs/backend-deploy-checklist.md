# 后端上线清单（DEPLOY-01，后端部分）

- 作者：Claude（claude/backend）
- 日期：2026-10-14
- 状态：**准备文档**。服务器尚未选定，以下步骤都还没有执行。
- 配套命令：`wp peptide doctor` 会逐项检查本清单中能自动检查的项目（fail = 上线前必须处理，warn = 需要确认）。

## 0. 前置条件（由用户负责，不满足就不能上线）

1. **合规结论**：商品清单中有美国处方药或未获批药物。需要由专业律师（建议找熟悉 FDA 事务的美国律师）确认哪些产品可以合法在线销售给哪些客户。只有确认可售的产品才能去掉 demo 标记、正式上架。
2. **支付服务商**书面确认接受这类商品；**主机商**和**发信服务**的使用条款也要同样确认。
3. 正式内容：条款（Terms）、隐私政策（Privacy）、退换货与配送政策、用途声明、结账确认语。
4. 真实运费规则、真实商品资料（规格、价格、图片、描述）、批次和检测报告。

## 1. 服务器要求

- PHP 8.2 或 8.3（本地开发用的是 8.3.29）；MySQL 8.0；WordPress 7.1.x；WooCommerce 11.x。
- 内存 2 GB 以上；免费 SSL；每天自动备份；能设置真实的系统 cron。
- 优先使用 Apache。如果用 Nginx，**必须**把报告私有目录放在网站根目录之外（见第 3 步）。

## 2. 搬迁步骤

1. 本地执行 `wp db export`，导出数据库（不进 Git）。
2. 在服务器装好 WordPress 和 WooCommerce；把仓库里的 `wp-content/plugins/peptide-core/`（main 分支）和 Codex 的主题部署上去。
3. 导入数据库后执行 `wp search-replace 'http://vialcovepeptidescom.local' 'https://<正式域名>' --all-tables`。
4. 把本地私有目录 `D:\Codex\Sites\vialcovepeptidescom\app\private\peptide-core` 里的报告文件复制到服务器的私有目录（只复制真实报告，不复制演示 PDF）。
5. `wp plugin activate woocommerce peptide-core`，然后执行 `wp peptide doctor`。

## 3. wp-config.php（服务器）

```php
define( 'PEPTIDE_CORE_PRIVATE_DIR', '/home/<user>/private/peptide-core' ); // 网站根目录之外，PHP 可写
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISALLOW_FILE_EDIT', true );   // 禁止在后台直接编辑主题和插件代码
define( 'DISABLE_WP_CRON', true );      // 改用系统 cron：每 5 分钟执行一次 wp cron event run --due-now
```

数据库口令、盐值等用服务商面板生成，**不要进 Git，也不要发到聊天里**。

## 4. 清理开发配置（按顺序执行）

| 步骤 | 命令或操作 | doctor 检查项 |
| --- | --- | --- |
| 关闭测试付款和测试运费 | `wp peptide demo commerce --disable` | Test payment / Shipping |
| 删除演示种子数据和演示订单 | `wp peptide demo purge --yes` | Demo seed records |
| 处理报价表导入的商品 | 只有确认可售的才去掉 demo 标记；其余用 `wp peptide import delete --tag=quote-2026-10-14 --yes` 删除，或保持草稿状态 | Products marked as demo |
| 关闭演示模式 | 后台“WooCommerce → VialCove 设置” | Demo mode |
| 删除本地测试订单 #54 和退款 #55 | 后台订单页 | — |
| 发件人邮箱和管理员邮箱改成正式域名邮箱 | WooCommerce → 设置 → 邮件；设置 → 常规 | Store email sender / Admin email |

## 5. 正式配置

1. **真实支付**：使用支付服务商的官方 WooCommerce 插件，先用沙箱模式下一单测试，再切换到正式模式。
2. **配送**：按用户给的真实运费规则建配送区域；不写没有依据的时效承诺。
3. **政策页面**：WooCommerce → 设置 → 高级 → 条款页面；设置 → 隐私 → 隐私政策页面。
4. **结账确认语和用途声明**：在“VialCove 设置”中填写，然后真实下一单，确认订单上保存了确认快照。
5. **发信**：安装 SMTP 插件，接 Amazon SES 或 Postmark；在域名 DNS 里配置 SPF、DKIM、DMARC；给真实邮箱下一单，确认能收到邮件且没有进垃圾箱。
6. **Cloudflare**：开启代理和 SSL（Full strict）。账户、购物车、结账、`/wp-json/peptide/v1/reports/*` 和 `/wp-json/peptide/v1/me/*` **不能缓存**。
7. **账号安全**：管理员使用强密码并开启两步验证；员工账号用 shop_manager 角色（这个角色看不到插件设置页）。

## 6. 上线后验证

- `wp peptide doctor`：不能有 fail。
- 访客：浏览商品、查批次、下载公开报告；登录后能下载 registered 级别的报告。
- 下一单真实小额订单，覆盖：支付 → 发货时指定批次 → 顾客在“我的订单”里看到批次号并下载报告 → 订单上有确认快照 → 邮件正常收到。
- 确认备份能恢复（至少做一次恢复演练）。
- 确认报告私有目录无法通过网址直接访问。
