# 沙盒验收公共函数。在后台机上 source；辅助文件放在 /var/tmp/m1-accept。
D=/var/tmp/m1-accept
WA="sudo -u vcsandbox -H wp --path=/srv/vialcove-sandbox/admin/public"
ADMIN=https://sandbox-admin.vialcoveresearch.com; SHOP=https://sandbox-shop.vialcoveresearch.com
cd /srv/vialcove-sandbox/tmp
# login <账号> [订单号]：设置 $COOKIE 和 $NONCE_*（一小时有效，不经过密码）
login() { eval "$(sudo -u vcsandbox -H env ACC_LOGIN="$1" ACC_ORDER="${2:-0}" wp --path=/srv/vialcove-sandbox/admin/public eval-file $D/cookie.php 2>/dev/null | sed -E 's/^([A-Za-z_0-9]+)=(.*)$/\1="\2"/')"; }
get() { curl -s --max-time 60 -H "Cookie: $COOKIE" "$@"; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 60 -H "Cookie: $COOKIE" "$@"; }
where() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 60 -H "Cookie: $COOKIE" "$@"; }
# menu <后台页面地址>：列出左侧顶级菜单
menu() { get "$1" | python3 -c "
import re,sys,html
h=sys.stdin.read(); i=h.find('id=\"adminmenu\"'); seg=h[i:h.find('id=\"wpcontent\"',i)] if i>0 else ''
for href,name in re.findall(r'<a href=[\'\"]([^\'\"]+)[\'\"][^>]*class=[\'\"][^\'\"]*menu-top[^\'\"]*[\'\"][^>]*>.*?<div class=[\'\"]wp-menu-name[\'\"]>(.*?)</div>', seg, re.S): print(html.unescape(href), '|', re.sub(r'<[^>]+>','',name).strip())
"; }
assets() { curl -s --max-time 60 ${2:+-H "Cookie: $2"} "$SHOP$1" | grep -oE '(src|href)="[^"]+\.(js|css)[^"]*"' | sed -E 's/\?ver=[^"]*"/"/' | sort -u; }
J() { python3 -c "import json,sys; print(json.dumps(json.load(sys.stdin),ensure_ascii=False)[:${1:-300}])" 2>/dev/null || echo "(不是 JSON)"; }
