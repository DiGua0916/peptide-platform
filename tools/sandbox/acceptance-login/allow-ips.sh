#!/usr/bin/env bash
# 把最近访问过沙盒登录页的外部 IP 记进验收名单，并更新访问入口和 MU 插件。后台机 root。不显示 IP。
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; SB=/srv/vialcove-sandbox; ACC=$SB/access
[ -f $ACC/acceptance.json ] || { echo "先 install.sh"; exit 1; }
python3 - "$ACC/acceptance.json" "${1:-}" <<'PY'
import json, re, subprocess, sys
path, extra = sys.argv[1], sys.argv[2]
cfg = json.load(open(path)); ips = list(cfg.get("ips", []))
log = subprocess.run("tail -n 120000 /var/log/nginx/access.log", shell=True, capture_output=True, text=True, errors="replace").stdout
for line in log.splitlines():
    if "/_sandbox/login" not in line or "acceptance-check" in line: continue
    ip = line.split(" ", 1)[0]
    if ip in ("127.0.0.1", "::1") or ip in ips: continue
    ips.append(ip)
for ip in filter(None, extra.split(",")):
    if ip not in ips: ips.append(ip)
cfg["ips"] = ips[-10:]
json.dump(cfg, open(path, "w"))
print("名单里现在有", len(cfg["ips"]), "个地址")
PY
chown root:vcsandbox $ACC/acceptance.json; chmod 660 $ACC/acceptance.json
# 访问入口：从备份重新生成（包含通行链接和 IP 放行两段）
python3 - "$ACC/gateway.php.before-acceptance" "$HERE/gateway-pass.php" "$ACC/gateway.php.new" <<'PY'
import sys
src = open(sys.argv[1]).read(); add = open(sys.argv[2]).read().split("\n", 1)[1]
anchor = "$config = json_decode(file_get_contents(__DIR__ . '/access.json'), true, 512, JSON_THROW_ON_ERROR);\n"
assert src.count(anchor) == 1
open(sys.argv[3], "w").write(src.replace(anchor, anchor + add))
PY
php -l $ACC/gateway.php.new >/dev/null && chown --reference=$ACC/gateway.php $ACC/gateway.php.new && chmod --reference=$ACC/gateway.php $ACC/gateway.php.new && mv $ACC/gateway.php.new $ACC/gateway.php
for site in admin hq; do install -o vcsandbox -g vcsandbox -m 644 "$HERE/sandbox-acceptance-login.php" $SB/$site/public/wp-content/mu-plugins/001-sandbox-acceptance-login.php; done
systemctl kill -s USR2 vialcove-sandbox-php.service; sleep 2; echo "已更新"
