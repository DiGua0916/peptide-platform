# 总后台插件管理（2026-11-08）

用户要求：第三方插件（自己购买的）由总后台上传、按店铺分配，总后台要能管理所有站点；店铺后台不提供安装入口。

## 架构

| 部分 | 位置 | 作用 |
| --- | --- | --- |
| 总后台页面 | `peptide-hq/includes/admin/class-plugins-page.php`（总后台 → 插件管理） | 上传 .zip、插件库、每个店铺“安装并启用 / 更新 / 停用并卸载”、“新店自动安装”、从库删除（未安装时） |
| 总后台逻辑 | `peptide-hq/includes/class-store-plugins.php` | 校验 zip（一个顶层文件夹 = 插件标识、主文件带 Plugin Name、无危险路径、≤ 32 MB）、存包、排队、读回执；新店注册（`pchq_site_registered`）时自动排队 |
| 数据表 | `peptide-hq/includes/class-db.php` v5 | `pchq_plugins`（库：slug、名称、版本、sha256、大小、auto_new）、`pchq_site_plugins`（店铺 × 插件：state、版本、operation_id、错误） |
| root 任务 | `tools/deployment/provisioning/plugins.py`（由 `worker.py` 加载） | 处理 `<队列>/plugins/requests/*.json`；按 uuid 从 root 证据找店铺（第一家店：lifecycle inventory；后开的店：完成的开店任务 / 租户 wp-config）；两台服务器都解包到 `/srv/vialcove/store-plugins/<店>/<插件>/`（root 所有、PHP 只读），在店铺代码目录 `wp-content/plugins/<插件>` 建软链接；后台机 `wp plugin activate`；检查首页和购物车（≥500 或 critical error 就停用并报失败） |
| 跨服务器 | 现有 forced-command SSH（`lifecycle` 命令） | 新的 `phase: plugin` 信封（含 base64 包，上限 48 MB；其他请求仍 ≤ 16 KB） |

队列目录（root 任务每 20 秒创建 / 检查）：`/var/lib/vialcove-provision/plugins/{requests,packages}`（root:vchq 0730）、`results`（0750）、`rejected`（root 0700）。HQ 只写有界请求和以 sha256 命名的包，不带路径、用户或命令。

## 关键约束

- 插件不进发布目录：新版本用 `cp -a current next` 复制时软链接保留；店铺自带插件（peptide-core 等）不能被同名覆盖（保留名单 + 遇到真实目录拒绝）。
- 主店：前台 `/srv/vialcove/current`（vialweb01），后台 `/srv/vialcove/store-admin/current`（vcs0001）。后开的店：`tenants/sNNNN/public` / `tenant-admin/sNNNN/public`（vcsNNNN）。
- 开店模板不带第三方插件（`copy_runtime` 跳过软链接）；新店靠“新店自动安装”在注册后安装。
- 待办：店铺下线清理（lifecycle purge）时顺带删除 `/srv/vialcove/store-plugins/<店>`。

## 测试

- `python3 -I tools/deployment/provisioning/test_plugins.py`：10 项通过（包校验、请求校验、第一家店 / 租户两台机安装与卸载、健康检查失败自动停用、同名冲突拒绝、包被改动 / 未知店铺、畸形请求隔离、web 端身份核对）。现有 test_worker / test_lifecycle / test_backup 在 macOS 上的错误数与改动前一致（临时目录软链接导致，和本改动无关）。
- 本地一次性总后台（`localwp/hq`，SQLite）：v5 表创建；上传坏包被拒（提示一个顶层文件夹）、好包入库；“安装并启用”生成的请求文件通过 root 任务的校验；重复点击被拦（处理中）。

## 上线需要（未执行，需用户批准）

1. 两台服务器 `/usr/local/lib/vialcove-provision/`：新增 `plugins.py`、替换 `worker.py`（先备份）。
2. 总后台：新版本目录加 4 个文件（store-plugins、plugins-page、db、sites、plugin 共 5 个 PHP），切换后 DB 自动升到 v5。
3. 总后台 nginx（`vialcove-hq`）加 `client_max_body_size 36m;`（现在默认 1 MB，传不了插件）。
4. 在 s0003（未对外开放的测试店）用一个无害测试插件走一遍：上传 → 安装 → 启用 → 自检 → 卸载。
