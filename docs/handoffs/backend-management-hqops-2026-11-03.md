# 固定管理域：HQ/Ops 候选实现

2026-11-03 · Codex · MANAGEMENT-HQOPS-01

用户已确认每店固定平台管理子域，顾客域可更换；HQ 独立。此候选只修改 peptide-hq、peptide-ops 及专属测试，不修改 core/theme/共享 CONTRACT，不接入原站、服务器或真实数据。配套 core 由另一个独立任务实现，待根代理组合及跨 Host 实测后决定合入。

## 行为

- HQ 0.3.2 / schema 4 新增 `management_url`、`public_url`；旧行空字段回退原 `url`。旧 `url` 继续保存机器管理地址，不随顾客域变化。
- 显式注册验证 HTTPS 根地址及完整 UUID；已有记录须符合原 UUID，有效连接密钥才能更新地址。换密钥仅写 pending，不更新地址；旧客户端不能把已固定管理地址覆盖为顾客 home。待定密钥探测也校验远端 UUID。
- HQ Client/运维目标使用已登记管理地址；一键登录及销售专属入口链接只允许该管理 origin，拒绝降级、其他端口/Host、凭据与 legacy 子路径逃逸。顾客预览使用 public 地址。HQ 账号权限仍为原 `manage_options`/nonce 与店端签名，不新增跨店共享账号。
- Ops 新店可显式提交管理/顾客 URL，均受私有 profile 域名白名单约束；顾客地址缺省同管理。`url`/安装 URL/健康检查使用管理地址；新建 UUID 同时写管理 UUID 常量与 Hq option。
- 双域配置固定 `WP_SITEURL`，不定义 `WP_HOME`，DB `home` 初始为 public；旧单域和本机 driver 保留原行为。新店仍使用独立 DB/DB 账号、密码和 salts，不复制客户资料。
- 新字段进入任务请求哈希，任务重试不能改顾客地址。受限 vhost 参数增加管理/顾客地址占位符；未扩展任意命令、DNS、TLS 或云资源购买。
- 窄审查补充：Ops 状态中的机器地址与完整备份下载票据使用 core 固定管理地址，顾客 home 变化不能把下载票据转到 public；显式配置缺失有效 core helper 时拒绝生成票据。发布后的前台 HTTP 健康检查保留原 public home 意图，不放开 public 的 Ops REST。

## 实际验证

- HQ 内存注册与真实源码路由测试：36 项通过。包括 public 切换、同密钥/UUID、pending key、legacy fallback、防止旧客户端覆盖、管理 origin 与子路径检查。
- Ops 纯配置边界测试：23 项通过。包括 profile 白名单、非法 URL、本机拒绝双域、同域起步、无 WP_HOME、双域 siteurl/home、独立 UUID/DB/salts 与 legacy 保持。
- Ops 机器地址纯测试：9 项通过，覆盖实际状态 DTO、下载 URL 构造、顾客域切换不影响票据与失效配置先于私有存储检查被拒绝。
- HQ 测试第一次在裸 `php -n` 下因 mbstring 未加载失败；加载本机已安装 mbstring 后重跑通过，未安装额外依赖。两套测试无 WordPress bootstrap、数据库、网络或文件操作。
- 首候选 16 个变更 PHP 文件及机器地址补充的 2 个 PHP 文件逐文件语法检查通过，`git diff --check` 通过；提交标识见根代理本次整合记录。

## 待根代理验收

需要配套 core 管理 Host/UUID 策略及实际 Nginx 双 Host 配置后，验证完整 WP cookies、REST nonce、canonical、销售账号与导出隔离，以及顾客域切换后的 HQ 连接和支付回调。此候选未证明公网部署、真实账号/订单/支付/邮件联调或双域生产验收完成。迁移旧 HQ 升 schema 4 前仍须备份；本次没有实际 DB migration。

独立分支 `codex/management-hqops-20261102`，base `b447d85e0024932348a562fc3f51f75493989c98`。本任务不 push、不合并 main，由根代理 cherry-pick 后统一验收；本分支不纳入任何私有 profile、凭据、运行配置或收据。
