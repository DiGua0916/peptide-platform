#!/usr/bin/env bash
# M1-5 发布代理：在一台服务器上以 root 执行，由 tools/deployment/release.py 通过 SSH 调用。
# 只做文件层面的事：解包、整目录原子替换、回滚、算哈希、看错误日志、数据库快照。不碰数据库内容。
#
#   stage <发布包.zip> <版本标签>                  解包到 /var/lib/vialcove-release/<标签>/src，全部 PHP 做语法检查
#   apply <标签> <站点根目录> <属主|keep> <组件…>    组件写成 plugins/peptide-core、themes/peptide-site
#   rollback <标签> <站点根目录> <组件…>            把这次发布替换掉的目录换回去
#   hash <站点根目录> <组件…>                      每行：组件 <TAB> 清单哈希 <TAB> 文件数（和 manifest.py 同一个算法）
#   fatals <起始时间戳> <日志文件…>                统计这个时间之后的 PHP 致命错误行数
#   dbsnapshot <标签> <数据库名…>                  mysqldump 到 /root/vialcove-backups/release-<标签>/（只读生产库）
#   prune <站点根目录> [保留几份=3]                删掉这份副本较旧的回滚点
#   cleanup <标签>                                删掉这次发布的解包目录（回滚点保留）
#
# 替换方式：新版本先完整拷到目标旁边（同一个文件系统），再用两次 rename 换进去——旧目录挪到回滚点，新目录就位。
# 回滚 = 再 rename 回来。回滚点放在站点根目录的上一级 .release-backups/ 里（不在 wp-content 里，WordPress 看不到）。
set -euo pipefail
STATE=/var/lib/vialcove-release
die() { echo "失败：$*" >&2; exit 1; }
[ "$(id -u)" = 0 ] || die "需要 root。"
cmd="${1:-}"; shift || true

label_ok() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$ ]] || die "版本标签不合法：$1"; }
comp_ok() { [[ "$1" =~ ^(plugins|themes)/peptide-(core|hq|panapay|ops|site)$ ]] || die "不认识的组件：$1"; }
root_ok() { # 站点根目录必须真的是一个 WordPress 安装
  [ -n "$1" ] && [ -d "$1/wp-content" ] && [ -f "$1/wp-includes/version.php" ] || die "不是 WordPress 站点根目录：$1"
}
backup_dir() { # <标签> <站点根目录>
  local real; real="$(readlink -f "$2")"
  echo "$(dirname "$real")/.release-backups/$1/$(basename "$real")"
}

case "$cmd" in
  stage)
    zip="${1:?缺少发布包}"; label="${2:?缺少版本标签}"; label_ok "$label"; [ -f "$zip" ] || die "找不到 $zip"
    dir="$STATE/$label"; rm -rf "$dir"; mkdir -p "$dir/src"; chmod 700 "$STATE" "$dir"
    unzip -q "$zip" -d "$dir/src"
    [ -d "$dir/src/wp-content" ] || die "发布包里没有 wp-content。"
    bad=$(find "$dir/src" -name '*.php' -print0 | xargs -0 -n 40 -P 2 sh -c 'for f; do php -l "$f" >/dev/null 2>&1 || echo "$f"; done' sh | head -5)
    [ -z "$bad" ] || die "发布包有 PHP 语法错误，没有替换任何文件：$bad"
    echo "已解包并通过语法检查：$(find "$dir/src" -type f | wc -l) 个文件"
    ;;
  apply)
    label="${1:?}"; root="${2:?}"; owner="${3:?}"; shift 3; label_ok "$label"; root_ok "$root"
    src="$STATE/$label/src/wp-content"; [ -d "$src" ] || die "这个版本还没有解包（先 stage）。"
    bk="$(backup_dir "$label" "$root")"
    for comp in "$@"; do comp_ok "$comp"; [ -d "$src/$comp" ] || die "发布包里没有 $comp"; done
    for comp in "$@"; do
      dest="$root/wp-content/$comp"; new="$dest.new-$label"
      [ ! -e "$bk/$comp" ] || die "这个版本已经在这份副本上替换过 $comp（回滚点已存在）。要重发请先 rollback 或换一个版本标签。"
      rm -rf "$new"; cp -a "$src/$comp" "$new"
      if [ "$owner" = keep ]; then
        if [ -d "$dest" ]; then chown -R --reference="$dest" "$new"; else chown -R --reference="$(dirname "$dest")" "$new"; fi
      else
        chown -R "$owner" "$new"
      fi
      find "$new" -type d -exec chmod 755 {} +; find "$new" -type f -exec chmod 644 {} +
      mkdir -p "$bk/$(dirname "$comp")"
      if [ -d "$dest" ]; then mv "$dest" "$bk/$comp"; else mkdir -p "$bk/$comp.absent"; fi   # 原来没有这个组件：记一笔，回滚时删掉新的
      mv "$new" "$dest"
      echo "已替换 $comp"
    done
    ;;
  rollback)
    label="${1:?}"; root="${2:?}"; shift 2; label_ok "$label"; root_ok "$root"
    bk="$(backup_dir "$label" "$root")"
    for comp in "$@"; do
      comp_ok "$comp"; dest="$root/wp-content/$comp"
      if [ -d "$bk/$comp" ]; then
        rm -rf "$dest.failed-$label"; [ ! -d "$dest" ] || mv "$dest" "$dest.failed-$label"
        mv "$bk/$comp" "$dest"; rm -rf "$dest.failed-$label"
        echo "已回滚 $comp"
      elif [ -d "$bk/$comp.absent" ]; then
        rm -rf "$dest"; rmdir "$bk/$comp.absent"; echo "已移除 $comp（发布前没有）"
      else
        echo "跳过 $comp（这份副本上没有这次发布的回滚点）"
      fi
    done
    ;;
  hash)
    root="${1:?}"; shift; root_ok "$root"
    python3 - "$root" "$@" <<'PY'
import hashlib, os, sys
SKIP = {"tests", "node_modules", "vendor", "uploads", "cache", "backups", "private", "__pycache__"}
root = sys.argv[1]
for comp in sys.argv[2:]:
    base = os.path.join(root, "wp-content", comp)
    if not os.path.isdir(base):
        print(f"{comp}\tabsent\t0"); continue
    pairs = []
    for cur, dirs, files in os.walk(base):
        dirs[:] = [d for d in dirs if d.lower() not in SKIP and not os.path.islink(os.path.join(cur, d))]
        for name in files:
            full = os.path.join(cur, name)
            with open(full, "rb") as handle:
                pairs.append((os.path.relpath(full, base).replace(os.sep, "/"), hashlib.sha256(handle.read()).hexdigest()))
    text = "".join(f"{rel}\0{digest}\n" for rel, digest in sorted(pairs, key=lambda p: p[0].encode()))
    print(f"{comp}\t{hashlib.sha256(text.encode()).hexdigest()}\t{len(pairs)}")
PY
    ;;
  fatals)
    since="${1:?}"; shift
    python3 - "$since" "$@" <<'PY'
import re, sys, time
since = int(sys.argv[1]); count = 0; sample = ""
for path in sys.argv[2:]:
    try:
        lines = open(path, errors="replace").read().splitlines()[-4000:]
    except OSError:
        continue
    for line in lines:
        if not re.search(r"PHP Fatal error|PHP Parse error|Uncaught (Error|Exception)", line):
            continue
        m = re.match(r"\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z/_+-]+))?\]", line)
        when = None
        if m:
            try:
                # 日志里是服务器本地时间（不带时区）：按本机时区换算，否则会把几小时前的错误当成刚发生的。
                when = time.mktime(time.strptime(m.group(1), "%d-%b-%Y %H:%M:%S"))
            except ValueError:
                pass
        if when is None or when >= since - 5:   # 读不出时间的行算进去：宁可多算，不漏掉
            count += 1; sample = sample or line[:240]
print(f"{count}\t{sample}")
PY
    ;;
  dbsnapshot)
    label="${1:?}"; shift; label_ok "$label"
    out="/root/vialcove-backups/release-$label"; mkdir -p "$out"; chmod 700 /root/vialcove-backups "$out"
    for db in "$@"; do
      [[ "$db" =~ ^[A-Za-z0-9_]{1,48}$ ]] || die "数据库名不合法：$db"
      ( umask 077; mysqldump --single-transaction --quick --no-tablespaces --skip-lock-tables "$db" | gzip > "$out/$db.sql.gz" )
      echo "$db $(stat -c %s "$out/$db.sql.gz") 字节"
    done
    ;;
  prune)
    root="${1:?}"; keep="${2:-3}"; root_ok "$root"
    base="$(dirname "$(readlink -f "$root")")/.release-backups"; name="$(basename "$(readlink -f "$root")")"
    [ -d "$base" ] || exit 0
    ls -1dt "$base"/*/"$name" 2>/dev/null | tail -n +"$((keep + 1))" | while read -r old; do rm -rf "$old"; rmdir "$(dirname "$old")" 2>/dev/null || true; echo "已删旧回滚点 $old"; done
    ;;
  cleanup)
    label="${1:?}"; label_ok "$label"; rm -rf "${STATE:?}/$label"; echo "已删解包目录"
    ;;
  *) sed -n 2,13p "$0"; exit 1;;
esac
