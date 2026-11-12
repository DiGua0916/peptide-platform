# baseline.sh <before|after>：记录管理员菜单、后台页面、前台页面和脚本清单，供换代码前后对比。
. /var/tmp/m1-accept/lib.sh; T=$1
login sandbox_admin
menu "$ADMIN/wp-admin/admin.php?page=pc-overview" > $D/menu-$T.txt
for p in pc-overview pc-promotions pc-system pc-staff "wc-orders" pc-recovery pc-data pc-visitors pc-mail pc-shipping pc-apps pc-manual-order pc-accounts; do echo "$p $(code "$ADMIN/wp-admin/admin.php?page=$p")"; done > $D/pages-$T.txt
for p in "edit.php?post_type=product" users.php plugins.php; do echo "$p $(code "$ADMIN/wp-admin/$p")"; done >> $D/pages-$T.txt
COOKIE=x; for u in / /cart/ /my-account/ "/?vc_product=11006&acc=1" /track-order/; do echo "$u $(curl -s -o /dev/null -w '%{http_code} %{size_download}' --max-time 60 "$SHOP$u")"; done > $D/shop-$T.txt
assets "/?acc=$T" > $D/assets-$T.txt
echo "$T: 菜单 $(wc -l < $D/menu-$T.txt) 项，后台页面 $(grep -c ' 200' $D/pages-$T.txt)/$(wc -l < $D/pages-$T.txt) 个 200，前台脚本样式 $(wc -l < $D/assets-$T.txt) 个"
