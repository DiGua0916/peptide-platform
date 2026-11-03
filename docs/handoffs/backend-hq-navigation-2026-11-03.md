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
