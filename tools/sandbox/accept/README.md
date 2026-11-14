# 沙盒验收辅助脚本（F1）

在后台机上用。把本目录的文件传到 `/var/tmp/m1-accept/`，以 `ubuntu` 运行（脚本内部用 sudo 切到沙盒账号）。只对沙盒有效：`cookie.php` 在没有 `VIALCOVE_SANDBOX` 标记的站点上直接退出。

- `lib.sh`：公共函数。`login <账号> [订单号]` 生成一小时有效的登录 cookie 和几个 nonce（不经过密码）；`get` / `code` / `where` 带 cookie 请求；`menu` 列左侧顶级菜单；`assets` 列页面加载的脚本和样式。
- `baseline.sh before|after` + `compare.sh`：换代码前后对比管理员菜单、16 个后台页面、5 个前台页面的状态码和字节数、首页脚本清单。
- `f5-m9.sh`、`f5-m11-m14.sh`：F5 三个模块的 HTTP 层验收（跑完把开关和测试账号改回去）。

顺序：`baseline.sh before` → `tools/sandbox/refresh.sh code <候选包>` → `baseline.sh after` → `compare.sh` → 各模块脚本。用完删掉 `/var/tmp/m1-accept`。
