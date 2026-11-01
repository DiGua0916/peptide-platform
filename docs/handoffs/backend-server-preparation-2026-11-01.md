# 2026-11-01 · Codex · SERVER-PREP-01

用户准备购买服务器，要求先准备迁移与安全部署材料，次日提供 IP。继续既有多肽项目记忆；本阶段仅做本地采购研究、部署交接和可复现的源码交付包，不连接、购买或修改云端资源。

## 范围与协作

- 新增 `docs/deployment/` 中的采购建议、生产实施步骤和无凭据资产模板；新增 `tools/deployment/prepare_release.py`，仅导出指定 Git 提交中的受控运行源码到 D 盘任务目录。
- 不改现有环境、业务接口、共享合同、WordPress/WooCommerce、前端工作树或运行中的客户数据；主题只作为已提交源码纳入交付包。
- 当前 `backend-deploy-checklist.md` 是 10 月 14 日历史清单，涉及自动 purge、五分钟支付调度和每店商户配置的步骤不得照搬。本次运行手册以当前 Ops/HQ 以及 Panapay 0.4 文档和代码为准。
- 实施仍由后续实际主机、域名、SSH 身份、加密依赖和备份恢复验收决定；文档/源码打包不代表生产安全或收款已通过。

## 实际检查与交付

新增文件：`docs/deployment/server-buying-guide-2026-11-01.md`、`docs/deployment/production-runbook.md`、`tools/deployment/inventory.example.json`、`tools/deployment/prepare_release.py` 及本交接。采购首选DO NYC3，Basic Regular店铺4c8G $48、HQ2c4G $24；2台基础$72、3台$120，每日快照30%、每个客户域名区域Cloudflare Pro月付$25另外算。Akamai/Vultr官方现价/地区及备份与DDoS边界由独立只读调研核实，未购买。

源码导出只读取Git不可变提交的4插件及主题，不读站点数据库/媒体/配置；拒绝源symlink、配置/备份文件名、仓库内输出、Windows非D盘输出、不同已有文件及输出symlink；重复输出逐字核验，ZIP固定元数据并附完整提交/逐文件SHA256。独立合成仓库验证普通成功/幂等、`.ENV`拒绝、并发不同ZIP拒绝，发现的早期竞态及大小写漏洞已修复；证据保留，不伪称初版通过。文件名限制不是完整secret scan或生产安全证明。

手册独立审阅修复：HQ需sodium真实函数，FPM/CLI同时核验；明确全debug关闭；本机hosts不证明服务器间或支付方公网命中。采用HQ根query路由不可套全域Access、免费BFM不能Skip、区域专属源站认证、真实IP与CDN入站分层、无query访问日志、盐/加密依赖连续性、Ops明文包外层加密且另备私有配置、源码只读与Ops自动替换权限取舍、真实每分钟调度、doctor JSON需quiet且解析fail等结论。

实际检查：Python编译、资产JSON解析、Git diff检查、独立源码/文档审阅及合成导出负例。提交后从整合提交生成交付ZIP，并在D盘核对全部文件SHA及禁止项；最终提交号/产物收据记本轮任务输出和项目记忆。完整证据根 `D:/Codex/Tasks/2026-11-01/server-preparation/outputs/`，含provider-research、deploy-readiness-audit、production-runbook-review及security-review-release-probes JSON。

尚缺：首批店数、本地现有站归属、实际IP/机房/SSH公钥与核实的主机指纹、域名及DNS授权、备份目的地/保留与恢复身份。没有SSH接入、Linux/Nginx语法或云运行测试，没有导出/传输真实数据或改DNS、TLS、WAF、收款开关。旧10/30文档的RPO/RTO仅目标，日报快照不能代替5分钟PITR；生产安全、真实支付和恢复仍待目标环境验收。后续先受限预上线验证，再冻结/最终迁移/切换；新站有写入后不得简单切回旧库。
