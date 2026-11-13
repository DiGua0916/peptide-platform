# CR 素材归因 · 线 3 总后台跨店对比交接（2026-11-11）

分支 `claude/cr-line3-hq-606aa4`，基于 `claude/creative-merge`（7b14c01）。只改了 `cr-foundation.md` 里线 3 名下的文件；`clean_report()`、`clean_row()`、`clean_spend()` 和店铺端代码都没动。

## 做了什么

1. **日报写入**：`Creatives::store_rows()` 实现。同一家店同一天整天覆盖：开事务 → 逐行 `REPLACE` → 查出这一天库里还有、这次没报的编号 → 逐个删掉 → 提交；任何一步失败就 `ROLLBACK` 并返回 500。即使数据库不支持事务，也是先写后删，不会“删了没写上”。`attrs` / `custom` 存 JSON（空的存 `{}`），`intent` 拆成 `intent_high/mid/low/invalid`。`handle_report()` 返回真实的 `{day, stored, rejected}`，写完顺便检查检查点（检查点出错不影响这次上报）。
2. **“素材对比”页面**（`pchq-creatives`，新文件 `includes/admin/class-creatives-page.php`）：
   - 四种对比方式：按素材属性（默认，按 `hook`，可选任一属性）、按单个素材、按店铺、按投手（`pchq_sites.buyer_name`，经 `Series::site_map()`）。
   - 筛选：日期范围（预设 昨天 / 近 7 天 / 近 30 天 / 本月，复用 `Report_Page::range()`）、店铺多选（默认收起，显示已选几家）、“只看带某个属性取值的素材”。
   - 指标和口径写在每个表头下面：花费、进站访客、有效访客、中高意向占比（（高+中）÷ 有效访客）、加购率（加购 ÷ 有效访客）、进结账率（进结账 ÷ 有效访客）、进收银页、订单、销售额、每有效访客成本（花费 ÷ 有效访客）、每高意向访客成本（花费 ÷ 高意向访客）、ROAS（销售额 ÷ 花费）。分母为 0 显示“—”；0 花费时成本显示 $0.00、ROAS 显示“—”。
   - 有效访客 < 30 的行置灰、标“样本不足”，排在样本足的后面。
   - 每行的分组名可以展开：所属店铺 + 素材卡片（缩略图 `thumb`、编号、店铺、属性、有效访客、日期），一组最多列 60 个。
   - 店铺不写死：店铺列表 = 在用店铺 ∪ 表里有数据的店铺（已下线但有数据的也有名字）；新店接入并开始上报后自动出现。
   - 同一编号在不同店是不同素材（编号每店独立），合并键是（店铺，编号）；多天合并时属性 / 缩略图取最近一天的。
3. **“素材词表”页面**（`pchq-creatives-vocab`）：列出全部属性和取值（含已停用），可改中文名、调顺序、停用 / 启用，可新增取值（已有属性下或新属性，新属性为选填）。不能删除。必填属性（hook、fmt、angle）至少保留一个启用的取值。保存有改动时 `pchq_attribute_vocab_ver` 加一；表单没改动不加（提示“没有改动，词表版本不变”）。接口 `GET creatives/vocab` 本来就只给启用的，未改。
4. **检查点导出**：每次上报写入后算全部店铺全部日期合计有效访客；跨过 1000 的新整数倍时生成一份全量导出（一次跨多个整数倍只生成一份，记最高那个；补传让合计变小不会重复生成）。用 `pchq_creatives_checkpoint_lock` 选项做 10 分钟短锁防并发。文件写在 `uploads/pchq-creative-exports/`（`.htaccess` 拒绝访问 + `index.php` + 文件名带 16 位随机串），只能经后台 `admin-post.php?action=pchq_creatives_download`（管理员 + nonce）下载；登记在 `pchq_creatives_exports`，保留最近 50 份，多出的连文件一起删。
   - CSV：每天每店每素材一行，列 `day, site_id, site, buyer, code, attr_<属性>…, custom(JSON), thumb, 各计数`；带 BOM；`= + - @` 开头的文字前加单引号防公式。
   - JSON：同样的明细 `rows`，加 `total`（合计指标）、`by_attr`（每个属性按取值的对比，带指标和样本不足标记）、`kind / checkpoint / from / to / min_sample / valid_visitors`。
   - 手动导出：“素材对比”页底部按当前日期和店铺导出 CSV 或 JSON（直接下载），可勾选“同时保存到导出列表”。
5. **菜单**：两个页面在 `Creatives_Page::hooks()` 里用 `add_submenu_page( 'pchq', … )` 登记（优先级 23，排在“跨店报表”后面），由 `Creatives::register_hooks()` 在后台时调用——和 `Orders` 启动订单页 / 报表页的方式一样，所以 `class-menu.php` 和 `class-plugin.php` 都没改。
   - **没有加进 `Menu::navigation_policy()`**：那里的参数类型只有 ID / 枚举，不支持日期和多值 `sites[]`，同一个键重复出现就整页加载；而且无刷新导航不会带上这两个页面自己的样式（`hq-plugins.css` + 内联样式），和“跨店报表”一样走普通整页加载更稳。以后要加，需要先给导航脚本加日期和多值参数类型。

## 改动文件

- `wp-content/plugins/peptide-hq/includes/class-creatives.php`：`clean_*` 之外的部分（写入、读取、指标、分组、词表维护、检查点和导出）。
- `wp-content/plugins/peptide-hq/includes/admin/class-creatives-page.php`（新）：两个页面、导出 / 下载 / 词表保存三个 admin-post 动作。
- `wp-content/plugins/peptide-hq/tests/cr-hq-compare-pure.php`（新）：77 项纯逻辑检查。
- `tests/data-observations-suites.json`：手工追加一行 `cr-hq-compare-pure`（加了 `"extensions": ["mbstring"]`，中文名长度用 `mb_strlen`；没有 mbstring 时代码自己退回 `strlen`）。
- `docs/handoffs/cr-line3-hq.md`（本文件）。

## 怎么验证的（这台 Mac，`tools/php/php` = PHP 8.3.32 原生）

- `php -l`：三个 PHP 文件都通过。
- `cr-hq-compare-pure`：**77 项通过**。用内存里的假 `$wpdb`（带事务快照）覆盖：一行转表行（JSON、intent 四列）、整天覆盖（补传只剩新行，别的店 / 别的日期不动，先写后删，空数组清空这一天）、写失败回滚且不删任何行；指标（0 访客全部为空、0 花费成本 $0 和 ROAS 为空、分母都是有效访客、ROAS = 销售额 ÷ 花费）；样本不足（29 是、30 不是）；分组（同编号不同店分开、跨店按属性合计、缺属性单独一组、UNREG 单独一组、样本不足排后面、合计 = 各组之和、按投手合并多店、新店自动出现、属性筛选）；检查点（999 不触发、1000 触发、同一倍数不重复、跳多倍只记最高、合计变小不触发、步长 0 不触发）；词表（改名 / 停用 / 调顺序 / 新增一次保存、表单缺的行不动、未知键忽略、重复 / 带横线 / 空中文名被拒、必填属性不能全停、选填可全停、有改动版本加一、没改动不变、写失败回滚且版本不变）；导出（行数、店铺名 / 投手 / 属性列、按属性汇总、CSV 表头和 BOM、防公式、空导出）。
- 全部套件：`node tests/run-data-observations.cjs --php tools/php/php` → **78 个套件 77 通过、1 失败**；失败的是 `mail-source-observations`（需要已安装 WordPress 的目录，打地基时就失败），没有新增失败。`cr-contract-pure` 36 项、`cr-hq-contract-pure` 17 项照常通过。
- 本地 HQ 实测：把 `localwp/hq` 和 `hq-db` 复制到会话临时目录，`peptide-hq` 软链接到本工作区，SQLite + WP-CLI（原目录没碰）：
  - 用 `WP_REST_Request` 直接调 `handle_report()`：样例上报返回 `{"day":"2026-11-10","stored":3,"rejected":2}`；三份上报共 9 行；补传只剩新行、别的店和日期不动；坏请求 400；路由已登记。
  - 在 SQLite 上确认 `START TRANSACTION` / `ROLLBACK` 真的生效（插一行后回滚，行数复原）。
  - 新店（site 4）上报 1200 有效访客 → 生成“检查点 1,000”，CSV / JSON 文件和 `.htaccess` 都在，JSON 合计等于表里合计；同样数据再报一次不再生成。
  - 词表：停用 hook-q、新增 hook-review，版本 1 → 2；词表接口不再返回 hook-q，表里保留。
  - WP-CLI 模拟管理员：后台菜单出现“素材对比”“素材词表”。
  - 浏览器（应用内置浏览器，复制的站点开在 127.0.0.1:8793，用 WP-CLI 生成的登录 cookie）：“素材对比”按属性 / 按店铺（勾两家店）显示正常，展开分组能看到素材卡片和所属店铺，样本不足行置灰；检查点 CSV 下载 200、`text/csv`、附件名正确；手动导出 JSON 200（8 行、4 个属性汇总），错误 nonce 返回 403；“素材词表”页改 hook-price 中文名保存 → 版本 2 → 3、提示“已保存 1 条改动”；不改直接保存 → “没有改动，词表版本不变”。服务器日志里没有 PHP 警告。

## 没有验证的

- 没有用真正的店铺签名（`Mail::verify`）走一次 `POST creatives/report`；只直接调用了 `handle_report()`。店铺端每日上报是线 4 的，联调时一起测。
- 没在 MySQL 上跑过（本地只有 SQLite）；`REPLACE`、事务在 InnoDB 上是标准用法，但没实测。
- 缩略图只用了样例里不存在的 https 地址，没看到真实图片显示；图片从店铺域名直接加载（`referrerpolicy="no-referrer"`）。
- 大数据量没压测：页面把日期范围内的日报行全读出来在 PHP 里合并；检查点导出是全量。几十家店 × 几百素材 × 90 天应该没问题，再大要改成 SQL 先汇总、导出分批写。
- 手机宽度没看过。
- 没在生产或沙盒服务器上跑过；版本号 `PEPTIDE_HQ_VERSION` 没抬（发布时由 F1 统一处理）。

## 需要联调时改的共用部分

- `docs/CONTRACT.md` 的 CR 一节：“打地基阶段 `store_rows()` 返回 501，由线 3 实现写入”改成已实现（整天覆盖、写失败 500 且不改动）；“总后台数据”加上 `pchq_creatives_checkpoint`、`pchq_creatives_exports` 两个选项，以及“词表版本只在有改动时加一”。
- **有效访客 / 加购 / 进结账 / 收银页 / 订单的口径由店铺端（线 4 上报）决定**，约定里目前没写。总后台表头按样例写成“进站访客去掉意向分层为‘无效’的”“加购数 ÷ 有效访客”等；线 4 定下口径后，如有出入改 `Creatives_Page::columns()` 的说明文字即可（计算不受影响）。
- 店铺端词表同步（线 2）按 `ver` 变大整体覆盖：总后台停用的取值不会出现在接口里，店铺本地应当把它们当停用处理；已登记素材上的旧取值，总后台对比页仍按原值分组（显示原值而不是中文名的情况：词表里查不到时）。

## 下一步

联调时：线 4 的每日上报接上后，用真实签名跑一遍 `creatives/report`，在“素材对比”里核对和店铺“素材看板”的数字一致；再按上面改 `CONTRACT.md`。
