# CR 素材归因 · 打地基交接（2026-11-11）

分支 `claude/creative-merge`：从集成线 `codex/checkout-acceptance-20261105`（87b460c）拉出，先合并了 m3、m6、m9，再在上面打地基。约定全文见 `docs/CONTRACT.md` 的“CR 素材归因”。

## 打地基做了什么

- 店铺端：访客追踪表 v5 的新列和索引；素材 5 张表（`Creatives\Schema`）；约定和校验的纯函数（`Creatives\Contract`）；后台三个页面的入口和占位（素材库、花费导入、素材看板）；投手菜单加“素材”，放行三个页面和三个 admin-post 动作。
- 总后台：`Creatives` 模块，两张表、词表初版、三个接口（素材日报只校验不写入、每日花费已实现、词表已实现）；`pchq_ad_spend` 接受 `source = csv`。
- 测试：`cr-contract-pure`（店铺端）、`cr-hq-contract-pure`（总后台端，和店铺端共用样例 `tests/fixtures/creatives/report-sample.json`）；`m9-buyer-scope-pure` 改成 7 个菜单并加了素材页面的检查。

## 四条线各改哪些文件

**原则：只改自己名下的文件。** 必须动别人的文件时，先在这里登记、和那条线说好。

| 线 | 内容 | 名下文件 |
| --- | --- | --- |
| 1 采集补齐 | 放宽白名单（`Contract::PARAMS`）、写入 v5 新列、换广告开新访问、首次 / 最近来源、IP 和地理网络（城市、ISP、ASN、IP 类型）、时区和语言、内置浏览器、`pc_ad_ads` 首次出现写入；m9 广告链接生成器改成 `Contract::URL_TEMPLATE` | `tracking/class-attribution.php`、`tracking/class-tracker.php`、`tracking/class-ingest.php`、`tracking/class-client-environment.php`、`tracking/class-country-provider.php`（或新的地理类）、`themes/peptide-site/assets/visitor-adapter.js`、`visitor-tracking.js`、`admin/class-onboarding.php` 的 `ad_link()` |
| 2 素材与花费 | 素材库页面（登记、缩略图、编号、广告名复制、未登记提醒）、词表从总后台拉取、CSV 导入（中英文列名）、推送每日花费 | `creatives/class-library-page.php`、`creatives/class-spend-page.php`、新文件 `creatives/class-creatives.php`、`creatives/class-spend-import.php`、`creatives/class-vocab-sync.php` |
| 3 总后台对比 | `Creatives::store_rows()`、“素材对比”页面、词表维护页面和下发、HQ 检查点导出 | `peptide-hq/includes/class-creatives.php`（除 `clean_*` 外）、新文件 `peptide-hq/includes/admin/class-creatives-page.php`、HQ 菜单登记 |
| 4 看板与导出 | 素材看板（按素材 / 属性，样本不足置灰）、漏参提醒、店铺检查点导出、每日向总后台上报 | `creatives/class-board-page.php`、新文件 `creatives/class-board.php`、`creatives/class-daily-report.php`、`creatives/class-export.php` |

共用、谁都不要单独改的：`creatives/class-contract.php`、`creatives/class-schema.php`、`tracking/class-schema.php`、`creatives/class-module.php`、`class-staff-buyer.php`、`tests/fixtures/creatives/`、`docs/CONTRACT.md` 的 CR 一节。确实要改时，在联调阶段统一改。

## 已验证 / 未验证

- 已跑：`tests/run-data-observations.cjs` 77 个套件 76 个通过（`mail-source-observations` 需要传入已安装 WordPress 的目录，在集成线上同样失败）；`cr-contract-pure` 36 项、`cr-hq-contract-pure` 17 项、`m9-buyer-scope-pure` 177 项；所有改动文件 `php -l`。
- 本地 WordPress（localwp 的店铺和总后台各复制一份，SQLite，WP-CLI）：
  - 店铺：访客追踪表启动时自己升到 v5，原有 488 条访问保留，新列齐全；素材 5 张表建表成功、重复执行也成功；词表初版 14 条；表读写正常。
  - 后台页面（WP-CLI 模拟登录）：管理员在“营销活动”下有“广告素材”；投手菜单 7 项，“素材”在“数据”和“广告设置”之间；三个页面都能渲染，标签栏当前页正确；投手打开看板页 classify 放行。
  - 总后台：两张表建好、词表 ver 1 共 14 条；三个接口已登记；花费接口写入 `pchq_ad_spend`（source = csv）；素材日报接口校验后返回 501（待线 3）。
- 没在浏览器里看过页面：本地店铺的销售账号必须走专属登录入口，管理员登录在复制的站点上也被拦，未继续排查。没有在生产或沙盒服务器上跑过。
- `PEPTIDE_CORE_VERSION`、`PEPTIDE_HQ_VERSION` 没有抬高，发布时由 F1 统一处理（访客追踪表会在 Tracker 启动时自己升级到 v5）。
