# CR 素材归因 · 线 2 素材库与花费导入交接（2026-11-11）

分支 `claude/cr-line2-library-e18302`，基于 `claude/creative-merge`（7b14c01）。只改了线 2 名下的文件和新建文件，共用文件没动。

## 改了哪些文件

| 文件 | 内容 |
| --- | --- |
| `peptide-core/includes/creatives/class-library-page.php` | 素材库页面 `pc-creatives` 和 admin-post 动作 `pc_creative_save`（op：create / update / archive / restore / link / vocab） |
| `peptide-core/includes/creatives/class-spend-page.php` | 花费导入页面 `pc-creatives-spend` 和动作 `pc_creative_spend_import`（op：import / push） |
| 新 `creatives/class-creatives.php` | 素材数据：编号分配、增改归档、统计、未登记广告、补登记归位、给总后台的签名请求 |
| 新 `creatives/class-spend-import.php` | CSV 解析、写库、补广告表、每日合计推送和重试 |
| 新 `creatives/class-vocab-sync.php` | 词表同步（每天定时 + 打开素材库超过 24 小时） |
| 新 `peptide-core/tests/cr-library-spend-pure.php` | 纯逻辑测试 58 项 |
| 新 `peptide-core/tests/cr-library-spend-db-pure.php` | 用内存 SQLite 模拟 `$wpdb` 的写库测试 51 项（需要 pdo_sqlite） |
| `tests/data-observations-suites.json` | 手工追加上面两个套件 |

## 做了什么

**素材库**
- 顶部“投放前：每条广告填两样东西”：`Contract::URL_TEMPLATE` 一键复制，说明网址参数和广告名各粘到哪里。
- 登记：钩子、形式、卖点必填，语言可选，选项来自 `Schema::vocab()`；备注；缩略图。校验用 `Contract::attr_problems()`。
- 编号：下一个号 = 素材表和广告表里出现过的最大编号 + 1。广告名里先用掉的号不会再分给别的素材。并发靠 `code` 唯一索引：撞号就重新取号再试，最多 8 次。
- 卡片：缩略图、编号、属性中文名、状态、累计花费、进站访客，规范广告名一键复制。可以编辑属性、备注和缩略图，也可以归档和恢复。
  - 状态：最近 3 天内（含今天，店铺时区）在 `pc_ad_daily_spend` 有花费 > 0 就是“投放中”，否则是“已停”。归档单独算。
  - 进站访客：`pc_track_visits` 按 `creative_code` 数不同的 `visitor_id`。线 1 写入之前都是 0。
- 筛选：可以按四个属性和状态筛选。默认不显示归档。
- 未登记广告：`pc_ad_ads` 里编号为空或编号不在素材表里的广告，显示广告名、ad_id、首次出现时间、累计花费。处理有两种：
  - 补登记：属性按广告名预填。广告名里有合规且空着的编号就沿用，Meta 里不用改名；否则分新号，并提示投手改广告名。
  - 归到已有素材。
  - 归位会改两处：`pc_ad_ads.code`，以及这条广告带来的访问的 `pc_track_visits.creative_code`（`utm_content = ad_id`）。之后触发 `do_action( 'pc_creatives_relinked', $ad_id, $code )`。
- 缩略图：投手的后台拦着 admin-ajax，没有 `upload_files` 权限，媒体库弹窗用不了。所以表单里直接上传图片，在 handler 里调 `media_handle_upload` 存成附件。有 `upload_files` 权限的管理员另外有“从媒体库选”。视频素材请投手上传封面截图。

**花费导入**
- 认中英文表头，表头的大小写、空格、全角括号都不影响：
  - 日期：Day / 日期 / 天；没有这一列时用 Reporting starts / 报告开始日期。如果开始和结束日期不同，就当作没按天细分的行，跳过并报错。
  - 广告编号：Ad ID / 广告编号。
  - 金额：Amount spent (USD) / 已花费金额 (USD)。不是 USD 时拒绝。
  - 可选列：广告名称、展示次数、链接点击量。
- 能处理：UTF-8 BOM、UTF-16（带 BOM）、逗号 / 制表符 / 分号分隔、引号和引号内换行、千分位、`$` / `US$`。
- 缺必需列时直接说缺哪几列。Meta 最后的合计行没有广告编号，会跳过，不算错误。坏行报行号，最多列 20 条。
- 同一个文件里 (ad_id, 日期) 重复时，以最后一行为准，并给出提示。
- 写库：
  - 按 (ad_id, 日期) `REPLACE`，重复上传结果不变。
  - 每次导入写一条 `pc_ad_spend_imports`。
  - CSV 里新出现的广告补进 `pc_ad_ads`：编号来自广告名，首次出现记为最早那天的店铺时区零点。已有广告缺广告名或编号时补上。
  - 导入后触发 `do_action( 'pc_creatives_spend_imported', $days, $import_id )`。
- 推送：
  - 受影响日期的全店合计（当天所有广告的合计，不只是这次文件里的广告）发给 `POST /peptide-hq/v1/creatives/spend`。签名同订单上报，请求体带 nonce，每批最多 62 天，0 也会推。
  - 没推成功的日期记在选项 `peptide_core_creatives_spend_pending`，一小时后重试；页面上也有“重新推送”按钮。
  - 没接总后台时只提示，日期先记下，每天检查一次，接上后自动补推。
- 页面上有 Meta 导出步骤和最近 10 次导入。

**词表同步**
- 签名 `GET /peptide-hq/v1/creatives/vocab`。`ver` 比本地 `MAX(ver)` 大时，在事务里整体覆盖 `pc_ad_attribute_vocab`。
- 以下情况保持本地词表不变：回应格式不对、必填属性缺取值、网络失败、没接总后台。
- 定时任务 `pc_creatives_vocab_sync` 每天跑一次。打开素材库时，距上次尝试超过 24 小时也会同步。页脚显示词表版本、上次检查时间，有“立即同步”按钮。状态存在选项 `peptide_core_creatives_vocab_sync`。
- 已登记素材用到、但新词表里停用的取值不会被改掉：卡片原样显示，编辑时标“词表里已停用”。

## 怎么验证的

- 测试用本机 `tools/php/php`（PHP 8.3.32）跑：
  - `cr-library-spend-pure` 58 项通过。
  - `cr-library-spend-db-pure` 51 项通过，覆盖：撞号重试、广告名占号、补登记归位（广告表 + 访问 + 动作）、导入覆盖和重复上传、补广告表、统计、推送请求体和 HMAC 签名、推送失败留待重试、未接入时每天检查、词表覆盖 / 不完整 / 旧版本 / 网络错误。
- `node tests/run-data-observations.cjs --php tools/php/php`：79 个套件，78 个通过。唯一失败的是 `mail-source-observations`，原本就跑不了。
- 所有改动文件都过了 `php -l`。
- 本地 WordPress 实测：把 `../localwp/site` 和 `db` 复制到会话临时目录，插件软链接到本工作区，SQLite，用 WP-CLI `eval-file` 跑。没开端口，没碰原目录。
  - 管理员：
    - 登记时缺属性会拒绝，正常登记得到 C0001。
    - 中文 + BOM + 千分位的 CSV 导入：3 行、2 条新广告；重复导入合计不变；统计花费正确。
    - 未登记广告能列出，补登记后归位（C0002）。
    - 归档、恢复、编辑都正常。
    - 没接总后台时，推送和词表同步只给提示。
    - 没选文件时报错。
    - 两个页面都能完整渲染。
  - 投手（临时建的销售账号，切成投手版，测完删除）：
    - 两个页面和两个动作 classify 都放行。
    - 能登记素材。
    - 页面上有上传框，没有“从媒体库选”。
    - 坏 nonce 被拒。

## 没验证的

- 没在浏览器里打开过页面：复制站点的后台登录问题同打地基，没排查。复制按钮、媒体库弹窗、确认框这些前端脚本没实际点过。
- 缩略图真实上传没跑过：CLI 里 `is_uploaded_file` 恒为假，`media_handle_upload` 这条路径没跑。CSV 上传也一样，实测是直接调用解析和写库，上传那几行检查没跑。
- 没有和真实总后台联调推送和词表接口：本地站点禁止外发 HTTP。请求体和签名只在测试里对过 HQ 的验签规则。
- 没用真实 Meta 导出的文件测：列名按 Meta 现有中英文界面写，需要投手给一份真实导出再核对。尤其是中文界面“天”这一列的确切名字，以及广告编号列是否需要手动勾选。
- MySQL 上没跑：本地是 SQLite。SQL 只用了标准写法（子查询、CASE、COALESCE、REPLACE）。

## 需要联调时改共用文件 / 和其他线对齐

1. **线 1**：补登记会改 `pc_track_visits.creative_code`（`WHERE utm_content = ad_id`）。如果线 1 的看法不同（比如 creative_code 只在进站时写一次），联调时统一。线 1 写 `pc_ad_ads` 时请照常从广告名取编号；线 2 导入 CSV 时也会补这张表，两边都只在没有这条广告时插入。
2. **线 4**：可以挂两个动作重报受影响的日期：
   - `pc_creatives_relinked( $ad_id, $code )`：补登记或归位之后。
   - `pc_creatives_spend_imported( $days, $import_id )`：导入花费之后。
3. **`class-staff-buyer.php`**（共用）：投手没有 `upload_files`，AJAX 也不放行 `query-attachments` / `upload-attachment`，所以投手用不了媒体库弹窗。现在用表单上传，够用。如果要让投手也能“从媒体库选”，需要在 AJAX 放行名单里加这两项，并临时给 `upload_files`。
4. **`docs/CONTRACT.md` 的 CR 一节**（共用）：联调时补上这些。
   - 两个动作的 `op` 取值。
   - 两个 do_action。
   - 三个选项：`peptide_core_creatives_spend_pending`、`peptide_core_creatives_vocab_sync`，以及定时任务 `pc_creatives_vocab_sync`、`pc_creatives_spend_push`。
   - 状态口径：3 天、含今天。
5. **`Module::placeholder()`**：线 2 的两个页面不再用它。线 4 用完之后可以删掉。
6. **总后台（线 3）**：`/creatives/vocab` 只返回启用中的词条，店铺端按“整体覆盖”处理，所以总后台停用一个取值后，店铺下拉里也就没有了，这符合预期。`/creatives/spend` 收到 0 会删那天的行，店铺端会推 0 天，这也符合预期。
