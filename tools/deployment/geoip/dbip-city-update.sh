#!/bin/bash
# 访客地区：DB-IP City Lite 每月自动更新（店主 2026-11-13）。
#
# - 装在 web 主机 /usr/local/sbin/vialcove-dbip-update，由 vialcove-dbip-update.timer 每天跑一次。
# - 当月文件已经在用 → 什么都不做；DB-IP 还没发布当月文件 → 等第二天再看。
# - 下载后检查：gzip 完整、解压后大于 50 MB、元数据是 DBIP-City-Lite；通过后把
#   /srv/vialcove-data/geoip/dbip-city-lite.mmdb 软链接换到新文件（6 家店 uploads/peptide-geoip/ 都链到这里）。
# - 换完用主店查一次 8.8.8.8，查不出城市就立刻换回上一个月的文件并报错退出。
# - 只保留当月和上一个月两份。
# - 用法：vialcove-dbip-update [YYYY-MM]（不写就是当月，UTC）。
set -euo pipefail

D=/srv/vialcove-data/geoip
LINK="$D/dbip-city-lite.mmdb"
MONTH="${1:-$(date -u +%Y-%m)}"
NAME="dbip-city-lite-$MONTH.mmdb"
NEW="$D/$NAME"
URL="https://download.db-ip.com/free/dbip-city-lite-$MONTH.mmdb.gz"

log() { echo "$(date -u +%FT%TZ) $*"; }

[[ "$MONTH" =~ ^[0-9]{4}-[0-9]{2}$ ]] || { log "bad month: $MONTH"; exit 2; }
mkdir -p "$D"
PREV="$(readlink "$LINK" 2>/dev/null || true)"

if [ "$PREV" = "$NAME" ] && [ -s "$NEW" ]; then
	log "already using $NAME"
	exit 0
fi

if [ ! -s "$NEW" ]; then
	code="$(curl -sS -o /dev/null -I -w '%{http_code}' --max-time 60 "$URL" || true)"
	if [ "$code" != "200" ]; then
		log "$MONTH not published yet (HTTP $code); keeping ${PREV:-none}"
		exit 0
	fi
	TMP="$(mktemp -d "$D/.dl.XXXXXX")"
	trap 'rm -rf "$TMP"' EXIT
	curl -sS -f --retry 3 --max-time 1800 -o "$TMP/f.gz" "$URL"
	gunzip -t "$TMP/f.gz"
	gunzip -c "$TMP/f.gz" > "$TMP/f.mmdb"
	size="$(stat -c %s "$TMP/f.mmdb")"
	if [ "$size" -lt 50000000 ]; then log "file too small ($size bytes); keeping ${PREV:-none}"; exit 1; fi
	if ! tail -c 200000 "$TMP/f.mmdb" | grep -aq 'DBIP-City-Lite'; then log "not a DB-IP City Lite file; keeping ${PREV:-none}"; exit 1; fi
	chmod 644 "$TMP/f.mmdb"
	mv "$TMP/f.mmdb" "$NEW"
	log "downloaded $NAME ($size bytes)"
fi

ln -sfn "$NAME" "$LINK.tmp"
mv -T "$LINK.tmp" "$LINK"
log "switched to $NAME (was ${PREV:-none})"

city="$(cd /srv/vialcove/current && sudo -u vialweb01 /usr/local/bin/wp eval 'echo \PeptideCore\Tracking\Ip_Network::lookup("8.8.8.8")["geo_city"];' 2>/dev/null | tail -n 1 || true)"
if [ -z "$city" ]; then
	if [ -n "$PREV" ] && [ -s "$D/$PREV" ]; then
		ln -sfn "$PREV" "$LINK.tmp"
		mv -T "$LINK.tmp" "$LINK"
		log "lookup check failed; switched back to $PREV"
	else
		log "lookup check failed; no previous file to switch back to"
	fi
	exit 1
fi
log "lookup check ok: 8.8.8.8 -> $city"

# 只留当月和上一个月（按文件名排序，软链接指向的那份一定保留）。
current="$(readlink "$LINK")"
ls -1 "$D"/dbip-city-lite-????-??.mmdb 2>/dev/null | sort | head -n -2 | while read -r old; do
	[ "$(basename "$old")" = "$current" ] && continue
	rm -f "$old"
	log "removed old $(basename "$old")"
done
