# CR 素材归因 · 联调交接（2026-11-11）

分支 `claude/creative-merge`：地基 7b14c01 上依次合并四条线——线 1 采集（0f75b16）、线 2 素材与花费（7ba5c97）、线 3 总后台对比（f1767c5）、线 4 看板与导出（28ac24f）。四条线都没改共用文件，唯一冲突是 `tests/data-observations-suites.json`（各自追加条目，两边都保留）。未推送，集成线 `codex/checkout-acceptance-20261105` 没动。

## 联调时改的

- `creatives/class-module.php`：接上 `pc_creatives_spend_imported` → 重报导入日期，`pc_creatives_relinked` → 重报这条广告带来访客的日期（`Module::rereport_days()` / `rereport_ad()`）。
- `class-page-cache.php`：`placement`、`ad_name` 算追踪参数（线 1 联调项 1）。
- `docs/CONTRACT.md` CR 一节：日报写入已实现、上报口径、重报、选项和定时任务、地理库、已知缺口。

## 验证

- `tests/run-data-observations.cjs`：84 个套件 83 个通过，唯一失败 `mail-source-observations`（需要已安装 WordPress 的目录，合并前就失败）。合并涉及的 PHP 文件 `php -l`、JS `node --check` 无错误。
- 本地端到端（localwp 的店铺和总后台各复制一份，SQLite + WP-CLI，没开端口给外部）：
  1. 店铺写入 6 条已采集的访问（前一天）：广告 A 3 条（名字带 C0001）、广告 B 2 条（名字没有编号，其中 1 条机房 IP）、1 条 FB 未带参数。
  2. 素材库登记 C0001、C0002 → 广告 B 出现在“未登记素材” → 归到 C0002 后消失，并自动把那一天放进重报队列。
  3. 导入 Meta 英文表头 CSV（带 BOM、`$10.00` 格式）：2 行全部保存，花费推送队列记下这一天。
  4. 每日上报请求体：C0001 访客 3 / 有效 3 / 高意向 2 / 加购 2 / 进结账 1 / 花费 $23.50 / 点击 52；C0002 访客 2 / 有效 1（机房 IP 判无效）/ 花费 $10.00；未带参数 1。和手算一致。
  5. 把店铺签名后的请求原样送进总后台 REST（经过 `Mail::verify`）：200，存 2 行；同一请求再送 401（重放），改一个字节 401（签名不对）。花费推送 200，`pchq_ad_spend` = 3350（source = csv）。
  6. 总后台“按 hook 对比”：lab 访客 3 / 花费 $23.50 / 加购率 66.7%，price 访客 2 / 有效 1 / 花费 $10.00，都标“样本不足”，和店铺数字一致。
  7. 店铺三个页面以管理员和投手身份渲染：无异常、无 PHP 提示；素材库显示两张卡片和网址参数模板；看板显示按属性的结果和漏参提醒（1 / 6 = 16.7%）。

## 没验证的（交给用户测试）

- 浏览器里看页面：本地复制站点的登录被拦（销售账号要专属入口，管理员登录也没过），页面只用 WP-CLI 渲染过。手机宽度没看。
- MySQL：本地只有 SQLite。线 1 发现 SQLite 插件的 `GREATEST()` 对毫秒时间算错（`pc_ad_ads.last_seen_ms` 在本地不对），MySQL 上应该没问题但没实测。
- 真实网络：店铺和总后台之间没走真实 HTTP（本地禁止外发），签名请求是在总后台进程里原样送进 REST 的。
- 真实数据：没用投手的真实 Meta 导出文件核对中文表头；没用真实 GeoLite2 / DB-IP 库；没在真实浏览器里点广告链接；`ip_type` 名单没拿真实流量校对。
- 定时任务没真正等到触发（直接调用测试）。
- `mail-source-observations` 需要在有真实 WordPress 的环境跑。
- 合并进来的 m3、m6、m9 本身也没在运行中的 WordPress 里验过。

## 上线前还要做

- 把 GeoLite2-City / ASN（或 DB-IP lite）库放到服务器。
- F1 统一抬 `PEPTIDE_CORE_VERSION`、`PEPTIDE_HQ_VERSION`；在 HQ 为各店打开访客追踪。
- 投手培训：网址参数模板、广告名规则、每天导入花费。
