# VialCove WordPress theme

负责人：Codex。2026-10-12 首版已挂载到本地 WordPress，并对接 Claude 的 WooCommerce + peptide-core。

## 本地预览

- VISITOR-ANALYTICS-TEST-01（2026-10-22）：客户状态追踪已接通 peptide-core protocol 1，本地测试开关已开启。`inc/visitor-tracking.php` 读取插件配置，`assets/visitor-adapter.js` 发送同源批次、管理 tab_id/ACK/keepalive/403 停止/429 冷却/413 拆批；观察器补视频最终状态及 Woo Blocks 点击下单捕获。真实匿名→登录→加购→测试订单关联、后台列表/时间线/转化页、原生 MP4 状态已验证；所有自建订单/账号/访客均已清理。店主从 `/wp-admin/admin.php?page=pc-visitors` 查看，测试顾客用隐身窗口访问 `/`，员工和 `?preview` 页面排除。视频验证仅向独立浏览器 DOM 注入既有 MP4，没有发布新视频。详情与证据见 `D:/Codex/Tasks/peptide-platform/outputs/visitor-adapter-20261022/README.md`。

- MENU-NAV-01 / CUSTOM-PAGES-01 / COUPON-01（2026-10-22）：页头和页脚已接后台菜单，未配置时回退原导航，配置为空时不显示；商品大菜单保留搜索，支持两级链接与后台样式。结账页读取已发布条款链接，保持原 Woo Blocks 确认与提交。主题覆盖 Coupons 列表/详情并加载独立复制反馈，header 后消费一次 Woo 通知，使分享码提示也能在普通页面显示。当前无公开优惠码或已勾选条款；没有创建实际活动或改后台数据。211 项只读/隔离及真实站点验证通过，详情见 `D:/Codex/Tasks/peptide-platform/outputs/menu-coupon-20261022/README.md`。

- UI-SALE-01：接入 PRICE-SALE-01 公开价格对，商品卡片和详情规格显示同一变体的现价/划线价/实际优惠比例；演示对照价旁标注 Demo pricing。`inc/sale-pricing.php` / `assets/sale-pricing.css` 管显示，缺少有效价格对时仅显示现价；详情选择前保留实际范围，选择后180ms更新，减少动态效果关闭动画。`inc/cart-pricing.php` / `assets/cart-pricing.js/css` 使用原生 Blocks filter，购物车OrderMeta SlotFill、结账主题自有挂载点读取官方cartStore实时促销节省，说明已含在售价中，Woo应付总额及优惠券计算不变。当前仅验证本站未启税环境，后端含税换算问题已交Claude；Woo升级需复测购物车实验性Slot。工作与截图：`D:/Codex/Tasks/peptide-platform/work/sale-ui-20261017/`。
- UI-CARD-PRICE-01：共享产品卡片的 variable 价格区间改为最低显示价，无 From 前缀。`vc_card_price_html()` 使用 Woo 含税/未税显示价格和原生货币格式；详情选规格、购物车、结账仍为原生实际价格。验证覆盖首页、商城96项与详情变体 #100：`D:/Codex/Tasks/peptide-platform/work/card-starting-price-20261017/verification.json`。
- UI-HERO-HIGHLIGHTS-01：首页包装图周围增加批次查询、检测资料说明和报告目录三张浮卡；`assets/hero-highlights.css` 只在首页加载，≤1250px改为图下信息条。链接沿用既有页面，未加入未经核实的检测指标。验证/截图见 `D:/Codex/Tasks/peptide-platform/work/hero-highlights-20261017/`。
- UI-PACKAGING-FIX-01：首页/商城包装图已换为 `assets/brand/packaging-corrected-20261017-{768,1536}.webp`，修正盒面 Logo 与包装细节；图片按原始 3:2 完整展示，商城说明位于图下。原始 VI/SVG 保留，仍标注 AI 概念。验证/完整编辑提示见 `D:/Codex/Tasks/peptide-platform/work/packaging-fix-20261017/`。
- UI-REPORT-HOVER-01：产品图悬停/键盘聚焦平滑显示报告占位纸张，移开恢复。触屏仍单击进入详情；减少动态效果即时切换。占位模板 `template-parts/product-report-preview.php`、样式 `assets/product-preview.css`，当前没有真实报告/结果/下载。验证 `D:/Codex/Tasks/peptide-platform/work/report-hover-20261017/verification.json`。
- UI-HOME-SORT-01（2026-10-17）：首页四个产品使用 `menu_order title ASC`，与商城沿用已保存的报价单顺序；当前为 Tirzepatide、Retatrutide、Semaglutide、NAD+。验证记录：`D:/Codex/Tasks/peptide-platform/work/home-quote-order-20261017/verification.json`。
- 新版首页：<http://vialcovepeptidescom.local/>
- 商品：`/shop/`；详情：`/product/demo-peptide-a/`。
- 批次查询：`/batch-reports/`。
- 证书目录：`/certificates/`，按商品搜索、每页 24 项；选中商品后复用公共批次接口显示当前报告与访问状态。
- 内容页：`/faq/`、`/about-us/`、`/quality-standards/`、`/methodology/`。
- 购物车、结账、账户：WooCommerce 现有页面，保留插件原生行为。
- 原版 Lunira 比较入口：<http://127.0.0.1:17842/>，与本主题互相独立。

UI-DEPTH-01：`assets/experience.css/js` 为现有 VI 的视觉层次和首页/商城入场增强；正文默认可见，无全屏预载器。三地图片与批次纸张按滚动一次呈现，购物车/结账不施加入场。`carousel.js` 以 `.hero-dot-progress` 的 WAAPI 动画作为单一播放时钟，自动暂停保留剩余时间；减少动态效果关闭自动播放。样式最后依赖 navigation 加载，不覆盖导航现有披露逻辑。验证工作位于 `D:/Codex/Tasks/peptide-platform/work/visual-depth-20261017/`。

UI-AUTOPLAY-01：用户要求首页持续自动播放。6.5 秒一屏，箭头/编号/滑动后继续；指针悬停和控制按钮焦点不暂停。仅显式 Pause、滑片内容焦点、离屏/隐藏和减少动态效果暂停。验证见 `D:/Codex/Tasks/peptide-platform/work/autoplay-20261017/verification.json`。

UI-CAROUSEL-CLEAN-01（最新）：用户要求移除全部底部控制栏，模板已不输出箭头、Play/Pause、01–04 标签或进度线。轮播由独立单 timer 运行；键盘聚焦可暂停、方向键与手机滑动可切换，轻点图片不停止自动播放。前述 WAAPI 进度/可见按钮说明已被此更新替代。验证见 `D:/Codex/Tasks/peptide-platform/work/carousel-clean-20261017/verification.json`。

主题正本为 `D:/Codex/Projects/peptide-platform-worktrees/frontend/wp-content/themes/peptide-site`。LocalWP 的 `D:/Codex/Sites/vialcovepeptidescom/app/public/wp-content/themes/peptide-site` 是指向正本的目录联接。编辑正本即可刷新查看，不需要构建。

本轮启用了 VialCove 主题（原为 twentytwentyfive），新建 Batch reports 页面并绑定 page-batch-reports.php，关闭了开发站点的 WooCommerce coming-soon 挡板。其余后台业务、账户语言、支付和物流配置未改。原设置记录：`D:/Codex/Tasks/peptide-platform/work/frontend-site-before.json`。

## 数据边界

- 商城默认顺序由 WooCommerce 原生 `menu_order` 管理，已按《产品报价2.4%.xlsx》的 94 个产品首次出现顺序保存；表外两个旧 Demo 在最后。产品菜单与证书索引使用同一排序，显式价格排序保留。迁移数据库或重新导入商品时须保留该字段。精确映射与原值备份在 `D:/Codex/Tasks/peptide-platform/work/catalogue-order-20261017/`。

- 商品、价格、库存、变体、加购、购物车、结账和账户使用 WooCommerce 原生 API/模板/区块。
- 商品的 `extensions.peptide_core` 用内部 Store API 请求读取并规范化 PHP 对象/数组，含 `is_demo`、`specs`、`batch_count`、`report_state`；不直接读取插件私有元数据键。
- 商品批次和报告调用 `peptide_core_get_product_batches()`，按插件返回的访问权限展示下载或登录提示。
- 查询页调用 `GET /wp-json/peptide/v1/batches/lookup?batch_no=`；已登录请求带 REST nonce，使用后端限流。处理 loading、400、401、403、404、410、429、5xx、断网和超时；新请求取消旧请求。
- 公开 PDF 沿用后端受控下载地址；前端不复制私有文件、不绕过权限。浏览器返回的文本用 textContent 渲染，下载地址须为同源 HTTP(S)。
- 购物车页订阅 WooCommerce Cart Store，数量修改和删除会同步页头数量。
- 未接收到真实产品图片时显示有 “Packaging concept” 标签的 CSS 包装示意；没有添加虚构商品、检测数值、评价或认证。

## UI-NAV-01 导航与首页顺序（2026-10-16）

- 页头采用正式 Compact Logo，桌面高度 76 px。Products / Research / About 使用原生 details，下拉可用鼠标、键盘和手机操作；搜索按商品名称，列表由 WooCommerce 公开目录读取。
- 首屏保留产品包装图为第一个轮播面板，之后立即显示 4 个商品卡片，再展示三地故事。移除原先插在中间的导览条；其他轮播场景保留。
- 新页面 418–422 分别为 certificates / faq / about-us / quality-standards / methodology。证书页绑定 `page-certificates.php`，其他四页绑定 `page-information.php`。页面编辑器正文可补充在模板内容前；固定布局与默认说明在主题模板中。
- About 复用 `template-parts/brand-network.php`；质量与 Methodology 为待真实资料补齐前的初版文档说明，不声称实验室资质或测试结果。
- `inc/navigation.php` 管理公共目录与 FAQ 文案；`assets/navigation.css/js` 管理新增表现。没有新 REST API 或数据库结构。
- 证书页按访客权限实时呈现，不共享缓存；已设 `DONOTCACHEPAGE` 和 no-cache headers。未来 CDN/缓存插件也应排除 `/certificates/`，遵守已有账户/报告缓存边界。
- 页面创建记录及改前快照：`D:/Codex/Tasks/peptide-platform/work/navigation-20261016/`。后续迁移环境需同步这些 WordPress 页面和模板绑定。

## 视觉与外部依赖

### UI-INTERACTION-01 / UI-SPEC-01（2026-10-17）

- 导航面板展开 320 ms / 收起 220 ms，使用原生 Web Animations API；手机子菜单与 FAQ 按真实高度过渡。快速反向从当前状态接续，取消旧动画回调，关闭中的内容 inert；断点变化和减少动态效果设置会稳定到最终状态。没有引入动画库，也没有恢复已取消的瓶盖动效。
- `assets/product-options.js/css` 把 WooCommerce 原生规格下拉渐进增强为平铺按钮。保留真实 select 和 Woo variation_id 提交流程；价格使用原生 `found_variation` 返回的格式化价格，清空恢复价格区间；库存/可用性由原生选项和 Woo 加购状态控制。支持方向键/Home/End、清空、URL 预选和无 JS 下拉回退。
- 57 个多规格商品的全部选项均已核对；37 个已有 Pack 数据的单规格商品从公共扩展提取规格，在购买区域展示。`inc/product-options.php` 仅处理主题呈现与资源加载，未改商品、价格、库存或后端数据。
- 验证记录：`D:/Codex/Tasks/peptide-platform/outputs/vialcove-interaction-verification-20261017.json`。手机检查为浏览器模拟；最终独立测试购物车已清空，没有创建订单或付款。

当前视觉基线为用户交付的 **VialCovePeptides VI 2.0 final（2026-10-13）**，已于 2026-10-16 应用于本地前台。早期 Lunira 方向保留为历史，当前配色、标志、字体和包装表现以 VI 为准。

- 主蓝青 `#177E9B`、Logo C 红 `#D95755`、正文 `#284650`、冷白 `#F4F8FB`、分隔线 `#D8E5EA`、浅冰青 `#DDEEF1`。小字使用更深的同色系以保持阅读对比度，错误状态保留语义色；没有将 C 之外的 Logo 部分染红或重绘标志。
- 原 SVG 用于页头、页脚、报告示意和商品包装占位图；完整横式渲染宽 320px，小尺寸使用 Compact。favicon 使用提供的实心端点版本。`assets/brand/` 存精确复制的 SVG、favicon、原字体和 OFL 许可，来源说明见其中 `README.md`。
- `assets/brand.css` 统一字体、配色令牌、字号、留白、细线、小圆角、商品／查询／原生 WooCommerce 页面。Inter 由本地 TTF 提供，移除 Google Fonts 和 Inter Tight；当前英文站没有部署 Noto Sans SC 文件，中文沿用系统回退。
- 首屏改用 VI 包内银盖、哑光白标签包装概念图，提供 768／1536 px WebP，保留 AI 包装示意标识。原蓝盖 DNA 插画不再请求。下列 2026-10-15 的首屏文件描述为历史；四屏轮播和三地场景结构保留。

- 2026-10-15 最新要求：瓶子恢复静态闭盖图，取消开合层和动效加载；原 `assets/vial-motion.js`、开盖底图仅保留历史文件，不再使用。首屏仍为粉末西林瓶与淡蓝 DNA 包装示意，不加载 Spline。
- 首页改为四屏轮播：静态西林瓶、以色列科研 REAL-02、中国制造 CN-03、美国仓储 US-L02。实现位于 `template-parts/home-hero.php` 与仅首页加载的 `assets/carousel.css`、`assets/carousel.js`。默认约 6.5 秒切换，支持编号、箭头、键盘和手机横向滑动；悬停、焦点、离屏或页面隐藏暂停，手动切换后需点 Play 恢复。减少动态效果默认不自动播放，无 JS 保留首屏。
- 三地品牌模块在首屏导览之后，使用用户提供的 REAL-01、CN-05、US-L03，明确标注 AI 场景；这些图不能作为实拍、科研能力、产能或履约的证明。来源及公开使用限制见 `assets/images/PROVENANCE.md`。
- 当前首页使用素材包 34 张场景中的 6 张，另外使用生成的静态西林瓶图；其余 28 张场景及团队参考原图保留在交付目录。轮播与下方卡片都保留 AI 场景标识。
- 图片使用本地 `srcset`、固定宽高；首屏优先加载，三地卡片延迟加载。字体与图片均由本地主题提供。
- 没有引入原 Webflow 的电商、表单、Rive 或第三方追踪脚本。源码原包仍在 Git 之外。
- 商业模板授权与用户提供的场景参考素材授权分别核实，不能互相代替。
- VI 验证：5 个 PHP lint、site.js 语法、diff 检查及 9 组 Edge 检查通过；首页 1440／1024／768／390／320 px，商城、商品、报告、账户、购物车、结账 1440／768／390／320 px 无横向溢出。验证实际规格选择、报告查询、临时访客购物车、轮播／触控／无 JS；未提交订单或测试支付。已修复窄屏 Woo 表格固有列宽和商品／合计的重叠；最终测试购物车通过原生移除按钮清空。结果 `D:/Codex/Tasks/peptide-platform/outputs/vialcove-vi-verification-20261016.json`，截图 `vialcove-vi-*.png`。移动为模拟设备，未实体手机／Safari 实测。
- 轮播验证：3 个 PHP lint、JS 语法、Git diff 检查及独立 Headless Edge 9 组行为检查通过；768／390／320 px 无横向溢出，已目视检查四屏桌面与两屏移动截图。结果为 `D:/Codex/Tasks/peptide-platform/outputs/vialcove-carousel-verification-20261015.json`。触屏为浏览器模拟，尚未实体手机或 Safari 真机验收。

实现参考：[WooCommerce classic theme handbook](https://developer.woocommerce.com/docs/theming/theme-development/classic-theme-developer-handbook)、[Cart Store](https://developer.woocommerce.com/docs/block-development/reference/data-store/cart/)、[WordPress theme functions](https://developer.wordpress.org/themes/classic-themes/basics/theme-functions/)。

## 正式访客追踪（2026-10-27）

- `peptide-core` 的 `vialcove_visitor_observer_config` 是唯一启用来源：严格要求 `enabled === true`、`protocol === 1` 和同源 HTTP(S) endpoint。正式模式 `test_mode:false` 与旧测试模式都支持；配置关闭时不加载观察器。员工、装修和预览页面继续排除。
- 服务端返回 403（包括 `pc_track_declined` / `pc_track_disabled` / `pc_track_staff`）时，清空整条内存队列、终止在途请求并移除心跳、视频监听；页面生命周期事件不会恢复追踪。退出链接与 GPC 由插件识别。临时网络错误和 429 保留原有有界重试策略。
- 独立回归：`node tests/visitor-transport.cjs`、`php -n tests/visitor-config.php`。不连接数据库、不发送真实跟踪事件。启用与保存期限仍在“访客数据 → 实时访客”后台配置。

## 公开仓库的素材范围

品牌 VI、Logo、自生成包装图与附有 OFL 许可的 Inter 字体随源码提供。`assets/images/PROVENANCE.md` 中三地场景的参考图授权尚未核实，因此主题 `.gitignore` 精确排除这 12 张响应式 WebP；本机保留，不随公开 GitHub 分发。首次克隆时，旧首页和品牌故事中的对应图片需要另行提供获授权素材。WordPress 商品上传、数据库、客户数据、第三方 COA 及购买模板原包也不包含在仓库中。

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
