# 手机店面稳定性修复交接

2026-11-05 · 作者 Codex · 主题分支 `codex/frontend`

## 交付范围与集成顺序

源协调窗口「独立站后端 站点」负责整合、GitHub 和部署。本窗口只修改主题与角色交接，本轮未自行 push、发布服务器、修改 Core/支付插件、业务记录或装修数据库。原有 UI、品牌、手机优先方向保持。本地运行目录继续是现有 LocalWP 主题 junction。

已先正常合并最新 `origin/main`，本地合并点 `94f11c1`；之后按顺序接收：

1. `3a9a06f`：规格状态隔离、盒装展示、最新报告首屏、画廊自动播放。
2. `ebc9bf3`：活动条、取消/恢复、原生装修快照请求内复用。
3. `eb450f3`：确切公开报告集合免带过期 REST nonce。
4. `3c08fb7`：共用 COA 阅读器、历史恢复、按视口绘制和生命周期。
5. `75ffbae`：静态 Inter 字体、触摸关闭外观、固定 2025 页脚。
6. `e4d6366`：预加载改为 CSS 真正使用的静态 regular 字体。

这些提交彼此有依赖，建议正常合并角色分支或按序接收。不要只复制 COA JS，遗漏 helper、样式、worker bootstrap 或字体文件。没有新增后台设置/schema/接口，原生积木文档和手机覆盖不需要迁移；发布仍须保留原文档配置，不能重建成默认值。

## 模块与确定根因

| 模块 | 已实现内容与边界 | 主要文件（相对主题） |
| --- | --- | --- |
| 商品规格/优惠 | TR20→TR15 立即废弃前一规格接受态；校验全部实际选中属性，旧异步结果不能回写。原先 summary 广域查询会读到残留 single_variation 的折扣节点，现只取接受的主价节点。价格/库存仍来自原生 Woo，不从父商品 on_sale 推算当前规格。 | `assets/home-quick-view.js`, `product-dialog-experience.js` |
| 盒装与底部购买区 | 数量 1 表示一盒；动态显示每瓶剂量及每盒瓶数，主价 `$X / box`。不改剂量、瓶数、价格或数量含义。移除重复 Clear/SKU/Explore 展示，保留表单祖先与 Woo 处理器。 | `product-dialog-meta.js`, `product-purchase-dock.js`, `storefront-motion.css` |
| 商品/COA 画廊 | 6.5 秒自动播放，仅至少两张有效图且进入视口；触摸/手动操作重置等待，离屏/隐藏/嵌套弹层/减少动态停止，销毁清理。未恢复用户删除的移动端箭头、播放或分页工具条。 | `product-dialog-experience.js` |
| 最新报告 | 第一页返回即可展示当前规格最新匹配；已有匹配时不先遍历全部历史，缺规格再继续分页。精确公开 report-library 集合不带过期 nonce；其它受保护/相似地址仍保持认证。 | `product-dialog-experience.js` |
| 取消加载/刷新 | 历史恢复的 loading 状态原先插入布局卡片；主动取消被换成 Loading cancelled，计时器/监听器仍存活。现取消和成功均退休临时 UI；真正读取失败保留紧凑重试，不隐藏结账错误。暖模板复用唯一 loader；新商品/购物车历史优先于旧 URL 商品参数。 | `product-dialog-polish.js`, `product-dialog-polish.css` |
| 活动胶囊 | 固定标题+自动顺序滚动优惠、圆端、透明外层保留。Web Animations 移动轨道替代每帧 scrollLeft；触摸/滚轮/焦点可接管，减少动态恢复手动轨道；尺寸不变不重建克隆。可见手机 header 决定避让，不再误取隐藏桌面 header。 | `campaign-announcement.js`, `campaign-strip-sticky.js`, `campaign-bubble.css` |
| 关闭按钮 | 商品、购物车、COA、菜单和搜索关闭键去装饰背景、边框、圆盘及鼠标/触摸焦点方框；保留真实按钮、44px 命中区域与键盘可见焦点，不改关闭/历史语义。 | `storefront-motion.js`, `storefront-motion.css` |
| 字体 | 保留原 Inter 造型；从许可原件生成完整字形静态 400/500/600/700/800 WOFF2，CSS 和 preload 使用内容哈希 URL，swap；原字体/OFL 保留。 | `assets/brand.css`, `assets/brand/*`, `functions.php`, `tools/build-storefront-fonts.py` |
| COA 阅读 | 默认按容器内容宽度适配、保留纸张比例；ResizeObserver 复绘同一个 PDF。视口附近单并发绘制，离屏释放位图；兼容主线程和 worker 缺少 Promise.withResolvers，vendor 字节未改。首次超时覆盖模块、PDF、首张画布，后续几何/绘制亦有可取消截止。 | `product-dialog-report-viewer.js/.css`, `product-report-pdf-compat.mjs`, `product-report-pdf-worker.mjs` |
| COA 路由 | 商品与原生资料库 View 链接共用阅读器；Back 关一层，Forward 原资源并等待纸张几何恢复滚动；旧关闭事件不能销毁新打开文档。归档公开文件精确免 nonce，保护文件保留；原 PDF/下载 URL 不改。 | `product-report-library-viewer.js`, `live-commerce-navigation.js`, `inc/product-report-viewer.php`, `functions.php` |
| 页脚/Geo | 两个主题页脚的 `{year}` 按用户固定为 2025；保留文字可编辑字段。`inc/visitor-tracking.php` 原 footer99→Country_Provider 唯一署名调用未删、未隐藏。Core owner 负责移除 switcher 重复署名；国家/语言自动识别与数据库常量未动。 | `inc/demo-site-parts.php`, `inc/footer-details.php` |
| 装修读取 | 仅一次公共 GET/HEAD 请求内复用原生 published 原始行，按 blog/template/document 分键，含空结果；最终商品/设备 DTO、价格和库存不缓存。产品专属 false、预览、后台/REST/CLI/写请求旁路及发布失效保留。 | `inc/live-commerce-settings.php` |

## 字体证据与尚未确定的原因

实际生产首页引用的带版本 `brand.css` 和旧 TTF SHA 与已发布源码一致；**未证实字体文件错传或 CDN 缓存导致截图细字**。TTF 的轴/默认字重正常。静态字体是消除连续变量轴选择并减少资源体积的候选修复，不是已确认 iOS 根因。

五档静态文件合计 582,020 bytes，原 TTF 876,576 bytes；常规预加载由旧 876,576 bytes 改成 113,476 bytes 的 regular 文件，其它档按浏览器实际使用加载。没有实测手机下载节省或 LCP 提升。每档 2,933 字形、2,849 cmap codepoints、全部轮廓及 advance/LSB 与原始对应实例逐个一致，重建 SHA 相同；无变量表。原 CSS 中间档会匹配相邻静态档，例如 650→700、750→800，需真实手机查看是否符合用户预期。

## 实际验证与测量分层

- Root 联合运行 9 个产品/活动/恢复 Node 脚本：55 顶层通过，其中一个脚本内部 54 断言不另混算；另运行历史取消 6、旧 URL/新层 2 断言。
- COA+library+motion 联合 Node：28 顶层通过；导航恢复独立 10 检查。包含真实未改 PDF.js worker 模块的兼容导入，但 DOM/canvas 边界仍是隔离对象。
- PHP：页脚 39、请求快照缓存 14、既有设备设置 50、COA enqueue 7 检查通过。改动 PHP lint、JS/MJS 语法、diff 检查通过。
- 真正原 PDF + 未改 PDF.js/worker、隔离 loopback Range 服务：64KiB 时首张 operator 请求 7 次，256/512/1024KiB 均 4 次，选择 512KiB，`disableAutoFetch:true` 配合 `disableStream:true`。只有一张测试 PDF；不是浏览器画布或手机网络时延。100 页视口模型首屏 raster 1 页、模拟滚动后共 2 页；页面几何仍逐页读取，不能声称所有文档元数据也完全按需。
- 活动条 60 秒模型每帧回调/scrollLeft 写入 3600→0；十次同尺寸刷新装饰节点重建 30→0。最新匹配位于第一页的 49 报告模型，读取 3→1、首次展示从第三个响应提前到第一个响应。均为生产 controller 的隔离操作计数，不是手机 FPS/长任务或真实网络。
- **实际 LocalWP 匿名首页 on/off 对照**：每次 Layout::published_modules 355→183、Templates::document_id 450→278，各少 172 次原生查找；SQL 始终 1,918（1,909 SELECT+9 SHOW），没有 SQL 降低或稳定 TTFB 提升结论。Xdebug/SAVEQUERIES 开发 HTTP 样本不等于线上 FPM。干净语义对照仅规范原生 quantity 随机 id/for 成对值和 serverNow 后 HTML hash 相同。过期 Panapay transient 清理的三次 DELETE 尝试被守护拒绝，诊断样本单列且未纳入比较。
- 已安装本地首页/资料库匿名 HTTP 200。18 项更新资产实际字节/SHA 一致；新 mjs 均 application/javascript，新字体有效 wOF2。原生资料库精确 script id 各一份 reader/bridge/history/style，无重复加载。最终 HTML preload 对应新 regular、页脚为 2025。这是资产送达/SSR 检查，不是视觉验收。
- Impeccable 检测器执行一次；仅源文件正则无法跑低对比/真实溢出检查，报告 admin-only DESIGN.md 对现有前台品牌的字体/颜色/字号 advisory。未为了消除提醒改成后台样式，也未将其作为前台通过证据。

证据统一位于 `D:/Codex/Tasks/2026-11-05/mobile-storefront-stability/outputs/`：`installed-assets.json`, `installed-library-assets.json`, `font-binary-validation.json`, `font-actual-html-baseline.json`, `coa-viewer-handoff.md`, `coa-range-worker-metrics.json`, `home-cancel-performance.md`, `home-local-request-memo.md`, `home-local-request-memo-semantic-final.json`, `product-state-metrics.json`。测试原 PDF、临时原始 HTML、运行进程/外部开发库仅在 D 盘任务目录，不进 Git；临时 profiling 服务已停止。

## 未完成验收 / 下一步

当前可复查的本地实现与提交已交付；**没有新的真实手机截图、真机 Canvas/Worker、Safari/Android 连续操作、实际移动网 LCP/长任务验收**。本轮 IAB 创建预览 40 秒超时，随后绑定已创建 tab 15 秒超时；未绕过访问方式，没有让子代理代操浏览器。当前不具备把整轮标成手机视觉验收通过的证据。

站点窗口接收后：整合 Core 的价格 priming、报告 HEAD/Range/cache 授权检查及 Geo 单署名；不跨店复用业务 ID，不改支付/优惠规则。再在真实 320/360/390/430 视口验证文字、触摸关闭、TR20→TR15→TR20、后台报价与盒装含义、刷新/Back/Forward、画廊隐藏恢复、COA 宽度/缩放/错误重试和唯一 Geo 署名；对同一首页重测应用与移动浏览器性能。若回归定位到某个模块，用各独立提交正常修正，勿 reset 或覆盖同事工作。

首单 5% 是较早已完成的配置同步，生产优惠券 11752 与本地 11748 通过原生身份解析绑定，回执在 `frontend-parity-completion/outputs/first-offer-native-apply.json` 和 `first-offer-public-verification.json`；本轮重复收到确认仅核查回执，没有再次创建或修改活动。
