# VialCove 运维代理

此插件由一个稳定的 MU 加载器引入，提供管理后台签名调用、独立 CLI 任务、站点备份与恢复、插件和主题版本交换、受控新店创建。它不修改 WordPress 或 WooCommerce 核心。

## 安装与配置

将本目录作为真实目录部署到 `wp-content/plugins/peptide-ops`，并创建 `wp-content/mu-plugins/vialcove-operations.php`：

```php
<?php
defined('ABSPATH') || exit;
$agent = WP_PLUGIN_DIR . '/peptide-ops/peptide-ops.php';
if (is_readable($agent)) { require_once $agent; }
```

在该站 `wp-config.php` 中配置 `PEPTIDE_OPS_PRIVATE_DIR` 为站点公开根目录外的绝对私有路径。限制目录访问，只给站点运行账号读写。不要把私有目录、配置、SQL、归档、凭据或任务日志提交到 Git。正常店铺使用既有 `peptide_core_hq` 中的独立连接密钥和管理后台绑定。

独立部署代理另外配置 `PEPTIDE_OPS_SERVER_KEY`（服务器生成的 64 位随机十六进制密钥）及 `PEPTIDE_OPS_HQ_URL`（唯一管理后台 URL）。在管理后台“站点运维 → 部署环境”验证连接。正式代理使用 HTTPS。不要在聊天中提供密钥。

私有目录 `config.json` 的 `tools` 配置 `php`、`php_ini`（可选）、`wp_cli`、`python`、`mysqldump`、`mysql` 的绝对路径。需要 PHP 8.1+、ZIP、MySQLi、mbstring、受控 `proc_open` 及上述工具。命令按参数数组执行，不拼接 shell。WordPress 运行账号必须有私有目录写权限和本站文件访问权限；恢复还需数据库导入权限。

开发站的源码目录链接可在服务器配置 `allowed_source_roots` 白名单后备份为普通文件。自动版本交换和整站恢复拒绝开发目录链接，避免覆盖工作树。正式运行应部署真实目录。

## 新店环境

私有 `profiles.json` 为 `{"profiles":{"环境ID":{...}}}`。页面请求只传环境 ID、站点标识、名称、网址和管理员身份；不能传任意路径、数据库连接或命令。

每个受控环境由服务器管理员配置：

- `enabled`、`label`、`environment`，以及 `driver`（`php-server` 或 `webserver`）。
- `managed_root`、`private_root`：事先存在、互相隔离的目录。新站按合法 slug 创建自己的子目录和所有权标记，不覆盖已有目录。
- `wp_source`：已安装 WordPress 的源码目录；只复制核心白名单，不复制旧 `wp-config.php`、上传、用户或订单。
- `plugin_sources.woocommerce`、`plugin_sources.peptide-core`、`theme_source`、`ops_source`：受控源码；部署成普通文件。
- `php_binary`、`php_ini`、`wp_cli`，以及本机 driver 所需 `python_binary` 和允许端口 `ports`。
- `db.host/user/password/name_prefix/create_user`：保存在私有文件的建库连接。正式环境必须创建独立站点数据库账号。新库和账号只允许按本任务所有权标记回收。
- `hq_url` 和当前 `hq_secret`：新店自动注册配置；管理后台接入后再做签名回连核验。
- 正式 `webserver` 环境：`allowed_domains` 白名单，以及已准备的 `webroot_ready` 或服务器管理员提供的参数数组 `vhost_command` / `vhost_rollback_command`。占位符仅支持实现中定义的受控站点值。

本机 driver 只绑定允许池中的 `127.0.0.1` 端口。正式 driver 不购买服务器、不自动配置 DNS 或申请 TLS；未准备好的映射、域名和依赖会使预检失败。真实服务器配置与公网部署应另行验证。

新站随机密码存放在该建站任务的私有凭据文件中，页面与任务日志不回显。日常通过管理后台店铺列表一键登录。支付、外部邮件、搜索连接保持未配置；建站不发送邮件或复制演示商品。

## 定时备份

管理后台“备份与恢复”为每个店铺配置每日时间、时区和保留数量。只清理超出数量的 `scheduled` 备份，手动备份、升级前和恢复前的保护备份保留；未结束恢复任务引用的恢复点拒绝删除。清理失败在任务结果中明确提示，不把清理失败当作备份丢失。

正式服务器应每五分钟执行管理后台的 WordPress cron（例如受控 PHP + WP-CLI `--path=<HQ> cron event run pchq_backup_schedules`）。PHP、WP-CLI 和站点路径按实际安装配置，不使用站点请求来执行长任务。

本机可在 HQ 私有 `config.json` 明确开启 `local_scheduler: {enabled:true, interval_seconds:60}`。管理后台会维护一个隐藏 Python 触发器，直接启动 WP-CLI 检查计划，不通过 HTTP；使用单例锁、PID/命令/创建时间核验。电脑关机时不能执行，重新开机并进入管理后台后恢复。未安装机器级服务或系统计划任务。

## 恢复与回滚边界

完整备份包含本站文件、上传、私有报告和本站表前缀对应的数据库表，并保存归属、清单与 SHA256。外层下载包由后台任务生成；下载入口仅签发短时一次性凭据，不在 HTTP 请求中打包大文件。过期定时备份清理时同时删除下载包。

恢复须确认本站 UUID，验证数据库/前缀绑定和完整性，并先保留当前完整状态。恢复保留当前服务器配置、网址和管理后台连接、MU/运维代理及私有任务记录。当前完整恢复检查失败时尝试回退；进程中断或回退失败时停止自动重跑，保留恢复点供人工检查。

版本升级先检查 ZIP 路径、单一根目录、主入口、版本、目标依赖及权限，再保存整站备份和该组件原文件快照。暂存目录与目标同盘交换，正常 PHP/HTTP 健康检查失败会自动复位。文件回滚不逆转第三方插件启动时自行执行的数据库迁移，必要时使用升级前完整备份。

若主机断电或进程在文件交换后被强制结束，新插件可能阻断 WordPress 启动，此时需从服务器使用保留目录/备份或 WP-CLI 的跳过插件、主题入口人工恢复。不能声称后台按钮覆盖所有主机故障。

2026-10-29 本地真实目录隔离站已验证升级、失败复位、文件回滚、完整恢复、恢复失败回退、独立建店及定时触发；没有对现用开发站执行文件替换或恢复，也没有进行正式公网部署。
