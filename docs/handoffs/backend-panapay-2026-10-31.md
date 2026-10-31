# Panapay payment integration

2026-10-31 · Codex. User explicitly assigns this conversation to payments; block-editor work belongs to the other conversation.

Worktree: D:/Codex/Worktrees/panapay-payments, branch codex/panapay-payments, base origin/main 7c65a8d. New optional WooCommerce gateway plugin peptide-panapay; existing peptide-core, theme, customer/order system and Blocks checkout remain authoritative. No secrets or original documentation with credentials enter Git.

Implement the provided createPayment, return and notify contract. Browser return cannot authorize payment because its signature is not order-bound. Only verified, order-bound server notifications may call native payment_complete; keep duplicate/late/replayed notifications idempotent. Support hosted redirect/iframe, per-site configuration and server HTTPS migration. Query/refund APIs absent; do not advertise automatic refunds or reconciliation as supported. Implementation and actual tests pending.


### 2026-10-31 · Codex：Panapay 独立插件及原生HPOS首轮实测检查点

按用户最新要求，本对话只负责支付，积木交其它对话继续；支付“先空着”的旧决定已被本次API接入替代，邮件/搜索/AI没有一并接入。工作树D:/Codex/Worktrees/panapay-payments，codex/panapay-payments，基线origin/main7c65a8d；此前fetch成功，桌面attach因非managed失败，所有工具明确D cwd。新独立插件wp-content/plugins/peptide-panapay，不改旧积木/主题/peptide-core在途源码。Gateway/Blocks/动态WCAPI return+notify/原生receipt、严格签名、每站配置、AES-GCM密钥与sessionURL加密、HTTPS/精确host许可、隐藏phone时条件AdditionalCheckoutField、HPOS/CPT原生持久化验证、持久交易+单订单one-shot completion槽均已写；查单/退款接口未提供，不声称支持自动查询/退款。root docs和README已有部署初稿，尚未完整交付/提交/推送/上线。

安全实审发现并实际修复：浏览器回跳不绑定订单所以永不据GET标paid；Woo save吞Exception不能信内存，写前/后通过独立主库连接确认已提交book/paid；租约只用于普通请求互斥，持久唯一order槽且只有首次INSERT请求可进入native payment_complete，未知执行不自动重播、人工核账；FAILED/paid清checkoutURL与book60KB/创建前45KB预算防HPOS TEXT溢出；原始长交易ID加密留存，>100或会被sanitize改变时native交易号用hash保留；包括review交易均加密留真ID，后台仅授权员工可见。额外独立Database不提交别人的外层事务，定制wpdb路由必须显式primary并保证Woo订单读写同主库，非自动支持HyperDB。

测试资源完全新建，未读取/克隆旧QA或原站配置/客户数据库：D:/Codex/Tasks/2026-10-31/panapay-integration/work/site-hpos；独立MySQL8.0.35实例PID29228/端口52818、datadir work/private/mysql；新空库panapay_qa_073aad30f23e、DB-scoped app user，所有密码/salts/encryption key只在受限ACL的work/private，禁止记忆或Git。WordPress7.1.4/Woo11.2.1引擎源码复制及main平台7c代码归档，不复制uploads/旧DB；新php.ini无Local auto_prepend、D日志/tmp、禁自动cron/AS和邮件，PHP HTTP默认隔离。新WP/admin/Woo/peptidecore/theme已安装，native wc hpos enable已实际创建HPOS表并启用；新插件已激活、schema2独立交易表/唯一索引安装。预留HTTP52819但PHP HTTP server尚未启动。

真实WP/Woo（仅provider HTTP受控模拟）当前HPOS39断言通过，native datastore真实OrdersTableDataStore，outputs/native-contract-hpos-current.json；包含create会话不paid、签名拒绝、真实server通知paid、重复无额外native完成/库存、late FAILED不降级、iframe native order-key receipt、跨order returntoken与交易复用拒绝、取消不复活、修改金额核账、unknown不重创、长交易ID不截断、settings slashes/secret不回填。早期WPCLI eval-file函数scope及复跑fixture长ID重复导致的失败均保留，不伪称从未失败。真实故障/独立连接/租约22断言通过，outputs/native-faults-hpos-current.json：creating save Exception→HTTP0，native完成save失败→DB未paid+后续同交易不重入，独立claim外层rollback后仍可见，旧lease无法重夺completion；故障原生hooks可已发生，不声称自动回滚。故障fixture原用MariaDB @@in_transaction，实际MySQL8不支持，已仅在自有case前ROLLBACK建立受控外层事务再测，旧失败报告保留。

支付方真实测试只发起一次合成create请求（初次doc CRLF解析失败在wp-load/HTTP前修正），本人order55/product54，不输入卡、不收费；实际得到http_request_failed，订单保持未付款/创建未知且不盲重试。outputs/provider-create-test.json不含完整URL/secret。另只读HEAD网关根地址返回302（outputs/provider-connectivity.json），证明基础HTTPS可达，不说明create成功/凭据有效/回调已联通。回调本地不可公网访问，真实card/settlement/public IPN均未验；不要因为本地模拟通过把真实对接写成完成。Desktop原始文档含测试密钥，仅在原件读取，不复制至代码/记忆。

下一：读并执行真正两个worker并发暂停/过期owner测试；新独立CPT库/站并跑native39与22；实际Blocks/条件phone/ack/receipt/HTTP notify UI；定位测试gateway POST连接问题（不能重试unknown原单/放宽TLS/绕安全），真实notify的order_number是否原样echo自有reference、字符串reference接受与正式商户均待证。做好可安装ZIP/密钥泄漏扫描/代码审查后独立分支commit push；主站默认disabled安装入口需在测试后完成，不混入积木。

### 2026-10-31 · Codex：开发交付与本地安装验收

本条更新上一检查点的在途事项，不代表生产收款通过。schema 3 另增同一主库的创建占用表：创建请求在发 HTTP 前保留持久占用，租约过期或进程中断不会授权另一个进程重复创建；未知结果保留人工核账，不能删占用强制重试。付款完成保护补充原生落库核对，阻止未持久成功后的默认库存/完成事件；不保证任意第三方提前执行的钩子可回滚，也没有耐久 outbox。

实际结果保存在 D:/Codex/Tasks/2026-10-31/panapay-integration/outputs：

- 原生 HPOS：合同 39、故障 24、创建占用 20 个断言通过。CPT 同样三组 83 个断言通过，schema 2→3 升级保留旧订单/元数据/交易账本及原有行，仅新增创建表和升级版本标记。断言包含重复签名检查，不能按 166 个独立功能计数。
- 真正两个 PHP 进程的通知竞争（订单122）和创建竞争（订单120）通过；只失效本人 QA 的临时租约，未删除持久占用。重复/不同交易不再次进入原生付款完成；创建竞争没有第二次 HTTP。
- 真实 HTTP 回调/receipt 路由 11 项通过；随后失败通知允许空 transaction_id、成功仍要求非空的定向 HTTP 检查 4 项通过。签名必须仍含空字段，字段缺失拒绝。支付方 HTTP 全为合成模拟。
- 原生 Store API 7 项、8 次请求通过，本人订单127保持 pending：缺电话/原确认项被原生400拦截，两项齐全才返回模拟收银台；未跟随外域跳转。第一次旧漂亮链接脚本遇301即停，另新 plain-REST 叶使用真实 rest_url 后通过，没有放开任意重定向。
- 浏览器权限最初与用户已启用的截图不一致，后实际重连，独立 QA 结账真实显示 Panapay；选择才显示电话，换回 bacs 隐藏，原确认项保留。截图 checkout-panapay-ui.png。仅页面查看/切换，未点 Place Order、未输入卡；本人 QA 浏览器购物车已正常清空，临时 QA 标签已关闭。隔离 PHP router 最初未处理源码 junction，导致 JS 返回 HTML；只修 QA router 静态文件路径后实显通过，未修改支付产品源码来掩盖问题。
- 初次定向 HTTP 测试发现自有服务已退出，0断言/0业务写入；恢复同一隔离 datadir 后定向4项通过。未重新初始化数据库，旧失败记录保留。

安装包 D:/Codex/Tasks/2026-10-31/panapay-integration/outputs/peptide-panapay-0.1.0.zip：20 个运行文件，排除测试/配置/数据库/原始支付文档；SHA256 da23b434be40239fbc41609324751949949089dc329b609e045e1031568afcf1。23个PHP及1个JS语法通过，针对原始文档真实签名密钥的内存比对未检出候选源码泄漏，ZIP逐文件比对和完整性通过。独立静态审查无阻止提交问题。

本地原店 wp-content/plugins/peptide-panapay 已安装同一20文件并通过原生 activate_plugin 激活插件；支付方式本身保持 enabled=no、无商户凭据，schema安装通过，旧 active_plugins 完整保留。激活前 active_plugins 备份仅存受限 private 目录。原生 CLI 默认 MySQL 端口错误已通过本次 CLI 的 mysqli.default_port=10006 对齐 Local 实例，没有改原 wp-config；首个报告访问私有 VERSION 常量在成功激活后报错，后续只读校验确认成功、未重复激活。后台插件页及后续原页面控件读取又连续超时，因此原店配置页视觉检查未通过；不能把独立 QA 页面截图冒充原店配置页。

仍待：真实创建接口单次尝试 http_request_failed 尚未解决，不盲重试未知原订单；支付方是否接受字符串 order_id 并原样回传、正式商户/收银台域名、服务器 HTTPS 真实公网通知、实际收银台/卡验证/结算、迁移恢复。协议缺少通知金额/币种与查单退款，不能独立核验实结金额或自动查单退款。部署文档已写清多店独立配置、加密依赖迁移、主库、精确回调 WAF/缓存及日志隐私规则。

本阶段 Git 提交/远端同步结果随后追加；积木、主题、同事数据改动不属于本支付提交。

代码提交 33bfbcd；fetch 得到同事 main 4bf46b5 后已正常合并为 8d7d96f，合并无冲突，支付插件/部署文档与实测版本逐文件未改变。最终相对远端 main 的内容仅为本插件和支付文档27文件；未提交或覆盖同事在途工作树。仓库没有全局提交身份，使用单次 git -c Codex 身份完成，未改用户全局配置。远端同步在此记录提交后执行，不能仅凭本条宣称已经 push 成功。

后续实际同步：本地交接提交7f19faa已成功推到 GitHub，ls-remote 核实 main 与 codex/panapay-payments 同为7f19faa671f411ab5ec49df5fa09639ec4e3aad3。最初两次直连 github:443 失败；读取用户已启用的系统代理127.0.0.1:8800后，仅对本次 git 命令使用 -c http.proxy，fetch/push/远端核实均成功，没有改全局代理或Git配置。最终原店标签已导航到 section=pc_panapay，但控件读取仍超时，未声称配置页视觉验收通过；独立QA结账实显证据有效。旧插件页临时标签未标记保留，原用户标签保持。

### 2026-10-31 · Codex：PAY-HQ-01 中央支付整体迁移与账号专属入口

用户后续明确确认“只用同一支付收款，账目自行按店记录”，要求支付整体迁总后台；又要求用户只分发站点账号密码及专属链接，不手动处理连接密钥。本阶段以此为新依据，取代前文尚未确认中央管理的设想。独立站仍保留真实 Woo 订单/库存，未做支付方资金分账。工作树继续 codex/panapay-payments，起点已快进同事main c287857；未重开积木任务、无CRK接入。

实现：同一 peptide-panapay 0.2 在 HQ 无 Woo 模式保存商户、创建会话、接 IPN 与持久回写，客户站仅连接器及原生付款。Hub_Protocol 方法/路由/正文/时间/nonce HMAC和响应绑定、独立主库原子 nonce；Hub_Store schema2 attempts/events 的店内订单 active_order 唯一创建占用、商户交易与店内完成唯一、事件即回写 outbox。固定 site_id+完整UUID+本地引用，不按域名/裸订单号认店；移除重注册不接管旧事件，当前 accepted key 才可用。未知创建不重发，HQ 成功确认后停止状态恢复返回收银台，缺当前商户配置在占用前拒绝。店铺持久 pc_panapay_central_managed，迁移漏配 HQ 地址也不回退旧商户。旧尝试保留冻结凭据/本地schema3/旧回调，不删或迁造旧账本。暂停新收款不丢弃旧通知。

HQ 支付中心 pchq-payments 管理员 nonce 配置/按店筛选/分币种订单金额/支付方确认与店铺回写双状态/待回写重试；商户参数只在 HQ，字段不回显密钥。客户站隐藏支付管理菜单、旧地址跳到概览提示迁移，Panapay设置和底层option写入口均只读。新站 Ops 自动从受控源码复制/依次激活 Woo/core/Panapay，健康要求连接器加载，不复制商户或客户数据；旧在途provision profile hash不改，缺新源码安全拒绝，远端部署需配对应受控源码。

账号：新 Core Staff_Entry 绑定本站uuid+用户ID，专属原生wp-login入口只接受该账号的原生密码认证；不是免密链接，不显示/分发机器连接凭据。GET/POST冲突、错误账号、通用员工入口绕过、停用账号拒绝；保留原管理员/HQ管理员入口、买家登录及较早认证错误。已认证HQ staff DTO追加staff_entry_url，销售账号页显示可复制只读链接，不返回密码。

实际验收（不相加冒称独立功能）：

- CPT旧协议合同39/故障24/创建20共83原生断言通过，保存旧日志范围，验证开始前未绑定HQ。
- 三端独立原生环境：原HPOS52819、CPT52820及新无Woo HQ52821；HQ库panapay_qa_hq_234bed07cd93全新，私有配置/连接资料只存本任务private，未复制原站配置/客户。原生中央HTTP23项通过：A/B实际原生订单都为129，USD19.90/29.90分开；同号只更新所属站、库存各减一次、原子重放/错误签名/跨店状态拒绝、同订单不同引用不能再创支付、成功通知后不再返回收银台。B离线仍收vendor纯文本ok且事件pending，恢复后正确回写。供应商全部example.com受控模拟。
- 原生未知恢复5项通过，本人QA订单131仅一次模拟provider HTTP，重试/STATUS不重创。
- 真正两个PHP进程中央创建竞争10项通过，PIDs52484/39964同时存活，第一进程已独立提交占用，第二不同引用同店同订单返回409，原占用不变，无HTTP/事件/原生订单。报告hub-creation-concurrency.json，本人合成HQ占用订单1170364914保留QA。
- 原生员工认证/会话18项通过，实际WP密码、错误用户名/密码、通用绕过、先前认证挑战及停用会话撤销；4本人临时账号已删除。模型27是额外源码检查，未当真浏览器认证。
- 原生店铺托管3项通过：持久管理标记、真实option写入被拦、原生配置仅只读提示；丢失HQ常量故障的实际源码模型2项通过，配置失败不回退本地。
- 设置/HTML模型15、连接器模型12、运维实际源码辅助函数模型18通过，均非真数据库/浏览器/建站；35PHP/1JS语法及diff通过。原文真实签名密钥内存扫描未出现在源码候选，未将原支付文档放Git。

真实本地安装：先备份原4既有源及旧支付20运行文件，ZIP SHA256 90f6cccd90863adb66310d09201fa14122e90975dd3735fc1824ad9b202b3bd5，仅private。原backend受影响既有源先与HEAD规范化逐字比对；初次gitapply因CRLF拒绝而未修改，确认后仅ignore-space-change应用当前4窄增量，加Staff_Entry新文件，其他同事文件不改。原店与HQ各安装28支付运行文件，HQ原生激活无Woo/schema2。从用户原文档内存导入测试商户至HQ加密，收款保持关闭、仅原注册店2授权；店铺原生HMAC自动连接/托管标记true、无商户字段通过。没有向原店/HQ导入任何合成测试订单/商品/交易。

浏览器：真实原HQ支付中心可读，菜单/收款未启用/空账本/原店授权及密钥不回显均看到；只收起配置，没有点击保存/启用/重试。截图 D:/Codex/Tasks/2026-10-31/panapay-hq-migration/outputs/original-hq-payment-center.png，标签6已标交付保留。客户站旧支付地址的辅助标签再次AX超时，不能声称已完成该站所有视觉检查；原生只读配置及自动连接已证，用户原编辑/YY标签未改。专属登录链接的浏览器登录、真实第三方2FA及新页390视口未验，原生认证18不替代这些范围。

早期中间失败保留：CPT B最初只读策略请求被该QA隔离MU拒绝，在创建商品/订单前停止；仅把自有三端端口加入该QA MU后通过。全程未放宽生产TLS/认证、未暴露公网测试站。HQ初次schema1在无业务记录时升级schema2加active_order，未改旧本地财务表。

安装包 D:/Codex/Tasks/2026-10-31/panapay-hq-migration/outputs/peptide-panapay-0.2.0.zip，SHA256 9e10efa6118ce98592d132fc1f94f1aa7821740ac6206fdce00da4322a142ad9，28运行文件，排除tests/数据/配置/私有资料，双原站运行文件逐字匹配。Core/HQ/Ops配套增量在本阶段Git源码中，不包含在单独支付ZIP。交付不等于生产上线：真实provider创建此前仍http_request_failed，字符串引用支持/公网IPN/真实卡和到账未验；生产每分钟系统调度、日志WAF/缓存、迁移恢复与正式参数待落地。查单/退款/资金分账无接口，不虚构；积分/履约耐久消费者也未实现，支付回写outbox不替代其业务幂等。

下一：完成本阶段自动GitHub同步；随后按服务器和支付方实际条件继续公网联调。正式启用前不测试真实收费，不自动重试此前未知provider原单。

本阶段同步已完成：功能提交 e73b12e6e144bfe13c286d2a8533567cb3f12a0c，fetch核对同事main c287857后正常推进，支付分支与main均已push并ls-remote验证同e73b12e；工作树干净。下面这个记录提交仅补充交付事实，不修改已验收的运行代码/安装包。后续工作为真实服务器/支付方联调，不将本地安装、原生模拟付款或GitHub同步视为生产收款通过。

### 2026-10-31 · Codex：PAY-HQ-02 账号绑定与六种结账标识（0.3）

用户要求参考 YY 的绑定收款账号流程，图标只做所选四种卡，再明确增加 Apple Pay 和 PayPal；本阶段仅支付，积木仍归另一个聊天。共享契约增量：策略由原五字段追加 payment_icons、iframe_height 为七个公开字段；内部 account_name 不进入策略或支付方请求。先更新全部店铺连接器再更新 HQ；新连接器兼容旧五字段，旧连接器拒绝新七字段，不能倒序依赖缓存。

实现：总后台先显示账号摘要与编辑弹窗，账号配置/展示保存和收款开关/店铺授权分开。服务端 account scope 强制沿用已保存 enabled/sites，access scope 只消费明确的开关/授权并保留测试模式；原权限/nonce不变，旧密钥空框保留、不回显。原生 dialog 支持焦点、Escape、取消恢复草稿，无 JS 内联回退。精确六个本地 SVG 为 American Express、Mastercard、Visa、Discover、Apple Pay、PayPal，原生与 Blocks 都读取 HQ 选择；只是标识，未注册独立钱包网关或实现快捷支付。实际渠道须支付方开通。请求同步/异步不作为付款依据，provider 返回 iframe/redirect 仍为准；高度默认530、整数320至1600，创建时冻结，旧尝试无值沿用530。

实际验证：无 Woo HQ 原生设置保存/输入拒绝19；HPOS店铺真实签名 HTTP 获取 HQ 七字段、六图标和浏览器保存高度620及原生Gateway/Blocks输出9通过。展示对象原生21包含反射；JS模型7、设置模型35（含中文字符上限）、表单模型12、弹窗模型10单列，不冒充真实交易。独立静态复核未见P1/P2，仅要求先店后HQ升级。QA浏览器真实账号保存和授权B关闭再恢复、取消/Escape、密钥为空；390弹窗宽366/x12/right378/body390/底832在844内、保存按钮可见。视口恢复时一次鼠标勾选被固定头覆盖未成功，键盘Space操作和随后正常恢复通过，未宣称全部指针场景覆盖。实际结账6SVG均加载，选择后电话出现、原确认项保留，未点下单或输入卡。

原本地两端先备份旧0.2运行源，逐字核实与HEAD基线，再依次店铺→HQ安装0.3各36运行文件；备份仅private，SHA256 da41da694082169b01df9f5e6fd5cbd0310f49fa4f82cb557894f69f3575e57a。原HQ仅设置六展示标识，商户、密钥、授权、测试模式和关闭状态均未变化；未从截图另抄商户/密钥。原店实际签名策略6图标/530及只读商户验证通过。首次显示偏好脚本将旧raw数组当表单字符串被输入校验拒绝、未写设置，改为只传展示选择及必要控制字段后通过。原HQ真浏览器新版弹窗已见六勾选/密钥空框，未在原后台点击保存/开启。证据 D:/Codex/Tasks/2026-10-31/panapay-account-binding/outputs，original-account-binding.png。

当前仍不具备真实卡与到账验证：支付方创建连接此前失败，公网IPN、正式参数、真实收银台/渠道开通、结算和服务器部署仍待联调。本阶段没有发支付方创建请求；截图展示参数不自动等于本项目正式商户。最终包、源码语法/保密扫描及GitHub同步在阶段结束前补记。

0.3收尾：31份PHP与2份JS语法、diff、原文真实secret内存扫描、精确6个无外部引用静态SVG、ZIP完整性通过；36运行文件双原站逐字匹配。安装包 D:/Codex/Tasks/2026-10-31/panapay-account-binding/outputs/peptide-panapay-0.3.0.zip，SHA256 e4635fefd81542cdc741f23c487b1459199991c37db102cc4be0f567539dec7b。隔离HQ配置已从受限备份恢复，QA购物车随后按原生UI清理；原站只保留用户指定6图标偏好，收款仍关。GitHub同步单独核实后追加，不以本条代替push结果。

GitHub实际同步：功能提交96d027a；fetch后正常合入同事main94f4853为923cd99415ec60ff2ba252c6ee1e5b9edb101d20，支付插件相对功能提交零差异。支付分支与main均已push且ls-remote核实同923cd99，工作树干净；没有stage或改写同事在途工作树，无强推。此后的文档记录提交不改变已验收运行源或0.3安装包。本人QA购物车原生清空并确认0，临时QA标签已关闭；原总后台支付标签保留交付，未修改YY。

### 2026-10-31 起 · Codex：PAY-HQ-03 多付款方式（0.4）

用户纠正0.3只能一个账号，明确要可连续添加多条支付，不需要官方服务商目录；填写各条网关参数后统一下发各店，买家可分别选择信用卡或PayPal。本轮按当前已提供的网关创建/通知协议实施多账号路由，不把名称/图标当官方PayPal或ApplePay OAuth接口。其他协议须真实文档。

Hub_Accounts新独立option采用稳定default/32hex账号、最大20保留身份、revision+独立主库CAS防覆盖；旧单例原样导入default，不扩大原授权/开启。新条目默认全部已接入及后续接入店铺、收款默认关闭，实际开启须完整参数；编辑、启停、归档分别nonce POST，归档保留身份和在途。来源alias不下发，秘密只HQ加密。新METHODS v1每method精确account_id加原7公开字段，关闭/归档DTO保留注册但不可新创；旧CONFIG仍仅default精确7。0.3→0.4顺序HQ先、店铺后，新Bridge无METHODS故障回退。仅旧CONFIG保留关闭default的可信域名；配置失败沿旧503使旧店在途可回退已保存域名，新METHODS逐账号隔离不变。

各原生Gateway/Blocks使用pc_panapay或pc_panapay_<32hex>，不同选项对应不同原生支付ID。请求签名包括账号选择，HQ请求哈希/创建引用/账号及加密可信域名冻结；账户归档/当前密钥网关变化不改在途。HQ schema3仅追加account_id/hosts_box并核列/唯一索引后标记；同单跨渠道创建、已成功订单和商户流水唯一约束保持。店铺尝试冻结账号/原生渠道，缺旧字段只识别default；混合/不符字段拒绝。Persistence与完成guard增加实际HPOS/CPT主库payment_method核对，缓存对象不能冒充另一渠道付款；回调、receipt、ACK均对账本冻结渠道。原生phone条件enum含各真实方式，非本集成方式仍隐藏，原确认不变。

实际原生与浏览器：HQ2→3保留原4尝试与全部事件，17项通过；A HPOS/B CPT实际签名METHODS都注册3方式其中2开启。3原生合成订单A133卡/A134钱包/B133钱包，预期不同商户，书中账号/渠道/高度冻结、仍pending。中央HTTP27通过：精确公开策略、错身份、同引用换账号409、未知账号拒绝、错误商户密钥400；编辑卡网关/密钥与归档钱包后旧STATUS/原生610px iframe receipt可续，已归档新创403；旧冻结签名确认和独立回写仅各店所属订单，A stock9/8、B9，重复不重复HTTP/库存，总provider模拟创建仅3。原生缓存渠道负例3、旧CONFIG兼容/坏配置新旧隔离6通过。旧83未在本阶段重跑，不用历史总数冒充当前检查。

实际QA浏览器添加第4条ApplePay命名关闭草稿、default独立开启再关闭；Card/PayPal两个radio不同native ID，phone/原确认保留，切bank隐藏phone，无PlaceOrder/卡输入。原站HQ新列表/Add/取消及390宽验收：body390、dialogx12/w366/right378/bottom832在844内、保存按钮可见。原站旧标签检测到未保存输入，保持未刷新并保留；另开新版标签交付，不丢原草稿、不读取回显密钥。

独立审查首发现单个退休网关DNS/坏密钥会导致全部策略503，已改成先安全显示DTO，停用/归档/无授权不解密/DNS，活动失败仅本账号关闭；另修32自定义域名加gateway变33时整体拒绝问题，启用配置限制有效并集32，坏单项策略隔离不截断信任。两次复核无新增P1/P2；旧CONFIG兼容修经复核建议及原生6补证。模型HQ27、配置21、弹窗11、策略隔离/host14、店铺28和JS6均模型/源码层，非独立原生功能数。

原站先私有备份旧0.3运行源，逐字核实基线，再HQ→店铺安装37文件；源备份SHA7c90d9e1d66f452d18fabfa2679d79ebfc53c811e3288a1da5ba64836293dde5，设置、原金融DDL/行另受限备份。原HQ金融行0→0、schema3和default迁移逐项原参数/凭据/六标识/关闭/指定店铺全同；原店真实签名新策略仅关闭default/无商户字段，未加入原站合成账号/订单、未启用原收款、YY未改。后续两条边界补丁与完整包的最终指纹随后补记。

QA新3账号已归档，旧default启用状态和旧单例option恢复，schema3与三笔合成paid记录保留作证；本人购物车/临时标签及任务自有MU随后清理。支付方全部为隔离example.com模拟，未再次尝试此前真实unknown创建。公网HTTPS/真实钱包和卡/渠道开通/到账/服务器调度与恢复仍待联调；不能把本轮原生模拟称为正式收款。

0.4最终收尾：32PHP/2JS语法、diff、原文真实secret内存扫描、37文件双原站指纹、ZIP完整性与私有/test排除通过。最终安装包D:/Codex/Tasks/2026-10-31/panapay-multiple-methods/outputs/peptide-panapay-0.4.0.zip，SHA256 a10923896e33723067eff998c5cb1b09915f05745f8ac1866c53536eac514f10。晚期HubAccounts/HubPage边界补丁与README已补拷至两原站，前一中间源另受限备份；最终复核无P1/P2。旧CONFIG模型旧“坏配置也200”的期望随正确旧客户端回退契约调整为抛错，仅该原有模型首跑不接受后修正测试；原生6与模型14/21最终通过。本人QA的请求捕获MU已移除、HQ原隔离MU逐字恢复；旧默认开关恢复，新3账号归档，原单例option不变，无本阶段真实支付方请求。旧83回归本轮未重跑；本轮原生范围以17/27/3/6和真浏览器为准，不混加历史。

GitHub实际同步：功能提交4ae551e，正常合入同事main813f702为f940ce4dc7a79a938f6987b0d77b85901d4ae811，支付插件相对功能提交零差异。main与codex/panapay-payments均push并ls-remote同f940ce4，工作树干净；同事完整历史保留，未stage或改写其工作树，无强推。此后的交付记录提交不改变安装包/运行源。浏览器已确认本人购物车空、QA临时标签关闭，原未保存标签与新支付列表均标保留。
