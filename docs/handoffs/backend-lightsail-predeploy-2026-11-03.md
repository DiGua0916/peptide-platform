# Lightsail 投流机：运行依赖与数据盘准备

2026-11-03 · Codex · LIGHTSAIL-PREDEPLOY-02

本阶段继续[首台系统基线](backend-lightsail-bootstrap-2026-11-02.md)。**服务器已接入并安装运行依赖，网站尚未创建或开放。** 本记录更新旧部署手册的历史状态，不把准备工作当作应用上线。

## 最新执行范围

- 现有 `vialcove-web-01` 专作投流店铺；独立 HQ 服务器由用户在另一窗口准备，本窗口不将 HQ 装到 web01。
- 用户要求部署完整现有功能，优先验证真实支付与真实邮件。支付商户配置由用户部署后在 HQ 填写，不在聊天或 Git 收集密钥。真实 SMTP 配置及批准测试收件人尚未提供；尚无真实发信或收款验收。
- 最终选择本机 MySQL，先前 30 美元/月托管数据库方案暂缓。用户已批准单个 **32GB 独立附加盘**，完整月标价约 3.20 美元，税和备份另计；100GB 盘不创建。
- 目标盘 `vialcove-web-data-01`，与现有主机同 Virginia / us-east-1a。IAB 能列出 AWS 标签，但选取标签超时，已给用户中英逐项创建/附加指引。最后实际 SSH 只读检查仍仅原 320GB 根盘，**新盘尚未创建/附加核验，更未格式化**。
- 发布源码取 GitHub 固定提交；用户本地仍在编辑，不复制角色工作树未提交文件。数据库中的商品、装修及媒体需另取一致性快照，保留源站原数据及发布状态。

## 实际完成与验证

### 临时内核缓解

正式仓当前安装并运行的 `7.0.0-1014-aws` 不能标记 CVE-2026-98432 已修复。按 [AWS 临时缓解说明](https://docs.aws.amazon.com/de_de/lightsail/latest/userguide/address-cve-2026-98432.html)，将 IPv4 TCP 的 IP options 条件 `0>>24&0x0f=6:15` → DROP 适配到持久 UFW `ufw-before-input` 首条，位于 lo/ESTABLISHED ACCEPT 之前。这不是丢弃普通 TCP options。

已核 u32 支持、输入链顺序、规则唯一性；`iptables-restore --test`、reload 后新严格验证 SSH、受控重启后新 SSH/规则持久均通过。变更前有 root 受限配置副本与自动回退；另设跨启动回退守卫，重启复验成功后全部取消删除，无遗留 VialCove timer。**没有购买 AWS 快照**，不把配置副本称为官方快照恢复演练；未做漏洞利用测试。仍须跟踪正式修复内核，安装验证后再有计划移除临时规则。

### 运行包安装

安装 72 个运行依赖，包括 Nginx 1.24.0、MySQL 8.0.46、PHP 8.3.6 CLI/FPM，以及 MySQL/cURL/mbstring/XML/ZIP/GD/intl/bcmath/soap、Certbot 2.9。OpenSSL、sodium 及必需函数实测存在。安装时临时 `policy-rc.d` 抑制自动启动，之后已移除。

实际复查 Nginx/MySQL/PHP-FPM 均 inactive 且 disabled，Nginx 默认 enabled site 链接已移除，MySQL 仅包初始化系统库，无店铺业务库；应用目录尚不存在。公网监听仍仅 SSH，UFW 尚未开放 80/443，Lightsail 云防火墙尚未配置验收。

### 受限源码与官方依赖缓存

建立无 sudo、无交互登录的系统账号 `vialdeploy`，在 `/srv/vialcove-source/peptide-platform.git` 从公开 GitHub main 浅裸缓存已提交源码，最近缓存 `b447d85e0024932348a562fc3f51f75493989c98`。没有复制 GitHub 令牌，没有将缓存作为运行网站。后续 main 已新增前端提交；正式发布仍需重新选定完整验收 SHA，不能以此旧缓存代表最新版。

官方 ZIP 均先在 D 盘下载，按官方文件清单及 ZIP 路径/CRC/重复/软链接规则核验，随后仅将公开依赖传服务器，在 `/srv/vialcove-source/dependencies` 再验 SHA256 并保存 `dependency-lock.json`：

| 依赖 | 版本 | ZIP SHA256 |
| --- | --- | --- |
| WordPress en_US | 7.1.4 | `a147146c159362778ecbbbfb07bb20ddf4e85b130f971c824e656a8f938c5f87` |
| WooCommerce | 11.2.1 | `5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586` |
| FluentSMTP | 2.4.1 | `4932248e0fae299842ca0bd6b8dc71de7efef9b0302ffd7f29df39028673553f` |

三者均未解包启用。完整性核验不等于项目兼容、邮件投递或支付验收。FluentSMTP 计划提供真实运输配置入口，不能启用 Email Simulation；盐连续性、邮件日志权限/保留及既有营销队列另需验收。

WP-CLI 2.12.0 已从官方固定 release 准备；官方 SHA256/SHA512 和 GPG 签名均通过，签名指纹为 `63AF7AA15067C05616FDDD88A3A2E8F226F0BC06`。PHAR SHA256 为 `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c`，上传后再验相同，安装在 `/usr/local/lib/vialcove-tools/wp-cli-2.12.0.phar`，root 所有且不可由站点用户改写，`/usr/local/bin/wp` 为固定 PHP 路径包装器。以无 sudo 的 `vialdeploy`、空 CLI 配置、非站点目录运行 `--version` 实际通过；未加载 WordPress 或处理业务数据。收据 `wpcli-verification-passed.json` 与 `wpcli-server-installed.json`，不能将工具可运行等同网站已上线。

### 原站只读容量盘点

未 bootstrap WordPress 或写入原库。实际只读 SQL 统计 70 表均 InnoDB，data+index 估计 23,658,496 字节，2 用户、85 产品、183 规格，CPT/HPOS 订单 0。只输出计数，不读取客户字段值，不能据此认定所有数据均合成。uploads 约 801MB，私有报告存储候选约 557MB；这些是盘点时点数据，不是最终一致性快照或已核引用的迁移清单。

## 尚未完成

1. 用户附加盘后，以实时设备容量、卷编号、根盘祖先及空盘签名核对目标，再格式化挂载；不假定 `/dev/nvme1n1`。MySQL、PHP、备份/cron 和临时目录须绑定实际挂载，缺盘必须停服务；重启与缺盘负例通过前不迁业务资料。
2. 固定管理域与顾客域分离源码仍在独立候选分支实际 HTTP 验收。真实 Woo 认证已发现 home_url/Customizer 权限检查递归导致后台 500，修复中；此前纯夹具通过不构成可上线证明。本交接不合入未完成管理改造或独立远程 DB TLS 候选。
3. 固定店铺子域 DNS、有效 TLS、双栈云/主机防火墙、Host/SNI 拒绝、目录权限、实际应用安装和原站一致性迁移尚待完成；DNS 的主域/www 记录不自动覆盖固定子域。
4. HQ 新机、机器签名对接、用户商户配置、批准的真实发信、完整支付通知/返回/对账仍待验。无自动开店、真实交易或正式投流承诺。
5. 独立加密异地备份与隔离恢复尚未建立；本机配置副本不替代业务备份。无应用压测、无 RPO/RTO 实测保证。

本机证据位于 `D:/Codex/Tasks/2026-11-02/lightsail-predeploy/outputs`：`runtime-installation.json`、`deployment-status-before-site.json`、`ipv4-options-*.json`、`mitigation-after-reboot.json`、`server-github-cache.json`、`server-dependencies-verified.json`、`source-db-metrics.json`、`data-disk-preflight.json`。资产 IP、凭据、数据库内容和任务脚本不纳入本 Git 交接。
