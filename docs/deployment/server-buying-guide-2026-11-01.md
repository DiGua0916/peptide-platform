# VialCove 服务器购买建议 · 2026-11-01

采购建议：首选 **DigitalOcean，美东 NYC3，按月付，Ubuntu 24.04 LTS**。备选 Akamai Cloud（原 Linode）；价格优先可比较 Vultr New Jersey。这个排序基于当前项目需要清晰的运维流程、独立实例和可核费用，不是三家性能或安全实测排名。先不要一次购买多年或全球多机房。

## 服务商与基础价格

以下是核查当日的美元月价，共享 CPU、自管 Linux；不包含税、WAF、独立异地备份、超量流量或人工运维。最终以实际机房和结算页面为准，不用优惠首月价替代续费价。

| 服务商 | 店铺起步规格 | 管理后台 HQ 起步规格 | 判断 |
| --- | --- | --- | --- |
| [DigitalOcean](https://www.digitalocean.com/pricing/droplets) | Basic Regular，4 vCPU / 8 GiB / 160 GiB SSD，$48/月 | 2 vCPU / 4 GiB / 80 GiB，$24/月 | 本轮首选；选择 NYC3，容量及套餐以创建时为准。 |
| [Akamai Cloud / Linode](https://www.akamai.com/cloud/pricing/cpu) | Shared CPU，4 CPU / 8 GB / 160 GB，$48/月 | 2 CPU / 4 GB / 80 GB，$24/月 | 可替代首选，使用同样的应用部署与隔离方案。 |
| [Vultr](https://www.vultr.com/pricing/) | Regular `vc2-4c-8gb`，4 vCPU / 8 GB / 160 GB，$40/月 | `vc2-2c-4gb`，2 vCPU / 4 GB / 80 GB，$20/月 | 官方定价网页读取403，使用[官方公开 plans API](https://api.vultr.com/v2/plans)核价；计划可用地区含 `ewr`（New Jersey）。不把南美地区加价套用为此价格。 |

DigitalOcean 的 NYC3 位于纽约；[官方可用区表](https://docs.digitalocean.com/platform/regional-availability/)列明对应套餐可用。选择美东是依据当前美国买家优先及未来欧洲方向的部署建议，尚没有用户实际网络测速。后续按买家分布、动态结账延迟及流量再决定增加欧洲或其他区域；CDN 无法把数据库和结账自动变成全球多活。

这些共享 CPU 规格是初期容量假设，不承诺并发或订单量。若实际监测显示 CPU 争用或高峰响应不稳，可比较独享 CPU；例如 DigitalOcean CPU-Optimized 4 vCPU / 8 GiB 为 $84/月，但本地盘仅50 GiB，必须重新核算媒体/数据库空间，不能只看CPU价格。

## 推荐购买数量

| 角色 | 初期配置 | 隔离要求 | DO 基础月费 |
| --- | --- | --- | --- |
| 核心 SEO / 复购会员站 | 4核8G，160G盘 | 独立 VM、站点运行账号、数据库账号和备份权限 | $48 |
| 广告承接站 | 4核8G，160G盘 | 与核心站、HQ分开 VM；每店独立账号/数据库，流量增长后再拆更多 VM | $48 |
| 管理后台 / 中央支付 HQ | 2核4G，80G盘 | 独立 VM；人员登录加访问控制，支付机器接口维持精确认证入口 | $24 |

如果首批只上线一种店铺，先买该店铺 + HQ 两台，基础 $72/月。核心站和投放站同时上线则三台 $120/月。首批店数与本地站点归属尚未确认，**没有据此自动创建多店或复制客户资料**。多台 VM 的账号仍需最小权限；同一个失守的云管理员账号可以影响全部 VM，所以云账号 MFA 和独立备份账号同样必要。

## 防护和备份另算

- DigitalOcean 备份[官方价格](https://docs.digitalocean.com/products/backups/details/pricing/)：每周备份为机器费20%，每日为30%；按两台 $72 算，每日备份额外 $21.60，三台 $120 则额外 $36。它不能替代数据库一致性、独立异地加密备份或已验证的恢复。
- [Cloudflare Pro](https://www.cloudflare.com/plans/)按月 $25/站点区域，年付折算 $20/月。可以先给正式客户站用 Pro；HQ 人员入口访问控制另配，不能认为买 Pro 就已经配置好管理员保护，也不能认为两个独立域名只买一次 Pro。核心站与投放站为两个独立区域时，月付合计 $50。
- [Backblaze B2](https://www.backblaze.com/cloud-storage/pricing)当前纯存储 $6.95/TB/月；实际账单还取决于容量、操作和恢复下载规则。可用独立账号/密钥的美国异地桶保存**客户端加密**备份，并核实不可变保留策略、恢复身份和容量。不能只把备份留在同一台主机或用同一组删除权限。

预算示例：DO三台 $120 + 每日机器备份 $36 + 两个客户站Pro $50 = **$206/月**；再为异地备份、监控、超量与税费留余量，初期可按 **$220–260/月预算占位**。这是规划预算，非全部服务报价或已购SLA。只有一类客户站 + HQ，则 $72 + $21.60 + $25 = **$118.60/月**，其余同样另计。先按月试运行，避免在容量尚未实测时年付锁定。

## 安全能力的边界

DigitalOcean [内置 DDoS 保护](https://docs.digitalocean.com/platform/ddos-protection/)侧重网络层 L3/L4，不能替代应用层保护；Vultr [额外 DDoS 服务](https://docs.vultr.com/support/platform/billing/how-much-does-ddos-protection-cost)为 $10/月/实例，官方说明缓解上限10Gbps。不能只凭“有DDoS”比较两者相同或宣称可挡任意规模攻击。

恶意同行可能造成洪水、应用请求、撞库、漏洞利用、采集或账号窃取；分别需要CDN/WAF/按行为限流、应用修补、MFA、最小权限、隔离及独立备份。公开商品图和文案可降低批量采集效率，不能保证绝对防复制。SEO站使用可验证搜索引擎身份及受控公开索引；不能仅按 User-Agent 白名单放行。合法广告承接与A/B测试不要求向平台审核隐藏另一套业务内容。

## 下单时选择

1. 先用本人云账号开 MFA，启用费用告警；选按月、NYC3、Ubuntu 24.04 LTS裸系统镜像，不预装未知面板或一键WordPress商店镜像。
2. 保留公网IPv4；IPv6若启用须同样纳入防火墙和源站访问控制，不能成为旁路。
3. 使用[SSH 公钥登录](https://docs.digitalocean.com/products/droplets/how-to/add-ssh-keys/)。只上传 `.pub` 公钥，私钥留在本机受限凭据目录，不进仓库、不发聊天；密钥具体创建/登记在接入时处理。
4. 原生每日备份可选，但独立异地应用备份仍要配置。初期不必购买负载均衡、Kubernetes或全球数据库；需求增长后另评。
5. 明天交接 IP、服务商/机房、SSH用户/端口、已登记的本机密钥位置、控制台核实的SSH主机指纹、正式域名和DNS操作入口。若需要登录，在浏览器/本地安全配置中完成，勿把密码/密钥粘贴聊天。

部署的实际顺序、机器路由、加密依赖及验收见 [生产实施手册](production-runbook.md)。
