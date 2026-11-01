# 2026-11-02 · Codex · DATA-TRACKING-QA-01

> 当前状态由文末 DATA-TRACKING-VISITOR-US-02 更新：用户要求中文、仅访客访问，已切换全新独立库；前文的合成商品/顾客/订单环境仅保留为历史归档，不再是当前测试站。用户进一步决定本次只完成真实美国出口，其他国家后续测试。

用户要求开测试环境，实际检查数据与客户追踪是否可用。本轮创建独立测试站并保留供用户操作，没有修改应用源码/主题样式，没有在原站导入测试订单或客户。

## 环境与入口

- 本机测试站：`http://localhost:64791`，只监听127.0.0.1，独立数据库及专属受限数据库账号，独立uploads/private/config/session；未读取或复制原客户数据库。
- 固定源码：73c1e294a2e0432e6a64beab229aa458187b3b0a，678个项目文件逐份快照。它包含03eccb3的后端报告工作及同事两条新前端提交；两者backend插件相同。测试站没有复制原站已发布装修/真实商品/COA/客户资料，使用原生默认页面与两个合成商品。
- 测试根：D:/Codex/Tasks/2026-11-02/data-tracking-qa，运行站点在work/site；nonsecret参数在outputs/environment.json，启动脚本work/start-server.ps1。当前PID37380，退出聊天后保留；进程重启须用脚本和环境marker校验，不得恢复到原站。
- 只启用WooCommerce及peptide-core，主题peptide-site。全站明确Synthetic QA。追踪enabled/live、is_test=false以走正式统计代码路径，但记录仍全是本环境合成；保留员工排除/GPC/退出。邮件、真实支付渠道、外部WP HTTP、cron/在线改文件被测试guard阻断，不接真实业务。
- 已在IAB登录测试管理员。测试凭据只在D任务work/private/login.json和LOCAL-LOGIN.txt，不进Git/记忆正文。运营页`/wp-admin/admin.php?page=pc-data-operations`；实际浏览器匿名轨迹`/wp-admin/admin.php?page=pc-visitors&visitor=3`；协议顾客轨迹visitor1。后台已保留tab45/46。

## 实际测试及结果

| 范围 | 实际执行与结论 |
|---|---|
| 环境健康 | 10/10：独立marker、目录、隔离/限制；首页及track/session真实HTTP200；外部WP HTTP短路、wp_mail拒绝、可用支付渠道空。Woo旧隐藏向导选项的CLI exit1是弃用迁移返回值，实际新hidden_lists/setup/profile.skipped正常；保留原收据。 |
| 协议/持久化 | 真实HTTP session和events：ACK、稳定UID去重、乱序/同页序号、多标签、曝光、非法输入、禁止客户端伪报paid、GPC/退出/开关、实际测试顾客登录关联、员工排除。首轮29/32，三项是harness把自动classification当重复、将201当失败；定向5/5澄清原UID每条一次、重试不增行或counter、两次Woo成功201/quantity2/两server facts。保留首轮，不冒称全32重跑。 |
| 原生加购 | HTTP主visitor1和额外cart probe visitor2各两次真实Store API加购，4条server成功事实；失败不计。未用浏览器自称的add_to_cart/payment来填正式事实。 |
| 真实浏览器SDK | IAB匿名首页(utm_source=qa-browser)→商品A→Add to cart成功→购物车qty1/$12.50/free shipping→结账。随后关闭匿名页终止心跳、再登录测试admin。独立SQL读回visitor3/visit4：5PV、4曝光、2product_view、1add_attempt、1server add_to_cart、1cart_view、1checkout_view、1shipping_selected；stage3、所有is_test0。4项对账通过；不是仅调用SDK或手工HTTP便声称实际UI旅程。 |
| 原生订单与退款 | 独立库5张合成Woo订单、1部分退款，普通Woo CRUD及原生观察回调；第一确认单自动关联visitor1最新visit2，多次重放placed/paid稳定UID各仍一条；另一guest故意无追踪身份，保留unknown。另pending/failed/Test payment覆盖排除。没有实际支付提供商/外部发信调用。 |
| 报表对账 | 16个真实Data REST handler均200/no-store，前后独立进程读取并与SQL/Woo/已知增量72项对账通过；未启用应用/邮件/实验来源维持无观察，不造来源。最终3访客/4访问/10PV、财务计入2已确认模拟订单，净销售37.25USD、退款12.50、净件2、客单18.63；排除Test payment1单，保留pending/failed。 |
| 访问时长复查 | 根在实际UI看平均0，补查发现加速31分钟访问切分时harness只回拨visit1.last_ms，造成负时长。不是生产源码故障。只对QA visit1用CAS恢复到该访问真实MAX事件时间，48.881秒；7项前后边界检查确认其它visits/visitors/events/tabs/源SHA不变。四访问都非负，独立平均124秒与API一致；最后只读重验仍72/72，不重造订单。原错数据/移时过程保留。 |
| 管理权限 | 15个Data报表路由对匿名及普通顾客拒绝，产品CSV拒绝，非法日期400；33/33通过。普通顾客不得读取别人的访客行为/跨店数据。 |

运营KPI按已确认订单/访客为66.67%，访客漏斗按1个付费访客/3访客为33.33%；订单数与访客数不同，不互相强配。unknown国家/未观察归因保留，第二guest不硬配第一访客。30分钟访问切分的时间加速已披露，并已修复其测试数据副作用；这不代表跨日回访/全部时区/DST验收。

## 真实UI与限制

真实IAB运营页已核sales37.25/refunds12.50/orders2/UV3/PV10，访客详情可见设备/来源/5个PV路径/成功加购/购物车/结账阶段。匿名页关闭后No signal/offline属预期，管理员访问被排除；用户要自己看实时新增，应用无痕窗口访问测试前台，管理员窗口查看后台。未开启持续伪流量生成器。截图browser-visitor-timeline.png、browser-operations.png、browser-checkout.png在任务outputs并已核图。

这次验证的是明确覆盖的功能链路，不是全功能、性能压测、渗透测试或正式上线验收。真实支付通知/结算、邮件收件箱、真实公网地理位置/MMDB、广告归因、生产网络缓存/CDN、未启用收藏/分享/A-B业务均未验。没有原站已发布装修快照，所以最新Demo首页自定义presentation/弹窗组合并未按原店配置全测；实际UI走默认首页/商品/Blocks购物车结账。浏览器有既有Woo脚本依赖声明warning及自动分类policy/evidence原始对象显示，未在本轮跨前端分工修改；核心入库/报表未因此失败。

历史pure/Node选择12套，9套通过，3套旧fixture缺新autoload/WC方法/旧GROUPS断言兼容未完成；保留原失败和定向尝试，不把它们算生产缺陷，也不以源码存在冒通过。实际WP/HTTP/报表结果是本轮结论主体。

原站前后只读count快照完全相同：82父品/183规格、219报告、2账号，tracking18访客/18访问/782事件/18标签；未导出客户行、订单明细或原数据库。本轮唯一仓库改动为本交接；真实测试库、配置/账号/源码副本/合成业务/日志/截图均在Git外。数据接口没有变更。

证据根：D:/Codex/Tasks/2026-11-02/data-tracking-qa/outputs，重点environment-health.json、source-snapshot.json、tracking-http-results.json、tracking-targeted-followup.json、tracking-native-readback.json、analytics-browser-readback.json、analytics-order-fixture.json、analytics-final-qa.json、analytics-verification-final-qa.json、analytics-permissions.json、QA-fixture-time-repair.json、analytics-duration-before/after.json、original-before/after.json及DELIVERY.json。实际Git提交和远程校验记录在交付收据/主记忆，无CRK，不发默认群。

## DATA-TRACKING-VISITOR-US-02：中文纯访客环境与真实美国出口

用户纠正测试范围：中文，只模拟进入、浏览和停留，不改原站业务数据；国家必须来自真实网络出口，不手填国家或伪造请求头。用户明确选择临时受限测试入口，随后选择“先完成美国，其他国家后续再测”。前一轮合成订单、客户、商品范围至此停止，不继续生成。

当前本机入口仍为 http://localhost:64791 ，实际站点为任务内 work/visitor-site，数据库 qa_visitor_tracking_229655ece7、独立受限账号与盐，只有一个系统管理员。WP/管理员均 zh_CN，复制本机已有 459 份中文包。旧 work/site、qa_data_tracking_74f003cabc 和历史证据保留；旧服务停止，未删除原站或旧库。商品/规格、客户账号、旧订单及 HPOS 订单均为 0；注册、加购、结账和业务写入受测试 guard 拦截。浏览追踪、隐私门、员工排除保持。修复仅在测试副本 qa-router.php 的 /wp-admin/ 目录分派，新增带路径/marker/库标记校验的 start-visitor-server.ps1；没有改应用仓库或前端主题源码。

DB-IP Country Lite November 2026 下载自官方，解压 8,515,063 bytes，官方 MD5 5974b336a7409ec854f67eacd52c2061、SHA1 09eea8433fe3eed71984fd6aac9d8ab3d9ca51f0 均匹配，SHA256 145ce5dfb812d8de28547e6289bb5c5f8b3df2f1dea21d324d5b575e0431311f。资源与机器路径仅在任务内，配置本地 MMDB 和精确 db-ip 署名，显示原有署名链接；环回地址仍真实 unknown，不回填国家。

实际网络链路：显式本机 QuickQ HTTP 代理 → 真实美国出口 → Cloudflare 临时 HTTPS 隧道 → 仅 loopback 的受限网关 → 独立签名 PHP 路由 → 本测试库。只开放固定中文浏览页、固定资源与追踪 session/events，随机 Bearer、固定 origin、已验证来源 HMAC 摘要白名单、请求大小/限额/短期限；后台、登录、商品 API 和业务路由不公开。上游签名绑定方法/路径/正文/来源/时间/nonce 及隐私元数据；验证后只在这一请求规范化 REMOTE_ADDR，不改原站代理配置或数据库 home/siteurl。

初次真实入口因 QuickTunnel 自身 CF-Worker=trycloudflare.com 被严格拒绝，保留失败收据。认证诊断只回安全域名和来源摘要，没有原始 IP。该摘要与同一显式代理独立 Cloudflare trace 的瞬时 IP 在内存计算结果一致（US/LAX）；仅对此固定 QuickTunnel Worker 和已核来源放行，其余 Worker/未知来源继续拒绝。前后真实出口摘要一致，128 次认证上游请求全部命中同一已核美国来源（121×200、7×301）。没有从节点名称或 CF-IPCountry 填国家；生产页面实际根据规范化地址查询 MMDB，模拟器只复制该次页面的真实 boot geo。此信任链依赖 Cloudflare 隧道与本机账号安全，不代表当前生产 collector 已独立鉴证每个客户端国家；原报表的来源观察说明保留。

一次有界运行：10 个独立 Cookie 模拟会话、10 次访问、20 PV、50 heartbeat、20 page_exit；客户端 90 事件全部 ACK，另有 10 条原生自动分类，共 100 事件。3/4/3 会话分别浏览 1/2/3 页，只访问首页和已存在的说明页。用真实墙钟等待，平均访问时长 100.1557 秒，无负时长、加购、结账、订单关联或客户登录。自动化 HTTP 会话经过真实出口，不是 10 个真人或 10 个不同公网 IP，也不是本轮真实浏览器 SDK 验收；没有时间回拨、SQL 造访客、国家手填或伪造转发头。

实际只读 SQL 独立对账：US 10 UV / 20 PV；业务计数仍商品 0、客户 0、订单 0、仅系统管理员 1。IAB 中文今日报表显示美国 10、美国浏览量 20、订单/销售额 0；真实访客轨迹单独保留。实时面板只反映其活动窗口，结束后下线正常。没有修改原站商品、客户、订单或原站网络配置。

验证分开记录：环境 HTTP 12/12、目录路由/普通测试登录 7/7、GeoIP 运行 5/5、网关离线策略/签名 55/55、模拟器离线 19/19；最后真实入口 5/5（无凭证 401、公共后台 404、首页 200、原生 geo known、无签名上游 403）。Worker 例外补丁另有编译/配置检查与真实最终入口核验，没有声称初版 55 项在补丁后完整重跑。所有国家数均来自这次真实链路，其他国家尚未测试。

测试完关闭云隧道及 64821/64822/64823 进程，禁用私有网关配置，撤销公共运行清单；只保留 loopback 64791 的中文后台供用户查看。任务内凭据/签名密钥/模拟 Cookie/数据库/资源及临时入口均不入 Git；没有安装系统服务、全局切换代理或向生产制造流量。

新证据根 D:/Codex/Tasks/2026-11-02/visitor-real-ip-network/outputs：before-network.json、during-us.json、after-us.json、ingress-http-check.json（初次失败）、worker-denied-diagnostic.json、ingress-http-check-final.json、us-egress-correlation-final.json、gateway-offline-tests.json、chinese-us-realtime.png、chinese-us-country.png 及最终 DELIVERY.json。模拟器收据在 visitor-entry-simulation/outputs/visitor-only-20261102-055508-a4358cd6.json；环境/路由/GeoIP 证据在 data-tracking-qa/outputs/visitor-*.json。后续国家须重新开放受限入口、验证新真实出口再执行，不复用已关闭入口的验证标记。
