#!/usr/bin/env bash
# M1-2 沙盒刷新。只在后台机上以 root 运行，只碰 /srv/vialcove-sandbox 和 vc_sandbox_* 两个库。
#
#   refresh.sh code <发布包.zip>                    整目录替换沙盒三个站的组件（先备份，失败自动换回）
#   refresh.sh data <脱敏快照.sql.gz> <原前台地址> <原后台地址>
#                                                  备份沙盒店铺库 → 导入快照 → 恢复沙盒自己的地址、管理员、总后台连接、测试付款
#   refresh.sh check                               只做健康检查
#
# 发布包用 tools/deployment/prepare_release.py 生成；快照用 tools/local-env/snapshot/snapshot.py 生成。
# 备份在 /var/lib/vialcove-sandbox/backups/refresh-<时间>/，回退方法见脚本结尾打印的说明。
set -euo pipefail
SB=/srv/vialcove-sandbox
STATE=/var/lib/vialcove-sandbox
OWNER=vcsandbox
DB=vc_sandbox_store
SERVICE=vialcove-sandbox-php.service
SHOP_URL=https://sandbox-shop.vialcoveresearch.com
ADMIN_URL=https://sandbox-admin.vialcoveresearch.com
HQ_URL=https://sandbox.vialcoveresearch.com
TS="$(date -u +%Y%m%dT%H%M%SZ)"
BK="$STATE/backups/refresh-$TS"

die() { echo "失败：$*" >&2; exit 1; }
[ "$(id -u)" = 0 ] || die "需要 root。"
[ -d "$SB/shop/public" ] && [ -d "$SB/admin/public" ] && [ -d "$SB/hq/public" ] || die "没有找到沙盒目录 $SB。"
grep -q 'VIALCOVE_SANDBOX' "$SB/shop/public/wp-config.php" || die "沙盒标记不在 wp-config.php 里，拒绝继续。"

reload_php() { systemctl kill -s USR2 "$SERVICE"; sleep 2; systemctl is-active --quiet "$SERVICE" || die "沙盒 PHP 服务没有在运行。"; }

# 健康检查：三个站从本机访问（/etc/hosts 把沙盒域名指到本机）。返回非 0 表示有问题。
check() {
  local bad=0 code
  while read -r url want; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$url" || true)"
    case " $want " in *" $code "*) echo "  正常 $code $url";; *) echo "  异常 $code（期望 $want）$url"; bad=1;; esac
  done <<EOF
$SHOP_URL/ 200
$SHOP_URL/?rest_route=/peptide/v1/ 200
$SHOP_URL/cart/ 200
$SHOP_URL/checkout/ 200 302
$ADMIN_URL/wp-login.php 200
$ADMIN_URL/?rest_route=/ 200
$HQ_URL/wp-login.php 200
$HQ_URL/?rest_route=/peptide-hq/v1/ 200
EOF
  # 页面里出现 PHP 致命错误的字样也算失败。
  if curl -s --max-time 40 "$SHOP_URL/" | grep -qiE 'fatal error|critical error on this website|There has been a critical error'; then echo "  异常：前台首页有致命错误字样"; bad=1; fi
  return $bad
}

components_for() { case "$1" in hq) echo "plugins/peptide-hq plugins/peptide-panapay plugins/peptide-ops";; *) echo "plugins/peptide-core plugins/peptide-panapay plugins/peptide-ops themes/peptide-site";; esac; }

swap_back() { # 把备份换回去
  local role comp dest
  for role in shop admin hq; do for comp in $(components_for $role); do
    dest="$SB/$role/public/wp-content/$comp"
    if [ -d "$BK/code/$role/$comp" ]; then rm -rf "$dest.failed-$TS"; [ -d "$dest" ] && mv "$dest" "$dest.failed-$TS"; mv "$BK/code/$role/$comp" "$dest"; rm -rf "$dest.failed-$TS"; fi
  done; done
  reload_php
}

cmd_code() {
  local zip="${1:?缺少发布包路径}" stage="$STATE/stage-$TS" role comp src dest
  [ -f "$zip" ] || die "找不到 $zip"
  mkdir -p "$stage" "$BK/code" && chmod 700 "$stage" "$BK"
  unzip -q "$zip" -d "$stage"
  [ -f "$stage/wp-content/plugins/peptide-core/peptide-core.php" ] || die "发布包里没有 peptide-core。"
  echo "语法检查…"
  find "$stage" -name '*.php' -print0 | xargs -0 -n 50 -P 2 sh -c 'for f; do php -l "$f" >/dev/null 2>&1 || { echo "语法错误：$f"; exit 255; }; done' sh || die "发布包有 PHP 语法错误，没有替换任何文件。"
  echo "替换前的状态："; check || echo "  （替换前就有异常，继续，但失败时仍会换回）"
  for role in shop admin hq; do for comp in $(components_for $role); do
    src="$stage/wp-content/$comp"; dest="$SB/$role/public/wp-content/$comp"
    [ -d "$src" ] || die "发布包缺 $comp"
    rm -rf "$dest.new-$TS"; cp -a "$src" "$dest.new-$TS"
    chown -R "$OWNER:$OWNER" "$dest.new-$TS"; find "$dest.new-$TS" -type d -exec chmod 755 {} +; find "$dest.new-$TS" -type f -exec chmod 644 {} +
    mkdir -p "$BK/code/$role/$(dirname "$comp")"
    [ -d "$dest" ] && mv "$dest" "$BK/code/$role/$comp"   # 旧版本整目录挪进备份
    mv "$dest.new-$TS" "$dest"                              # 新版本一次 rename 就位
  done; done
  rm -rf "$stage"
  reload_php
  echo "替换后的状态："
  if ! check; then echo "健康检查没过，换回旧版本…"; swap_back; check || true; die "已换回替换前的代码。备份和现场在 $BK"; fi
  echo "代码已更新。旧版本在 $BK/code（回退：把其中各目录挪回原位，再 systemctl kill -s USR2 $SERVICE）"
}

cmd_data() {
  local snap="${1:?缺少快照路径}" from_public="${2:?缺少原前台地址}" from_admin="${3:?缺少原后台地址}"
  local wp="sudo -u $OWNER -H wp --path=$SB/admin/public --skip-plugins --skip-themes"
  [ -f "$snap" ] || die "找不到 $snap"
  # grep -c 读完整个流；grep -q 提前退出会让 gzip 收到 SIGPIPE，在 pipefail 下被当成失败。
  [ "$(gzip -dc "$snap" | grep -c "pc_sanitized_snapshot")" -ge 1 ] || die "文件里没有脱敏标记，不是 snapshot.py 导出的快照，拒绝导入。"
  mkdir -p "$BK" && chmod 700 "$BK"
  echo "备份沙盒店铺库…"
  mysqldump --single-transaction --no-tablespaces "$DB" | gzip > "$BK/$DB.sql.gz"
  # 沙盒自己的身份先存到两张临时表里（快照只会重建它自己的表）。
  mysql "$DB" <<SQL
DROP TABLE IF EXISTS _sbx_keep_options, _sbx_keep_user;
CREATE TABLE _sbx_keep_options AS SELECT option_name, option_value, autoload FROM wp_options WHERE option_name IN
  ('siteurl','home','blogname','active_plugins','woocommerce_cod_settings','peptide_core_hq','peptide_core_domains','peptide_core_template_domains','admin_email','woocommerce_coming_soon');
CREATE TABLE _sbx_keep_user AS SELECT user_login, user_pass, user_nicename, user_email, display_name FROM wp_users ORDER BY ID LIMIT 1;
SQL
  echo "导入快照…"
  gzip -dc "$snap" | mysql --default-character-set=utf8mb4 "$DB"
  mysql "$DB" <<SQL
REPLACE INTO wp_options (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM _sbx_keep_options;
SET @admin := (SELECT MIN(user_id) FROM wp_usermeta WHERE meta_key = 'wp_capabilities' AND meta_value LIKE '%administrator%');
UPDATE wp_users u JOIN _sbx_keep_user k SET u.user_login = k.user_login, u.user_pass = k.user_pass, u.user_nicename = k.user_nicename, u.user_email = k.user_email, u.display_name = k.display_name WHERE u.ID = @admin;
DELETE FROM wp_options WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%';
REPLACE INTO wp_options (option_name, option_value, autoload) VALUES ('pc_page_cache_generation', UNIX_TIMESTAMP(), 'yes');
DROP TABLE _sbx_keep_options, _sbx_keep_user;
SQL
  echo "把原站地址换成沙盒地址…"
  local host_public="${from_public#https://}" host_admin="${from_admin#https://}"
  $wp search-replace "$from_admin" "$ADMIN_URL" --all-tables-with-prefix --skip-columns=guid --report-changed-only | tail -3
  $wp search-replace "$from_public" "$SHOP_URL" --all-tables-with-prefix --skip-columns=guid --report-changed-only | tail -3
  # 不带 www 的写法（邮件模板、域名设置里会出现）。
  $wp search-replace "https://${host_public#www.}" "$SHOP_URL" --all-tables-with-prefix --skip-columns=guid --report-changed-only | tail -2
  $wp cache flush >/dev/null 2>&1 || true
  rm -rf "$SB/shared/private/page-cache" 2>/dev/null || true
  reload_php
  echo "导入后的状态："
  if ! check; then
    echo "健康检查没过，恢复导入前的沙盒库…"
    gzip -dc "$BK/$DB.sql.gz" | mysql --default-character-set=utf8mb4 "$DB"; reload_php; check || true
    die "已恢复导入前的沙盒库。备份在 $BK"
  fi
  mysql -N "$DB" -e "SELECT CONCAT('订单 ', COUNT(*)) FROM wp_wc_orders WHERE type='shop_order'; SELECT CONCAT('已发布商品 ', COUNT(*)) FROM wp_posts WHERE post_type='product' AND post_status='publish'; SELECT CONCAT('账号 ', COUNT(*), '，其中非示例邮箱 ', SUM(user_email NOT LIKE '%@example.test' AND user_email <> '')) FROM wp_users; SELECT CONCAT(option_name, ' = ', option_value) FROM wp_options WHERE option_name IN ('home','siteurl','pc_sanitized_snapshot');"
  echo "数据已刷新。导入前的沙盒库在 $BK/$DB.sql.gz（回退：gzip -dc 该文件 | mysql $DB，再 systemctl kill -s USR2 $SERVICE）"
}

case "${1:-}" in
  code) shift; cmd_code "$@";;
  data) shift; cmd_data "$@";;
  check) check;;
  *) sed -n 2,11p "$0"; exit 1;;
esac
