# JSON 支付通知接收修复

用户报告自己下单后卡侧已占用/扣款，网关后台成功，但商城仍未更新。本轮暂停尚未发布的后台连续导航改造；该改造的本地文件保留，不混入此支付发布。

## 已核实的根因

订单 11737 于 2026-11-03 17:54 创建，店铺和 HQ 收银台会话均为 created、正式模式。只读打开已登录网关订单表确认对应最新记录为 17:55:29、USD80、Successed；原始入口日志同秒与稍后外部 POST 400，但旧日志未含 REST 路由，起初未把该相关性冒充根因。

临时、精确目标通知元数据观察器在 18:14 上线。18:15:02 自然到达的真实 `/pc-panapay/v1/hub/notify` POST 确认：Content-Type 为 JSON，表单四字段为空；JSON 的 order_number/status/transaction_id/sign 全部为字符串，状态 SUCCEEDED；对应目标 11737、正式模式、冻结密钥验签为 true，当前响应却为 400 notification_not_accepted。HQ 持久通知事件仍为 0。证据已明确将失败定位为 JSON 内容未被读取，非定时回写单独延迟；HQ 原生定时任务已正常运行。

`Hub::notify()` 原来只调用 `get_body_params()`；最小修复按照 WordPress 的 `is_json_content_type()` 二选一调用 JSON 或表单读取器，紧接数组校验。不会把 query/form/JSON 混在一起，也不在 JSON 失败时回退表单。原 8192 字节上限、具名字符串/状态/交易号限制、冻结账号密钥验签、持久事件存储、唯一交易保护与纯文本 ok ACK 保持不变。不会调用支付创建、伪造通知、手动把订单改 paid 或绕过验签。

## 验证与限制

新增 `tests/hub-notification-content-type.php` 直接调用真实 Hub、Signature 与本机官方 WordPress REST Request/Response；数据库和 Crypto 外围为内存边界，无真实 HTTP/SQL。覆盖两种合法格式、声明格式不混用、畸形/空/标量/列表 JSON、字段类型/长度、无效签名/状态/交易号、正式环境测试模式、8192 边界与持久失败不返回成功；最终报告在 `D:/Codex/Tasks/2026-11-03/payment-notification-triage/outputs`。

该阶段生产发布仅选择 `peptide-panapay/includes/class-hub.php` 一个已提交文件，从实际 current 复制并校验旧源 SHA/共享配置，再原子换链并保留临时观察器和并行 CDN/前台代码。精确上线和真实通知/回写验收后续追加。

另一个未解决观察：11737 当前订单与建单时冻结 fingerprint 不同，但金额/币种相同。后续即使成功通知入库，原有订单完整性校验仍可能转人工核对。当前尚不能将其归因为用户改地址或程序缺陷；只读比对正在进行，不能擅自重写冻结值或放宽规则。

观察器只记目标订单的字段形态、枚举、验签布尔与结果，不记录原引用/流水/签名/密钥/地址或卡资料；未知引用不查支付数据库。Root 私有 0710 目录/0660 单链接日志，flock、32 条/32KiB 和一小时 TTL，恢复结果后应移除这一个观察器。源码/收据/模型及后续清理记录在上述任务目录，非客户数据 Git 制品。
