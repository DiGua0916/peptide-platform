# M14 前台主题 交接（F5，2026-11-10）

分支 `claude/m9-buyer-admin-940389`，提交 `9b93b55`（和 M9、M11 在同一个分支上）。没有合并 main，没有发布。

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

## 改动文件

- 新增：`inc/entry-address.php`、`inc/storefront-identity.php`、`assets/entry-address.js`、`tests/m14-entry-address-pure.php`、`tests/m14-storefront-identity-pure.php`、`tests/m14-entry-address.cjs`、`tests/m14-visitor-attribution-ads.cjs`。
- 修改：`functions.php`（两行 require，三个函数移走）、`footer.php`、`inc/product-dialog-content.php`、`assets/site.css`、`assets/visitor-adapter.js`、`assets/visitor-tracking.js`、`tests/footer-details.php`。
- 新选项：`pc_entry_address`。

## 实际做过的检查

- `php -l`、`node --check` 通过。
- 主题的 PHP 用例 31 个全部通过（其中 `footer-details.php` 在主线上原本失败，版权年份的断言没跟上 11-08 的改动，已更新）。
- 新用例：`m14-entry-address-pure`（22 项）、`m14-storefront-identity-pure`（15 项）、`m14-entry-address.cjs`（9 个）、`m14-visitor-attribution-ads.cjs`（4 个，用真实的两个追踪脚本跑）通过。
- 主题的 `.cjs` 用例：43 通过、11 失败。**这 11 个在没有改动的主线上同样失败**，这次没有修（见下）。
- **没有在浏览器里看过任何页面**：地址栏的变化、弹窗里声明的样式、页脚链接的位置都没有实际看过。

## 上沙盒后的验收清单

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

- **11 个 `visitor-*.cjs` 用例在主线上失败**（其中 `visitor-intents`、`visitor-product-actions` 在默认套件里）。原因：11-08 的“有互动才上报”（`assets/visitor-tracking.js` 的 `engageMs`，默认 6 秒）加上之后，用例还在等“页面一打开就上报”。修法已经验证过可行：在用例的假浏览器里先派发一个 `isTrusted: true` 的 `pointerdown`（`m14-visitor-attribution-ads.cjs` 就是这么做的）。11 个文件的夹具各不相同，需要逐个改，这次没有做。
- 分享出去的商品地址被社交平台抓取预览时，拿到的仍是首页的标题和图片。
- 政策页定稿内容：等平台所有者提供。
- FE-03（商品页转化元素）、NF-07（手机端性能）没有开始。
