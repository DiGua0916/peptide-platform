# 总后台常用页面局部导航

用户反馈：每点菜单都重新转圈、短暂整页白屏。原生完整页面基线在真实内置浏览器销售账号页测得 load 1.861 秒、TTFB 0.668 秒、13 个 JS/CSS 资源；单次样本不是 p95。源站没有 CPU/内存资源吃紧证据。CDN 工作由另一窗口独立处理，本轮不改 DNS/Nginx/CDN 配置。

本轮针对概览、店铺、服务器、销售账号、告警、回收站六种原生 HQ 页面保留外框、按用户明确点击请求原生 HTML，只在最新响应通过校验后更新内容。首次进入、保存/删除/登录操作、原生 WP 页面、付款、运维和自动开店仍走原生完整导航；复杂页依赖专用脚本/轮询，未宣称全部后台变为 SPA。销售账号的 staff_list 仍是实时远端请求，最长 20 秒上限不在本轮修改，局部导航不等于消除这个服务器往返。

## 实现与边界

- Menu 仅在管理权限、非 AJAX/iframe、精确六路由上加载 navigation.js 并输出构建、会话和页面元标识。原 WordPress 权限、nonce、POST 处理器与 HMAC 店铺接口不变，没有新 REST/AJAX 端点。
- 链接只允许同 origin、同 admin.php、白名单 query，拒重复/数组/未知参数、nonce/action/token、外链/SSO、修饰键、下载、非本窗口目标。对原生表单不 preventDefault、不序列化或重放；实际合法原生 POST 只在本次 beforeunload 中放行，取消/校验失败仍保护未保存内容。
- 不 hover 预取，不自动重试，不缓存或持久化后台 HTML、表单值、密码、nonce。请求 same-origin credentials、no-store；历史状态仅 URL/滚动位置/索引。后台 GET 会消费提示和恢复草稿等状态，因此不能投机预取。
- 返回必须为成功 HTML、精确最终 URL、相同会话/构建/政策、合法页面 screen 标识和原生内容结构。新资源或脚本/iframe 等未知初始化需求转原生导航；认证失效转原生请求的 URL，不把登录 HTML 注入后台。没有 eval 或执行 fetched inline scripts。
- 快速点击取消前请求且以序号拒绝迟到响应；收到新内容前保留当前内容与外框。请求失败保留原表单并释放 busy/inert，提供用户主动重新打开。脏表单在开始和提交前检查，密码仅参与内存 dirty 检查，不进入日志、历史或浏览器持久化存储。
- before-swap 清理旧 dialog/监听/observer/动画，after-swap 初始化新模块；同步标题、面包屑、菜单、焦点及后退滚动。WordPress notice 和帮助面板显式重绑。历史写入失败使用已批准 URL 原生回退，避免地址与内容错位。
- 视觉保持既有黑白紧凑规范。加载只显示真实 indeterminate 状态，不显示假百分比，不在等候时把旧界面变白；遵守 reduced motion。

## 验证与发布记录

模型使用真实生产 JS 和 PHP 类，覆盖 URL/权限/构建/会话、并发/取消/错误、未保存/实际原生提交、历史、完整回退以及 workspace 多次初始化。模型不称真实 HTTP 或真实保存验收。准确计数、源 SHA 在 D:/Codex/Tasks/2026-11-03/hq-navigation/outputs/verification.json 和 workspace-lifecycle-checks.json。

正式发布只叠加 HQ 的 hq-navigation.js、hq-workspace.js、hq-workspace.css、class-menu.php 四个文件，从实际 current 复制并做基线 SHA/CAS 校验；保留另窗口的收款/前端/CDN 改动。更新前加密 HQ 备份复制 D 盘密文并校验，不解密、不恢复或变更客户数据。部署/实际浏览器/限制以该任务目录最终收据为准。

### 2026-11-03 正式验收

- 最终生产源提交 `5161ffe6675a7c947189ff8eced3ce331f4fd541`，HQ current 为 `hq-navigation-5161ffe6675a`。独立只读核验四文件 SHA 与共享配置均匹配，HQ/客户后台登录 HTTPS 200。顾客与客户后台的 `storefront-motion-04c1bcedcb17-*` 切换早于本次 HQ 发布，未被本发布修改；CDN/DNS/Nginx 由并行窗口处理，不以本轮结果宣称已完成 CDN。
- 首次上线实机发现中文父菜单编码导致元标识与 WordPress body class 不同，已按原生 `admin-header.php` 的 hook_suffix 规范修复。初始/目标 body 无匹配标识时安全回原生导航；修正后真实中文后台 `screenMatches=true`，不是仅模型通过。
- 生产 JS 85 项、实际 Menu 类隔离模型 41 项、workspace 清理/重绑模型 31 项全部通过。源 SHA、独立测试边界和检查名保存在 `outputs/verification.json` 与 `workspace-lifecycle-checks.json`；模型不冒充真实保存、交易或删除测试。
- 同一个已登录内置浏览器完成销售账号 → 店铺 → 后退销售账号 → 概览 → 服务器 → 告警 → 店铺回收站，并再次回到店铺。初始与末次 `performance.timeOrigin` 都为 `1793697342910.3`，navigation entry 始终 1，JS/CSS entries 始终 18：这组实际点击没有重建文档或重新加载静态资源。店铺“更多”能在局部切换后打开，Escape 关闭后焦点恢复到“更多”、body overflow 释放，无业务表单提交。
- 实际 HTML fetch：店铺 570.7ms、后退销售账号 1176.3ms、概览 522.8ms、服务器 527.7ms、告警 551.3ms、回收站 527.9ms。只是这次会话的单次资源请求耗时，不是端到端 p95 或首次加载加速率。修复后首次完整 reload 为 2147.8ms、TTFB 802.4ms，不能与原 1861.4ms 基线直接宣称整体提速。
- 检查覆盖用户窄窗口与临时桌面视口；测试结束已清除设备尺寸覆盖，页面停留店铺列表。截图 `outputs/navigation-live.png`，原始性能证据 `browser-fixed-enabled.json`、`browser-sites-navigation.json`、`browser-back-navigation.json`、`browser-six-routes.json`。会话/nonce/客户原始数据未写入证据或 Git。

继续边界：这轮仅 HQ 六类常用页面局部导航。客户后台、支付中心、运维、自动开店、原生 WordPress 页面与原生保存动作保留完整导航；销售账号的远端实时 staff_list 仍可能等待最多 20 秒。未声称全部后台无等待、首屏不加载或 CDN 消除了动态请求成本。
