# 沙盒验收用的免密入口（只给沙盒，只在验收期间装）

给平台所有者验收用：点一个链接就过访问保护并登录好，不用输任何账号密码。链接带期限，撤掉密钥文件后立刻全部失效。

- `install.sh`（后台机 root）：生成一把随机密钥写进 `/srv/vialcove-sandbox/access/acceptance.json`；在沙盒访问入口 `gateway.php` 里加一段“认带签名的通行链接”；给沙盒的店铺后台和总后台各放一个 MU 插件“认带签名的登录链接”；生成一个带 5 个按钮的网页文件（只有 root 能读）。原来的 `gateway.php` 先备份。
- `remove.sh`：删掉密钥文件和两个 MU 插件，把 `gateway.php` 换回备份。验收结束后执行。
- 不改 nginx，不动正式站。MU 插件在没有 `VIALCOVE_SANDBOX` 标记的站点上什么都不做。
- 链接里的令牌 = 过期时间 + 用途 + HMAC-SHA256 签名。登录链接只认 `sandbox_admin`、`sbx_buyer`、`sbx_newbuyer` 三个账号。
- 这些链接等同于凭据：不写进仓库、交接文档和聊天文字，只以文件形式交给平台所有者。
