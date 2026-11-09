# 复盘：主店支付"支付会话未确认"（2026-11-09）

## 现象
主店 vialcovepeptides.com 测试下单，点付款后结账页报：
"The payment session has not been confirmed. Please contact the store before paying again."
订单 11811–11816 全部停在待付款。

## 一共有两个原因，先后发生

### 原因一（上午，总后台服务器配置）
总后台 `/etc/hosts` 里残留着 `s0001.vialcoveresearch.com → 127.0.0.1` 之类的本机回环条目。
支付中枢在创建会话前会校验每个店铺的后台地址必须是"公网可解析的 HTTPS 地址"，解析到 127.0.0.1 直接判不合格，
抛出 `Panapay gateway must be a public HTTPS origin`，店铺收到的就是"未确认"。
**处理**：删掉这些 hosts 条目（沙盒那一行保留）。6 个站点的地址校验与轮询恢复正常。

### 原因二（下午，收单方换了收银台域名）
hosts 修好后再下单，中枢已经成功向收单方（backupaoy.top）创建了会话，但收单方返回的付款页地址在
**easygatewayb.top**，而总后台「信用卡」账户登记的收银台域名只有 marina-style.com、nexus-globaltrade.com。
中枢出于防钓鱼只放行名单内的域名，不在名单 → 会话判为 `unknown`（"创建结果待核对"）→ 店铺提示"未确认"。
**处理**：把 easygatewayb.top 加进「信用卡」账户的收银台域名（旧配置备份 `pc_panapay_hub_accounts_backup_20261109b`）。
两个支付账户都是"全部站点"共用，所以一次修改对 6 个站点同时生效。之后 11815（信用卡）、11816（wallet）会话均正常创建。

## 为什么查了这么久
- 店铺侧日志里没有任何记录：中枢返回 `unknown` 时店铺代码把异常全部吞掉（`Bridge::create` 的 catch 只返回状态，不记原因）。
- 中枢侧也只把"放行失败"静默改成 `unknown`，没有写明细、没有提醒。
- 总后台 nginx 对站点关了访问日志。
最后是靠解密中枢会话表里的付款页地址，才看到域名对不上。

## 已做的预防
1. **中枢拒绝名单外域名时自动提醒**（`peptide-panapay/includes/class-hub.php` `host_alert()`）：
   - 在中枢明细表记一条"收银台域名不在名单"（带域名、账户、订单号）；
   - 在总后台「提醒」开一条新类型 **支付通道提醒**（`PeptideHq\Alerts` 新增 `payment`），
     文字直接写明：哪个账户、哪个域名、去"支付账户 → 收银台域名"加上即可；同一域名只开一条，会按提醒邮箱发邮件；
   - 域名加进名单、下一笔会话放行后自动标记已恢复（`host_alert_clear()`）。
   已部署到总后台（release `pay-host-alert-1794241890-hq`）并用假域名实测：开告警 → 恢复 → 清理。
2. 总后台 hosts 文件里不再放任何 vialcove 域名的回环条目（已清理，文档记录）。

## 以后怎么避免
- **向收单方要完整的收银台域名清单**，一次性全部登记到两个账户（信用卡、wallet）；他们每次换域名都要提前通知。
  wallet 账户目前只有 valewood-llc.com 一个，风险一样存在。
- 看到"支付会话未确认"先看总后台「提醒」，有「支付通道提醒」就照着加域名，不用再查日志。
- 子店（vialcovepro / ultra / plus / prime / official 等）仍需店主在收单方的轮询系统里登记域名，否则收单方会直接拒绝创建（这是另一个独立问题，今天 07:46 阳站 11858 的"创建失败"就是它）。
- 不要在总后台服务器 `/etc/hosts` 里把任何店铺域名指到本机。
