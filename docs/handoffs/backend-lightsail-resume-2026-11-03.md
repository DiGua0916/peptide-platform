# Lightsail 投流店铺实际上线与验收

2026-11-03 · Codex · LIGHTSAIL-RESUME-01

投流店铺公开入口为 `https://s0001.vialcovepeptides.com`，固定管理入口已由另一获用户授权的聊天切换为 **`https://s0001.vialcoveresearch.com`**，独立店铺admin部署在后台服务器。web01承载公开PHP/API、主MySQL及数据文件，本机没有HQ。首次手动加密备份与隔离库/媒体恢复已通过，另一窗口接管的daily timer现为active。**站点能够访问，不代表已经可真实交易：doctor仍有6 fail、3 warn，支付和邮件实测未完成。**

## 当前发布与协作边界

首次从GitHub固定部署的组合为 `8c1daeddf268f12a572dc52dc97b52d731194a6a`，包含管理Host、原生店铺插件及前端历史。该版本正常原子推送后在服务器取同一提交，官方WordPress/WooCommerce/FluentSMTP依赖独立核验，初次暂存逐文件哈希检查11043项。

随后另一窗口更新Core，实际 `/srv/vialcove/current` 指向 **`/srv/vialcove/releases/c258436-web01`**。当前Core的435个文件已逐项匹配完整提交 **`c258436c5b4a7c8645521b4ff447e87b0d2c6327`**，其他非Core组件保持原8c组合；不能将整台运行站称为单一8c版本。官方WordPress校验另实际exit0，不能仅以早期暂存检查覆盖后续更新。

服务器从固定release运行，后续GitHub/main及本地前后端工作树可继续开发；本地未提交文件不会自动进入release。部署保留原本地数据与正在编辑的内容，未重置本地商品、装修或草稿。源码更新与线上业务数据库更新须分别处理。

### 并发更新后的入口与机器分工

另一授权聊天 `D:/Codex/Tasks/2026-11-03/store-admin-deployment` 已部署独立店铺admin，并于21:56Z切换固定management origin。新管理域的A记录指向后台机，登录页200和有效TLS由该聊天核验；这不等于本聊天完成了新域密码登录或全新架构验收。

公开home仍在原店铺域，其 `/wp-login.php` 现在403符合入口隔离，不应当作网站故障或改回公开。新admin经SSH隧道访问web01主库；文件桥接由上述聊天负责验收。本聊天此前的同机后台、HTTP和原生临时会话结果仅覆盖当时状态，不能自动证明此次跨机编辑、发布、文件桥接和全部授权链路已通过。

## 实际部署与数据

- 使用独立32GB数据盘和本机MySQL；没有创建100GB盘或托管数据库。附加前后实际核对唯一新盘、精确容量、稳定标识、无旧签名并排除根盘后才初始化。
- 数据盘固定挂载，完整冷复制初始MySQL系统datadir并逐文件核对后bind到默认路径。专用应用账号、FPM池、uploads、私报、配置及临时写入目录分离，关键数据不靠根盘空目录兜底。旧初始系统目录仅为准备副本，不是当前业务恢复库。
- 加密迁移产物已认证、解密、导入并落盘；原源快照70张InnoDB表，插件上线新增表后当前目标库为71表。文件清点3637个，438个私报均有DB引用且无缺失。原店身份、盐及加密连续性保留，具体值不公开。
- 原生验收确认82个已发布主商品、183个已发布变体、2个用户、0个订单；3个商品auto-draft及报告草稿状态保留。438个私报为219份正文及缩略图，没有因未发布误删报告或自动发布草稿。
- 发布模板和店身份保留。当前公开home为原店铺域，固定management origin已换至新管理域，不能沿用先前home/siteurl同域的快照结论。后续顾客域仍须按管理/顾客域分离流程绑定，不直接改固定管理入口。
- 迁移保护gate已删除，网站现提供实际页面，不再是仅503的准备入口。HQ及其他店铺不装在本release内。

## 实际验收

### 存储、依赖与重启

挂载及UUID守卫先于vendor预处理执行。最终用同一D-Bus连接持有单位引用，核真实BindsTo、After、父单位BoundBy及对象路径；三服务和MySQL bind均指向实际父挂载，CLI转义显示并非依赖错误。

实际MySQL合成库建、写、读及唯一随机marker库删除通过，数据文件位于正确盘；当时仍只有4个系统库，之后才导入业务。MySQL仅loopback监听，binlog/tmp/error路径落在数据盘，FPM专用socket启动通过。根代理随后核实AppArmor为enforce，未通过禁用策略绕过数据路径限制。

业务导入前实际停止父挂载，三服务全部停止；底层两个根盘目录为空、root所有且000。runtime mask父挂载后三服务start均失败、guard返回255、底层目录前后不变。finally移除本次mask、恢复正确盘和MySQL bind，三服务active且无mask残留；后续冷重启实际通过。此受控卸载验收不等于盘满、只读或任意I/O故障测试。

### 网站与后台：切换前验收及当前边界

- 新管理域切换前，公网有效TLS及34项真实HTTP检查通过；修正 `.mjs` MIME后模块资源按正确类型提供。未将其说成新跨机架构、完整浏览器JS、手机交互或压测全部通过。
- 切换后本聊天重新核实：新管理域登录页200且TLS有效、公开首页200、旧公开域登录页403。旧域登录403是正确隔离；新跨机架构其余测试由负责窗口完成，不能用本聊天切换前烟测代替。
- 当时以现有用户原生临时认证会话访问后台/订单页，验证有效REST nonce及缺nonce拒绝；会话随后销毁。未创建/删除用户、未改原密码，**没有实际密码登录测试**。该结果不自动覆盖新独立admin域，不保存Cookie/nonce。
- 原生实例验配置、发布模板、媒体引用及Ops worker通过；检查期间受控阻断邮件/外联，不作为真实供应方发信或付款验收。
- 真实cron以专用应用身份运行，首轮Result=success、退出码0，挂载视图与守卫一致；未放宽Linux属主或整机写权限绕过调度问题。

### 独立HQ连接

本机没有HQ。此前已为独立HQ的 **site 2** 验证双向签名online，原site 1保留，未覆盖旧登记；这证明当时身份连接，不等于支付方已经可收款。管理域切换后，HQ中管理源、机器请求及登录票据的绑定须由负责窗口按新入口复核，不能将旧online探针自动推广为新跨机架构验收。

HQ仍有出站供应方allowlist与mailgate限制，需协调其维护窗口的精确目标和放行条件。不要为赶进度无差别开放出站或移除邮件保护；用户部署后自行填写商户资料的决定继续有效。

## 加密备份、实际恢复与当前云调度

本聊天候选在被后续并发配置接管前，完成一次manual备份：明文仅在已核数据盘root:0700目录暂存，使用公开recipient，归档含逻辑SQL、backup-info、代码、独立uploads/报告/config及必要system配置，跳过重复uploads链接、旧备份、迁移密文和临时/session数据。SQL使用single-transaction，文件随后采集，**不是数据库与文件同一原子时间点的整站快照**。

该manual实际密文为1,309,370,824字节，已手动取回D盘受限目录并核SHA；真实age认证解密及最小权限新隔离库恢复通过：71表、2用户、82个已发布主商品、183个已发布变体、0订单。3637个媒体/报告文件全部实体恢复且SHA均相同；生产库未被覆盖，临时库、恢复账号和明文已清理。

首轮恢复误用源快照70表硬编码断言失败；改按上线插件新增表后的实际71表核对，再验通过，没有删除新表凑数。已验范围是该manual密文、隔离逻辑库与媒体实体，不自动扩大为新后台机、完整新主机启动或跨机链路恢复。

另一授权窗口的 `store-admin-deployment/install-backups.py` 于22:04Z覆盖同名backup.service/timer，根代理保留其新配置，没有覆盖回旧版本。**当前真实ExecStart为 `/usr/local/sbin/vialcove-web01-backup`（无.py），密文目录 `/srv/vialcove-data/store-backups`**；daily timer为active，计划19:40 UTC（北京时间03:40）并最多随机延迟300秒。本聊天实际触发当前服务，Result=success、ExecMainStatus=0；不将手动触发说成已观察到每日计划自动触发。

旧候选的“7个scheduled成功记录保留”仅做过本地纯夹具，**当前云脚本没有自动retention，也没有旧候选的scheduled成功收据，不能声称7份策略生效**。根代理确认新unit/script未引用后，按精确内容核对删除闲置旧 `.py` 和不用recipient；本聊天服务器age私钥、迁移transport-auth及snapshot-manifest也已删除。原始源密文、本地密钥和D盘manual副本保留。

当前web01备份不会自动覆盖独立admin机、其SSH隧道或文件桥接配置，相关主机备份由负责窗口衔接。上述manual恢复证据也不自动认证新窗口备份脚本的全部产物。自动异地复制与自动retention未实现，视频S3/CloudFront等服务未购买；现有副本不构成已承诺的RPO/RTO。

## 未完成的业务闭环

1. 当前doctor为6 fail、3 warn：付款、配送、Terms、Privacy、正式发件邮箱及admin邮箱未配置；客服、用途声明、结账确认仍有warn。需要真实业务配置，不能称今晚已具备真实交易闭环。
2. 用户填写商户配置后，协调HQ出站allowlist/mailgate，实测收银台、notify/return、金额及订单回写/核账，并配置真实邮件运输后验证外部投递。当前0订单，online连接或FluentSMTP安装不证明收款/邮件成功。
3. 独立admin窗口继续负责新入口、SSH隧道、文件桥接及授权链路验收；顾客域、完整浏览器/移动交互和压测按各自范围执行，不由本聊天旧同机结果代签。
4. 当前备份的自动retention、自动异地复制及独立admin机灾备尚未实现；不得用一次manual恢复通过替代这些能力或承诺RPO/RTO。

## 历史故障与证据

早期内置浏览器标签控制超时改用用户逐步点击；源码push遇正常并发main推进被拒后正常合并，没有强推。存储离线verify最初找不到fstab生成的父mount，单加generators仍失败，最终显式传经核验的实际生成fragment通过，并保留运行时挂载验证。多次busctl因inactive单位GC、过早拍父逆依赖造成的假失败，已用单连接持有全部引用后读取对象图修正。最初“仅暂存、未导入、未上线”及随后“后台仍与公开站同机同域”均为历史阶段，现由上述实际状态取代；旧收据保留但不混作新架构验收。

私有证据根为 `D:/Codex/Tasks/2026-11-03/lightsail-resume/outputs`。关键收据：storage-dbus-connection-audit-final.json、storage-runtime-acceptance.json、missing-data-disk-acceptance.json、storage-reboot-verified.json、media-restored-verified.json、native-store-acceptance.json、public-http-acceptance.json、native-admin-session-acceptance.json、real-cron-verified.json、initial-encrypted-backup.json、initial-backup-offserver-copy.json、encrypted-backup-real-restore.json、current-backup-final-state.json及HQ登记/连接收据。新admin变更另见 `D:/Codex/Tasks/2026-11-03/store-admin-deployment`，本聊天不替其签署全链路通过。

真实IP、UUID、密码、SQL内容、客户资料、Cookie/nonce、密钥及私有备份不入Git。本次只改本部署交接，不代提交其他窗口在途文件。控制台指导继续遵用户要求：一次只给一个操作。
