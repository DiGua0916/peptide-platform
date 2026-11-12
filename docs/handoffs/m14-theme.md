# M14 前台主题 交接（F5，2026-11-10，2026-11-12 更新）

分支 `claude/m9-buyer-admin-940389`，提交 `9b93b55`（第一批）、之后修主题用例的提交、`ab62b67`（商品地址修正），和 M9、M11 在同一个分支上。没有合并 main，没有发布。

## 2026-11-12 更新

- 合并了主线 11-11 改主题的提交（`product-dialog-experience.js`、`product-dialog-polish.css`、`visitor-tracking.js`、`functions.php`：满减档位改成优惠券样式、热销小火苗、物流球等），没有冲突。合并后主题用例全部通过（`.cjs` 55 个、PHP 30 个）。
- **FE-02 商品地址在真实页面里原来不生效，已修。** 本机浏览器验收发现：开关打开后，从首页点开商品，地址栏仍然是 `/?vc_product=ID`。原因是脚本从弹窗里的“详情”链接读商品地址，而实际的商品弹窗里没有这个链接（之前只用假的页面结构测过）。现在依次从三处取：弹窗里的详情链接（有的话）、服务器给出的“这次进站的商品”的地址、页面上这个商品的卡片链接。
- 修正后在本机浏览器（手机宽度）里实际走过，结果都符合预期：
  - 首页点商品：地址栏变成 `/product/demo-peptide-a/`，`utm_source`、`fbclid` 还在。
  - 关掉弹窗：地址栏回到首页；历史记录条数不变。
  - 再点开后按返回：弹窗关闭，地址栏回到首页；历史记录条数不变（没有多按一次或少按一次）。
  - 直接打开分享出去的 `/product/demo-peptide-b/?utm_source=share`：弹出这个商品，地址栏是商品自己的地址。
  - 旧广告链接 `/?vc_product=17&fbclid=…`：弹出商品，地址栏变成商品地址，`fbclid` 还在。
  - 专辑链接：地址栏是专辑自己的地址。
  - 店铺信息里填的品牌名出现在网页标题里；页脚上方有 “Track your order”，查订单页面自己不显示。
- **仍然没验的**：Facebook 事件管理工具里有没有多出 PageView（本机没有真实像素，要和 F4 一起在沙盒看）；加购、购物车抽屉、结账在开关打开时的表现只看了商品弹窗这一段；商品弹窗里的使用声明样式（本机这份站没有配置声明）。
- 提醒：主线 11-11 加的进站 18+ 确认是整页的弹窗，新访客要先点过它才看得到后面的商品弹窗，地址栏的变化不受它影响。

平台所有者 2026-11-10 的决定：

- Codex 不再修改主题，主题由 F5 接手（`AGENTS.md` 的归属说明已更新）。
- Q5：保留弹窗，换地址。

接口约定在 `docs/CONTRACT.md` 的「M14 前台主题」。

## 做了什么

- **FE-02 商品 / 专辑地址可分享**（`inc/entry-address.php`、`assets/entry-address.js`）
  - 开工前的实际情况：`/product/<slug>/` 和专辑链接本来就能当广告落地页，服务器把它们 302 到首页并自动打开商品弹窗（`inc/storefront-entry.php`），落地页统计也按商品页记。缺的是地址栏：显示的是 `/?vc_product=123`，在首页点开商品时地址不变，没法分享。
  - 现在：服务器端的入口一行没动，只改地址栏。商品弹窗打开时显示商品自己的地址，专辑落地后显示专辑地址，关掉弹窗恢复页面地址。`/?vc_product=` 旧链接照常可用，没有加 301。
  - 按店开关 `pc_entry_address`，默认关。
- **NF-06 使用声明**：店铺配置的声明原来只在页脚上方输出，现在商品弹窗内容末尾也固定输出。文字只来自店铺设置，主题不自带措辞。
- **查订单入口**（M11 的配合项）：页脚上方输出 “Track your order”。
- **品牌名**：`vc_brand_name()` 读店铺填的对外英文品牌名，没填沿用默认值。
- **广告名称进归因**（F4 提的配合项）：`utm_campaign`、`utm_term`、`utm_content` 随来源一起上报。
- 品牌名、使用声明、查订单入口移到 `inc/storefront-identity.php`。
- **修好了主题里原本失败的 11 个追踪用例**（主线上就失败，其中 `visitor-intents`、`visitor-product-actions` 在默认套件里）：
  - 10 个的原因相同：11-08 加了“有互动才上报”（`assets/visitor-tracking.js` 的 `engageMs`，默认 6 秒），用例还在等“页面一打开就上报”。现在 4 个基础夹具（`visitor-transport`、`visitor-product-actions`、`visitor-metadata-push`、`visitor-products`）在启动观察器时带上它自己的选项 `engageMs: 0`，其余用例复用这些夹具，跟着恢复；`visitor-quick-view-pair` 直接传 `engageMs: 0`。追踪脚本本身没有改。
  - `visitor-client-environment` 另有一个原因：同一天加的爬虫 / 无头浏览器过滤每次启动会读一次 `navigator.userAgent`，用例把它算成了“读取环境信息”。现在用例只统计环境采集才会读的两项（`userAgentData`、`platform`）。
  - 因为这些用例现在都从“已互动”开始，另加了 `tests/m14-visitor-engagement.cjs` 专门测这道门本身：没互动不上报、真实互动立刻上报、脚本触发的事件不算、停留 6 秒算、隐藏的页面等到显示、无头浏览器不上报。

## 改动文件

- 新增：`inc/entry-address.php`、`inc/storefront-identity.php`、`assets/entry-address.js`、`tests/m14-entry-address-pure.php`、`tests/m14-storefront-identity-pure.php`、`tests/m14-entry-address.cjs`、`tests/m14-visitor-attribution-ads.cjs`、`tests/m14-visitor-engagement.cjs`。
- 修改：`functions.php`（两行 require，三个函数移走）、`footer.php`、`inc/product-dialog-content.php`、`assets/site.css`、`assets/visitor-adapter.js`、`assets/visitor-tracking.js`（只加了三个广告参数）、`tests/footer-details.php`、`tests/visitor-transport.cjs`、`tests/visitor-product-actions.cjs`、`tests/visitor-metadata-push.cjs`、`tests/visitor-products.cjs`、`tests/visitor-quick-view-pair.cjs`、`tests/visitor-client-environment.cjs`。
- 新选项：`pc_entry_address`。

## 实际做过的检查

- `php -l`、`node --check` 通过。
- 主题的 PHP 用例 30 个全部通过（其中 `footer-details.php` 在主线上原本失败，版权年份的断言没跟上 11-08 的改动，已更新）。
- 主题的 `.cjs` 用例 55 个全部通过（主线上是 41 过、11 败）。
- 新用例：`m14-entry-address-pure`（33 项，11-12）、`m14-storefront-identity-pure`（15 项）、`m14-entry-address.cjs`（12 个，11-12）、`m14-visitor-attribution-ads.cjs`（4 个）、`m14-visitor-engagement.cjs`（7 个），后两个用真实的追踪脚本跑。
- 11-10 写的时候没有在浏览器里看过；11-12 在本机临时站里看过，见上面的更新（也因此发现商品地址不生效）。

## 上沙盒后的验收清单

注意生效时间：开关打开或关闭后，匿名访客看到的首页是缓存页，最多 5 分钟后才变；验收时等 5 分钟，或用带任意参数的地址（例如 `/?nocache=1`）。

1. 开关关着：页面源码里没有 `entry-address.js`，行为和现在一样。
2. `wp option update pc_entry_address on`，用手机：
   - 打开 `/?vc_product=<ID>`（旧广告链接）：弹出商品，地址栏变成商品自己的地址，`utm_*`、`fbclid` 还在。
   - 在首页点一个商品：地址栏变成商品地址；点返回或关掉弹窗：地址栏回到首页，弹窗关闭，返回键行为和以前一样（不会多按一次或少按一次）。
   - 把地址栏里的商品地址发到另一台手机打开：直接弹出同一个商品。
   - 打开专辑链接：地址栏是专辑自己的地址，筛选结果正确。
   - 加购、打开购物车抽屉、结账：和关着开关时一样。
3. Facebook 事件管理工具里，打开商品弹窗时没有多出 PageView（请 F4 一起看）。
4. 配置了使用声明的店：商品弹窗内容最下面有声明；页脚上方照旧有。
5. 已购回访打开的店：页脚上方有 “Track your order”，查订单页面自己不显示这个入口。

## 没做的和已知问题

- 插件目录下的 `peptide-core/tests/customer-checkout-observer.cjs` 也加载主题的追踪脚本，在主线上失败，把互动等待设成 0 也一样失败，原因不是这道门。它不在默认套件里，也不是主题的用例，这次没有动，已告诉 F4。
- 分享出去的商品地址被社交平台抓取预览时，拿到的仍是首页的标题和图片。
- 政策页定稿内容：等平台所有者提供。
- FE-03（商品页转化元素）、NF-07（手机端性能）没有开始。
