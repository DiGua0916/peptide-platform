WA="sudo -u vcsandbox -H wp --path=/srv/vialcove-sandbox/admin/public"
ADMIN=https://sandbox-admin.vialcoveresearch.com; SHOP=https://sandbox-shop.vialcoveresearch.com
cd /srv/vialcove-sandbox/tmp
cookie() { sudo -u vcsandbox -H env ACC_LOGIN="$1" wp --path=/srv/vialcove-sandbox/admin/public eval-file /var/tmp/m1-accept/cookie.php 2>/dev/null | tail -1; }
get() { curl -s --max-time 60 -H "Cookie: $1" "$2"; }           # cookie url
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 60 -H "Cookie: $1" "${@:2}"; }
menu() { get "$1" "$ADMIN/wp-admin/admin.php?page=pc-overview" | python3 -c "
import re,sys,html
h=sys.stdin.read()
m=re.search(r'<ul id=\"adminmenu\".*?</ul>\s*</div>', h, re.S) or re.search(r'id=\"adminmenu\"(.*)', h, re.S)
seg=m.group(0) if m else ''
for href,cls in re.findall(r'<a href=[\"\\']([^\"\\']+)[\"\\'][^>]*class=[\"\\']([^\"\\']*)', seg):
    if 'menu-top' in cls: print('TOP', html.unescape(href))
    else: print('  sub', html.unescape(href))
"; }
