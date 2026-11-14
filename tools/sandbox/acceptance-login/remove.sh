#!/usr/bin/env bash
# 撤掉沙盒验收免密入口：所有链接立刻失效。后台机 root。
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "需要 root"; exit 1; }
SB=/srv/vialcove-sandbox; ACC=$SB/access
rm -f $ACC/acceptance.json /root/vialcove-sandbox-acceptance-links.html
for site in admin hq; do rm -f $SB/$site/public/wp-content/mu-plugins/001-sandbox-acceptance-login.php; done
if [ -f $ACC/gateway.php.before-acceptance ]; then mv $ACC/gateway.php.before-acceptance $ACC/gateway.php; fi
systemctl kill -s USR2 vialcove-sandbox-php.service
echo "已撤掉：密钥、两个 MU 插件已删，gateway.php 已换回原样。"
