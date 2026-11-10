#!/usr/bin/env bash
# M1-1 版本盘点：只读采集本机所有代码副本的文件哈希。
# 只执行 ls / readlink / find / sha256sum / grep，不写任何文件、不重载任何服务。
# 输出为制表符分隔的行，由 drift.py 解析：
#   H <role>
#   R <label> <root> <resolved>
#   O <label> <private_dir_defined> <server_key_defined> <release_records> <plugins 目录属主:权限> <DISALLOW_FILE_MODS>
#   C <label> <component> <present|missing>
#   F <label> <component> <sha256> <relative path>
set -u
CFG=/etc/vialcove-provision/config.json
cfg() { sudo python3 -c "import json,sys;print(json.load(open('$CFG')).get(sys.argv[1]) or '')" "$1" 2>/dev/null; }
ROLE=$(cfg role)
printf 'H\t%s\n' "$ROLE"
# P <FPM 池名> <运行账号>：用来判断运维代理（以站点账号运行）能不能写代码目录。
for f in $(sudo sh -c 'ls /etc/php/*/fpm/pool.d/*.conf 2>/dev/null'); do printf 'P\t%s\t%s\n' "$(basename "$f" .conf)" "$(sudo grep -E '^user *=' "$f" | sed -E 's/.*= *//' | head -1)"; done

declare -a LABELS ROOTS
add() { if sudo test -d "$2"; then LABELS+=("$1"); ROOTS+=("$2"); fi; }
if [ "$ROLE" = web ]; then
  add "web:s0001" /srv/vialcove/current
  for d in $(sudo sh -c 'ls -d /srv/vialcove/tenants/*/public 2>/dev/null'); do add "web:$(basename "$(dirname "$d")")" "$d"; done
  add "web:template" "$(cfg source_web_root)"
else
  add "admin:s0001" /srv/vialcove/store-admin/current
  for d in $(sudo sh -c 'ls -d /srv/vialcove/tenant-admin/*/public 2>/dev/null'); do add "admin:$(basename "$(dirname "$d")")" "$d"; done
  add "admin:template" "$(cfg source_admin_root)"
  HQ=$(cfg hq_root); [ -n "$HQ" ] && add "admin:hq" "$HQ"
fi

COMPONENTS="plugins/peptide-core plugins/peptide-hq plugins/peptide-panapay plugins/peptide-ops themes/peptide-site mu-plugins"
for i in "${!ROOTS[@]}"; do
  label=${LABELS[$i]}; root=${ROOTS[$i]}
  printf 'R\t%s\t%s\t%s\n' "$label" "$root" "$(sudo readlink -f "$root")"
  # 运维代理配置：只报告“是否定义”，不输出密钥值。
  pd=no; sk=no; rec=0; nomods=no
  stub="$root/wp-config.php"; real=""
  # 线上的 wp-config.php 只有一行 require，真正的配置在数据目录或 shared 目录。
  if sudo test -f "$stub"; then real=$(sudo grep -oE "require(_once)? +['\"][^'\"]+wp-config\.php['\"]" "$stub" | sed -E "s/.*['\"]([^'\"]+)['\"]/\1/" | head -1); fi
  for c in "$stub" "$real"; do
    if [ -n "$c" ] && sudo test -f "$c"; then
      sudo grep -q "PEPTIDE_OPS_PRIVATE_DIR" "$c" && pd=yes
      sudo grep -q "PEPTIDE_OPS_SERVER_KEY" "$c" && sk=yes
      sudo grep -qE "DISALLOW_FILE_MODS['\"] *, *true" "$c" && nomods=yes
      dir=$(sudo grep -E "define\( *['\"]PEPTIDE_OPS_PRIVATE_DIR" "$c" | sed -E "s/.*, *['\"]([^'\"]+)['\"].*/\1/" | head -1)
      if [ -n "$dir" ] && sudo test -d "$dir/releases"; then rec=$(sudo sh -c "ls -1 '$dir/releases' 2>/dev/null | wc -l"); fi
    fi
  done
  owner=$(sudo stat -c '%U:%a' "$root/wp-content/plugins" 2>/dev/null)
  printf 'O\t%s\t%s\t%s\t%s\t%s\t%s\n' "$label" "$pd" "$sk" "$rec" "$owner" "$nomods"
  for comp in $COMPONENTS; do
    dir="$root/wp-content/$comp"; name=$(basename "$comp")
    if sudo test -d "$dir"; then
      printf 'C\t%s\t%s\tpresent\n' "$label" "$name"
      sudo sh -c "cd '$dir' && find -L . -type f -print0 | sort -z | xargs -0 -r sha256sum" | sed -E "s#^([0-9a-f]{64})  \./#F\t$label\t$name\t\1\t#"
    else
      printf 'C\t%s\t%s\tmissing\n' "$label" "$name"
    fi
  done
done
