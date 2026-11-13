# M1-1 版本盘点结果（2026-11-10，F1）

采集时间 2026-11-10 14:15 UTC，比对基准 `6aece05`（主线 `codex/checkout-acceptance-20261105` 当时的最新提交）。全程只读：只在两台机器上执行了 `ls / readlink / find / sha256sum / grep` 和查询表名，没有写文件、没有重载服务。工具在 `tools/deployment/inventory/`，可以随时重跑。

## 结论

1. **生产上没有“仓库里没有的改动”。** 14 份店铺副本加总后台，共 15 份、约 1.4 万个文件逐个比对：没有任何一个文件的内容是仓库历史里找不到的，也没有多出来的文件。不需要往仓库补文件（但有一处仓库比线上“多”了不该有的代码，见第 4 条）。
2. **店铺核心代码是齐的。** peptide-core 14 份副本的 PHP、JS、CSS 与 `6aece05` 完全一致（日常会话在我盘点期间刚把 `6aece05` 推上去）。peptide-ops 15 份全部一致。
3. **有 22 个文件落后或缺失，都不影响现在的运行，但说明“按文件推”确实会漏：**
   - 后台机 7 份副本（6 家店后台 + 后台开店模板）的主题有 3 个文件停在 11-05 / 11-07 的旧版（`footer-details.css`、`inc/footer-details.php`、`inc/visitor-tracking.php`）：11-10 的“页脚同步到子站”只推了前台机。后台机不渲染前台页面，所以顾客看不到差别。
   - 后台开店模板的主题另有 4 个文件更旧（`footer.php`、`functions.php`、`header.php`、`home-hero.php`，最旧的是 10-27）。**新开的店，后台那一份会带着旧主题文件。**
   - 14 份店铺副本里的 peptide-panapay 有 5 个 Hub 专用文件是旧版，另缺 `class-hub-details.php`。这些文件只在总后台的支付 Hub 上运行，店铺上用不到。
   - 总后台的 `peptide-hq/includes/class-monitor.php` 停在 11-03 的版本。**这一条是反过来的：线上是对的，仓库是错的**，见下面第 4 条。总后台的 peptide-panapay 有 2 个店铺专用文件是旧版（总后台用不到）。
   - `peptide-core/tools/*.cjs`（3 个构建脚本）14 份都没有，前台机 7 份还少 2 个 README：都是开发文件，线上不需要。发布包以后要不要带它们，在 M1-5 里定（建议不带）。
   - `peptide-site/tools/mobile-preview.html` 14 份都是 10-27 的旧版（开发预览页，线上不需要）。
4. **发现一个埋在仓库里的事故，已修。** 11-08 试过“总后台轮询时跟随店铺自己的名称”，结果把 6 个店铺标签（地瓜、李军、阳…）全部覆盖成域名，当天在服务器上回退了文件、手工恢复了标签——但仓库里那段代码没删（提交 `35c15c7` 标题写的是“回退”，内容却是把它加了进去）。按文件推的时候没人再推这个文件，所以一直没事；**一旦改成整包发布，总后台一更新，6 个标签会在 10 分钟内再被覆盖一次。** 本次已在仓库里删掉这段，并补了回归测试 `peptide-hq/tests/m1-monitor-name-pure.php`（拿旧代码跑会失败，拿新代码跑通过）。
5. **运维代理（peptide-ops）目前一份也发布不了。** 15 份副本都装了代理，但：
   - 所有代码目录的属主是 root（`root:755`），而 PHP 以每家店自己的账号运行（`vcs0006`、`vialweb01` 等），代理没有权限替换插件目录；10 家子店副本和总后台还设置了“禁止文件修改”。
   - 没有任何一份配置了独立的运维密钥 `PEPTIDE_OPS_SERVER_KEY`；两份开店模板没有配置私有目录（模板不是运行中的站点，代理本来也够不着）。
   - 15 份副本的发布记录都是 0 条：`release.apply` 在生产上从来没有成功用过。
   - 所以 M1-5 的发布脚本按开发文档 11.2 的备选方案走：**由发布脚本通过 SSH 以 root 做整目录原子替换**（新版本放旁边，一次 rename 切换，保留最近 3 个旧版本），代理只保留备份等功能。代码目录属主是 root 本身是好事（站点被攻破也改不了代码），不建议为了让代理能发布而放开。
6. **副本的形态不统一，发布脚本要分别处理：** 主店前台和主店后台是“releases 目录 + current 软链接”（但目录名还叫 `23cf5a7-*`，之后一直在里面就地改文件）；5 家子店和两份模板是普通目录；总后台是“releases 目录 + current 软链接”，而且每次都真的换新目录（做法正确）。
7. MU 插件各副本不一样是正常的（主店有支付配对、后台有一键登录、模板有邮件闸门）；仓库管的那几个 MU 文件与仓库一致。

## 对后续任务的影响

- M1-2 沙盒刷新和第一次正式发布，会顺带把上面第 3 条的落后文件全部补齐，不单独处理。
- M1-6 的 `code_drift` 告警要按“发布包清单”比对，不是按整个仓库目录（否则开发文件会一直误报）。
- 在 M1-5 上线之前，日常会话仍在直接推生产（盘点期间就推了一次）。每次直推后可以用下面的命令复查有没有漏推。

## 怎么重跑

```bash
# hosts.json 放在仓库之外，格式见 tools/deployment/inventory/hosts.example.json
python3 tools/deployment/inventory/drift.py collect --hosts ~/.vialcove/hosts.json --out /tmp/inv
python3 tools/deployment/inventory/drift.py report  --raw /tmp/inv --ref origin/codex/checkout-acceptance-20261105 --out /tmp/inv
python3 tools/deployment/inventory/drift.py check   --raw /tmp/inv --ref HEAD   # 有漂移时退出码 1
```

---

以下是工具生成的明细。

## 明细

比对基准：提交 `6aece05`（`HEAD`）。采集方式：只读（`tools/deployment/inventory/collect.sh`），没有改动服务器。

共 15 份副本：`admin:s0001`、`admin:s0006`、`admin:s0007`、`admin:s0008`、`admin:s0009`、`admin:s0010`、`admin:template`、`admin:hq`、`web:s0001`、`web:s0006`、`web:s0007`、`web:s0008`、`web:s0009`、`web:s0010`、`web:template`。

### 1. 每份副本的结果

每格是“与仓库不一致的文件数 / 该组件文件总数”；`—` 表示这份副本没有这个组件。

| 副本 | 路径 | peptide-core | peptide-hq | peptide-panapay | peptide-ops | peptide-site | mu-plugins |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `admin:s0001` | `/srv/vialcove/store-admin/releases/23cf5a7-s0001` | 3 / 543 | — | 6 / 49 | 0 / 14 | 4 / 406 | 0 / 3 |
| `admin:s0006` | `/srv/vialcove/tenant-admin/s0006/public` | 3 / 543 | — | 6 / 49 | 0 / 14 | 4 / 406 | 0 / 2 |
| `admin:s0007` | `/srv/vialcove/tenant-admin/s0007/public` | 3 / 543 | — | 6 / 49 | 0 / 14 | 4 / 406 | 0 / 2 |
| `admin:s0008` | `/srv/vialcove/tenant-admin/s0008/public` | 3 / 543 | — | 6 / 49 | 0 / 14 | 4 / 406 | 0 / 2 |
| `admin:s0009` | `/srv/vialcove/tenant-admin/s0009/public` | 3 / 543 | — | 6 / 49 | 0 / 14 | 4 / 406 | 0 / 2 |
| `admin:s0010` | `/srv/vialcove/tenant-admin/s0010/public` | 3 / 543 | — | 6 / 49 | 0 / 14 | 4 / 406 | 0 / 2 |
| `admin:template` | `/srv/vialcove-provision-template/yy-sync-0532574-1794082452-admin/source` | 3 / 543 | — | 6 / 49 | 0 / 14 | 8 / 406 | 0 / 3 |
| `admin:hq` | `/srv/vialcove/hq/releases/ai-notes-1794313895-hq/public` | — | 1 / 79 | 2 / 50 | 0 / 14 | — | 0 / 6 |
| `web:s0001` | `/srv/vialcove/releases/23cf5a7-web01` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 2 |
| `web:s0006` | `/srv/vialcove/tenants/s0006/public` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 1 |
| `web:s0007` | `/srv/vialcove/tenants/s0007/public` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 1 |
| `web:s0008` | `/srv/vialcove/tenants/s0008/public` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 1 |
| `web:s0009` | `/srv/vialcove/tenants/s0009/public` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 1 |
| `web:s0010` | `/srv/vialcove/tenants/s0010/public` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 1 |
| `web:template` | `/srv/vialcove-provision-template/yy-sync-0532574-1794082452-web/source` | 5 / 541 | — | 6 / 49 | 0 / 14 | 1 / 406 | 0 / 2 |

### 2. 副本之间是否一致

同一组件按整目录哈希分组；只有一组说明所有副本互相一致。

- **peptide-core**：2 组
  - `e722afc85f22`：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template
  - `e2ef8554fc1f`：web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template
- **peptide-hq**：1 组
- **peptide-panapay**：2 组
  - `19baca282a7f`：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template
  - `0cf8e58c0361`：admin:hq
- **peptide-ops**：1 组
- **peptide-site**：3 组
  - `8badfd0bbd08`：web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template
  - `a529c704662f`：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010
  - `8198365ff3c8`：admin:template
- **mu-plugins**：7 组
  - `30d3895d0265`：admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010
  - `582ca61b6158`：web:s0006、web:s0007、web:s0008、web:s0009、web:s0010
  - `d657986f02b9`：admin:s0001
  - `4b5b02f9f70e`：admin:template
  - `b923199f6127`：admin:hq
  - `d08448dc9c38`：web:s0001
  - `66d0cd9b1838`：web:template

### 3. 运维代理（peptide-ops）覆盖情况

| 副本 | 装了代理 | 配了私有目录 | 配了独立密钥 | 已有发布记录数 | plugins 目录属主:权限 | 禁止文件修改 |
| --- | --- | --- | --- | --- | --- | --- |
| `admin:s0001` | 是 | 是 | 否 | 0 | `root:755` | 否 |
| `admin:s0006` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `admin:s0007` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `admin:s0008` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `admin:s0009` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `admin:s0010` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `admin:template` | 是 | 否 | 否 | 0 | `root:755` | 否 |
| `admin:hq` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `web:s0001` | 是 | 是 | 否 | 0 | `root:755` | 否 |
| `web:s0006` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `web:s0007` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `web:s0008` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `web:s0009` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `web:s0010` | 是 | 是 | 否 | 0 | `root:755` | 是 |
| `web:template` | 是 | 否 | 否 | 0 | `root:755` | 否 |

### 4. 不一致的文件

#### 落后（内容等于仓库的旧版本）（16 项）

| 文件 | 哪些副本 | 线上哈希 | 对应提交 |
| --- | --- | --- | --- |
| `peptide-hq/includes/class-monitor.php` | 1 份：admin:hq | `302ea0512b58` | bf9dcf2 2026-11-03 |
| `peptide-panapay/assets/hub.css` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `f5118bba5e19` | 3f2d470 2026-11-07 |
| `peptide-panapay/assets/hub.js` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `ab02202a901a` | e655a45 2026-11-03 |
| `peptide-panapay/includes/class-callbacks.php` | 1 份：admin:hq | `4434c283422e` | 154a507 2026-11-05 |
| `peptide-panapay/includes/class-hub-accounts.php` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `7a89b6d98854` | 0f774c4 2026-11-07 |
| `peptide-panapay/includes/class-hub-page.php` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `e2aa5c96ce82` | 0f774c4 2026-11-07 |
| `peptide-panapay/includes/class-pending-orders.php` | 1 份：admin:hq | `df7e1d7bf605` | 47e5437 2026-11-03 |
| `peptide-panapay/includes/class-provider-client.php` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `4dbafed28ceb` | d723971 2026-11-03 |
| `peptide-site/assets/footer-details.css` | 7 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template | `13e033a20a77` | 9f7947a 2026-11-05 |
| `peptide-site/footer.php` | 1 份：admin:template | `fb28590ad367` | dad2dac 2026-11-06 |
| `peptide-site/functions.php` | 1 份：admin:template | `b9c0e086da43` | 55a3cdd 2026-11-07 |
| `peptide-site/header.php` | 1 份：admin:template | `a4e00516bed9` | a884aaa 2026-10-30 |
| `peptide-site/inc/footer-details.php` | 7 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template | `0cf2292d1e57` | e25bb45 2026-11-05 |
| `peptide-site/inc/visitor-tracking.php` | 7 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template | `43626be10dcf` | 808669d 2026-11-07 |
| `peptide-site/template-parts/home-hero.php` | 1 份：admin:template | `a839e75c82fb` | f102fd1 2026-10-27 |
| `peptide-site/tools/mobile-preview.html` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `7cdd09c9fe59` | f102fd1 2026-10-27 |

#### 仓库有、线上缺（6 项）

| 文件 | 哪些副本 | 线上哈希 | 对应提交 |
| --- | --- | --- | --- |
| `peptide-core/assets/woocommerce-dialogs/README.md` | 7 份：web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `` | — |
| `peptide-core/assets/wordpress-dialogs/README.md` | 7 份：web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `` | — |
| `peptide-core/tools/build-woocommerce-client-dialogs.cjs` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `` | — |
| `peptide-core/tools/build-woocommerce-dialogs.cjs` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `` | — |
| `peptide-core/tools/build-wordpress-dialogs.cjs` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `` | — |
| `peptide-panapay/includes/class-hub-details.php` | 14 份：admin:s0001、admin:s0006、admin:s0007、admin:s0008、admin:s0009、admin:s0010、admin:template、web:s0001、web:s0006、web:s0007、web:s0008、web:s0009、web:s0010、web:template | `` | — |

