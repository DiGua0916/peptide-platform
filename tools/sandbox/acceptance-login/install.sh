#!/usr/bin/env bash
# 装沙盒验收免密入口。后台机 root。用法：install.sh <天数，默认 7>
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "需要 root"; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"; SB=/srv/vialcove-sandbox; ACC=$SB/access; DAYS="${1:-7}"
grep -q VIALCOVE_SANDBOX $SB/shop/public/wp-config.php || { echo "不是沙盒"; exit 1; }
php -l "$HERE/sandbox-acceptance-login.php" >/dev/null
# 1. 密钥
umask 027
python3 - "$ACC/acceptance.json" "$DAYS" <<'PY'
import json, secrets, sys, time
json.dump({"key": secrets.token_hex(32), "created": int(time.time()), "expires": int(time.time()) + int(sys.argv[2]) * 86400}, open(sys.argv[1], "w"))
PY
chown root:vcsandbox $ACC/acceptance.json; chmod 640 $ACC/acceptance.json
# 2. 访问入口加通行链接（先备份；已经加过就不重复加）
if ! grep -q "沙盒验收专用通行链接" $ACC/gateway.php; then
  cp -p $ACC/gateway.php $ACC/gateway.php.before-acceptance
  python3 - "$ACC/gateway.php" "$HERE/gateway-pass.php" <<'PY'
import sys
path, patch = sys.argv[1], sys.argv[2]
src = open(path).read(); add = open(patch).read().split("\n", 1)[1]   # 去掉补丁文件开头的 <?php
anchor = "$config = json_decode(file_get_contents(__DIR__ . '/access.json'), true, 512, JSON_THROW_ON_ERROR);\n"
assert src.count(anchor) == 1, "gateway.php 的结构变了，没有改动"
open(path + ".new", "w").write(src.replace(anchor, anchor + add))
PY
  php -l $ACC/gateway.php.new >/dev/null && chown --reference=$ACC/gateway.php $ACC/gateway.php.new && chmod --reference=$ACC/gateway.php $ACC/gateway.php.new && mv $ACC/gateway.php.new $ACC/gateway.php
fi
# 3. 两个站的 MU 插件
for site in admin hq; do install -o vcsandbox -g vcsandbox -m 644 "$HERE/sandbox-acceptance-login.php" $SB/$site/public/wp-content/mu-plugins/001-sandbox-acceptance-login.php; done
systemctl kill -s USR2 vialcove-sandbox-php.service; sleep 2
# 4. 生成带按钮的网页（只有 root 能读）
umask 077
python3 - "$ACC/acceptance.json" /root/vialcove-sandbox-acceptance-links.html <<'PY'
import hashlib, hmac, html, json, sys, time, urllib.parse
cfg = json.load(open(sys.argv[1])); key = cfg["key"].encode(); exp = str(cfg["expires"])
sign = lambda text: hmac.new(key, text.encode(), hashlib.sha256).hexdigest()
D = ".vialcoveresearch.com"
def link(host, login=None, path="/"):
    nxt = path if login is None else "/wp-admin/?sbx_login=" + exp + "." + login + "." + sign("login." + exp + "." + login + "." + host)
    return "https://" + host + "/_sandbox/login?k=" + exp + "." + sign("gate." + exp + "." + host) + "&next=" + urllib.parse.quote(nxt, safe="")
rows = [
    ("前台（顾客看到的店）", "不用登录", link("sandbox-shop" + D), "普通窗口"),
    ("店铺后台 · 管理员", "sandbox_admin", link("sandbox-admin" + D, "sandbox_admin"), "普通窗口"),
    ("总后台 · 管理员", "sandbox_admin", link("sandbox" + D, "sandbox_admin"), "普通窗口"),
    ("店铺后台 · 投手", "sbx_buyer", link("sandbox-admin" + D, "sbx_buyer"), "无痕窗口（右键 → 在无痕窗口中打开）"),
    ("店铺后台 · 新店投手（会先要求改密码，随便设一个，然后进开店向导）", "sbx_newbuyer", link("sandbox-admin" + D, "sbx_newbuyer"), "另一个无痕窗口，或看完投手后再点"),
]
until = time.strftime("%Y-%m-%d %H:%M", time.gmtime(cfg["expires"] + 8 * 3600))
out = ["<!doctype html><html lang='zh-CN'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>沙盒验收入口</title>",
       "<style>body{font:16px/1.6 system-ui,-apple-system,sans-serif;max-width:720px;margin:32px auto;padding:0 16px;color:#18353e}a.b{display:block;padding:14px 18px;margin:6px 0 2px;background:#18353e;color:#fff;border-radius:10px;text-decoration:none;font-weight:600}small{color:#5b6b70}li{margin:14px 0;list-style:none}ul{padding:0}</style></head><body>",
       "<h1>沙盒验收入口</h1><p>点按钮就进去了，不用输任何账号密码。有效期到北京时间 " + until + "。只用于沙盒，不是正式站。</p><ul>"]
for name, who, url, how in rows:
    out.append("<li><a class='b' href='" + html.escape(url, quote=True) + "' target='_blank' rel='noopener'>" + html.escape(name) + "</a><small>账号：" + html.escape(who) + "　打开方式：" + html.escape(how) + "</small></li>")
out.append("</ul><p><small>同一个窗口里，店铺后台一次只能是一个身份：点了“投手”就会顶掉“管理员”。想同时对比，就按上面写的用无痕窗口开投手。总后台和前台不受影响。</small></p></body></html>")
open(sys.argv[2], "w").write("\n".join(out))
PY
echo "已安装。入口网页：/root/vialcove-sandbox-acceptance-links.html（$(wc -c < /root/vialcove-sandbox-acceptance-links.html) 字节）；有效 $DAYS 天"
