# VialCove WordPress theme

负责人：Codex。2026-10-12 首版已挂载到本地 WordPress，并对接 Claude 的 WooCommerce + peptide-core。

## 本地预览

- 新版首页：<http://vialcovepeptidescom.local/>
- 商品：`/shop/`；详情：`/product/demo-peptide-a/`。
- 批次查询：`/batch-reports/`。
- 购物车、结账、账户：WooCommerce 现有页面，保留插件原生行为。
- 原版 Lunira 比较入口：<http://127.0.0.1:17842/>，与本主题互相独立。

主题正本为 `D:/Codex/Projects/peptide-platform-worktrees/frontend/wp-content/themes/peptide-site`。LocalWP 的 `D:/Codex/Sites/vialcovepeptidescom/app/public/wp-content/themes/peptide-site` 是指向正本的目录联接。编辑正本即可刷新查看，不需要构建。

本轮启用了 VialCove 主题（原为 twentytwentyfive），新建 Batch reports 页面并绑定 page-batch-reports.php，关闭了开发站点的 WooCommerce coming-soon 挡板。其余后台业务、账户语言、支付和物流配置未改。原设置记录：`D:/Codex/Tasks/peptide-platform/work/frontend-site-before.json`。

## 数据边界

- 商品、价格、库存、变体、加购、购物车、结账和账户使用 WooCommerce 原生 API/模板/区块。
- 商品的 `extensions.peptide_core` 用内部 Store API 请求读取并规范化 PHP 对象/数组，含 `is_demo`、`specs`、`batch_count`、`report_state`；不直接读取插件私有元数据键。
- 商品批次和报告调用 `peptide_core_get_product_batches()`，按插件返回的访问权限展示下载或登录提示。
- 查询页调用 `GET /wp-json/peptide/v1/batches/lookup?batch_no=`；已登录请求带 REST nonce，使用后端限流。处理 loading、400、401、403、404、410、429、5xx、断网和超时；新请求取消旧请求。
- 公开 PDF 沿用后端受控下载地址；前端不复制私有文件、不绕过权限。浏览器返回的文本用 textContent 渲染，下载地址须为同源 HTTP(S)。
- 购物车页订阅 WooCommerce Cart Store，数量修改和删除会同步页头数量。
- 未接收到真实产品图片时显示有 “Packaging concept” 标签的 CSS 包装示意；没有添加虚构商品、检测数值、评价或认证。

## 视觉与外部依赖

沿用用户选定 Lunira 的蓝白、留白、排版方向和 DNA 场景，页面结构及 WordPress 主题代码为本项目适配。不是把商业 ZIP 的 48 个页面全部移植到 WordPress。

- 复用 ZIP 中首页提供的 Spline URL：`https://prod.spline.design/omcKY0jf8uElEQht/scene.splinecode`。
- 浏览器按需载入固定版本 `@splinetool/runtime@2.0.75`（jsDelivr）；字体为 Google Fonts Inter / Inter Tight。
- 低动态偏好、节流模式或 3D 加载失败时使用本地 CSS DNA 示意；动画支持暂停，页面隐藏时暂停。
- 没有引入原 Webflow 的电商、表单、Rive 或第三方追踪脚本。源码原包仍在 Git 之外。
- Spline 和字体目前仍需外网，尚未做完整离线素材迁移；素材许可与可编辑源文件沿用购买包的实际授权边界。

实现参考：[WooCommerce classic theme handbook](https://developer.woocommerce.com/docs/theming/theme-development/classic-theme-developer-handbook)、[Cart Store](https://developer.woocommerce.com/docs/block-development/reference/data-store/cart/)、[WordPress theme functions](https://developer.wordpress.org/themes/classic-themes/basics/theme-functions/)。

## 初版验证（d7bfa64）

- 11 个主题 PHP 文件语法检查、JavaScript 语法、Git diff 检查。
- Headless Edge 真浏览器：首页品牌和后台商品、原版 Spline 加载；商城检索/无结果；商品规格/批次/公开 PDF 下载/受限提示；WooCommerce 加购、购物车与结账页面；查询公开/受限/待发布/不存在批次。
- 浏览器路由模拟：503、429，以及含 HTML 文本/危险下载 URL 的响应，确认错误提示、清空旧结果和安全渲染。模拟不改后端。
- 390px：首页、商城、详情、查询、购物车、账户均无横向溢出；移动菜单开关、Escape 关闭及导航。
- 独立检查：购物车数量 1→2、删除最后一件后 2→0，页头数量无需刷新即可更新。
- 结果：`D:/Codex/Tasks/peptide-platform/outputs/vialcove-verification.json`、`vialcove-cart-verification.json`；截图同目录 `vialcove-*.png`；脚本在任务 `work/verify-vialcove*.cjs`。

未验证：真实手机、登录后的报告下载 UI、真实变体商品、付款、物流、邮件、生产部署和完整无障碍审计。演示商品/报告仍由 Claude 插件创建，主题没有修改其业务记录。品牌故事按用户简报展示为概念稿，真实机构、资质、履约承诺待用户提供。

## INT-01 更新与验证（2026-10-12）

- 当前站点提示直接读取 demo_notice；可选用途声明从 use_statement_html 读取并清理输出。空声明不生成空模块。
- page-commerce.php 包裹 WooCommerce 原生区块，统一购物步骤、摘要、字段、支付/配送单选项、错误与确认框样式；不得换成经典结账 shortcode，否则 BE-05 确认项失效。
- 账户订单页的 .pc-order-batch 统一样式，未分配提示用公共订单 REST 加上订单行顺序匹配，不读私有元数据。完整后端 download_url 保留 nonce。
- 本轮 12 个 PHP 文件、JS 语法与 diff 检查通过。BE-05 的 17 项回归通过；浏览器 7 组检查、手机宽度布局、账户 PDF 真实下载、重复商品订单行、Store API 确认必填校验均通过。购物车数量同步再次通过。
- 验证结果与截图：D:/Codex/Tasks/peptide-platform/outputs/int01-*。测试设置、顾客与订单已还原/清理，原 #54/#55 未改。旧段中的“登录后报告下载未验证”已由本轮补齐。
- 仍待：实际网关/配送选项配置后的联调、真实支付、真实设备、正式文案/政策链接、真实变体商品、生产部署。未配置配送方法时 Woo 不展示配送步骤；没有为演示硬编码运费或支付品牌。

## PAY-SHIP-01 浏览器端到端验收（2026-10-12）

Claude 配置的美国范围、$0 测试配送及不扣款的测试付款已在浏览器验证。访客实际完成商品→购物车→结账→必选确认→下单成功；订单 processing，确认文案及 UTC 时间快照正确，Local Mailpit 收到顾客及管理员邮件。测试订单 #88/#89 已清理，原有 #54/#55、商品库存/销量和测试商务配置保留，临时确认文案恢复为空。

成功页使用 Your order. 标题、订单摘要卡片和独立地址区块；桌面两列对齐、390px 无横向溢出。未替换 Woo 订单模板/权限检查，仅包裹页面和添加样式。结果与截图在 D:/Codex/Tasks/peptide-platform/outputs/pay-ship-*。

以上补齐上节“实际测试支付/配送选项配置后联调”的待办；真实支付渠道、正式运费、政策/联系信息、生产邮件和上线仍待完成。
