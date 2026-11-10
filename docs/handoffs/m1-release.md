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
