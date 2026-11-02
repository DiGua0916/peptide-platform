# 固定店铺管理域与顾客域：源码候选

2026-11-03 · Codex · MANAGEMENT-HOST-01

本分支从 `b447d85e0024932348a562fc3f51f75493989c98` 开始；仅实现每店固定管理地址与可更换顾客地址，不触碰其他窗口的 activity/recent/theme 工作，不操作原站、服务器、数据库、DNS、邮件或支付。是否合入和上线由根代理完成组合验收后决定。

## 配置契约

- 显式双域模式需要 `PEPTIDE_CORE_MANAGEMENT_URL`（HTTPS 根 origin，无凭据、子路径、查询、fragment、非 443 端口）和 `PEPTIDE_CORE_MANAGEMENT_UUID`（完整小写 UUID）。UUID 必须与 `peptide_core_hq.uuid` 一致。只定义一个常量或错误身份会拒绝 HTTP 请求，不能静默回退。
- `WP_SITEURL` / DB `siteurl` 固定为管理地址；不得定义 `WP_HOME`，DB `home` 保存可更换顾客主域。初始两地址可以相同。管理地址由平台受控配置输入，core 不根据任意 Host 猜测店 ID。
- `COOKIE_DOMAIN` 必须为空或 false；`COOKIEPATH`、`SITECOOKIEPATH` 为 `/`，`ADMIN_COOKIE_PATH` 为 `/wp-admin`。WP 提前初始化 cookie 常量，错误旧 siteurl 或子路径配置会失败关闭，避免登录循环或父域共享登录。
- 无上述常量的旧单域实例继续原行为。显式模式只支持 HTTPS 根站点；本补丁不改变 Multisite 或子目录部署。

## 已实现

- 按实际 Host 校验店铺；不采信 forwarded Host。固定管理 Host、存储的顾客主域和 Domains 登记别名外的 Host 拒绝。别名的 Nginx 精确路由、DNS 和 TLS 仍须平台部署核验，core 不自动管理它们。
- 后台、登录、Staff_Entry、HQ 登录票据和机器 REST 固定管理地址。HQ 票据绑定 UUID 与管理 origin。public Host 禁止后台/人员登录、人员会话重放、HQ/Ops/支付事件机器接口；REST 真正 dispatch 和 batch 子请求也校验，大小写遵循 WordPress 的不区分大小写路由行为。
- 管理页面的 REST nonce、核心脚本、媒体与编辑预览同源；顾客 home、账号、找回密码、退出、前台资源及 Woo 邮件图片保持顾客域。uploads 只改 URL 字段，不改磁盘路径。不会给任意父域加 cookie 或扩大 HQ/销售入口的可信 origin。
- Domains 切换 primary 仅更新 `home`，须已连接且 HTTPS active；固定管理 `siteurl` 不变，管理域不能设置前台重定向。管理 Host 普通前台访问跳到顾客域；有权限的模板、页面、文章、退出弹窗和原生 Customizer 预览留在管理 origin。退出预览仅放行现有三个 staff 抑制计数/发码的 AJAX，不开放管理域购物/结账。
- 顾客 AJAX、订阅/评论表单、Store API 和业务权限仍由现有回调处理；本补丁不宣称已审计或重构所有模块权限。

## 实际验证和限制

独立评审发现并修复了 REST cookie 认证返回 true、batch 子路由、大小写路由、WP_CONTENT_URL 提前初始化、旧 cookie 路径和既有预览/顾客账号链接的回归。纯 PHP 夹具覆盖这些边界；另只读加载本机 WordPress 7.1.4 的原生 link/cookie/post/media helper 检查真实 URL 和媒体算法，无 WordPress bootstrap、DB、网络或原站写入。

运行入口为 `wp-content/plugins/peptide-core/tests/management-host-policy.php`，模式：`managed`、`legacy`、`cli`、`bad-cookie`、`bad-home`、`bad-path`、`wrong-uuid`、`wrong-siteurl`。可追加 WordPress 源码根目录启用原生 helper 检查。最终实际通过：managed 96、native helper 102、legacy 3、CLI 79、五个失效配置各 1 项；组合 HQ 41、Ops 26 + 9、Panapay 33。33 个变更 PHP 文件逐个 lint 及整个 base diff 的空白检查通过。

HQ/Ops/Panapay 已组合到本分支：HQ 0.3.2 / schema 4、Ops 0.2.0、Panapay 0.4.2。显式 URL 校验统一为多段 DNS hostname，拒绝 IP/localhost，旧单域 fallback 不变。HQ schema/helper 必须先于新版 Panapay 启用；未做实际 schema 升级。HQ 对历史重复 UUID 的注册拒绝，未清洗数据或证明全部历史运维查询不存在歧义。

支付补充：provider site 和浏览器 return 只用登记 public primary，机器事件只用管理地址。新增尝试在签名前把站点和原生 Woo callback origin 归一到 public primary，保留路径、引用和令牌；不改变旧冻结尝试。HQ 返回时再次核对当前登记 public 与精确 callback，换主域后的旧返回拒绝跳转，异步支付回写不依赖该跳转。主域切换后要确认 HQ 已接收新 public 地址，再开放新付款。

不同顾客 alias 的 host-only 账号/购物车 cookie 不可互读。alias 付款返回 primary 后，会员订单页可能要求重新登录，游客可能需邮箱校验，alias 购物车也不会自动在 primary 清空；不通过共享 cookie 或关闭 Woo 原生校验绕过。实际发布应选择单一 public 主域并安排其他旧域跳转，或在接收 alias 结账前完成游客/会员两条独立验收。

尚未完成的上线门槛：两个真实 Host 的 Nginx/TLS、浏览器 WP 登录和 host-only cookies、REST nonce、媒体与全部预览（含 Customizer selective refresh 的纯 POST）、顾客登录/退出/忘密、public primary 切换后 HQ 管理连接、支付异步回写及浏览器回跳、真实受控邮件收件、不同店账号/订单/导出隔离。源码夹具通过不能替代这些实测；未声称全功能或生产跨域验收通过。

本分支不 push、不直接合并 main，不纳入私有 profile、主机 IP、指纹、凭据、运行配置或真实客户资料。HQ schema 改动在实际迁移前需要备份；本任务没有执行迁移。
