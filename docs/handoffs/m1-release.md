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
