# 本地 A/B 分流演示：双端口与可配置网址

保留状态（2026-10-11 最新决定）：用户要求保留本演示的代码和记录，暂不继续扩展；项目主线回到 WordPress 前端，由 Codex 继续负责。以下是已有版本的功能与使用说明，不代表正在推进主站集成。

用户希望自己体验不同访问请求进入不同页面，并追加了电脑显示 A、手机显示 B 的设备规则。该原型支持设备分流和显式模拟参数，不识别真实审核人员。

## 打开

双击同目录的“打开分流演示.cmd”，或执行：

    python "D:\Codex\Projects\peptide-platform\tools\ab-demo\server.py" open

默认同时启动两个端口：

| 地址 | 用途 |
| --- | --- |
| http://127.0.0.1:17837/ | 配置后台：填写 A / B 网址、保存、预览和查看记录 |
| http://127.0.0.1:17839/ | 统一访问入口：按设备显示 A 或 B |

在后台填写完整的 `http://` 或 `https://` 网址，点击“保存网址”。本地例子为 `http://localhost:9000/`，外部例子为你的网站 HTTPS 地址。留空使用原来的内置演示。可以先按“预览 A / B”检查，再打开统一入口。预览按钮不会写访问记录。

关闭网页后服务继续运行；电脑重启后再次运行启动文件。前台运行两个端口可用 `python server.py serve --port 17837 --entry-port 17839`。两个端口须不同且均未被其他服务占用。

配置后台的 API 不在入口端口开放；仍保留后台旧 `/entry` 路径用于兼容。健康检查 `/api/health` 返回 `version=4` 和端口角色。默认只监听本机；显式启用局域网时只开放访问端口，不调用模型。

## 手机局域网测试

用户已要求对 17839 开放局域网。本机本次检查为以太网 `192.168.0.104/24`，启动参数如下：

    python server.py serve --port 17837 --entry-port 17839 --lan-address 192.168.0.104 --lan-prefix 24

手机连接同一局域网 Wi-Fi，打开 http://192.168.0.104:17839/ 。后台显示此地址；后台 17837 仍绑定 127.0.0.1。

入口绑定 0.0.0.0，但应用只接受本机与指定子网的来源地址和明确允许的 Host。Windows 防火墙需要单独允许该 Python 程序的 TCP 17839，仅本地地址 192.168.0.104、来源 192.168.0.0/24。本次不关闭防火墙、不更改网络类别、不做路由器端口转发。

如果路由器开启访客隔离，或手机使用蜂窝流量 / 其他网络，手机仍可能连接不上。IP 改变后需更新启动参数和对应防火墙规则。本机 HTTP 验证不能代替手机实机联机验收。

## 原理：不是自动跳转，也不是反向代理

访问入口 → Python 读取设备信息 → 选择 A / B → 返回 HTTP 200 页面。

- 如果目标留空，服务直接返回内置 A / B 的 HTML。
- 如果目标填了网址，服务返回包含 iframe 的页面，浏览器在这个框架内加载目标网址。顶层地址栏仍是本地入口，没有 301 / 302、Location 响应头或顶层 JavaScript 跳转。
- 目标页自己的链接和重定向可以在框架内部继续发生；手动点击“打开原网页”会另开原地址。这个按钮是显式操作，不是失败后的自动跳转。
- Python 不抓取、复制或代理目标网站。目标资源由浏览器向原站请求，登录、表单、支付、存储与 Cookie 受原站及浏览器限制；未保证这些流程都能在框架内使用。
- 目标若设置 `X-Frame-Options` 或 CSP `frame-ancestors` 禁止嵌入，浏览器会拒绝展示。本工具不会移除这些保护；入口会一直保留“打开原网页”链接。由于跨域限制，不能把 iframe 的 load 事件当成“网站加载成功”。

因此本版本接受本地与外部 HTTP/HTTPS 地址，但不能承诺互联网上任何网站都能完整嵌入。`file://`、脚本地址、包含账号密码的 URL、本工具自己的后台或入口地址会被拒绝；后者用于避免直接循环嵌套。本地文件先通过自己的 HTTP 服务提供。

参考：[MDN HTTP 跳转](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Redirections)、[X-Frame-Options](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Frame-Options)、[frame-ancestors](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors)。

## 代码与数据在哪里

正式源码：`D:\Codex\Projects\peptide-platform\tools\ab-demo\`。

- `server.py`：两个端口、设备判断 `device_route()`、网址校验与保存、`Handler.page()` 页面响应和访问记录。
- `public/index.html`、`app.js`、`style.css`：后台表单、保存、预览、统计和样式。
- `public/embedded.html`、`embedded.css`：入口嵌入页面及手动打开原网页的链接。
- `.runtime/visits.sqlite3`：网址配置和访问记录；在 D 盘，不纳入 Git。重启不清空，升级保留旧记录。

## 三种模式

- 自动设备分流：统一入口 http://127.0.0.1:17839/，服务端读取浏览器的 Sec-CH-UA-Mobile 提示，缺失或无效时参考 User-Agent。电脑选择 A，手机选择 B；平板与未知设备默认 A。控制台默认选中此模式。
- 模拟身份：选择“模拟审核访客”进入 A（品牌介绍）；选择“模拟普通访客”进入 B（产品目录）。身份规则始终显示在控制面板。
- 随机测试：设定 A 版本占比，服务端随机分组。浏览器 Cookie 保持 24 小时，同一访客继续看到同一版。“这次模拟一个新访客”会重新分组，结果仍可能与上次相同。
- 比例修改只影响新访客；身份模拟不改写随机分组 Cookie。
- 控制台点击“访问一次”会写一条记录；访问统一入口每次请求也写一条。设备检测和 iframe 预览不额外计数；手动页面预览、刷新统计、保存配置不新增记录。记录表示分流发生，不表示远端网站已加载成功。
- 手动身份和随机测试仅影响控制台实验。访问端口根路径始终按设备规则选择。

## 设备判断的边界

这是展示分流，不是身份认证或防绕过的访问控制。浏览器可能修改标识，手机“桌面版网站”也可能显示 A；电脑缩小窗口不会自动变成手机。平板如果明确发送移动体验提示 ?1，则按提示显示 B，否则默认 A。

自动入口根据当前请求返回内置 HTML 或 iframe 包装页，并设置 Vary 和 no-store。控制台、/preview/ 和 /pages/ 的手动预览仍可查看两版，方便本机调试；它们不是受保护资源。

手机自己的 127.0.0.1 指向手机，不能直接连接这台电脑。启用局域网后请使用上述电脑 LAN 地址。本次没有部署公网网站。

填写的 localhost / 127.0.0.1 目标由访问者的浏览器解释，也指向访问者本机。如果以后开放手机访问，目标也须使用手机能够访问到的地址。

实现参考：[MDN Sec-CH-UA-Mobile](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-CH-UA-Mobile) 和 [User-Agent 判断限制](https://developer.mozilla.org/en-US/docs/Web/HTTP/Browser_detection_using_the_user_agent)。

两套页面使用虚构品牌和演示条目，没有真实商品、库存、支付或检测结论。页面预览通过真实本地 HTTP 读取，分流判断在 Python 服务端进行，日志写入 SQLite。

## 数据与范围

后台始终仅绑定 127.0.0.1，入口可按明确参数开放指定局域网；日志在运行源码同目录的 .runtime/visits.sqlite3。目录位于 D 盘并被 Git 忽略。统计为模拟请求次数，不代表独立用户或转化率。

这是独立于 WordPress 的本地交互原型，不是已安装的 WordPress 插件。它没有 IP/浏览器指纹识别、审核人员识别或真实广告投放接入；设备判断只参考请求自带的移动提示和 User-Agent，不保存原始 User-Agent。填写外部网址后，浏览器会按正常访问向该网站发送网络请求。

## 验证

    python "D:\Codex\Projects\peptide-platform\tools\ab-demo\test_demo.py"
    node --check "D:\Codex\Projects\peptide-platform\tools\ab-demo\public\app.js"

测试数据库保存在 D 盘任务临时目录，与正式演示数据隔离。20 项测试覆盖身份和设备路由、分组保持、参数校验、历史与配置持久化、并发记录、双端口 API 隔离、URL 校验、iframe 转义与无自动重定向，以及局域网 Host / 来源子网边界。浏览器验证需另外检查目标网站实际是否允许嵌入。
