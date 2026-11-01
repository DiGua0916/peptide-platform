# VialCove 服务器购买建议 · 2026-11-01

最新用户偏好：**安全与客户隐私优先、性能稳定、买家浏览/操作流畅**。因此将本次主方案升级为 **DigitalOcean 独享vCPU，美东 NYC3，按月付，Ubuntu 24.04 LTS**；下文共享CPU价保留作历史对照，不再作为本次推荐采购配置。这个排序不是三家性能或安全实测排名，尚未采购或做云端压测。

## 本次推荐：独享vCPU方案

| 角色 | 官方套餐（Regular） | 包含本地SSD | 基础月费 |
| --- | --- | --- | --- |
| 核心 SEO / 复购会员站 | General Purpose：4独享vCPU / 16GiB | 50GiB | $126 |
| 广告承接站 | CPU-Optimized：4独享vCPU / 8GiB | 50GiB | $84 |
| 管理后台 / 中央支付HQ | CPU-Optimized：2独享vCPU / 4GiB | 25GiB | $42 |

价格来自[DigitalOcean官方](https://www.digitalocean.com/pricing/droplets)，三台合计 **$252/月**。独享vCPU减少与其他客户争用CPU的变量，不是包下整台物理服务器，也不保证整站任何时刻无延迟。更充足的核心站内存用于PHP/数据库等进程的合理工作空间，实际FPM进程数和内存预算仍需测定。

若先只上核心站和HQ，两台基础 **$168/月**；只上投放站和HQ为 **$126/月**。首批数量与本地现站归属未确认，不自动购买三台。

**本地盘比旧共享套餐小，不能误填成160GiB。** 为满足客户资料静态加密要求，本方案规划各实例独立[加密Volume](https://docs.digitalocean.com/products/volumes/details/features/)承载数据库、私有文件及含凭据的受限配置；系统盘仅放可重建系统/源码。先按每台100GiB估算，[每100GiB $10/月](https://www.digitalocean.com/pricing/volumes)，实际容量在购买前核实。临时导出、PHP临时文件、备份缓存、swap及故障转储也需检查，防止客户资料落回未受保护的系统盘。它是网络块存储，不承诺与本地盘/NVMe相同性能；数据库IO与恢复工作空间必须测，未通过则调整加密存储/数据库方案，不能以关闭加密换速度。

DO[官方明确](https://docs.digitalocean.com/products/backups/details/limits/)整机自动备份没有静态加密、也不含外挂Volume；其[备份在同一机房](https://docs.digitalocean.com/products/backups/details/features/)，可能影响繁忙数据库写入。**因此本次不默认给含客户资料的机器开启这类备份**，主备份改为一致性数据库/文件备份，客户端加密后保存独立异地。Volume原生存储/快照继承加密仍属同服务商控制，不等于独立灾备或客户自持密钥；有不同密钥控制要求须另评。恢复方案、密钥、保留与实际费用仍需验证。

加密盘未挂载时数据库/PHP及相关任务须停止，不能在空目录回落根盘继续写。系统服务依赖、临时/日志/会话路径、重启和缺盘负例都列入上线验收；静态加密不能代替运行中的权限保护。

## 按当前偏好计算预算

以下均月付、不含税/流量超量/人工运维，假设同时有核心站、投放站、HQ共三台；最终按容量和账号控制台确认。

| 项目 | 稳定性能起步 | 核心站加强边缘服务 |
| --- | ---: | ---: |
| 三台独享vCPU机器 | $252 | $252 |
| 三台加密数据Volume预算：3×100GiB | $30 | $30 |
| Cloudflare客户站套餐 | 两个Pro：$50 | 核心Business $250 + 投放Pro $25：$275 |
| 以上已知小计 | **$332/月** | **$557/月** |
| 含一致性加密异地备份/快照/监控等余量的规划预算 | **约$400–500/月** | **约$600–750/月** |

推荐先落实左侧独享CPU和所有基础安全控制；按核心站重要程度、所需边缘功能/支持决定是否采用右侧Business。用户的高安全偏好不自动授权购买付费套餐。Business不是企业级Bot Management，套餐提供的SLA/服务抵扣也不等于整站零故障或资料不会泄露。两列均需配置WAF、精确回调例外、源站认证、人员MFA和独立备份；不能以购买套餐代替验收。[Cloudflare套餐与价格](https://www.cloudflare.com/plans/)、[Business机器人防护范围](https://developers.cloudflare.com/bots/plans/biz-and-ent/)。

流畅度须把图片、脚本、PHP、数据库、网络和安全策略一起测；冷缓存/登录/购物车不能被匿名首页缓存成绩替代。验收目标见[访问性能与安全验收](performance-and-security-acceptance.md)。

## 共享CPU历史价格对照（不作为本次主方案）

以下是核查当日的美元月价，共享 CPU、自管 Linux；不包含税、WAF、独立异地备份、超量流量或人工运维。最终以实际机房和结算页面为准，不用优惠首月价替代续费价。

| 服务商 | 店铺起步规格 | 管理后台 HQ 起步规格 | 判断 |
| --- | --- | --- | --- |
| [DigitalOcean](https://www.digitalocean.com/pricing/droplets) | Basic Regular，4 vCPU / 8 GiB / 160 GiB SSD，$48/月 | 2 vCPU / 4 GiB / 80 GiB，$24/月 | 历史低成本对照，本次改独享vCPU。 |
| [Akamai Cloud / Linode](https://www.akamai.com/cloud/pricing/cpu) | Shared CPU，4 CPU / 8 GB / 160 GB，$48/月 | 2 CPU / 4 GB / 80 GB，$24/月 | 可替代首选，使用同样的应用部署与隔离方案。 |
| [Vultr](https://www.vultr.com/pricing/) | Regular `vc2-4c-8gb`，4 vCPU / 8 GB / 160 GB，$40/月 | `vc2-2c-4gb`，2 vCPU / 4 GB / 80 GB，$20/月 | 官方定价网页读取403，使用[官方公开 plans API](https://api.vultr.com/v2/plans)核价；计划可用地区含 `ewr`（New Jersey）。不把南美地区加价套用为此价格。 |

DigitalOcean 的 NYC3 位于纽约；[官方可用区表](https://docs.digitalocean.com/platform/regional-availability/)列明对应套餐可用。选择美东是依据当前美国买家优先及未来欧洲方向的部署建议，尚没有用户实际网络测速。后续按买家分布、动态结账延迟及流量再决定增加欧洲或其他区域；CDN 无法把数据库和结账自动变成全球多活。

这些共享 CPU 规格是初期容量假设，不承诺并发或订单量。若实际监测显示 CPU 争用或高峰响应不稳，可比较独享 CPU；例如 DigitalOcean CPU-Optimized 4 vCPU / 8 GiB 为 $84/月，但本地盘仅50 GiB，必须重新核算媒体/数据库空间，不能只看CPU价格。

## 隔离要求

核心站、投放站、HQ各自独立VM、站点运行账号、数据库账号和数据盘权限；首批数量未确认，不自动创建多店或复制客户资料。同一个失守的云管理员账号仍可能影响全部VM，所以云账号MFA与独立备份账号同样必要。

## 备份/边缘计费依据及历史预算

- DigitalOcean 整机备份[官方价格](https://docs.digitalocean.com/products/backups/details/pricing/)每周为机器费20%，每日为30%。这里只保留计费依据；基于上述加密限制，本次默认不启用，不计入当前主方案。任何额外快照也需明确覆盖范围、静态加密与保留费用，不能替代独立异地加密备份。
- [Cloudflare Pro](https://www.cloudflare.com/plans/)按月 $25/站点区域，年付折算 $20/月。可以先给正式客户站用 Pro；HQ 人员入口访问控制另配，不能认为买 Pro 就已经配置好管理员保护，也不能认为两个独立域名只买一次 Pro。核心站与投放站为两个独立区域时，月付合计 $50。
- [Backblaze B2](https://www.backblaze.com/cloud-storage/pricing)当前纯存储 $6.95/TB/月；实际账单还取决于容量、操作和恢复下载规则。可用独立账号/密钥的美国异地桶保存**客户端加密**备份，并核实不可变保留策略、恢复身份和容量。不能只把备份留在同一台主机或用同一组删除权限。

历史共享CPU示例$206/月及旧$220–260预算占位已过时，**不再对应用户最新独享CPU与加密存储/备份偏好**。当前已知小计为上方$332/$557，均非全部费用报价或已购SLA。先按月试运行，避免在容量尚未实测时年付锁定。

## 安全能力的边界

DigitalOcean [内置 DDoS 保护](https://docs.digitalocean.com/platform/ddos-protection/)侧重网络层 L3/L4，不能替代应用层保护；Vultr [额外 DDoS 服务](https://docs.vultr.com/support/platform/billing/how-much-does-ddos-protection-cost)为 $10/月/实例，官方说明缓解上限10Gbps。不能只凭“有DDoS”比较两者相同或宣称可挡任意规模攻击。

恶意同行可能造成洪水、应用请求、撞库、漏洞利用、采集或账号窃取；分别需要CDN/WAF/按行为限流、应用修补、MFA、最小权限、隔离及独立备份。公开商品图和文案可降低批量采集效率，不能保证绝对防复制。SEO站使用可验证搜索引擎身份及受控公开索引；不能仅按 User-Agent 白名单放行。合法广告承接与A/B测试不要求向平台审核隐藏另一套业务内容。

## 下单时选择

1. 先用本人云账号开 MFA，启用费用告警；选按月、NYC3、Ubuntu 24.04 LTS裸系统镜像，不预装未知面板或一键WordPress商店镜像。
2. 保留公网IPv4；IPv6若启用须同样纳入防火墙和源站访问控制，不能成为旁路。
3. 使用[SSH 公钥登录](https://docs.digitalocean.com/products/droplets/how-to/add-ssh-keys/)。只上传 `.pub` 公钥，私钥留在本机受限凭据目录，不进仓库、不发聊天；密钥具体创建/登记在接入时处理。
4. 按上方方案核实加密数据Volume与独立异地应用备份，先不默认启用未加密整机备份。初期不必购买负载均衡、Kubernetes或全球数据库；需求增长后另评。
5. 明天交接 IP、服务商/机房、SSH用户/端口、已登记的本机密钥位置、控制台核实的SSH主机指纹、正式域名和DNS操作入口。若需要登录，在浏览器/本地安全配置中完成，勿把密码/密钥粘贴聊天。

部署的实际顺序、机器路由、加密依赖及验收见 [生产实施手册](production-runbook.md)。
