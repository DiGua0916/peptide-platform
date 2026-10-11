# 本地 A/B 分流演示

用户希望自己体验不同访问请求进入不同页面，并追加了电脑显示 A、手机显示 B 的设备规则。该原型支持设备分流和显式模拟参数，不识别真实审核人员。

## 打开

双击同目录的“打开分流演示.cmd”，或执行：

    python "D:\Codex\Projects\peptide-platform\tools\ab-demo\server.py" open

地址：http://127.0.0.1:17836/ 。关闭网页后服务继续在本机运行；电脑重启后再次打开启动文件。没有外部服务或模型调用。

## 三种模式

- 自动设备分流：统一入口 http://127.0.0.1:17836/entry，服务端读取浏览器的 Sec-CH-UA-Mobile 提示，缺失或无效时参考 User-Agent。电脑返回 A 页面，手机返回 B 页面；平板与未知设备默认 A。控制台默认选中此模式。
- 模拟身份：选择“模拟审核访客”进入 A（品牌介绍）；选择“模拟普通访客”进入 B（产品目录）。身份规则始终显示在控制面板。
- 随机测试：设定 A 版本占比，服务端随机分组。浏览器 Cookie 保持 24 小时，同一访客继续看到同一版。“这次模拟一个新访客”会重新分组，结果仍可能与上次相同。
- 比例修改只影响新访客；身份模拟不改写随机分组 Cookie。
- 控制台点击“访问一次”会写一条记录；自动入口 /entry 每次请求也写一条。设备检测和 iframe 预览不额外计数；手动页面预览、刷新统计也不新增记录。

## 设备判断的边界

这是展示分流，不是身份认证或防绕过的访问控制。浏览器可能修改标识，手机“桌面版网站”也可能显示 A；电脑缩小窗口不会自动变成手机。平板如果明确发送移动体验提示 ?1，则按提示显示 B，否则默认 A。

自动入口 /entry 根据当前请求直接返回对应 HTML，并设置 Vary 和 no-store。控制台及 /pages/ 的手动预览仍可查看两版，方便本机调试；它们不是受保护资源。

当前只监听 127.0.0.1；手机自己的 127.0.0.1 指向手机，不能直接连接这台电脑。本次未开放局域网监听、修改防火墙或部署网站，实际手机联机验证需另行配置局域网入口或部署地址。

实现参考：[MDN Sec-CH-UA-Mobile](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-CH-UA-Mobile) 和 [User-Agent 判断限制](https://developer.mozilla.org/en-US/docs/Web/HTTP/Browser_detection_using_the_user_agent)。

两套页面使用虚构品牌和演示条目，没有真实商品、库存、支付或检测结论。页面预览通过真实本地 HTTP 读取，分流判断在 Python 服务端进行，日志写入 SQLite。

## 数据与范围

服务仅绑定 127.0.0.1；日志在运行源码同目录的 .runtime/visits.sqlite3。目录位于 D 盘并被 Git 忽略。统计为模拟请求次数，不代表独立用户或转化率。

这是独立于 WordPress 的本地交互原型，不是已安装的 WordPress 插件。它没有 IP/浏览器指纹识别、审核人员识别、真实广告投放接入或外部跳转规则；设备判断只参考请求自带的移动提示和 User-Agent，不保存原始 User-Agent。

## 验证

    python "D:\Codex\Projects\peptide-platform\tools\ab-demo\test_demo.py"
    node --check "D:\Codex\Projects\peptide-platform\tools\ab-demo\public\app.js"

测试数据库保存在 D 盘任务临时目录，与正式演示数据隔离。覆盖身份路由、分组保持、新访客、参数校验、HTTP Cookie、历史保留、并发记录和本机访问约束。
