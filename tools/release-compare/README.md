# 发布前后逐页比对（44 个地址）

F5 2026-11-14 写，给 F1 在沙盒复验用。同一个库，先用旧代码取一遍页面，换成新代码再取一遍，逐行比，看线上已有的功能是不是原样。**只在沙盒或本机副本上用，不要在生产上跑。**

## 文件

- `prepare.php`：在站点上用 WP-CLI 跑。给三个测试账号各开一个 2 小时的登录会话，把 cookie 和这份库里要用的商品、订单、专辑编号写进一个配置文件（权限 600，屏幕上不打印 cookie）。
- `compare.py`：在任何有 Python 3 的机器上跑（只用标准库）。`fetch` 按配置取 44 个地址，`diff` 比两次结果。
- `cleanup.php`：比完在站点上跑，把 `prepare.php` 开的会话作废。

配置文件里是登录凭据：放在仓库外面，不要提交，用完删掉。

## 要准备的账号（沙盒上）

- 投手：一个已经是投手版的账号（`wp peptide buyer on <账号>`）。
- 要改密码的投手（可选）：一个投手版、首次登录要改密码的账号。没有就写 `-`，这 4 个地址跳过，总数变成 40。
- 管理员：一个管理员账号。
- 库里要有：1 件简单商品、2 件多规格商品、1 张已付款订单、1 个有商品的专辑（`prepare.php` 自己挑）。

## 步骤

```bash
# 1. 沙盒是旧代码时，在沙盒上生成配置（文件不在服务器上就用 eval-file - 从标准输入读）
wp eval-file - /tmp/cmp.json <投手账号> <要改密码的投手或 -> <管理员账号> < tools/release-compare/prepare.php
# 把 /tmp/cmp.json 拷到跑 compare.py 的机器上（scp），拷完删掉服务器上的那份

# 2. 旧代码取两遍，确认没有噪音（应当 44 / 44 一致）
python3 tools/release-compare/compare.py fetch cmp.json old1
python3 tools/release-compare/compare.py fetch cmp.json old2
python3 tools/release-compare/compare.py diff old1 old2

# 3. 发新代码（开关全关），然后取一遍，和旧代码比
python3 tools/release-compare/compare.py fetch cmp.json new
python3 tools/release-compare/compare.py diff old1 new --show 5

# 4. 作废会话、删配置（会话 2 小时后也会自己过期）
wp eval-file - /tmp/cmp.json < tools/release-compare/cleanup.php
```

沙盒是自签名证书时，`fetch` 后面加 `--insecure`。两次取之间不要在沙盒上下单、改设置，否则页面本来就会不一样。

## 怎么看结果

每个地址一行：`SAME` 一致，`DIFF` 有差异（状态码、跳转地址、去掉噪音后多了 / 少了几行）。`--show N` 打印每处差异的前 N 行。

**只合 F5 一个分支时**（本机副本 2026-11-14 实测，旧 = 主线 `02482ce2`，新 = F5 分支），40 / 44 一致，4 处差异都是预期的：

| 地址 | 差异 | 为什么 |
| --- | --- | --- |
| `buyer:order-detail` | 403 → 200 | 投手只读打开订单详情（热修复包 A）。包 A 已经上线的话这一处也一致 |
| `buyer:orders-processing`、`buyer:order-native` | 页面一样，只有 `wcSettings` 那一行不同 | WooCommerce 写进页面的“当前用户能力”多了 `pc_view_campaigns`（投手角色上的只读权限，所有者 11-13 定）。解码那一行逐项比，只差这一项 |
| `visitor:buyer-status-api` | 200 → 404 | M11 方案 A 下线了这个接口（所有者 11-14 定） |

**五个模块一起合进主线之后**还会多出别的模块带来的差异，要对着各模块的发布材料看。和投手页面有关、F5 已知的：F2（M4）的只读营销活动在的话，投手每一页的左侧菜单多一项“营销活动”（20 个投手地址都会 `DIFF`，差在菜单那几行），`buyer:pc-promotions` 从 403 变成 200（只读列表），投手首页多“当前在跑的活动”卡片。

## 回退时投手角色上的 `pc_view_campaigns`

新代码发布后第一次请求会给投手角色 `pc_media_buyer` 补一项只读权限 `pc_view_campaigns`（写一次 `{前缀}user_roles` 选项，每家店一次）。

- 退回旧代码后，这项权限**留着也没有影响**：旧代码不认识它，投手打开营销页面仍是 403（本机实测）。按“数据库升级不回退”的约定可以不动。
- 一定要去掉的话，**先退回代码，再删**：`wp cap remove pc_media_buyer pc_view_campaigns`，用 `wp cap list pc_media_buyer` 确认只剩 `read`、`view_admin_dashboard`。新代码还在的时候删，下一次请求就会被补回去（本机实测）。
