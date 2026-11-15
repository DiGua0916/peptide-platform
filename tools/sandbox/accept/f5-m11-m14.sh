# M11 已购回访 + M14 商品地址验收（HTTP 层）。结束时把两个开关改回 off。
. /var/tmp/m1-accept/lib.sh; COOKIE=x
R="acc=$RANDOM"   # 带随机参数绕过页面缓存
OID=$($WA eval 'foreach ( wc_get_orders( array( "limit" => 20, "billing_email" => "acc.customer@example.test" ) ) as $o ) { if ( $o->get_date_paid() ) { echo $o->get_id(); break; } }' 2>/dev/null | tail -1)
echo "## 开关关"; $WA option update pc_buyer_recognition off >/dev/null 2>&1; $WA option update pc_entry_address off >/dev/null 2>&1
echo "status $(curl -s "$SHOP/?rest_route=/peptide/v1/buyer/status" | J)"; assets "/?$R" > /tmp/acc-off.txt; echo "首页资源 $(wc -l < /tmp/acc-off.txt)，buyer-status $(grep -c buyer-status /tmp/acc-off.txt)，entry-address $(grep -c entry-address /tmp/acc-off.txt)，Track your order $(curl -s "$SHOP/?$R" | grep -ci 'track your order')"
echo "## M11 开"; $WA option update pc_buyer_recognition on 2>&1 | tail -1
L() { curl -s -D /tmp/acc-h.txt -X POST -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"order\":\"$2\"}" "$SHOP/?rest_route=/peptide/v1/order-lookup"; }
echo "查订单页 $(curl -s -o /dev/null -w '%{http_code}' "$SHOP/track-order/?$R")，表单 $(curl -s "$SHOP/track-order/?$R" | grep -ciE 'order-lookup')"
echo "1 都对：$(L acc.customer@example.test $OID | J 260)"; grep -i '^set-cookie: pc_buyer' /tmp/acc-h.txt | sed -E 's/(pc_buyer=)[^;]*/\1<略>/' | tr -d '\r'; BC=$(grep -i '^set-cookie: pc_buyer' /tmp/acc-h.txt | sed -E 's/^[^:]+: (pc_buyer=[^;]+).*/\1/' | tr -d '\r')
echo "1b 用显示的订单号（hang-…）：$(L acc.customer@example.test "$($WA eval "echo wc_get_order( $OID )->get_order_number();" 2>/dev/null | tail -1)" | J 80)"
echo "2 邮箱不对：$(L nobody@example.test $OID | J 60)"; echo "3 单号不对：$(L acc.customer@example.test 999999 | J 60)"
for i in 5 6 7; do echo -n "第 $i 次 $(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"email":"n@example.test","order":"1"}' "$SHOP/?rest_route=/peptide/v1/order-lookup")；"; done; echo
echo "带 cookie：$(curl -s -H "Cookie: $BC" "$SHOP/?rest_route=/peptide/v1/buyer/status" | J 200)"
T="${BC%?}$( [ "${BC: -1}" = a ] && echo b || echo a )"; echo "改一位：$(curl -s -H "Cookie: $T" "$SHOP/?rest_route=/peptide/v1/buyer/status" | J)"
assets "/?$R" "$BC" | diff /tmp/acc-off.txt - | grep '^[<>]' | head -4
echo "无 cookie 首页与关时的差异：$(assets "/?${R}b" | diff /tmp/acc-off.txt - | grep -c '^[<>]') 处；页脚入口 $(curl -s "$SHOP/?${R}c" | grep -ci 'track your order')；查订单页上的入口 $(curl -s "$SHOP/track-order/?${R}d" | grep -c 'class="vc-track-order"')"
echo "## M14 开"; $WA option update pc_entry_address on 2>&1 | tail -1
h=$(curl -s "$SHOP/?${R}e"); echo "entry-address.js $(echo "$h" | grep -c 'entry-address.js')；$(echo "$h" | grep -oE 'var vcEntryAddress = [^;]{0,120}' | head -1)"
for u in "/?vc_product=11006&${R}f" "/?vc_product=11006&utm_source=fb&fbclid=abc&${R}g" "/product/retatrutide/" "/cart/"; do echo "$u → $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 60 "$SHOP$u")"; done
echo "## 改回 off"; $WA option update pc_buyer_recognition off >/dev/null 2>&1; $WA option update pc_entry_address off >/dev/null 2>&1
assets "/?${R}z" | diff -q /tmp/acc-off.txt - >/dev/null && echo "首页资源回到基线"; echo "status $(curl -s "$SHOP/?rest_route=/peptide/v1/buyer/status" | J)"; rm -f /tmp/acc-*.txt
echo "fatal: $(sudo grep -ciE 'fatal' /var/log/vialcove-sandbox/fpm.log)"
