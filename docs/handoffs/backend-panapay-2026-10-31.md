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
