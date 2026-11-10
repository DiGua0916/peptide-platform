# M14 前台主题 交接（F5，2026-11-10）

状态：**没有开工，主题文件一行没动。** 等平台所有者确认下面三件事，第 1 件确认之前不动主题。

## 等确认

1. **主题归属**：`AGENTS.md` 里 `wp-content/themes/peptide-site/` 归 Codex。F5 接手之前，需要平台所有者确认 Codex 不再同时修改主题；确认后先更新 `AGENTS.md` 的归属说明再动主题。
2. **Q5 商品页是否改成独立 URL**（PRD 11.1）：决定 FE-02 怎么做。
3. 政策页定稿内容由平台所有者提供（NF-06）。

## 开工前看到的情况（只读）

- 广告用的弹窗式商品链接是 `/?vc_product=<商品 ID>`（首页缓存 `Page_Cache::entry_product()` 专门识别它）。FE-02 改成独立 URL 时，这个地址必须继续可达或 301 到商品固定链接，已投放的广告才不会失效。
- 商品和专辑本身有固定链接（`get_permalink()` / `get_term_link()`），投手的“生成投放链接”已经用这两个地址；主题里有 `woocommerce/` 模板目录。
- 主题品牌名写死为 `VialCovePeptides`（`functions.php` 的 `vc_brand_name()`，页眉 Logo 是固定的 SVG），不随店铺信息里的店名变化。
- “仅供研究”声明：插件设置里有 `use_statement_html`（`Settings::public_config()` 对外提供），主题页脚和商品页是否固定输出，需要开工后逐个模板核对。
- F1 的盘点（`docs/handoffs/m1-inventory-2026-11-10.md`）：后台机 7 份副本的主题有 3 个文件落后，第一次正式发布会补齐，不用单独处理。
- M11 需要主题配合的一处：页脚或“联系我们”里放 “Track your order” 入口，指向 `\PeptideCore\Order_Lookup::page_url()`（开关关着时为空，不输出）。

## 计划（确认后）

1. FE-02：商品页 `/products/<slug>`、专辑页 `/collections/<slug>` 可直接访问和分享；首页点商品仍可弹窗，同时用 `history.pushState` 改地址；`/?vc_product=<ID>` 保持可达或 301。
2. NF-06：页脚和商品页固定输出“仅供研究”声明；政策页用定稿内容。
3. FE-03：按 PRD 的元素表补缺的转化元素（先确认哪些由 F2 负责）。
4. NF-07：图片和脚本的手机端加载；营销脚本读 `window.pcBuyer`。
