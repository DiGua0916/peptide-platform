#!/usr/bin/env bash
# 把脱敏快照（snapshot.py 导出的 .sql.gz）导入本地 MySQL 测试站。
#   tools/local-env/import-snapshot.sh <快照.sql.gz> <原站地址，如 https://vialcoveplus.com>
# 前提：已用 DB_ENGINE=mysql 跑过 setup.sh。没有脱敏标记的文件一律拒绝导入。
set -euo pipefail
FILE="${1:?用法：import-snapshot.sh <快照.sql.gz> <原站地址>}"
FROM="${2:?缺少原站地址}"
ROOT="${VIALCOVE_LOCAL:-$HOME/vialcove-local}"; ROLE="${ROLE:-store}"; WP="$ROOT/wp-$ROLE"
[ -x "$WP" ] || { echo "先运行 setup.sh（DB_ENGINE=mysql）。" >&2; exit 1; }
[ -f "$ROOT/site-$ROLE/wp-content/db.php" ] && { echo "这个本地站用的是 SQLite，导不进 MySQL 快照。用 DB_ENGINE=mysql 重建。" >&2; exit 1; }
gzip -dc "$FILE" | grep -q "pc_sanitized_snapshot" || { echo "拒绝：文件里没有脱敏标记 pc_sanitized_snapshot，不是 snapshot.py 导出的快照。" >&2; exit 1; }
TO="$("$WP" option get siteurl)"
PASS="$(cat "$ROOT/.admin-password-$ROLE")"
gzip -dc "$FILE" | "$WP" db import -
"$WP" search-replace "$FROM" "$TO" --all-tables --skip-columns=guid --quiet
"$WP" user create localadmin admin@example.test --role=administrator --user_pass="$PASS" 2>/dev/null || "$WP" user update localadmin --user_pass="$PASS" --skip-email
"$WP" eval-file "$(dirname "${BASH_SOURCE[0]}")/seed-local.php"
"$WP" cache flush >/dev/null 2>&1 || true
echo "导入完成：$TO（管理员 localadmin，密码在 $ROOT/.admin-password-$ROLE）"
