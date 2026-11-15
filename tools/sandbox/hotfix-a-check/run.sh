#!/usr/bin/env bash
# 热修复包 A 的沙盒验证（在沙盒所在的服务器上跑；从本机通过 SSH 调用，脚本和 accept/cookie.php 放在同一个目录）。
# 只动沙盒：临时假像素、3 张带标记的测试单、1 个临时顾客，跑完全部清掉。不向外发请求。
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; cd /srv/vialcove-sandbox/tmp; chmod 644 "$HERE"/*.php
W='sudo -u vcsandbox -H wp --path=/srv/vialcove-sandbox/shop/public'; SHOP=https://sandbox-shop.vialcoveresearch.com; ADMIN=https://sandbox-admin.vialcoveresearch.com
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
OUT=$($W eval-file "$HERE/setup.php" 2>&1); echo "$OUT" | grep -vE '^(IDS|URL[NT]) ' | cut -c1-300
URLN=$(echo "$OUT" | sed -n 's/^URLN //p'); URLT=$(echo "$OUT" | sed -n 's/^URLT //p'); read -r NID TID <<<"$(echo "$OUT" | sed -n 's/^IDS //p')"
ck() { sudo -u vcsandbox -H env ACC_LOGIN="$1" ACC_ORDER=0 wp --path="/srv/vialcove-sandbox/$2/public" eval-file "$HERE/cookie.php" 2>/dev/null | sed -n 's/^COOKIE=//p'; }
chk() { [ "$2" = "$3" ] && echo "PASS $1  [$4]" || echo "FAIL $1  [期望 $3 实际 $2；$4]"; }
get() { local H=(); [ -n "${3:-}" ] && H=(-H "Cookie: $3")
  curl -s --max-time 60 -A "$UA" -D /tmp/f1h.hdr -o /tmp/f1h.html "${H[@]}" "$2"
  CODE=$(head -1 /tmp/f1h.hdr | awk '{print $2}'); VID=$(grep -ci '^set-cookie: pc_vid=' /tmp/f1h.hdr); PX=$(grep -c '100000000000001' /tmp/f1h.html); RO=$(grep -c 'pc-buyer-order-detail-readonly' /tmp/f1h.html)
  echo "  ($1) HTTP $CODE，发访客 cookie $VID 次，像素编号 $PX 行"; }
R=$RANDOM
get '未登录，公开地址首页' "$SHOP/?f1chk=${R}a"
chk '公开地址：未登录访客拿到访客 cookie' "$VID" 1 ""; chk '公开地址：未登录访客的页面带像素' "$([ "$PX" -ge 1 ] && echo y || echo n)" y "×$PX"
get '顾客登录，公开地址首页' "$SHOP/?f1chk=${R}c" "$(ck f1_hv_customer shop)"
chk '公开地址：登录的顾客照常拿访客 cookie、带像素' "$CODE/$VID/$([ "$PX" -ge 1 ] && echo y || echo n)" "200/1/y" ""
get '投手登录，公开地址首页' "$SHOP/?f1chk=${R}b" "$(ck sbx_buyer shop)"
chk '公开地址：带着员工登录状态打不开（主线原有规则）' "$CODE/$VID/$PX" "403/0/0" ""
BC="$(ck sbx_buyer admin)"; AC="$(ck sandbox_admin admin)"
get '投手登录，管理地址预览店铺' "$ADMIN/?pc_template_preview=1&f1chk=${R}p" "$BC"
chk '管理地址预览：投手能打开' "$CODE" 200 ""; chk '管理地址预览：投手不拿访客 cookie（不被追踪）' "$VID" 0 ""; chk '管理地址预览：投手的页面不带像素' "$PX" 0 ""
for pair in "普通单 $NID $URLN n" "测试单 $TID $URLT t"; do set -- $pair
  curl -s --max-time 60 -A "$UA" -o /tmp/f1h.html "$3&f1chk=$R"; ev=$(grep -c "order_$2" /tmp/f1h.html); echo "  ($1 #$2) “订单已收到”页里 event_id order_$2 出现 $ev 行"; eval "or_$4=$ev"
done
chk '“订单已收到”页：普通单带购买事件' "$([ "$or_n" -ge 1 ] && echo y || echo n)" y "×$or_n"
chk '“订单已收到”页：测试单不带购买事件' "$or_t" 0 ""
get '投手，订单详情页' "$ADMIN/wp-admin/admin.php?page=pc-order&id=$NID" "$BC"
chk '订单详情：投手能打开（200）且带只读样式' "$CODE/$([ "$RO" -ge 1 ] && echo y || echo n)/$(grep -c "$NID" /tmp/f1h.html | awk '{print ($1>0)?"y":"n"}')" "200/y/y" ""
get '管理员，订单详情页' "$ADMIN/wp-admin/admin.php?page=pc-order&id=$NID" "$AC"
chk '订单详情：管理员不受影响（200，没有只读样式）' "$CODE/$RO" "200/0" ""
code=$(curl -s --max-time 60 -o /dev/null -w '%{http_code}|%{redirect_url}' -H "Cookie: $BC" "$ADMIN/wp-admin/admin.php?page=wc-orders&action=edit&id=$NID")
chk '订单详情：旧的订单编辑地址跳到订单详情页' "$([[ "$code" == 302*page=pc-order* ]] && echo y || echo n)" y "$code"
rm -f /tmp/f1h.*
$W eval-file "$HERE/cleanup.php" 2>&1 | tail -2 | cut -c1-300
