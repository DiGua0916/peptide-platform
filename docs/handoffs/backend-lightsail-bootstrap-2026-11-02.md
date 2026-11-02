# Lightsail 首台主机接入与系统基线

2026-11-02 · Codex · LIGHTSAIL-BOOTSTRAP-01

本阶段已实际接入首台 Lightsail 主机、应用稳定仓更新、配置主机防火墙与 SSH，并受控重启复验。网站、数据库、客户资料和 DNS 尚未部署。本记录更新 `production-runbook.md` 中“尚未接入服务器”的历史状态，仅覆盖主机基线部分。

## 已核实资产与接入

- 实例 `vialcove-web-01`，Virginia / us-east-1a，Compute-optimized、Dual-stack、4 vCPU / 8 GB / 320 GB 根盘。用户截图显示 Running、两项状态检查 Passed，并已登录 AWS 浏览器 SSH。
- 用户创建 `vialcove-web-ip-01` 并绑定成功。实际源站 IP、主机指纹和本机凭据位置只记在 D 盘任务资产与项目记忆中，不写入本 Git 交接。
- 首次主机 ED25519 SHA256 指纹由用户在已认证的 AWS 浏览器 SSH 中输出，与本机 SSH 握手逐字核对后才建立专用 `known_hosts` 信任。未关闭 StrictHostKeyChecking。
- 本机通过区域默认私钥以 `ubuntu` 登录。受限操作副本保存在 D 盘 Git 目录之外，仅当前 Windows 用户和 SYSTEM 有访问权限；桌面原件保留，私钥内容未输出或上传。
- Windows 自带 `ssh-keyscan` 遇到不支持的 KEX 错误，未采用其结果。正式连接指定 `curve25519-sha256` 与已核实的 ED25519 主机密钥，继续严格验证身份。

## 实际修改

1. 刷新 Ubuntu noble 正式软件源，模拟确认无删除后以 `--with-new-pkgs --no-remove` 升级，保留现有配置。104 个包升级、6 个新增依赖，未启用 proposed 源、未做发行版大版本升级或 autoremove。
2. 安装当前稳定内核 `7.0.0-1014-aws`，从原 `7.0.0-1012-aws` 受控重启到新版本；旧内核保留。OpenSSH server 更新到 `1:9.6p1-3ubuntu13.20`。
3. `/etc/apt/apt.conf.d/99-vialcove-no-auto-reboot` 明确关闭无人值守自动重启；原有安全更新服务和定时器保持启用。
4. UFW 默认拒绝入站、允许出站；仅允许 22/TCP，IPv4 与 IPv6 均生效。SSH 当前仍为 Anywhere 来源，以保留本机和 AWS 浏览器入口，**未声称已限制到管理员 IP**。未修改 Lightsail 云防火墙，也未核验其完整规则。
5. `/etc/ssh/sshd_config.d/00-vialcove-baseline.conf` 禁止 root、密码、键盘交互认证和 X11，保留公钥登录；不改现有 `ubuntu` 授权公钥、AWS/cloud-init 密钥逻辑或 SSH 转发设置。
6. 防火墙及 SSH 分步变更前均设 5 分钟受限回滚任务，保留原 SSH 会话，每步另建新连接验收后才取消回滚。最后一次受控重启后确认没有遗留 VialCove 回滚或重启定时器。

配置回退副本在主机 `/var/backups/vialcove-bootstrap/`，更新日志在 `/var/log/vialcove-bootstrap/`，均限 root；它们只是本次系统配置记录，**不构成业务备份或恢复演练**。

## 实际验证

- 指纹核对后的本机密钥登录、更新后的新连接、UFW 后新连接、sshd reload 后新连接、重启后新连接均成功。
- `sshd -t` 通过；未发现原有 Match 规则，另以实际管理连接的用户与来源上下文检查 `sshd -T -C`，重启后仍符合上述认证设置。
- 重启前后 boot ID 不同；正在运行 `7.0.0-1014-aws`，`reboot-required` 不存在。
- 系统 `running`，没有 failed units，NTP 已同步；`dpkg --audit` 为空，正式仓后续模拟升级为 0 项待更新/新增/删除。
- UFW 重启后 active，22/TCP 双栈规则存在；实际公网 TCP 监听仅 SSH，DNS 监听限本地回环。
- 尚无 Nginx、PHP-FPM、MySQL、Redis 业务包或业务数据库。仅有根盘，没有附加客户数据盘。
- AWS 浏览器 SSH 在变更前由用户真实登录，变更保留其入口与身份逻辑；**变更后未重新点击浏览器登录验收**。没有以本机新连接替代这一项声明。

完整本机证据目录：`D:/Codex/Tasks/2026-11-02/lightsail-bootstrap/outputs/`，主要收据为 `system-audit-before.json`、`package-update-plan.json`、`package-update-result.json`、`firewall-verified.json`、`ssh-baseline-verified.json`、`controlled-reboot.json`、`reconnect-after-reboot.json`、`system-audit-after.json`、`final-checks.json`。任务脚本、主机日志与资产不纳入本次 Git 提交。

## 尚未解决与下一步

1. AWS Linux 内核公告涉及的 CVE-2026-98432 **尚不能标记修复**。本轮 [Ubuntu 官方 CVE 状态](https://ubuntu.com/security/CVE-2026-98432)对 noble 的 linux-aws-7.0 仍示修复中，目标为 `7.0.0-1017.18~24.04.1`；[官方迁移记录](https://ubuntu-archive-team.ubuntu.com/proposed-migration/noble/update_excuses.html)亦仍待迁移。本机正式仓候选只到 1014，不能用当前升级成功宣称该漏洞消失。正式业务开放前重新核实、安装可用修复并复验；未运行漏洞利用或主动攻击测试。
2. 域名及 DNS/Cloudflare Free、TLS、云防火墙管理来源、受限预上线入口仍待配置。本次不证明 CDN、源站隐藏或真实客户 IP 链路已可用。
3. 附加数据盘、加密路径与挂载失败停止服务方案、独立异地备份及恢复仍待实施；不能把根盘空间当作已完成客户资料隔离。旧手册中的 DigitalOcean 存储/备份限制不直接适用于 AWS。
4. 网站安装及迁移需先确定实际域名、数据路径、源码版本和冻结快照，再遵循 production-runbook。现有本地商品、装修、客户和订单未修改或上传；支付、SMTP、搜索、自动开店保持原状。
5. 没有运行应用性能压测，没有购买附加磁盘、备份、域名或付费 Cloudflare 服务；本阶段完成不等于公网网站上线或全部安全验收完成。

用户操作偏好：控制台指引给出英文原文、中文意思与具体填写值；内置浏览器终端无法正常粘贴，后续已授权主机操作优先走本机后台 SSH，不占用用户桌面。
