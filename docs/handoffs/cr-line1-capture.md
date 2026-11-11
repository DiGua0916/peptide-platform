# CR 素材归因 · 线 1 采集补齐交接（2026-11-11）

分支 `claude/cr-line1-capture-c15d45`，基于 `claude/creative-merge`（7b14c01）。没有推送。约定见 `docs/CONTRACT.md` 的“CR 素材归因”，分工见 `docs/handoffs/cr-foundation.md`。

## 做了什么

1. **放宽参数白名单**：`Attribution` 改用 `Contract::PARAMS`（固定顺序）。`utm_source` / `utm_medium` 仍按原规则严格校验（不合格整条拒收）；`utm_campaign / utm_term / utm_content / placement / ad_name` 是可选的，不合格（空、控制字符、超长）只丢那一项。长度上限和列宽一致：utm_* 120、placement 64、ad_name 255。`attribution_query` 现在包含全部参数，弃单（`Recovery_Items`）和结账流失（`Checkout_Dropoffs`）里 `parse_str` 读 `utm_campaign` / `utm_content` 能读到。
2. **入口快照可选带三项浏览器事实**：`has_fbclid`（只报有没有，不报值）、`timezone`（`Intl…timeZone`）、`language`（`navigator.language`）。校验函数在 `Client_Environment::timezone()` / `language()`。不带或不合格就不出现，旧客户端的快照和以前逐字节相同。
3. **写入访问表 v5 新列**：新文件 `tracking/class-visit-facts.php` 的 `Visit_Facts::columns()`（纯函数）算出全部新列（`creative_code` 用 `Contract::parse_ad_name()`，`source_class` / `params_valid` / `in_app` 用 Contract 的函数），`Attribution::capture()` 在原来那条 compare-and-set（`WHERE attribution_version = 0`）里一起写——仍然“每次访问第一页记一次、之后不改”。没带的 utm 类参数写 NULL。
4. **换广告开新访问**：`Tracker::visit()` 加了 `$ad_id` 参数；Ingest 从这批里新的入口页取有效广告 ID（`Visit_Facts::ad_id()`，必须是 6–25 位数字，广告预览的 `{{ad.id}}` 不算），和最近一次访问的 `utm_content` 不同就开新访问。判断在纯函数 `Tracker::continues()`。没有广告 ID 的事件照旧只按 30 分钟。
5. **访客级来源**：新建访问时 `first_visit_id = CASE WHEN first_visit_id = 0 THEN 新访问 ELSE 原值 END`（和 `visits + 1` 同一条 UPDATE）；入口快照写成功且 `source_class = paid` 时 `last_paid_visit_id = GREATEST(原值, 这次访问)`。
6. **IP 地理和网络**：新文件 `tracking/class-ip-network.php`。
   - 库路径：常量 `PC_DATA_GEOIP_CITY_DATABASE`、`PC_DATA_GEOIP_ASN_DATABASE`（绝对路径）；没定义时找 `uploads/peptide-geoip/` 下的 `GeoLite2-City.mmdb` / `dbip-city-lite.mmdb` 和 `GeoLite2-ASN.mmdb` / `dbip-asn-lite.mmdb`；过滤器 `peptide_core_ip_network_databases` 可改。库文件不进 Git。
   - 读库复用 WooCommerce 自带的 MaxMind Reader（`Country_Provider::reader_available()`、`local_path()` 改成 public 共用），不新增依赖。
   - 没有库 / 读不了 / 内网地址 / 查不到：这些列留空，不报错（整段 try/catch）。
   - `ip_type` 简化版：vpn（常见 VPN 出口 ASN 和名字）> hosting（AWS、Google Cloud、Azure、DigitalOcean、OVH、Hetzner、Linode、Vultr、Oracle、阿里云、腾讯云、Contabo 等）> mobile > residential > 空。付费库带 `connection_type` 时 Cellular → mobile、Cable/DSL → residential。名单在类常量里，可以继续补。
   - IP 取法：`REMOTE_ADDR`；只有 `REMOTE_ADDR` 在 `PEPTIDE_CORE_TRUSTED_PROXIES` 里时才用 CF-Connecting-IP / X-Forwarded-For（同 `Rest_Controller::client_ip()` 的规则，但不按 /64 合并 IPv6）。IP 原样入库（用户已同意）。同一请求只查一次库。
7. **广告首次出现**：入口快照写成功且广告 ID 有效时，`Visit_Facts::after_capture()` 写 `pc_ad_ads`（`INSERT … ON DUPLICATE KEY UPDATE`）：第一次插入 ad_id、编号、ad_name、广告组 / 系列 ID（无效的留空）、first / last_seen；之后只在这次更晚时更新 ad_name（非空）和编号（名字里有编号时），广告组 / 系列 ID 原来为空才补，最后推进 `last_seen_ms`；`first_seen_ms` 不改。素材表没装好（`Creatives\Schema::installed()` 为假）时跳过。注意：只有“访问的第一页”触发，所以 `last_seen_ms` 是“最近一次带这条广告开始的访问”。
8. **m9 广告链接生成器**：`Onboarding::ad_link()` 输出 `落地页 + ? + Contract::URL_TEMPLATE`（ID、`{{site_source_name}}`、placement、ad_name），落地页原有参数保留，旧的 utm_*、模板里的参数和 fbclid 去掉。`$campaign` / `$source` 参数保留签名但不再使用。
9. **页面缓存**：确认过，见下面“页面缓存”。
10. **浏览器端**：`visitor-adapter.js` 的 `attribution()` 多读 placement、ad_name（同样跳过空值、重复、控制字符和没替换的 `{{…}}`，超长截断），加 `has_fbclid` / `timezone` / `language`；`visitor-tracking.js` 的快照复制保留这些字段。

## 改动文件

- 线 1 名下：`tracking/class-attribution.php`、`class-tracker.php`、`class-ingest.php`、`class-client-environment.php`、`class-country-provider.php`（两个函数改 public）、`themes/peptide-site/assets/visitor-adapter.js`、`visitor-tracking.js`、`admin/class-onboarding.php`（只改 `ad_link()`）。
- 新文件：`tracking/class-visit-facts.php`、`tracking/class-ip-network.php`。
- 测试：新增 `peptide-core/tests/cr-capture-pure.php`（87 项）、`themes/peptide-site/tests/cr-visitor-attribution.cjs`（5 项）；改 `m9-onboarding-pure.php`（链接断言换成模板）、`tracking-attribution-pure.php`（多加载 Contract 和 Client_Environment）；`tests/data-observations-suites.json` 手工追加 `cr-capture-pure`、`cr-visitor-attribution`、`tracking-attribution-pure` 三条。
- 共用文件一个都没改。

## 验证

- `tests/run-data-observations.cjs --php tools/php/php`：80 个套件 79 个通过；唯一失败 `mail-source-observations`（需要已安装 WordPress 的目录，打地基时同样失败）。改动前是 77 个套件 76 个通过，新增 3 个都通过。
- `cr-capture-pure.php` 87 项：白名单和可选事实、v5 列、来源分类、换广告判断、广告表行和 upsert SQL、`capture()` 的 SQL（假 `$wpdb`）、`after_capture()`、IP 类型 16 个样例、库记录映射。**本机有 WooCommerce 源码时，测试会生成两个只含 8.0.0.0/8 的小 mmdb，用真实 MaxMind Reader 查 8.8.8.8**（在这台 Mac 上跑了，通过）；找不到 WooCommerce 源码时这一段跳过并打印说明。
- `m9-onboarding-pure` 85 项、`tracking-attribution-pure` 88 项、`cr-contract-pure` 36 项、`m9-buyer-scope-pure` 177 项、`country-provider-pure`、`country-resource-attribution-pure`、`tracking-page-context-pure` 通过；改动的 PHP 文件都 `php -l` 过。
- 主题 JS：`cr-visitor-attribution.cjs` 5 项；`m14-visitor-attribution-ads`、`visitor-metadata-push`、`visitor-transport`、`visitor-client-environment`、`visitor-surfaces`、`visitor-page-view-subscriptions` 及其余 `visitor-*.cjs`、`m14-*.cjs` 全部通过。
- 另外 `peptide-core/tests/` 下 7 个没登记的 node 测试（`tracking-detail-races`、`tracking-impressions-admin`、`tracking-insights`、`tracking-live-controls`、`tracking-page-context`、`tracking-refresh`、`customer-checkout-observer`）在改动前的基线上同样失败，和本线无关。
- 本地 WordPress（把 `localwp/site`、`localwp/db` 复制到临时目录，插件和主题软链接到本工作区，SQLite，只用 WP-CLI `eval-file`，没开端口）：生成城市库和 ASN 库后经 `Ingest::handle()` 送真实批次——
  - 第一页：快照写入，v5 全部列正确（ID、placement、编号 C0012、has_fbclid、paid、params_valid、IP、加州 / Mountain View / 经纬度、T-Mobile / 21928 / mobile、时区、语言、in_app = fb），`attribution_query` 含 utm_campaign / utm_content；`first_visit_id`、`last_paid_visit_id` 指向这次访问；`pc_ad_ads` 第一次写入。
  - 站内第二页（无 utm）：同一访问，快照不变。换另一条有效广告：新访问，`first_visit_id` 不变、`last_paid_visit_id` 移到新访问。同一条广告再点：不开新访问。没有广告 ID：不开新访问。
  - 另一位访客点同一条改过名的广告：`pc_ad_ads` 的 ad_name 更新、first_seen 不变。
  - 内网 IP 的直接访问：source_class = other，IP 入库，地理网络列为空，没有报错，debug.log 没有警告。
  - **本地 SQLite 的 `GREATEST()` 不可靠**：sqlite-database-integration 把毫秒时间算错（`GREATEST(1, 1794417609098)` 返回 1），复制来的站点里原有 488 条访问的 `last_ms` 本来就全是负数。实测脚本在每批之后把 `last_ms` 按 MySQL 的结果补回去；`pc_ad_ads.last_seen_ms` 的推进在本地是错的值，只在纯测试里核对了 SQL。MySQL 上没有这个问题，但**没有在 MySQL 上跑过**。

## 没验证的

- 没在真实浏览器里点过广告链接；浏览器端只在 Node VM 里测。
- 没用真实的 GeoLite2 / DB-IP 库查过（只用生成的小库走了真实 Reader）；真实库的字段名按官方格式写的（两家相同）。
- `ip_type` 名单是简化版，没有拿真实流量校对过误判率。
- 没在 MySQL、生产或沙盒服务器上跑过；`ON DUPLICATE KEY UPDATE` 里 `VALUES()` 在 MySQL 8.0.20+ 会有弃用提示（仍可用）。

## 页面缓存（第 8 项）

- `Page_Cache` 只在服务端把 utm_* 和点击 ID 从 `REQUEST_URI` / `QUERY_STRING` / `$_GET` 去掉再渲染（不重定向），浏览器地址栏不变；`visitor-adapter.js` 读的是 `location.href`，所以缓存页和非缓存页都能读到参数并上报。主题里改地址栏的 `entry-address.js` 只去掉 `vc_product` / `vc_catalog_category`，不动 utm。
- 但 `Page_Cache::tracking_name()` 只认 `utm_*`、点击 ID 和 `ref`：**带 `placement` 和 `ad_name` 的模板链接不算“只带追踪参数”，这些请求不走首页缓存**（照常渲染，功能不受影响，只是广告流量拿不到缓存的速度）。见下面联调项 1。

## 需要联调时改（共用文件 / 别人的文件）

1. `includes/class-page-cache.php`（不在线 1 名下）：`tracking_name()` 加上 `placement`、`ad_name`，让模板链接也能用首页缓存。`leaks()` 对它们按普通参数处理即可。
2. `includes/tracking/class-privacy.php`（不在线 1 名下）：个人数据导出只导出 `Attribution::COLUMNS`，新存的 IP 和地理网络列（以及 utm_campaign 等）没进导出；抹除按访客整行删除，IP 会一起删掉。建议导出加上 `Attribution::FACT_COLUMNS`，隐私说明里写明保存 IP。
3. m9 开店向导表单（`class-onboarding.php` 里 `ad_link()` 以外的部分，约 358–359 行）：“投放平台”和“广告系列名称”两个输入框已经不影响链接，可以去掉或改成说明“网址参数固定用模板”。路由 `/admin/ad-link` 的入参和返回格式没改。
4. `docs/CONTRACT.md` CR 一节可以补一句：入口快照可选带 `has_fbclid` / `timezone` / `language`；`pc_ad_ads` 只在访问第一页更新，`last_seen_ms` 是最近一次带这条广告开始的访问；IP 地理库的常量和默认位置。
5. 库文件放置：生产需要把 GeoLite2-City / GeoLite2-ASN（或 DB-IP lite）放进 `uploads/peptide-geoip/` 或配置常量；DB-IP lite 需要署名（现有的 `Country_Provider::attribution()` 只管国家库）。
