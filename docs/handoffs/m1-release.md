# M1 发布与测试 / M2 安全与连接 — 交接（F1）

分支 `claude/m1-release-6edf1f`。依据：开发文档 v1.1 第 7 节 NF-05、第 11–13 节。

## 2026-11-10 第一批

### 做了什么

1. **M1-1 版本盘点**（只读）。结果和结论：`docs/handoffs/m1-inventory-2026-11-10.md`。要点：
   - 生产 15 份副本没有“仓库里没有的改动”；22 个文件落后或缺失，都不影响运行。
   - 发现并修掉一个埋在仓库里的事故：`hq/class-monitor.php` 里“轮询时用店铺名覆盖总后台标签”的代码，11-08 在服务器上回退过但仓库没删，整包发布会让它复发。
   - 运维代理 `release.apply` 在生产上一份也用不了（代码目录属主 root、没有独立密钥、0 条发布记录）→ M1-5 用 SSH 做原子替换。
   - 工具：`tools/deployment/inventory/collect.sh`（远端只读采集）、`drift.py`（collect / report / check）。
2. **本地测试环境** `tools/local-env/`：`setup.sh`、`router.php`、`seed-local.php`、`mu-plugins/vialcove-local-safety.php`（邮件不外发、外网拦截、拒绝未脱敏的库）、`import-snapshot.sh`、`bundle-pure-test.py`。说明见 `tools/local-env/README.md`。
3. **脱敏数据快照** `tools/local-env/snapshot/snapshot.py` + `test_snapshot.py`。只在数据库服务器上运行，生产库只读；未登记的表拒绝导出；出文件前做泄漏检查。
4. **两个公共钩子**（写进 `docs/CONTRACT.md` 末尾“M1 / M2 基础”一节）：
   - 店铺端 `pc_hq_capabilities` → `/hq/status` 的 `capabilities`；总后台 `Client::capabilities()` / `Client::supports()`。
   - 总后台 `pchq_monitor_checks`（`Monitor::module_checks()` / `apply_checks()`），告警显示名 `pchq_alert_types`。
5. 主线同步：合入 `origin/codex/checkout-acceptance-20261105` 到 `6aece05`（快进）。

### 改动文件

- 新增：`tools/deployment/inventory/{collect.sh,drift.py,hosts.example.json}`、`tools/local-env/**`、`wp-content/plugins/peptide-core/tests/m1-hq-capabilities-pure.php`、`wp-content/plugins/peptide-hq/tests/m1-monitor-checks-pure.php`、`m1-monitor-name-pure.php`、`docs/handoffs/m1-inventory-2026-11-10.md`、本文件。
- 修改：`core/class-hq-connector.php`（加 `capabilities()`、`clean_capabilities()`，status 多一个字段）、`hq/class-client.php`（加两个只读方法）、`hq/class-monitor.php`（加模块巡检；删反向改名）、`hq/class-alerts.php`（`types()` 加过滤器）、`tests/data-observations-suites.json`（追加 3 条）、`docs/CONTRACT.md`（追加一节）。
- 没有改表结构、没有抬版本号（还没发布；第一次正式发布时抬 peptide-core 和 peptide-hq）。

### 实际验证

- `python3 tools/local-env/snapshot/test_snapshot.py`：11 项通过（本机）。
- 三个 PHP 纯逻辑测试：8 + 17 + 7 项通过。本机没有 PHP，是用 `bundle-pure-test.py` 拼成一个流、经 SSH 交给后台机的 PHP 8.3.6 从标准输入执行的（没有在服务器上写文件）。回归测试做了反证：换回旧的 `class-monitor.php` 会失败。
- 改过和新增的 PHP 文件全部 `php -l` 通过（同样经标准输入）。
- `drift.py` 对两台生产机实际跑通（采集 7079 + 7249 个文件）。
- **没有验证的**：
  - `setup.sh` / `import-snapshot.sh` 没有实际运行（本机没有 PHP、MySQL、Node、Docker、Homebrew），只做了 `bash -n`。
  - `snapshot.py export` 的 SQL 没有在真实 MySQL 上执行（要在生产数据库服务器上建临时库，等平台所有者同意）。
  - 钩子没有在真实 WordPress 里跑过（没有部署）；`tests/run-data-observations.cjs` 没跑（没有 Node）。
  - 生产上没有任何改动。

### 环境说明

- 这台 Mac 上没有 `D:/Codex/Projects/...`，项目聊天室（`room.py`）用不了。开工、完工登记写在会话共享笔记目录的 `F1-m1-status.md`。
- `drift.py` 用的 `hosts.json`（主机、用户、私钥路径）放在仓库之外，不入库；格式见 `tools/deployment/inventory/hosts.example.json`。

### 需要平台所有者定的事

1. 同意拉脱敏快照吗？建议顺序：先对沙盒库 `vc_sandbox_store` 跑 `--report-only`（只在后台机 MySQL 上建一个临时库、用完即删），确认 SQL 和泄漏检查没问题；再对一家子店和总后台正式导出。
2. 这台 Mac 要不要装 PHP（本地站和本地跑测试需要）？不装的话，测试统一放沙盒跑。
3. 试点店用哪家（建议近 7 天访客最少的子店，M1-5 之前定）。

### 下一步

- M1-2 沙盒刷新（代码到当前主线、导入脱敏快照）→ M1-3 测试基线（让编排器认 `status: skipped`，在沙盒跑全部测试、出失败清单）→ M1-4 `critical-path` 和 `prod-check` → M1-6 `/hq/status` 上报组件版本和清单哈希、`pchq_releases`、`code_drift` → M1-5 `release.py` → M1-7 → M1-8。
- M1-6 的清单要按“发布包里的文件”算，开发文件（`tests/`、`tools/*.cjs`、README）不算漂移。
- `prepare_release.py` 现在把 `peptide-core/tools/*.cjs`、`peptide-site/tools/mobile-preview.html` 也打进包，M1-5 时决定是否排除。

### 给其他会话

- 功能声明和巡检的用法见 `docs/CONTRACT.md`“M1 / M2 基础”一节。新功能键、新巡检类型、新数据表（脱敏怎么处理）写在各自那节，F1 汇总。
- 测试条目追加到 `tests/data-observations-suites.json` 末尾，文件名带模块前缀。
- 日常会话请注意：`35c15c7` 那次“回退”只回退了服务器，仓库没回退。以后在服务器上回退文件，仓库要同步回退。

## 2026-11-10 补充：脱敏快照沙盒试跑（平台所有者已同意）

- 在后台机对 `vc_sandbox_store`、`vc_sandbox_hq` 以 `--report-only` 运行 `snapshot.py export`：复制到临时库 → 脱敏 → 泄漏检查 → 删除临时库，不出文件。SQL 在 MySQL 8.0.46 上全部执行成功。
- 第一次查出 `wp_usermeta` 的 `_wc_email_verified_*` 里有邮箱；已加规则（usermeta 里键名含 email 的，除 `billing_email` 外一律删除），泄漏报告改为按键名分组。复跑两库均干净，`vcsnap_*` 临时库无残留。
- 服务器上留下的唯一东西：空目录 `/root/vialcove-snapshots`（0700）。
- 还没做：对真实店铺库和总后台库导出（需要再次确认）；沙盒数据少，脱敏对真实数据的覆盖面要到那时核对。

## 2026-11-10 补充：第一次正式导出脱敏快照（平台所有者已同意）

- 先 `--report-only`，再正式导出。子店 s0010（`vc_s0010`，72 张表）和总后台（`vialcove_hq`，23 张表）。
- 真实数据查出并补上的遗漏：选项 `auto_core_update_notified`（管理员邮箱）、`pc_email_backup_*`（改为名字带 backup 的选项一律不带）；另外主动去掉 `pc_product_broadcast_v1`（商品播报姓名池，分不清有没有真实顾客名）和 `pc_ai_auto_* / pc_ai_tune_* / pc_ai_notes* / pc_ai_resolved`（AI 分析和店主备注）。
- 导出文件（留在各自服务器上，root 0600，**没有下载到开发机**）：
  - 前台机 `/root/vialcove-snapshots/vc_s0010-sanitized-20261110T150844Z.sql.gz`（730 KB，sha256 `d4aafe2d422e614c…`）
  - 后台机 `/root/vialcove-snapshots/vialcove_hq-sanitized-20261110T150749Z.sql.gz`（26 KB，sha256 `cdac2b6e19b03f6f…`）
- 导出后在服务器上核对：文件里的邮箱只有 `@example.test` 和店铺自己的 `@vialcoveplus.com`；已知的 7 个真实顾客姓名 0 次命中；电话只剩店铺公开的 WhatsApp；地址、账号抽样为假数据且保留了国家（PR）；支付、邮件、AI、像素、连接密钥相关选项不在文件里；临时库无残留。生产库只被读。
- 局限：姓名和地址没有通用的自动检查（只能靠表清单和抽查）。其他店导出前仍要先跑 `--report-only`。
- 下一步：M1-2 把 s0010 快照导入沙盒（会改沙盒库，做之前再确认）。

## 2026-11-10 M1-2 沙盒刷新（平台所有者已同意）

- 新脚本 `tools/sandbox/refresh.sh`（后台机 root 运行，只碰 `/srv/vialcove-sandbox` 和 `vc_sandbox_store`）：`code <发布包>` 整目录替换三个站的组件，`data <快照> <原前台地址> <原后台地址>` 导入脱敏快照并恢复沙盒自己的地址、管理员、总后台连接和测试付款，`check` 做 8 项健康检查。两步都先备份，健康检查不过自动换回。
- 代码：`prepare_release.py` 从提交 `d0f24cf`（主线 `1fcfea5` + 本分支 M1 改动）打包，1103 个文件，包 sha256 `71cc2272…`。沙盒三个站替换后：peptide-core 0.32.0、peptide-panapay 0.4.7（原 0.4.6）、peptide-hq 0.3.4（原 0.3.3）、peptide-ops 0.2.0、主题同步。旧代码在 `/var/lib/vialcove-sandbox/backups/refresh-20261110T152512Z/code`。
- 数据：导入 s0010 脱敏快照。沙盒现在有 5 张订单、92 个已发布商品、s0010 的优惠 / 运费 / 装修设置；账号只剩沙盒管理员。导入前的沙盒库在 `/var/lib/vialcove-sandbox/backups/refresh-20261110T152550Z/vc_sandbox_store.sql.gz`。
- 图片：快照引用的 413 个媒体文件里沙盒缺 20 个（s0010 自己上传的商品图和视频，公开内容），已从前台机 s0010 的 uploads 复制进沙盒，现在缺 0 个。
- 实际验证（都在后台机本机执行）：
  - 8 项健康检查替换前、替换后、导入后全部正常；`/product/<slug>/` 302 到 `/?vc_product=…`，与生产 s0010 的行为一致。
  - 沙盒保险还在：可用付款方式只有 `cod`；响应头 `x-vialcove-environment: sandbox`；页脚 SANDBOX 标记；MU 守卫文件没动（邮件拦截、外网拦截）。
  - **两个公共钩子第一次在真实 WordPress 里跑通**：沙盒店铺 `/hq/status` 返回 `capabilities: {"hq.capabilities":"1"}`；沙盒总后台 `Monitor::poll(1)` 成功，`Client::supports(1,'hq.capabilities')` 为真，未处理告警 0 条；总后台里的店铺名称仍是 “VialCove SANDBOX”，没有被店铺名覆盖。
  - FPM 日志无 fatal / error。
- 没验证的：没有用浏览器登录沙盒后台（这台机器上没有沙盒的 Basic 认证和管理员密码）；没有走下单流程（M1-4 的事）；沙盒总后台库没有导入 HQ 快照（保留它自己的一家沙盒店，导入生产 HQ 数据会把 6 家真实店铺登记进沙盒总后台，没有必要）。
- 遗留：沙盒里 4 个 WooCommerce 邮件设置还写着 `@vialcoveplus.com` 的收件地址和 “VialCovePlus” 发件名（邮件被守卫拦截，不会发出）。
- 顺带修了一个脚本错误：`gzip -dc … | grep -q` 在 `pipefail` 下会误判（`import-snapshot.sh` 同样修了）。
- 在生产机上留下 / 清理的东西：后台机 `/root/vialcove-snapshots/` 里多了一份 s0010 快照的副本（root 0600）；前台机临时脚本 `/tmp/m1-pack.sh` 用完已删；后台机 `~/m1-sandbox` 用完已删。

### 沙盒以后怎么刷新

```bash
python3 tools/deployment/prepare_release.py --output <仓库外的目录> --ref <提交>
scp <发布包.zip> tools/sandbox/refresh.sh ubuntu@<后台机>:m1-sandbox/
ssh ubuntu@<后台机> 'sudo bash ~/m1-sandbox/refresh.sh code ~/m1-sandbox/<发布包.zip>'
```

## 2026-11-12 主线同步、沙盒刷新、M1-3 测试基线

- 主线合入到 `976c1fa`（本分支 `49482b9`）。主线改到 F1 名下的只有 `hq/class-alerts.php`，自动合并，`pchq_alert_types` 过滤器还在。
- 沙盒三个站更新到 `12b0f63`（主线 `976c1fa` + M1 改动），1122 个文件，8 项健康检查通过，FPM 日志无 fatal。此前放在沙盒上的 F5 候选代码已被替换（F5 要等它同步主线后复验）。沙盒店铺库仍是 11-10 的 s0010 脱敏快照。
- 脱敏规则跟上主线新功能：生产库没有新表（两台机器只读核对：店铺 74 张、总后台 23 张，与 11-10 相同）；新增处理的是选项和订单资料——手动订单的付款链接和草稿、搜索索引、物流单号（换成假单号）、付款人联系方式、卖家 / 来源备注、付款参考号、像素上下文、关怀邮件记录、17TRACK 和 FedEx 的密钥与令牌。离线自检 11 项通过。**新规则还没在真实库上跑过**，下次导快照时先 `--report-only`。
- 本机现在能直接跑测试：`../tools/php/php`（原生 PHP 8.3.32）和 `../tools/node-v26.13.0-darwin-arm64/bin/node`。

### M1-3 测试基线

**纯逻辑和前端用例（编排文件，本机跑）**
- 命令：`node tests/run-data-observations.cjs --php <php 路径>`。新增 `--include-skipped`（连跳过的一起跑）、`--module M4`（只跑某模块登记的）。
- 编排器现在认 `status: "skipped"`，必须同时写 `skip_reason` 和 `owner`，否则报错。
- 基线（`49482b9`）：54 个默认用例，**45 过 / 0 败 / 9 跳过**。跳过的 9 个在主线上本来就失败：

| 用例 | 归属 | 原因 |
| --- | --- | --- |
| data-product-user-ratios-node | F4 | “十个比率都显示百分比”断言失败 |
| data-native-search-pure | F4 | “八步都用按访客绑定的映射”断言失败 |
| data-product-action-rankings-pure | F4 | 同上 |
| tracking-product-actions-pure | F4 | 调用了已不存在的 `Tracker::is_automated()` |
| experiment-data-pure | F4 | “分析菜单保留旧 10 项”断言失败 |
| visitor-product-actions | F5 | 主题用例：“正好发出三个显式动作”失败（11-11 之后新出现） |
| visitor-intents | F5 | 主题用例：两个“原生锚点排队意图”子用例失败（11-11 之后新出现） |
| mail-source-observations | 日常会话 | 退出码 2、没有输出 |
| tracking-store-api-cart-success-pure | 日常会话 | 加载不到 `PeptideCore\Recovery` 类 |

**冒烟和接 WooCommerce 的用例（沙盒跑）**
- 新脚本 `tools/sandbox/run-smoke.sh`：先备份沙盒两个库，（`SEED=1` 时）写入演示数据，逐个跑 `smoke-*.php` 和 `*-native.php`，跑完恢复数据库。`GATE=tests/smoke-suites.json` 只跑门槛用例，有失败退出码 1。
- 新目录文件 `tests/smoke-suites.json`：82 个用例的基线状态（`12b0f63`，带演示数据）：
  - **gate 22 个**：通过，以后发布前必须通过。
  - **skipped 28 个**：基线时就失败。按名字初步归属：F4 12、日常会话 7、F2 5、F5 2、F1 2（`smoke-hq-connector`、`smoke-hq`，我自己查）。其中 7 个 `smoke-data-*` 只是找不到它们要的“fixture product”，不是功能坏了。
  - **local-only 32 个**：用例自带“只能在本地隔离环境运行”的保护，在沙盒直接拒绝执行。这些要在本地站跑，沙盒门槛不含它们。
- 不带演示数据时只有 12 个通过；差的 10 个都是找不到测试商品。
- 两次冒烟跑完后沙盒库已恢复（健康检查 8 项正常，商品、订单、账号数与跑前一致）。

### 还没做
- skipped 的 28 个冒烟用例没有逐个分析根因，只记录了第一条失败信息；归属是按文件名猜的，需要各会话认领。
- local-only 的 32 个没有在本地跑（本地站要 WooCommerce + 数据库，`tools/local-env/setup.sh` 现在有 PHP 可以试了，下一步做）。
- M1-4 之后的任务、F2 / F3 / F4 的沙盒验收、F5 复验都还没开始。

## 2026-11-12（二）四条分支合并验收、接线登记、两项生产只读核对

- 合并验收：`docs/handoffs/m1-sandbox-acceptance-all-2026-11-12.md`。五条分支代码零冲突；合并后默认套件 80 过 / 0 败 / 9 跳过；F2、F3、F4 手册里服务器上能跑的步骤全部通过。
- 接线（本分支）：
  - `/hq/summary` 每天加 `abandoned / abandoned_open / recall_emailed / recall_recovered`（有 M6 时）；`/hq/status` 加 `modules`（`pricing_engine`、`onboarding` 和过滤器 `pc_hq_status_modules`）。
  - 总后台内置巡检 `pricing_mismatch`（代 F2 实现）。
  - 脱敏登记：`pc_track_ads` 保留；`pc_m4_shadow_*` 清空；`pchq_series*`、`pchq_ad_spend` 保留；`pchq_orders.email_hash` 重新加盐；`pchq_refunds.note` 清空；`pchq_sites.buyer_name` 改成“投手 N”；`pchq_series_reserved` 不带；各模块开关在快照里一律设回 off。新列只在存在时处理（老库不会报错）。
  - CONTRACT 的功能键表（16 个）、巡检类型表（4 个）、2b / 2c 两小节。
  - 测试：`m1-hq-capabilities-pure` 15 项、`m1-monitor-checks-pure` 26 项、快照自检 14 项，本机通过。**这批接线还没在沙盒的真实 WordPress 里跑过**（沙盒上的候选是接线之前打的包）。
- 还没接的：F5 要的 `/hq/staff` 带 `media_buyer`（NF-05 H 要删这个接口，放到 M2 一起定）；F2 要的总后台三档开关页面和 F4 要的远程换档通道（要动总后台页面，归日常会话 / 待定）；新店默认 `pc_media_buyer_default`。

### 试点店建议值

按“近 7 天访客最少的子店”：**李军 s0006（vialcovepro.com）**，近 7 天访客 54、订单 0（总后台每日汇总，2026-11-12 只读查询）。次少是老毛 s0009（55），但它开着流量斗篷（见下），不适合做验收。发布脚本按 s0006 准备；真正发布前仍要平台所有者确认。

| 店 | 近 7 天访客 | 订单 |
| --- | --- | --- |
| 李军 s0006 | 54 | 0 |
| 老毛 s0009 | 55 | 0 |
| 地瓜 s0001（主店） | 59 | 2 |
| 光辉 s0008 | 81 | 0 |
| 芒果 s0010 | 230 | 2 |
| 阳 s0007 | 395 | 1 |

### 生产只读核对一：加购推荐

6 家店各执行 `wp eval 'var_dump( \PeptideCore\Activity\Quote_Cart::supported_hooks() );'`：**6 家全部是 `bool(false)`**。按 F2 的说明，这表示主线的手动订单功能让加购推荐在所有店都不出现了。沙盒（同一份主线代码）也是 false。只读，没有改任何东西；原因和修法在 F2 的 CONTRACT M4 一节“给日常会话”第 1 条，归日常会话。

### 生产只读核对二：流量斗篷和跳转

| 店 | `acloak_options` | 里面的 `enabled` | 从这台机器访问首页（手机 / 桌面浏览器标识） |
| --- | --- | --- | --- |
| 地瓜 s0001 | 没有 | — | 200 / 200 |
| 李军 s0006 | 有 | 没有这一项 | 200 / 200 |
| 阳 s0007 | 有 | 没有这一项 | 200 / 200 |
| 光辉 s0008 | 有 | 没有这一项 | 200 / 200 |
| 老毛 s0009 | 有 | `1` | 200 / **302 → https://shiyishop.com/** |
| 芒果 s0010 | 有 | 没有这一项 | 200 / 200 |

- vialcovepro.com（李军）现在**没有**在跳转（两种浏览器标识都是 200）。
- 5 家子店都有 `acloak_options`，跳转目标都填的是 `https://shiyishop.com`，并开着按机房 IP 和浏览器标识拦截；但只有老毛 s0009 有 `enabled = 1`，也只有它在实际跳转：桌面浏览器标识被 302 到 shiyishop.com，手机标识正常。
- 这是从一台美国机房 IP 的机器看到的，真实顾客（住宅 IP、手机）看到什么可能不同。只看了首页。没有改任何设置，怎么处理由平台所有者定。

### 归 F1 的两个冒烟用例

`smoke-hq-connector`（7 过 26 败）、`smoke-hq`（13 过后 LogicException）：还没查，排在 M1-4 之前。

## 2026-11-12（三）差异报告的第三条路：F3 的只读导出脚本，沙盒试跑通过

- F3 给了不用先发布新代码、也不用拉快照的办法（它分支 `0c9d2cd` 的 `tools/catalog/store_products_dump.php`，演练文档第 5b 节）：在每家店用 `wp eval-file` 跑一次，临时载入它的一个类文件（不放进插件目录、不注册钩子），把商品、款式、分类、图片哈希、运费方案写成一个 JSON；之后离线比较。
- F1 审过脚本：除了写输出文件没有别的写入；自带“写入语句计数”；输出已存在时拒绝覆盖。
- **沙盒试跑**（先把沙盒换回“主线 + M1”，系列类不在，和生产店一样；MySQL，92 个商品）：导出 92 个；**写入语句 0 条**；导出前后选项、文章、文章附加数据、任务队列的行数和最大编号相同；耗时 1.6 秒，内存峰值 193 MB；再跑一次拒绝覆盖；文件 2 MB，里面没有邮箱，没有订单 / 顾客 / 账号 / 密钥字段，只有店名和域名。
- **在生产上跑仍然要平台所有者同意**：它是在生产店里执行一段没发布的代码（只读）。同意后的做法：6 家店各跑一次，文件留在服务器上，离线比较出报告，报告交平台所有者确认，不执行同步。母版店用哪家也要他定（F3 的文档默认 s0001）。
- 其他：登记了主线 11-12 的 `pchq_customer_links`（客户编号重新加盐、打码邮箱和设备摘要清空）、`pchq_orders.customer_id`、订单和账号上的 `_pc_customer_id`、F3 的 `pchq_abandoned`（保留）；总后台轮询后触发 `do_action( 'pchq_summary_days', $site_id, $days, $summary )`，供模块存每日汇总里的字段。快照自检 15 项、默认套件 45 过 0 败 9 跳过。
- 沙盒现在：`2c18514`（主线 `7c6d2d3` + M1，含上一批接线）。合并候选已撤下，F3 的新提交 `0c9d2cd`（`pchq_m10_db` 升到 2）下次换候选时重跑它的第 2、4 步。

## 2026-11-12（四）M1-4：critical-path 套件和 prod-check 脚本

主线同步到 `bd4e355`（加购推荐修复）。沙盒刷新到 `0a48099`（主线 + M1）后核对：`Quote_Cart::supported_hooks()` 在沙盒上是 `bool(true)`。

### 归 F1 的两个冒烟用例（已查明，都是用例过时）
- `smoke-hq-connector`：向前台域名发签名请求、总后台地址写 http、不带防重放参数——双域名之后必然被拒。
- `smoke-hq`：清理时直接 `Sites::delete()`，11-03 起必须走 `Site_Lifecycle::request`，抛 LogicException。
- 两个都继续跳过，M2 改注册和密钥时重写；连接这条链路现在由 critical-path 覆盖。

### critical-path（`tools/sandbox/critical_path.py`，发布流水线 G1）
在后台机上跑：`python3 critical_path.py --out result.json`（旁边要有 `accept/cookie.php`）。只认沙盒。会下几张货到付款的测试订单、建一张临时九折码，结束时全部删掉。有失败退出码 1。

| 链路 | 检查点（共 47 项） |
| --- | --- |
| 前台打开 | 首页、商品链接、专辑页、购物车、我的账户：200、无 PHP 报错、有“仅供研究”声明 |
| 加购与优惠 | 加购推荐的钩子都认得；样例 1（一种商品 2 件）商品金额 = 单价 × 2；不存在的码被拒；样例 2（两种商品 + 临时九折码）码生效、减的钱不超过 10%、总额变小；样例 3（波多黎各地址）有运费、选中的运费计入总额；三个样例都核对“总额 = 商品 − 优惠 + 运费 + 税” |
| 结账 | 正常下单成功、订单金额 = 结账页总额、状态正确、不是测试单；收件信息全填 1 → 能下单、被标记为测试单、**没有任何邮件记录** |
| 弃单 | 未付款订单进弃单列表；同一位顾客两张只显示一条；测试单不进；付款帮助信进了发件箱；同一位顾客只发一封 |
| 追踪 | 接收接口已注册；格式不对的事件被拒绝而不是报错。**8 步漏斗顺序：跳过**（事件由浏览器脚本上报，要 F4 提供可回放的夹具） |
| 像素 | **跳过**（沙盒没有像素配置；在试点店按 F4 的清单验） |
| 后台 | 店铺后台 12 个主菜单页；手动订单、顾客账户、弃单、邮件、订单列表、新订单详情（正常单和测试单各一）；总后台导航里的全部页面：200、无 PHP 报错 |
| 总后台连接 | 轮询成功；状态里有功能声明和模块简报；每日汇总 7 天；模板接口签名通过；错误密钥被拒；未签名被拒；一键登录链接能用且只能用一次 |

**沙盒实跑（`0a48099`）：通过 45，失败 0，跳过 2。** 第一次跑出 8 个失败，全是套件自己的问题（加购接口返回 201、弃单要离开满 5 分钟才算、总后台不用 WordPress 左侧菜单），改完复跑通过；没有发现站点问题。

这一版和开发文档 11.3 的差距：优惠的“预期总价”没有写死三个数字（沙盒数据会换），改成了内部一致性加九折码上限；满减和运费折扣的精确金额由 F2 的 24 组合走查覆盖（合进主线后接进来）；追踪和像素两条要浏览器。还没做反证（人为弄坏一处看它拦不拦得住），放在 M1-8 演练里。

### prod-check（`tools/deployment/prod_check.py`，G2 / G3）
从外面像顾客一样访问，只用标准库。
- **快速版** `--quick`（只读，G3 每家店替换后跑）：首页（含声明）、商品接口、商品页、购物车、结账页、后台登录页、总后台接口拒绝未签名请求，共 7 项。**已对生产 6 家店各跑一遍：全部 7 / 7 通过**（只发了 GET 请求）。
- **完整版**（G2 试点店）：在快速版之后加购 → 收件信息全填 1 → 下单 → 跟着支付通道的地址走到付款页，确认有刷卡框；不填卡、不付款；打印订单号。**会在店里留下一张待付款的测试单，所以只在平台所有者确认过发布之后跑。**
  - 在沙盒上用货到付款实跑：16 项通过（测试单已删）。
  - **没有在生产上跑过**：真实支付通道那几步（付款方式是不是 `pc_panapay`、返回的付款地址、页面里有没有卡号框）是按代码和文档写的，第一次跑可能要调识别规则。
- 读操作遇到正文被中途掐断会重试（这台机器出网经过代理时出现过）；写操作不重试。
