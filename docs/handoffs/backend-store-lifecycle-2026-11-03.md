# 总后台店铺回收与操作弹窗

用户确认：移除应完整停止店铺，而非只删总后台登记。店铺运行数据保留 72 小时；已有加密备份和支付核对记录继续按原规则保留。本次不移除任何现有店铺，不改 DNS/CDN、收款配置或前端窗口的代码。

## 操作与数据契约

- Sites 列表的“更多”打开独立原生 dialog，详情不再撑高表格行。服务器分配为名称/IP 双行按钮与独立单选弹窗，点击“保存分配”才提交原 `pchq_site_server` POST；取消不变更分配。
- 完整移除使用 `pchq_site_retire` POST、管理权限和 nonce；旧 `pchq_site_delete` 无法硬删注册。根进程确认的回执驱动 `active → offlining → retained → restoring/active` 或 `purging → purged`。不因 PHP 接受请求就声称两台服务器已下线。
- HQ 队列只含版本、操作 ID、操作者 ID、站点 ID/UUID、固定管理/顾客 URL、action、retirement ID；客户端不能提供命令、资源路径、SQL 或期限。根回执只有 state/site_id/uuid/retirement_id/retired_at/purge_at/error_code/message。错误码为小写 ASCII 数字下划线，最多 80 字符。
- 回收站支持截止时间内恢复原身份，失败任务可复用原操作重试，不能延长原期限。截止后不再开放恢复。到期清理由现有根 worker 定时处理，无须管理员开页；归属漂移或非暂态错误暂停并显示需要处理。
- 停用立即阻断 HQ 登录、运维、监控、新 relay 与旧开店任务重放。签名验证后的历史支付通知继续记账；过期已清理店铺的未完成转发进入人工核对。不会删除共享商户或历史财务账本。
- 清理保留最小身份墓碑、域名认领和关闭路由，防止旧请求重建或域名落到别的店铺。已有备份与冻结支付记录不是 72 小时清理对象；这不是“所有副本 72 小时内抹除”的承诺。

## 服务器边界

`tools/deployment/provisioning/lifecycle.py` 为与 worker 同目录的 root-owned 模块。精确库存位于根私有配置目录，拥有者、配置 SHA、站点 UUID、专属 DB 授权、路径种类和 unit 均在操作前校验。路径删除不跟随软链、不跨挂载点，重试需匹配原 inode/device 日志。

旧首店有专门核对的库存；其数据库隧道同时服务其他店铺，不能因名称含 s0001 而停止或删除。新开店在完成前根据根部署记录登记库存。缺少归属库存时拒绝移除，不猜测；既有孤立登记不自动恢复或删除。

新开店的代码与已发布的四种布局及引用图片将从独立根私有模板读取，避免首店移除后破坏开店源。模板不克隆用户、商品、订单数据库，属于版本快照；后续母版更新需要明确刷新快照。加密运行备份包含模板及生命周期记录，已清理 DB 不再参与日常 dump。

两个主机通过现有强制命令 SSH 通道接收严格 JSON phase；不改 wrapper、sudoers、凭据范围。锁序遵守 worker/create → 对应发布锁 → 备份锁，避免被取消的阻塞操作事后执行。

恢复采用双方准备、后台先开、顾客入口最后开的顺序。网络分区无法保证跨主机物理原子切换；SSH 回包丢失时尝试核对和重新关闭，HQ 未获双方确认不标记 active，必须由运维核对实际状态。

## 本轮实际交付

- 源码部署基准为 c20b173ba1b5；HQ 完整 current 为 /srv/vialcove/hq/releases/site-lifecycle-complete-c20b173ba1b5。14 个 HQ/Panapay 文件和两机各 5 个 broker 文件最终逐项 SHA 匹配；两个应用 shared config 字节不变。期间同事将顾客端/商家管理端更新为 storefront-motion-04c1bcedcb17 两角色版本，本轮保留这些新版本，没有回退。
- Linux 原生隔离模型：worker 36、lifecycle 29、backup 8，通过；与 Windows 使用相同测试。HQ 模型 62、实际支付/Menu/retry 处理器模型 66、UI DOM/renderer 45，通过。初次原生模型发现测试夹具依赖不存在的 test 账号，已改为显式模拟 UID/GID 后全组通过，没有创建系统测试账号。
- 已从首店脱离独立版本模板：管理端 11,045、顾客端 11,044 个代码文件，四种已发布布局和四张引用图片；禁止的环境/SQL/密钥/日志等源文件扫描为零，不复制客户数据库。私有 profile 保留原版本 checkpoint，原生加密备份计划验证包含新模板及生命周期记录。
- 旧首店精确库存已在双方登记，原生归属/独占 SQL 授权检查通过。三份关闭候选 vhost 在隔离 include 图中通过 nginx -t，没有加载这些关闭候选。实际通过既有强制命令 SSH 通道执行双方只读 preflight，没有排入 retire 请求或写入任何 current 生命周期记录。
- Web 的旧 /var/lib/vialcove-deploy 实际是 nologin 部署账号 HOME，不能当 root 私有锁锚。已使用根私有 ledger/locks 作为 canonical 锚；原 root 0600 锁仅作老发布工具的过渡协调，并检查 inode，不能当安全边界。未改变 HOME 所有权、wrapper、sudoers 或访问范围。未来发布工具应统一 canonical 锚后移除旧锁兼容层。
- 首次 HQ 发布清单误用了已提交后为空的工作区 diff，漏选 UI 和部分既有类；浏览器读回发现旧控件后，改为已提交范围的明确 14 文件清单并补齐发布。hq-runtime-first-incomplete.json 保留这次失误，最终 hq-runtime-deploy.json/final-check 两角色报告为完整结果，不能把首次三文件收据当完成证明。
- 真实 IAB 已加载新的 filemtime 资源版本，两个独立 dialog 均可打开；More 位于 table/scroll 外且 matches(:modal)，行高打开前后均 88.667px。取消服务器弹窗没有更改原分配；Escape 关闭 More 后 body lock 清除、焦点回到 More。真实截图为 server-picker-live.png 和 site-tools-live.png。没有提交服务器分配/删除/恢复表单；窄屏规则已模型检查，本轮不宣称生产手机宽度实测。
- 原有 worker timer active、最近 tick success；HQ/商家登录与顾客首页实际 HTTPS 200。回收站当前 0 条，现有正常登记 1 条；没有借 UI 验收删除生产店铺，也没有对历史孤立店铺重新登记或重启其 timer。

自动清理/恢复的破坏性两机演练、真实 72 小时等待及备份恢复仍未执行；模型与只读生产 preflight 不能替代这些演练。暂态网络/备份锁会重试；资源归属变化和失败恢复需要管理员处理，不承诺故障时无条件准点抹除。

## 验证与发布范围

本地实际类/处理器模型覆盖权限、POST/nonce、状态 CAS、根回执、重复任务、失败重试、期限、支付保留及旧入口拒绝；Python 模型禁止未 mock 的外部命令。另有真实服务器只读归属检查、隔离 Nginx 候选语法检查和真实浏览器 dialog 检查。模型不等于生产两机下线、恢复或真实到期删除演练，本轮不对生产店铺做破坏性验收。

部署从各机器实际 current 复制，叠加所列 HQ/Panapay 文件及根 broker；不整仓替换，不覆盖并行支付或顾客端发布。回滚 checkpoint、源 SHA、模型结果及实际界面证据在 `D:/Codex/Tasks/2026-11-03/site-lifecycle/outputs/`，最终部署收据以该目录实际文件为准。加密备份仅复制密文并核对 SHA，未解密或恢复演练。
