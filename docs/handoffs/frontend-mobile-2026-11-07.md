# 前台手机端优化（第一档 + 第二档）— 2026-11-07

分支：`codex/checkout-acceptance-20261105`。不合入 main，不强推。本文记录的是本地完成的改动和验证；沙盒部署和真机测速见文末“未完成”。

前提（用户 2026-11-07 确认）：所有顾客都从 Facebook 手机广告进入独立站，所以优化以手机为准；Facebook 像素和 CAPI 同时开启，不能受影响。

## 1. 改前基线（沙盒，服务器本机请求，按手机实际下载的 gzip 大小）

| 页面 | HTML | CSS | JS | 说明 |
| --- | --- | --- | --- | --- |
| 首页 `/?fbclid=…` | 40 KB | 43 个 / 140 KB，全部阻塞首屏 | 57 个 / 252 KB | 97 张图片 |
| 结账 `/checkout/` | 34 KB | 46 个 / 144 KB | 108 个 / 885 KB | 其中 `wp-components` 273 KB 是 WooCommerce 结账自身的依赖（通过 `wp-notices`），不能去掉 |
| 购物车 `/cart/` | 38 KB | 43 个 / 138 KB | 105 个 / 872 KB | |
| 账户 `/my-account/` | 23 KB | 40 个 / 117 KB | 52 个 / 229 KB | |

另外查到：

- 商品详情页 `/product/xxx/` 会 302 到 `/?vc_product=ID#vc-products`（首页打开商品弹窗）。所以 Facebook 的实际路径是 **首页 → 商品弹窗 → 结账**。
- `?vc_product=` 不在首页缓存的白名单里，所以**商品广告落地页每次都要完整渲染**（10 人并发时约 4 秒）。
- **ViewContent 从来没有发出过**：`view_item` 只在真正的商品页生成，但商品页都跳到了首页弹窗，弹窗打开时又不发事件。Facebook 只能看到 PageView → AddToCart。
- 首页目录 83 张商品卡在 HTML 里全部可见，要等页面底部的 JS 运行后才隐藏第一页以外的卡片。
- 目录卡片图片经过 `wp_kses_post()` 输出，而 WordPress 的白名单里没有 `srcset/sizes/decoding/fetchpriority`，所以每张卡都固定下载 600px 的 PNG（平均约 145 KB）。

## 2. 改动

| # | 内容 | 文件 |
| --- | --- | --- |
| A | **商品广告落地页走首页缓存**：`vc_product=<ID>`，可以带 utm/fbclid 等跟踪参数，按商品分别缓存。只缓存已发布的商品（随便填的 ID 不会占满缓存）；只从不带其他参数的 `/?vc_product=ID` 生成缓存。缓存版本号从 2 升到 3。 | `peptide-core/includes/class-page-cache.php` |
| B | **商品弹窗发 ViewContent（像素 + CAPI）**：弹窗展示商品时，主题派发 `pc:view-item`；`pixels.js` 调用 `POST /peptide/v1/pixel-view`；服务端生成 `view_item`，用 Conversions API 回传，并把同一个 `event_id` 交给浏览器像素，两边按 event_id 去重。每个商品每次页面浏览只发一次。只对公开、已发布、无密码的商品生效；GPC、退出回传、员工都不发；来源地址只接受本站地址。登录用户带 REST nonce，保证员工照样被排除。 | `class-pixels.php`、`assets/pixels.js`、主题 `home-quick-view.js` |
| C | **商品弹窗脚本按需加载**（第二档第 2 项）：弹窗相关的 16–17 个脚本照常入队，但在页脚输出成不执行的标签。小加载器在以下时机按原顺序执行：第一次触摸、按键或聚焦；或页面加载完、浏览器空闲时（最多约 2.5 秒）。脚本加载完之前点了商品，会先拦下这次点击，加载完再重放。以下情况保持原来的立即加载：商品落地页（`vc_product`）、目录入口（`vc_catalog_*`）、编辑器预览，以及前进/后退恢复弹窗层。被其他立即加载的脚本依赖的脚本不会延后（按依赖关系计算）。像素脚本永远不延后。 | 主题 `inc/lazy-dialog.php`、`assets/lazy-dialog.js` |
| D | **不影响首屏的样式不再阻塞渲染**（第一档第 1 项）：弹窗样式（3 个）在所有页面改成非阻塞加载；首页区块样式（6 个）在没有自己页面布局的页面上（结账、购物车、账户、普通页面）改成非阻塞加载。文件和顺序都不变，只把 `<link>` 换成 `media="print"` → `all` 的写法，另外加 `<noscript>` 兜底。`modules-shared`、`modules-content`、报告查看器样式仍然阻塞渲染，因为结账页和 COA 库会用到。 | 主题 `inc/offscreen-styles.php` |
| E | **滚动节流**：按钮动效的位置计算每帧最多做一次，没有动效按钮时完全不监听滚动。**视频统计**：页面上没有视频时不再每秒运行一次计时器。 | 主题 `modules-shared.js`、`visitor-video.js` |
| F | **首页目录**：第一页以外的卡片在服务端就加上 `hidden`，和 JS 首次渲染的结果完全一致。手机在 JS 运行前不再排版、也不再下载这些卡片的图片。 | 主题 `template-parts/modules/demo-catalog.php` |
| G | **目录卡片图片恢复响应式**：新增 `vc_kses_image()`，在 post 白名单的基础上保留 `srcset/sizes/decoding/fetchpriority`，`onerror`、`<script>` 等照样过滤。 | 主题 `functions.php` |
| H | **CDN 开关（默认关闭）**：在 wp-config.php 定义 `PEPTIDE_CORE_CDN_URL`（https）后，前台页面的 CSS、JS 和媒体库图片走 CDN。.mjs worker、REST、后台、预览、员工浏览和第三方脚本都不改。 | `peptide-core/includes/class-static-cdn.php` |

## 3. 验证（本地真实 WordPress，已导入沙盒的装修文档）

- 浏览器用手机尺寸（375×812）验证：
  - 在弹窗脚本还没执行时点商品（当时有 17 个脚本未执行），点击被拦下，加载完后弹窗正确打开了商品 10；
  - 空闲加载正常；
  - 商品落地页 `?vc_product=10&fbclid=…` 仍然立即加载并打开弹窗；
  - Facebook 浏览器事件依次为 **PageView → ViewContent → AddToCart**（`eid=vie_…`），`pixel-view` 返回 200，商品成功加入购物车。
- 首屏没有变化：分别在账户页（94 个元素）、结账页（304 个）、首页（409 个）记录每个可见元素的位置、尺寸、字号和颜色，然后把改成非阻塞的样式全部禁用（模拟它们还没下载完）再比较，结果完全一致。结账页唯一的差异是 WordPress 一个 1×1 的读屏辅助元素，它出现的时机不同，和样式无关。
- 测试：
  - 新增或扩展的测试全部通过：
    - `page-cache-pure.php`：102 项；
    - `smoke-pixels.php`：新增 6 项，全部通过；
    - `static-cdn-pure.php`：11 项；
    - 主题 `lazy-dialog-pure.php`：13 项；
    - `visitor-video-idle.cjs`：4 项；
    - `home-product-first.php`：23 项。
  - 独立测试跑了 166 项通过，35 项失败。这 35 项在上次推送的提交 4102d43 上同样失败，属于已有问题，这次没有新增失败。
  - 所有改动的 PHP/JS 文件语法检查都通过。

## 4. 未完成 / 需要用户

1. **沙盒部署和真机测速**：沙盒前面有一层访问登录（`/_sandbox/login`），需要用户在内置浏览器里登录。之后可以在部署前测一次基线，部署后再测一次，按手机尺寸对比首屏时间和下载量。部署后旧缓存会自动失效：页面缓存版本号升到 3，片段缓存版本号升到 2，不需要手动清空。
2. **正式独立站服务器**：图片的浏览器缓存时长和是否返回 WebP 只能在那台服务器上检查（这台 54.144.250.46 上只有总后台和店铺后台）。沙盒的图片只缓存 300 秒，而且只有沙盒配置了 WebP。
3. **CloudFront**：在 AWS 建好分发（源站指向独立站）后，在 wp-config.php 加上 `define( 'PEPTIDE_CORE_CDN_URL', 'https://xxxx.cloudfront.net' );`。字体文件需要允许跨域（`Access-Control-Allow-Origin`）。
4. **广告链接建议**：广告直接使用 `https://店铺域名/?vc_product=商品ID`，可以省掉商品页 302 跳转的一次往返；现在这个地址也会走缓存。
5. 插件区块里的 6 处 300px 缩略图同样被 `wp_kses_post` 去掉了 `srcset`（`class-commerce-blocks.php`），图片小，这次没改。
6. 回头客首页仍然要完整渲染（会话 Cookie），这次没改。

## 5. 沙盒部署与实测（2026-11-07，用户批准）

**范围**：只改了沙盒的 `/srv/vialcove-sandbox/{shop,admin}` 下 14 个运行文件（提交 55a3cdd，不含测试和文档），以及对 `vialcove-sandbox-php` 做的 USR2 平滑重载。Nginx、MySQL、正式站、wp-config 都没动。部署前逐个核对过，沙盒上的文件和 4102d43 版本完全一致。

**备份 / 回滚**：备份在 `/home/ubuntu/vialcove-deploy-backups/frontend-20261107T085156Z/`，里面有 manifest、改前文件和 created.txt（列出 8 个新建文件）。回滚方法：把备份文件按原路径拷回（属主 vcsandbox），删除 created.txt 里列出的文件，然后执行 `sudo systemctl kill --kill-whom=main -s USR2 vialcove-sandbox-php`。

**部署后检查**：

- shop 返回 200，admin 返回 302，hq 返回 200；
- fpm.log 和各站 debug.log 没有新错误，只有部署前压测留下的 "max_children (2)" 警告；
- 首页 MISS→HIT；`/?vc_product=11306` 先 MISS，带 fbclid 的同一商品 HIT；不存在的商品 ID 返回 BYPASS；
- 页面上有 18 个按需加载的弹窗脚本和加载器，3 个非阻塞样式；
- 目录 83 张卡里有 75 张初始隐藏，82 张卡片图片全部带 srcset。

**浏览器实测**（内置浏览器，手机尺寸 375×812，已登录沙盒，同一个"老顾客"会话，购物车里有 10 件商品；本机网速快，所以绝对时间很小，主要看下载量和阻塞数量）：

| 页面 | 指标 | 改前 | 改后 |
| --- | --- | --- | --- |
| 首页 `/?fbclid=` | 阻塞首屏的文件 | 48 个 / 186 KB | 45 个 / 177 KB |
| | 页面加载完之前的 JS | 110 个 | 93 个（17 个弹窗脚本、60 KB 压缩后，改到加载完后再下载执行） |
| | 加载完之前的图片 | 16 张 / 1352 KB | 15 张 / 864 KB（−36%） |
| 结账 | 阻塞首屏的文件 | 51 个 / 191 KB | 43 个 / 166 KB |
| | 加载完之前的 JS | 159 个 / 1528 KB | 144 个 / 1477 KB |

**交互验证**（沙盒）：

- 弹窗脚本还没执行时点商品（当时 17 个未执行），点击被拦下，加载完后正确打开了商品 11306；
- 离开页面再按返回，弹窗层正确恢复（back_forward 导航）；
- 商品广告落地页立即打开弹窗；
- 结账页正常，数量和"10 vials per box"都显示。

**并发**（服务器本机，无 Cookie 的手机首次访问，30 次请求、10 个并发，取中位数）：

| 落地页 | 改前 | 改后 |
| --- | --- | --- |
| 商品广告 `/?vc_product=11306&fbclid=…` | 2.10 s（BYPASS） | **0.81 s（HIT）** |
| 另一商品 `/?vc_product=11450&utm_source=facebook&fbclid=…` | 约 2.1 s | **0.79 s（HIT）** |
| 首页广告 `/?fbclid=…` | 0.77 s（HIT） | 0.82 s（HIT，正常波动） |

**没能验证**：

- 沙盒没有配置 Facebook 像素（页面上没有 pcPixels），所以无法在沙盒上看到 ViewContent。`POST /wp-json/peptide/v1/pixel-view` 返回 200 和 `{"events":[]}`，这是没配像素时的预期结果。像素和 CAPI 的完整链路是在本地验证的（第 3 节）。
- 沙盒 shop 的 PHP 进程池只有 2 个，所以不走缓存的访问在 10 个并发时大约要 2 秒。
