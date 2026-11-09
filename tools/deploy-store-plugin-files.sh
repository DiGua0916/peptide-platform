set -e
ROLE="$1"; TS=$(date -u +%Y%m%dT%H%M%S)
rm -rf /tmp/trk && mkdir /tmp/trk && tar xzf /tmp/trk.tgz -C /tmp/trk 2>/dev/null
for f in $(cd /tmp/trk && find . -name '*.php'); do php -l /tmp/trk/$f >/dev/null || { echo "语法错误 $f"; exit 1; }; done
if [ "$ROLE" = web ]; then T="/srv/vialcove/current"; for s in s0006 s0007 s0008 s0009 s0010; do T="$T /srv/vialcove/tenants/$s/public"; done; TPL=$(sudo python3 -c "import json;print(json.load(open(\"/etc/vialcove-provision/config.json\"))[\"source_web_root\"])" 2>/dev/null);
else T="/srv/vialcove/store-admin/current"; for s in s0006 s0007 s0008 s0009 s0010; do T="$T /srv/vialcove/tenant-admin/$s/public"; done; TPL=$(sudo python3 -c "import json;print(json.load(open(\"/etc/vialcove-provision/config.json\"))[\"source_admin_root\"])" 2>/dev/null); fi
[ -n "$TPL" ] && T="$T $TPL"
PLUGIN="${PLUGIN:-peptide-core}"; FILES="${FILES:-includes/class-mail-outbox.php includes/admin/class-mail-page.php}"
echo "-- 旧文件哈希（每个文件应只有一种）"
for f in $FILES; do echo "$f: $(for t in $T; do sudo sha256sum $t/wp-content/plugins/$PLUGIN/$f 2>/dev/null | cut -c1-12; done | sort | uniq -c | tr '\n' ' ')"; done
B=$HOME/vialcove-deploy-backups/trk-plain-$TS; mkdir -p $B
n=0
for t in $T; do
  P=$t/wp-content/plugins/$PLUGIN; tag=$(echo $t | tr '/' '_')
  for f in $FILES; do
    if [ -f "$P/$f" ]; then mkdir -p $B/$tag/$(dirname $f); sudo cp -p $P/$f $B/$tag/$f; own=$(sudo stat -c '%U:%G' $P/$f); mode=$(sudo stat -c '%a' $P/$f); else own=$(sudo stat -c '%U:%G' $P/$(dirname $f)); mode=644; echo "新文件 $f → $t"; fi
    sudo install -o ${own%%:*} -g ${own##*:} -m $mode /tmp/trk/$f $P/$f; n=$((n+1))
  done
done
echo "-- 写入 $n 个文件，备份在 $B"
echo "-- 新哈希"; for f in $FILES; do echo "$f: $(for t in $T; do sudo sha256sum $t/wp-content/plugins/$PLUGIN/$f 2>/dev/null | cut -c1-12; done | sort | uniq -c | tr '\n' ' ')"; done
sudo systemctl reload php8.3-fpm && echo "php-fpm reloaded"
