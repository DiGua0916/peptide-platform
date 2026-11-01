# VialCove 生产部署准备与接入手册

2026-11-01 · Codex。**状态：本地准备，尚未接入或部署服务器。** 本手册承接10月30日客户隐私需求文档，当前实现以 Panapay 0.4、Ops/HQ 实码为准。旧 `backend-deploy-checklist.md` 的自动删除演示、五分钟支付调度和每店商户配置不可直接执行。

用户最新要求提高安全性与性能；当前采购模板已改独享vCPU，原共享CPU成本为历史。当前配置与费用见[购买建议](server-buying-guide-2026-11-01.md)，实际访问/压测与安全联合验收见[性能验收](performance-and-security-acceptance.md)。未以更高配置替代实际验证。

## 1. 明天先取得的输入

使用 [inventory.example.json](../../tools/deployment/inventory.example.json) 在D盘任务私有目录制作实际资产表；只保存主机、域名、配置位置与状态引用，不保存密码/令牌/私钥。

- 服务商/账号项目、机房、每台IP（含启用IPv6）、配置、系统、控制台救援入口。
- SSH 用户/端口、本机已授权密钥位置；从服务商控制台独立核实主机密钥 SHA256 指纹。不能用 `StrictHostKeyChecking=no` 或未经核实的 `ssh-keyscan` 结果自动信任首次主机。
- 首批上线店数、本地现有店迁到核心站还是投放站，正式客户域名及HQ子域名；域名/DNS/Cloudflare账号的登录或最小权限授权。
- 备份异地目的地及不可变保留/恢复身份；原有实例加密依赖的安全迁移方式。账号在浏览器或受限本地文件配置，不经聊天或Git。
- 实际上线时间/冻结窗口、运费与内容验收责任、是否仅预上线。支付/SMTP/搜索账号未配置的继续关闭，迁移不自动开启。

## 2. 架构与权限

核心站、广告承接站、HQ 是**独立WordPress实例，不是Multisite**。按采购建议分 VM；每实例独立Linux账号、PHP-FPM池、DB库及只限该库的账号。数据库仅本机/受控私网，不对公网开放3306。HQ保存中央商户与支付事件，并在支付创建时处理必要客户资料，属于同样敏感边界，不能按“不接触客户数据”降级保护。

建议先手工按受控版本部署，PHP网站进程不持有sudo、全局建库账号或Nginx配置写权限。生产源码由独立部署身份写入，上传和私有数据仅所属站点可写。**这与现有Ops自动替换/恢复要求有取舍**：如启用原生Ops升级/恢复，必须明确授予该站所属代码/数据的写入权限；不能同时承诺全代码只读与后台自动升级恢复全可用。新店 provisioner 单独受限部署身份，不与公开店铺流量共用高权限；初期未完成隔离时保持自动开店禁用。

[Ops README](../../wp-content/plugins/peptide-ops/README.md) 的真实目录、MU loader、私有tools/profiles配置为后续部署依据。不能把本机junction部署成生产软链接，也不能将开发数据库、连接身份和客户记录当新店模板。服务器 `webserver` driver 不自动购买、DNS或TLS；未准备域名与vhost就不能宣称自动开店可用。

## 3. 源站与边缘保护

基线建议 Ubuntu 24.04 LTS + PHP 8.3 + MySQL 8.0 + Nginx；选8.3为与当前本地兼容起点，仍需核实实际生产模块。WooCommerce官方[建议PHP8.3+](https://woocommerce.com/document/update-php-wordpress/)。生产使用同一经验收的WP/Woo版本，补丁升级先在隔离环境回归，不在迁移中盲升主版本。

1. 先验证新运维账号SSH登录和控制台救援，再限制SSH为管理来源/VPN并关闭密码及常态root登录；更改防火墙前保留现用会话并验证第二会话，防锁外。
2. 公网仅必要HTTP(S)入口；数据库/Redis、管理面板不暴露公网。HTTP仅用于必要跳转/ACME，否则关闭。IPv4和IPv6同步防火墙规则。
3. Cloudflare SSL Full (strict)；源站使用有效TLS，HTTPS识别正确。仅CDN IP允许进入不是充分的本区域认证：[全局AOP证书全账户共享](https://developers.cloudflare.com/ssl/origin-configuration/authenticated-origin-pull/explanation/)，需选择本区域专属AOP/mTLS，或经过实际验证的Tunnel；同时默认Host/SNI拒绝未知站点。此阶段没有建立任何Tunnel。
4. 先把CDN入站限制放云/主机防火墙。Nginx信任官方代理网段后再取 `CF-Connecting-IP`；不能因real_ip替换后的 `$remote_addr` 是买家IP，又在同一处用它去匹配Cloudflare网段而误拒。可信代理来源、TLS和内部API链路需要共同实测。
5. HQ人员入口可保护 `/wp-admin/*`、`/wp-login.php`，管理员再开MFA；逐项核实 `admin-ajax.php` 等真实用途，不给整个admin目录做无差别公开例外。这只是人员入口增强，不等于所有管理REST均已私网化；管理API仍需WordPress权限/nonce或原有机器签名，不因浏览器入口受保护就免鉴权。买家和员工流程按实际登录入口分别验证。当前内部客户端没有自动附带Cloudflare Access Service Token，不能当作已支持。
6. **不要对HQ整个域名启用交互式Access，再以 `/wp-json/*` 例外推定机器请求可用。** 当前机器请求使用 `/` 加 `rest_route` query；[Access应用路径不支持query字符串](https://developers.cloudflare.com/cloudflare-one/access-controls/policies/app-paths/)。应保护人员路径并在边缘/源站按精确方法、规范化路由及应用认证分别校验。
7. 含回调的域名默认不启用免费Bot Fight Mode：[官方说明其不能用WAF Skip例外](https://developers.cloudflare.com/bots/get-started/free/)。如用可配置例外的SBFM/Bot Management，仍需真实验证；挑战只用于适合的浏览器路径，机器接口不能收到验证码/HTML挑战。异常限流保留，但不能以支付方未知IP范围猜白名单。

本手册不预生成未知域名的vhost、证书、防火墙命令或全站WAF表达式。等真实主机和DNS可核实时按同一资产表生成、先语法检查再启用，避免把占位规则直接应用到生产。

## 4. 必须可达的接口与缓存

精确规则以 [Panapay部署文档第4节](../payments/panapay-deployment.md#4-网络入口waf-与缓存) 为准：

| 实例 | 根路径query中的rest_route | 方法/认证 |
| --- | --- | --- |
| HQ | `/pc-panapay/v1/hub/methods`、`/pc-panapay/v1/hub/config`、`/pc-panapay/v1/hub/session`、`/pc-panapay/v1/hub/session/status` | POST；各店独立HMAC，保持请求体与签名，不能浏览器挑战 |
| HQ | `/pc-panapay/v1/hub/notify` | POST表单；支付方签名，持久保存后纯文本`ok` |
| HQ | `/pc-panapay/v1/hub/return` | GET；尝试/令牌/返回签名，只读回原店 |
| 店铺 | `/pc-panapay/v1/store/event` | POST JSON；签名结果，校验所属UUID/订单 |

运维/注册/一键登录另按HQ和Ops实际路由验证，不开放整个REST以图省事。保留旧付款在途通知入口直达唯一权威实例，不能用改变POST方法的跳转，也不并行运行两个可写财务副本。

首轮只缓存静态图片/CSS/JS，不启用“Cache Everything”或通用Nginx整页缓存。账户、购物车、结账、订单支付/返回、授权报告、`rest_route`、`pc_hq_login`、`ticket` 及登录/Woo会话cookie全部按实际路径和query验证绕过。未来启用公共商品页缓存，另测A/B用户私有响应不能交叉命中；API POST绝不缓存。管理员/下载的根query入口同样不能漏。

Nginx日志使用不带query的 `$uri`、方法、状态、时延和内部请求编号；不记录 `$request`、`$request_uri`、请求体、Cookie、Authorization、支付收银台URL或真实客户资料。WAF/APM/CDN日志也独立检查脱敏及保留期。安全诊断需要IP时限定用途、权限和短期保留，不为方便永久记录。

## 5. 迁移清单与加密连续性

源码包只含Git跟踪的4插件与主题，使用工具从完整提交导出，有逐文件及ZIP SHA256；**不等于整站备份**。WordPress与WooCommerce另从官方可信来源取得并核验版本/完整性。主题包是已提交源码快照，打包不会把协作者未提交文件带上。

在当前已提交的后端工作树准备源码包（工具不接触WP实例、不调用SSH）：

```powershell
python D:/Codex/Projects/peptide-platform-worktrees/backend/tools/deployment/prepare_release.py --repo D:/Codex/Projects/peptide-platform-worktrees/backend --ref HEAD --output D:/Codex/Tasks/2026-11-01/server-preparation/outputs/release
```

输出包括按完整提交标识绑定的ZIP及逐文件manifest，重复执行要求已有内容逐字相同；不覆盖不同输出、软链接或未提交的已跟踪改动。文件名限制不能代替密钥扫描/人工审查；传输前验证ZIP与manifest的SHA及当前部署选择，不能仅凭文件存在声称代码安全。

逐实例分别做受限加密迁移，不把完整内容打印到工具输出：

| 对象 | 来源与注意 |
| --- | --- |
| 实例数据库 | 包含原生产品/变体/订单/顾客、HQ账本/事件、设置、装修文档及custom_css；仅同实例整库迁移，保护导出一致性 |
| uploads | 原生媒体、派生尺寸和产品图；保留数据库附件引用，不能只传源码 |
| 私有报告 | `PEPTIDE_CORE_PRIVATE_DIR`，生产必须webroot之外，校验真实文件数及SHA；不得把报告目录直接设静态可浏览 |
| 原实例配置与加密依赖 | 安全保留原salts或显式加密键，HQ原Crypto依赖auth盐，Panapay依赖32字节标准Base64键或盐派生；不能生成新盐后宣称旧密文可读 |
| Ops私有目录 | 单独备份config/profiles与必要任务状态；Ops整站备份刻意不含它，不能当已完整覆盖 |
| 域名/调度/系统配置 | vhost、TLS、防火墙、DNS、进程与备份配置各自受限保存；密钥独立保管 |

源本地站为 `D:/Codex/Sites/vialcovepeptidescom/app/public`，HQ由审计后的实际路径确定。部署包不直接复制 `wp-config.php` 到公开交付包；受限迁移时保留既有加密材料并改DB/URL/路径。**原实例迁移保留原UUID和连接身份，新建店生成新UUID/密钥/独立数据库**，不能混用。

URL替换先 `wp search-replace <old> <new> --all-tables-with-prefix --skip-columns=guid --dry-run`，保留dry-run摘要并核对原URL再实际执行，使用原生序列化安全处理。只限本站表前缀，不在共享数据库上无差别改全部表；不强行改加密支付快照中的旧回调URL。重新核实HQ与店铺HTTPS地址/接受关系，不能把DB文字替换当作签名连接/旧支付回调迁移完成。

现有82个已发布主品/183变体是本轮审计起点，迁移当天重新核对；前端同事的装修草稿/媒体可能继续更新，必须在冻结窗口取最终DB/媒体快照。不能重跑旧导入脚本、旧演示purge或把未发布首页草稿当成正式首页。

## 6. 运行配置与定时任务

每实例服务器独立配置，敏感值不放资产JSON、源码或聊天：

- `WP_ENVIRONMENT_TYPE=production`，明确 `WP_DEBUG=false`、`WP_DEBUG_LOG=false`、`WP_DEBUG_DISPLAY=false`，`DISALLOW_FILE_EDIT=true`；迁移前发现的debug.log移至受限证据存储，不随uploads或源码公开。私有报告和Ops目录在webroot外，属主/权限只给本实例。
- `DISABLE_WP_CRON=true` 配真实每分钟系统任务；本站账号用受控PHP和WP-CLI执行 `cron event run --due-now`，带互斥、超时和不含敏感内容的失败监控。HQ覆盖 `pc_panapay_hub_relay`、`pchq_backup_schedules`、`pchq_poll`，店铺也必须运行到期事件。不能用“页面被访问过”证明后台持续运行。
- 配置完整可信代理网段；`PEPTIDE_CORE_TRUSTED_PROXIES`、系统真实IP规则和CDN入站认证相互匹配。当前Ops新店配置不自动补齐这些生产项，新店成功后仍逐项验。
- 必需PHP模块按代码核验：MySQLi、mbstring、curl、openssl（AES-256-GCM）、**sodium**、zip、DOM/XML、GD或Imagick及WP/Woo所需模块；网站进程和CLI都检查。HQ Crypto直接使用 `sodium_crypto_generichash`、`sodium_crypto_secretbox` 和 `sodium_crypto_secretbox_open`，必须核实函数存在，仅有OpenSSL不够。受控Ops需要 `proc_open` 和绝对工具路径；启用前核权限。
- MySQL各实例主库，不能把支付查询指向只读副本。保持当前经核实的订单存储，不在迁移中未经计划切HPOS；新站默认可选HPOS，但以兼容验证为准。
- 保留支付账号现有关闭状态；迁移不是开通支付。真实支付按当前文档逐项验网关创建/公网通知/回写/金额核账/结算再由用户决定开启。

## 7. 备份、验收与切换

Ops `files.zip` / `database.sql` 是未加密本地备份，可能含配置；SHA256只证明完整性。独立任务先客户端加密再上传异地桶，备份身份无删除历史权限，恢复身份独立受控，并核实不可变策略；应用UI备份与每日主机快照都不能代替这一层。

最新采购核实：DO整机自动备份未做静态加密且不包含外挂Volume，不能把它当已加密客户备份。本次模板默认不开启这项；每实例客户DB/私有配置及文件规划在原生静态加密Volume中，根盘仅可重建系统与源码，临时文件/swap/日志/故障转储同样核隐私边界。网络盘IO需要实测，加密盘与同服务商快照不能代替独立客户端加密异地恢复。详见[官方备份限制](https://docs.digitalocean.com/products/backups/details/limits/)、[Volume加密](https://docs.digitalocean.com/products/volumes/details/features/)。本段替代上版“默认每日机器快照”的采购取向，未改变任何实际云资源。

**挂载失效必须停止相关服务，不能静默回落根盘。** 目标机按实际挂载点给MySQL/PHP/备份任务配置 `RequiresMountsFor` 等依赖，并实际验证挂载点及异常卸载后的停止/告警；不能只用 `fstab nofail` 然后让进程在空目录新建数据。逐项核MySQL数据/binlog/redo/undo/tmpdir及各日志、PHP upload_tmp_dir/session.save_path、Nginx请求体/FastCGI临时目录、SQL导出恢复与加密前暂存、journald/core dump/swap。资产表记录各类路径和挂载检查，重启/缺盘负例不通过就不能接收客户资料。该目标不表示整台VM的所有系统配置均已加密；静态加密也不能阻止运行中root或失守应用读取已挂载明文，仍需最小权限和修补。

10月30日建议的核心RPO≤5分钟/RTO≤2小时是目标，**不是已购SLA**。每日快照的备份间隔为24小时，不能拿它承诺5分钟RPO。若确定该目标，需补MySQL一致性全备+受控binlog连续归档/PITR、异地密钥和实际恢复计时；费用、延迟与恢复步骤另验。至少完成一次隔离恢复，验证旧密文/订单/商品/媒体/私有报告以及恢复后重放删除记录不会复活已要求删除的数据。

切DNS前须取得目标环境证据：

- [ ] OS补丁、SSH/MFA/最小权限、云与主机双栈防火墙、无公网数据库，HTTP/TLS与Host/SNI拒绝、源站绕过实测。
- [ ] 源码提交和逐文件hash一致；WP/Woo完整性、PHP模块、webroot/private边界；无开发junction；仅正确实例有写权限。
- [ ] 产品/变体、专辑顺序、图/描述、装修草稿与发布状态、DB自定义CSS、订单/账户计数迁移前后核对；不输出客户内容。
- [ ] 用 `wp --quiet peptide doctor --format=json` 分别捕获stdout/stderr，stdout须严格解析JSON，再逐项判断，**不能只看退出码**：当前实现fail仅warning；未加quiet的成功摘要可能污染JSON。任何解析错误或 `level=fail` 处理后复查，warn逐项有结论。
- [ ] 两个买家/不同权限报告、未授权下载、订单归属、员工登录与HQ跨店授权实际验证；缓存不串用户。
- [ ] 原实例加密资料可解密、HQ→店同步及各签名接口无Access/挑战拦截；错误签名/重放拒绝。不要向真实支付通知接口编造成功事件。
- [ ] 每分钟调度在无人浏览、断线恢复及进程重启后仍工作；outbox积压和失败告警能被运维接收。
- [ ] 备份异地可恢复、密钥不随同一泄露身份取得；恢复计时/丢失窗口实测并由用户确认。
- [ ] 支付、邮件、搜索服务未验的继续停用，不标为正式交易闭环已完成。

先用临时受限预上线入口/本机hosts验页面，不改公共DNS。本机hosts只影响本机，**不能证明HQ↔店铺或支付方→HQ已命中新实例**；这些请求须分别从实际发起环境核实DNS、有效TLS、目标实例身份和响应签名/回调证据，不以关闭sslverify替代。支付方公网回调需要真正可达的受控正式入口，纯本地hosts无法验证。

冻结旧站写入 → 最终DB/文件快照 → 迁移与验证 → 小范围DNS切换 → 监测。切换后唯一新主库处理真实写入；旧站留只读受限回退点。**新站已有订单后不能直接切回旧DB**，要先冻结并核对/迁回增量，避免丢单或双写。任何恢复失败停止自动重试，保留证据和当前完整状态。

## 8. 已准备与未执行

已准备：采购依据、隔离/权限方案、无凭据资产模板、迁移/回退与生产验收清单、可复现受控源码打包工具。

尚未执行：云账号/SSH接入、Linux安装、DNS/TLS/WAF配置、实际数据导出/传输、云端压测与安全验收、真实收款及恢复演练。IP到位后先核实资产与登录，不使用未知主机的一键root脚本。
