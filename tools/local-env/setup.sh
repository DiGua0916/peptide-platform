#!/usr/bin/env bash
# 建一个本地测试站：WordPress + WooCommerce + 本仓库的插件和主题（软链接，改仓库即时生效）。
# 站点目录在仓库之外，不会被提交。不连接任何生产数据库、支付方或邮件服务。
#
#   tools/local-env/setup.sh                      # SQLite + 演示数据，装在 ~/vialcove-local
#   VIALCOVE_LOCAL=/path tools/local-env/setup.sh # 换目录
#   DB_ENGINE=mysql DB_NAME=vc_local DB_USER=vc_local DB_PASSWORD=... tools/local-env/setup.sh
#                                                  # 用 MySQL（导入脱敏快照必须用它）
#   ROLE=hq tools/local-env/setup.sh               # 总后台站（启用 peptide-hq，不装 WooCommerce 主题数据）
#
# 需要：PHP 8.1+（mbstring、curl、zip；SQLite 模式要 pdo_sqlite，MySQL 模式要 mysqli）、curl、unzip。
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
# shellcheck disable=SC1091
. "$HERE/versions.env"
ROOT="${VIALCOVE_LOCAL:-$HOME/vialcove-local}"
ROLE="${ROLE:-store}"
DB_ENGINE="${DB_ENGINE:-sqlite}"
PORT="${PORT:-8080}"
URL="http://127.0.0.1:$PORT"
SITE="$ROOT/site-$ROLE"
DL="$ROOT/dl"

die() { echo "失败：$*" >&2; exit 1; }
case "$ROOT" in "$REPO"|"$REPO"/*) die "站点目录不能放在仓库里：$ROOT";; esac
command -v php >/dev/null || die "没有找到 php。本机先装 PHP 8.1 以上（见 README 的“没有 PHP 怎么办”）。"
php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);' || die "PHP 版本低于 8.1。"
need="mbstring curl zip"; [ "$DB_ENGINE" = sqlite ] && need="$need pdo_sqlite" || need="$need mysqli"
for ext in $need; do php -m | grep -qix "$ext" || die "PHP 缺少扩展 $ext。"; done
for tool in curl unzip; do command -v $tool >/dev/null || die "没有找到 $tool。"; done

mkdir -p "$DL" "$ROOT/logs/mail" "$ROOT/private-reports"
LOCK="$ROOT/downloads.lock"; touch "$LOCK"
sha() { if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
# 下载并核对哈希。已知哈希写在 versions.env；其余第一次下载时记进 downloads.lock，以后必须一致。
fetch() { # name url [sha256]
  local name="$1" url="$2" want="${3:-}" file="$DL/$1" got
  if [ ! -f "$file" ]; then
    curl -fsSL --retry 2 -o "$file.part" "$url" || die "下载失败：$url"
    mv "$file.part" "$file"
  fi
  got="$(sha "$file")"
  [ -n "$want" ] || want="$(grep -E "^$name " "$LOCK" | cut -d' ' -f2 || true)"
  if [ -z "$want" ]; then echo "$name $got" >> "$LOCK"; echo "记录 $name 的哈希 $got"; elif [ "$want" != "$got" ]; then die "$name 的哈希不对（期望 $want，实际 $got）。删掉 $file 重试，仍不对就停下来查原因。"; fi
}
fetch "wordpress-$WP_VERSION.zip" "https://github.com/WordPress/WordPress/archive/refs/tags/$WP_VERSION.zip"
fetch "woocommerce-$WC_VERSION.zip" "https://github.com/woocommerce/woocommerce/releases/download/$WC_VERSION/woocommerce.zip" "$WC_SHA256"
fetch "wp-cli-$WP_CLI_VERSION.phar" "https://github.com/wp-cli/wp-cli/releases/download/v$WP_CLI_VERSION/wp-cli-$WP_CLI_VERSION.phar"
[ "$DB_ENGINE" = sqlite ] && fetch "sqlite-database-integration-$SQLITE_PLUGIN_VERSION.zip" "https://downloads.wordpress.org/plugin/sqlite-database-integration.$SQLITE_PLUGIN_VERSION.zip"

if [ ! -f "$SITE/wp-load.php" ]; then
  rm -rf "$ROOT/.unpack" && mkdir -p "$ROOT/.unpack" && unzip -q "$DL/wordpress-$WP_VERSION.zip" -d "$ROOT/.unpack"
  mkdir -p "$SITE" && cp -R "$ROOT/.unpack/WordPress-$WP_VERSION/." "$SITE/" && rm -rf "$ROOT/.unpack"
fi
[ -d "$SITE/wp-content/plugins/woocommerce" ] || unzip -q "$DL/woocommerce-$WC_VERSION.zip" -d "$SITE/wp-content/plugins"
if [ "$DB_ENGINE" = sqlite ] && [ ! -d "$SITE/wp-content/plugins/sqlite-database-integration" ]; then
  unzip -q "$DL/sqlite-database-integration-$SQLITE_PLUGIN_VERSION.zip" -d "$SITE/wp-content/plugins"
  sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"
fi

# 仓库代码用软链接接进来。
link() { rm -rf "$2"; ln -s "$1" "$2"; }
for plugin in peptide-core peptide-panapay peptide-hq peptide-ops; do link "$REPO/wp-content/plugins/$plugin" "$SITE/wp-content/plugins/$plugin"; done
link "$REPO/wp-content/themes/peptide-site" "$SITE/wp-content/themes/peptide-site"
mkdir -p "$SITE/wp-content/mu-plugins"
link "$HERE/mu-plugins/vialcove-local-safety.php" "$SITE/wp-content/mu-plugins/vialcove-local-safety.php"

if [ ! -f "$SITE/wp-config.php" ]; then
  salt() { php -r 'echo bin2hex(random_bytes(32));'; }
  {
    echo "<?php"
    echo "// 本地测试站，由 tools/local-env/setup.sh 生成。不要拿去别处用。"
    echo "define( 'DB_NAME', '${DB_NAME:-vialcove_local}' ); define( 'DB_USER', '${DB_USER:-}' ); define( 'DB_PASSWORD', '${DB_PASSWORD:-}' ); define( 'DB_HOST', '${DB_HOST:-127.0.0.1}' );"
    echo "define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' ); \$table_prefix = 'wp_';"
    [ "$DB_ENGINE" = sqlite ] && echo "define( 'DB_DIR', '$ROOT/db-$ROLE' ); define( 'DB_FILE', 'local.sqlite' );"
    for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do echo "define( '$k', '$(salt)' );"; done
    echo "define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'VIALCOVE_LOCAL_ENV', true ); define( 'VIALCOVE_LOCAL_LOGS', '$ROOT/logs' );"
    echo "define( 'WP_HOME', '$URL' ); define( 'WP_SITEURL', '$URL' );"
    echo "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$ROOT/logs/debug-$ROLE.log' ); define( 'WP_DEBUG_DISPLAY', false );"
    echo "define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost' );"
    echo "define( 'PEPTIDE_CORE_PRIVATE_DIR', '$ROOT/private-reports' );"
    echo "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }"
    echo "require_once ABSPATH . 'wp-settings.php';"
  } > "$SITE/wp-config.php"
  chmod 600 "$SITE/wp-config.php"
fi
mkdir -p "$ROOT/db-$ROLE"

cat > "$ROOT/wp-$ROLE" <<WRAP
#!/bin/sh
exec php -d memory_limit=1024M "$DL/wp-cli-$WP_CLI_VERSION.phar" --path="$SITE" "\$@"
WRAP
cat > "$ROOT/start-$ROLE" <<WRAP
#!/bin/sh
# 启动本地站：$URL（PHP 内置服务器，4 个 worker）。日志 $ROOT/logs/server-$ROLE.log
cd "$SITE" && PHP_CLI_SERVER_WORKERS=4 exec php -d memory_limit=512M -S 127.0.0.1:$PORT "$HERE/router.php" >> "$ROOT/logs/server-$ROLE.log" 2>&1
WRAP
chmod +x "$ROOT/wp-$ROLE" "$ROOT/start-$ROLE"
WP="$ROOT/wp-$ROLE"

if ! "$WP" core is-installed 2>/dev/null; then
  PASS="$(php -r 'echo bin2hex(random_bytes(12));')"
  "$WP" core install --url="$URL" --title="VialCove local ($ROLE)" --admin_user=localadmin --admin_password="$PASS" --admin_email=admin@example.test --skip-email
  ( umask 077; echo "$PASS" > "$ROOT/.admin-password-$ROLE" )
fi
if [ "$ROLE" = hq ]; then
  "$WP" plugin activate peptide-hq
else
  "$WP" plugin activate woocommerce peptide-core peptide-panapay
  "$WP" theme activate peptide-site
  "$WP" eval-file "$HERE/seed-local.php"
fi
"$WP" rewrite structure '/%postname%/' >/dev/null
echo
echo "完成。启动：$ROOT/start-$ROLE   地址：$URL   命令行：$ROOT/wp-$ROLE <命令>"
echo "管理员 localadmin，密码在 $ROOT/.admin-password-$ROLE"
[ "$ROLE" = hq ] || echo "演示商品：$ROOT/wp-$ROLE peptide demo seed"
