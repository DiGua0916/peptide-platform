#!/usr/bin/env bash
# M1-3：在沙盒里跑全部冒烟测试（smoke-*.php）和接 WooCommerce 的测试（*-native.php）。后台机 root 运行。
#   [SEED=1] run-smoke.sh <解开的测试目录> [只跑路径匹配这个正则的]
# <测试目录> 里的结构与仓库相同（wp-content/plugins/<插件>/tests/…），由 pack-tests.sh 打包上传。
# 先备份沙盒店铺库和总后台库，跑完后恢复：冒烟测试会建商品、订单、账号，不让它们留在沙盒里。
# 结果：每个用例一行  <PASS|FAIL|ERROR|TIMEOUT> <用例> <通过数>/<失败数> <说明>，汇总写到 <测试目录>/smoke-result.tsv
set -uo pipefail
SRC="${1:?缺少测试目录}"; ONLY="${2:-}"
SB=/srv/vialcove-sandbox; OWNER=vcsandbox; SERVICE=vialcove-sandbox-php.service
[ "$(id -u)" = 0 ] || { echo "需要 root"; exit 1; }
grep -q VIALCOVE_SANDBOX "$SB/shop/public/wp-config.php" || { echo "不是沙盒"; exit 1; }
TS="$(date -u +%Y%m%dT%H%M%SZ)"; BK="/var/lib/vialcove-sandbox/backups/smoke-$TS"; mkdir -p "$BK" && chmod 700 "$BK"
mysqldump --single-transaction --no-tablespaces vc_sandbox_store | gzip > "$BK/vc_sandbox_store.sql.gz"
mysqldump --single-transaction --no-tablespaces vc_sandbox_hq | gzip > "$BK/vc_sandbox_hq.sql.gz"
restore() {
  echo "恢复沙盒数据库…"
  for db in vc_sandbox_store vc_sandbox_hq; do
    # 先删掉测试期间新建的表，再整库导回。
    for t in $(mysql -N "$db" -e 'SHOW TABLES'); do mysql "$db" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS \`$t\`"; done
    gzip -dc "$BK/$db.sql.gz" | mysql --default-character-set=utf8mb4 "$db"
  done
  systemctl kill -s USR2 "$SERVICE"
}
trap restore EXIT
OUT="$SRC/smoke-result.tsv"; : > "$OUT"; chmod -R a+rX "$SRC"
cd "$SB/tmp"
run_one() { # 站点目录  测试文件
  local site="$1" file="$2" name log rc pass fail status note
  name="$(echo "$file" | sed -E 's#^.*wp-content/(plugins|themes)/##')"
  # 用 require 而不是 eval-file：带 declare(strict_types=1) 的用例 eval-file 跑不了。
  log="$(timeout 180 sudo -u "$OWNER" -H wp --path="$site" eval "require '$file';" 2>&1 | tr -d '\000')"; rc=${PIPESTATUS[0]}
  pass=$(printf '%s\n' "$log" | grep -c '^PASS' || true); fail=$(printf '%s\n' "$log" | grep -c '^FAIL' || true)
  if [ $rc = 124 ]; then status=TIMEOUT; elif printf '%s' "$log" | grep -qE 'PHP Fatal|Fatal error|Uncaught'; then status=ERROR; elif [ "$fail" -gt 0 ] || [ $rc != 0 ]; then status=FAIL; else status=PASS; fi
  note="$(printf '%s\n' "$log" | grep -E '^FAIL|Fatal error|Uncaught|Error:' | head -2 | tr '\t\n' '  ' | cut -c1-240)"
  printf '%s\t%s\t%s/%s\t%s\n' "$status" "$name" "$pass" "$fail" "$note" | tee -a "$OUT"
}
# SEED=1：先写入演示数据（wp peptide demo seed），很多冒烟用例靠它找“测试商品”。跑完随数据库一起恢复掉。
if [ "${SEED:-0}" = 1 ]; then sudo -u "$OWNER" -H wp --path="$SB/admin/public" peptide demo seed 2>&1 | tail -2; fi
for f in $(find "$SRC/wp-content" \( -name 'smoke-*.php' -o -name '*-native.php' \) ! -name '._*' | sort); do
  [ -z "$ONLY" ] || echo "$f" | grep -qE "$ONLY" || continue   # 第二个参数是正则，例如 'smoke-(pricing|marketing)\.php' 
  # GATE=<tests/smoke-suites.json 的路径>：只跑登记为 gate 的用例（发布前的沙盒门槛）。
  if [ -n "${GATE:-}" ]; then python3 -c "import json,sys; sys.exit(0 if any(x['status']=='gate' and sys.argv[2].endswith(x['path']) for x in json.load(open(sys.argv[1]))['suites']) else 1)" "$GATE" "$f" || continue; fi
  case "$f" in */peptide-hq/*) run_one "$SB/hq/public" "$f";; *) run_one "$SB/admin/public" "$f";; esac
done
echo "汇总：$(cut -f1 "$OUT" | sort | uniq -c | tr '\n' ' ')"
[ -z "${GATE:-}" ] || ! grep -qvE '^PASS' "$OUT" || { echo "门槛用例有失败。"; exit 1; }
