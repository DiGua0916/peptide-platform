# 后台北京时间统一 · 2026-11-07 · Codex

用户要求访客与数据中心统一中国时间。根因：正式 s0001 店铺 timezone_string=America/Los_Angeles、gmt_offset=-8；维度卡片直接对 anchor_ms 调 toISOString()，强制 UTC。访客列表使用 cfg.timezone 的 Intl，日期范围 Range 使用 wp_timezone()。

已将正式店铺 timezone_string 配置为 Asia/Shanghai、gmt_offset=8；客户站随后读回已相同，未迁移/重写任何 Unix 时间戳、订单或历史事件。仅替换 live dimensions 的日期显示表达式，采用响应携带的 timezone，history 的接受范围/真实滚动窗口不变。勿通过加减 8 小时改存储值或 anchor。后续部署保留这两个站点选项。

精确部署：只改各 current 中 assets/data-realtime-dimensions.js 的一个表达式，不覆盖其他协作者 release 内容。admin 备份 /var/lib/vialcove-timezone-backups/1794058309；web 备份 /var/lib/vialcove-timezone-backups/1794058315，含原 JS 和 options.json。web 配置写入前已读到 Asia/Shanghai，因此恢复原洛杉矶选项须参考 admin 备份。未修改 HQ、其他租户与沙盒配置。

验证：node --check；新增 realtime-timezone-label.cjs 通过 UTC→北京时间、跨北京时间零点及 history 标签；已有 data-realtime-dimensions.cjs 154 项通过。正式 WP Range today 返回 [1793980800,1794067200)，即 2026-11-07 00:00 至 11-08 00:00 +08:00。浏览器实际实时页显示当前店铺时区 Asia/Shanghai(UTC+08:00)，主面板与下方 9 面板均 21:33:17，无原始 Z 标签；访客列表显示 Asia/Shanghai，Visitor 869 原 11-06 13:34:08 变为 11-07 05:34:08。原始毫秒未动。窗口/计数定义不同仍不能直接相加，近期空商品事件不归因于时区。

证据：D:/Codex/Tasks/2026-11-07/china-time/outputs。只验当前正式商家数据/访客页与查询边界，不声称所有业务统计差异都已消除。
