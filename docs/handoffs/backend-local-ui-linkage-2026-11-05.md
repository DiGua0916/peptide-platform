# 本地 UI / 后端联动与多窗口边界

2026-11-05 · Codex。本地后端联调及手机配置代码复核完成；用户已亲自查看并认可本地手机版，明确“检查通过后上传”。本轮执行 GitHub 同步，不部署服务器。

## 多窗口核对

- 阶段开始时，另一个后台修复窗口 `D:/Codex/Worktrees/panapay-payments` 被用户暂停。其当时六个改动是 admin-workspace.css、tracking-admin.js、tracking-insights.css、tracking-workspace.js、tracking-page-context.cjs、hq-workspace.css；本轮没有改这些文件。用户后来在该窗口恢复修复/优化，已推送 ac7f7cb / a254f5d / 43bed8f；本次整合保留其历史。被否决的对比设计和本地后台设计稿不在上传范围。
- 前端正在同步主题、补装修积木配置，本轮没有编辑其主题或模块 schema。现有主题/插件角色工作树包含已装但未提交的补丁，不能把 dirty 简单当作无用文件，不能整树 pull/copy/reset。
- 当前 LocalWP 主题 junction 指向 frontend，Peptide Core 指向 backend；工作树隔离不能代替共享运行文件/数据库协调。本轮安装前逐文件与备份基线比较，变化即停止，原子替换三个允许文件。
- 共同插件入口、API、菜单、设置和装修文档属于共享修改点；必须逐文件整合。本文仅记录本窗口范围，不声称其他窗口已阅读或存在技术文件锁。

## 已有联动复核

已存在的首单券、活动倒计时、邮编辅助、COA、单位价服务继续复用，没有另造插件。七个关键运行文件与源一致。只读审计和既有隔离检查：首单 PHP 63 / JS 22；倒计时 PHP 451 / JS 27；邮编 JS 15；结账金额内存 34；购物车优惠明细 JS 14，均通过。没有真实下单、扣款或改用户购物车。

本轮补的明确缺口是前端已经实现消费、后端尚缺失的 `extensions.peptide_core.shipping_savings`，原协议见 `frontend-cart-summary-shipping-2026-11-05.md`。它用于解释实际运费优惠，不决定收费。

## 本轮代码

- 新增 `includes/shipping/class-savings-trace.php`，只读生成当前已选配送费率的税外追溯。完整覆盖全部原生包裹，逐个核对明确 package_id、chosen rate ID、现价及原始元数据；各包裹合计必须等于 Woo 原生 total_shipping。
- `includes/shipping/class-discounts.php` 只增加真实 discount ID、应用后的 cost 私有来源，以及一次 shipping package cache 版本标识。原最低折扣选择、运费计算、税按比例处理算法保留。
- `includes/api/class-store-api.php` 在同一 cart 响应注册可空的 shipping_savings 对象。
- 新增 `tests/shipping-savings-trace-pure.php`。

金额是同币种、同精度的最小单位整数十进制字符串。有效时 status=applied、complete=true、tax_basis=exclusive；含无折扣包裹，实际折扣只累计一次。缺选择、无优惠、旧缓存、来源不全、包裹重复、下游改价或合计不符时为 null，前端保留原生运费。没有新公开端点，不公开地址、私人规则或其他 rate metadata，不改付款配置、订单、实际优惠活动或首页布局。

## 实际验证与限制

- 纯隔离边界 82/82；PHP syntax / diff whitespace 通过。
- 安装版 WordPress/WooCommerce 原始 WC_Shipping_Rate、原折扣引擎、Store API 费率/金额 schema、真实扩展注册回调，独立进程内存 fixture 13/13。CLI 显式加载安装版 Store API helper 并调用原注册方法，不将其称为 HTTP 路由初始化验收。数据库 SQL 只读保护，业务/配置前后指纹一致，无写尝试；没有选用或覆盖用户实际购物车。
- 前端当前实际运行版 live-cart-experience.js 读取上述 PHP 原生回执，6 项消费核对通过：折前/减免/实付、只计一次、总额保留、无输入修改、异常费率拒绝、无 trace 回退。
- 本地已备份并增量安装三份业务 PHP。收据 `D:/Codex/Tasks/2026-11-05/local-backend-ui-linkage/outputs/installed.json`；原生及前端消费证据为同目录 native-shipping.json / native-shipping-client.json。
- 本机 PHP CLI 存在既有 Imagick DLL 启动警告，检查仍 exit 0；本批不涉及图片处理，没有顺带修改 PHP 配置。
- 例中配送费率/优惠仅进程内合成 fixture，不是已创建的商家活动或真实客户报价。店铺没有命中实际运费折扣时，页面按原生 Shipping 显示。
- 前端仍在补积木和同步，本批没有新的浏览器画面验收，也没有宣称完整 UI 最终验收。按用户最新流程，待本地联调与用户确认后再统一上传 GitHub；未部署生产。

## 后续本地联调追加

用户继续授权后，核对最新 frontend `4cae228`：公开英/西语倒计时、真实首单卡、COA 搜索/原 PDF 入口 11 项 HTTP/markup 核对通过；实际已发布 schema/模块/应用配置 17 项只读检查通过。商品同 SKU、快捷加购、推荐、单位价检查 Node42、cardPHP51、unitPHP109通过；新的 schema129 / enqueue18 / checkoutSubtotal17通过。上述不是实际手机画面验收，也没有真实改购物车/创建订单。

当时发现一项需前端共享 helper/controller 修复的 P2：商品/购物车的手机独立设置在原生模块中保存正确，但 vc_live_product_settings / vc_live_cart_settings 仅返回桌面值，下游组件也只读全局值。最初只读复现 broadcastEnabled、cart_progress_enabled 两例：native mobile=false，emitted=true。checkout subtotal 和 catalogQuickAdd 新控制本已处理手机覆盖。原问题交接 `D:/Codex/Tasks/2026-11-05/local-backend-ui-linkage/outputs/前端联调_手机版独立设置.md`，修复前证据 mobile-overrides-reproduction.json。

本窗口没有同时修改前端正在占用的主题文件。用户随后明确回复“允许直接发给前端窗口”，已通过正式跨聊天工具发送给“独立站前端”聊天，包含完整路径、两例复现、共用文件范围、设备继承/发布隔离/跨断点验收及本轮shipping追溯保留要求。

前端已在 9815f07 修复完整 devices.desktop/mobile DTO 与客户端设备选择/生命周期；详见 frontend-native-mobile-controls-2026-11-05.md。独立复核未发现新 P1/P2：Node28 顶层、PHP设备50/入口73通过，polish内部46断言另记不双算；6个关键源与实际运行副本一致。Root 的真实已发布 getter / Renderer 内存探针 9/9 通过（两字段覆盖双向覆写和继承、发布值不变、probe本身无SQL写尝试；引导阶段单列，不泛称任意WP引导无维护写）。新证据 mobile-fix-verification.json。此前P2的代码遗漏已修复，尚未由此推断手机真实视觉/滚动/触控验收完成。

本轮最后再次读取 IAB 手机预览 tab14 仍超时；没有绕过浏览器工具或改用其他自动化提取Cookie。已向用户询问其是否已亲自看过本地手机版并认可，遵守“未验收不上传”的最新要求。代码、Git历史和回归可先在本地准备，未获该验收条件前不推送；服务器部署仍不在本轮范围。

## GitHub 上传前本地整合结果

已先备份本轮五个源码/交接文件及原分支指针，正常本地提交运费追溯 ddad2b2；随后顺序合入前端 9815f07、同事远端 43bed8f，整合提交 0839b86，无冲突、无 force/reset/clean，未提交 backend 运行目录的大量补丁。源范围独审未见数据库、凭据、备份、已否决的对比提案或本地后台新设计稿混入。

整合后 47 项 lint/syntax/隔离测试命令全部通过，联合 Node 回归 123/123；原生 shipping 内存/schema 13/13、当前前端消费 6项、匿名公开 GET/markup 11项再次通过。手机设备 native getter 9/9通过，前端独审仍无新P1/P2。证据为任务 outputs/integrated-checks.json / native-shipping.json / native-shipping-client.json / public-linkage.json / mobile-fix-verification.json。

前端窗口已明确由本窗口统一整合推送，并保持其源码不动。本节记录的是本地准备状态，尚未执行 push；最终是否上传仍依用户对本地手机画面的认可。GitHub同步与生产部署分开，本轮没有服务器部署动作。

## 用户验收与本次上传范围

用户随后明确回复“我已看过认可，检查通过后上传”，已满足此前待确认的本地手机版验收条件。此处记录的是用户亲自验收，浏览器工具读取超时的限制仍如上，不能表述为工具已完成手机画面验收。

整合后的代码检查已通过，上传前 fresh fetch 确认 origin/main 仍为已合入的 43bed8f，无新增分歧。本次将同一整合版本正常推送至 main 与 codex/admin-ui-taste-20261103，包含前端 9815f07、同事后台 43bed8f、本轮运费追溯 ddad2b2 和本文验收记录；不移动前端角色分支，不提交 backend 运行目录的未提交补丁。实际远端提交号以 push 后的任务收据为准。

本次没有服务器部署、真实下单或扣款，没有修改真实购物车、订单、地址、付款配置或数据库。不包含已否决的新旧对比改版及另一窗口尚待认可的后台设计稿。
