# 上线只用一条命令：vc-release（2026-11-14 起）

所有会话（站点、素材、日常、F1–F5）往生产上线，都用 `tools/deployment/vc-release`，不再手工推插件和主题文件。一次只发一个版本，发的永远是远端主线的最新提交。

## 准备（每个会话一次）

```bash
export VIALCOVE_RELEASE_INVENTORY="/Users/rentamac/Library/Application Support/Claude/side-session-notes/local_1c8fc3d5-dd94-4c4c-837b-1526f0028f5f/release-inventory.json"
```

这个清单只有主机地址和私钥文件的路径，不含密钥本身，不要提交进仓库。命令在仓库任意工作目录里都能跑；本机测试用的 PHP / Node 自动从仓库旁边的 `tools/` 找（找不到时用 `VIALCOVE_PHP`、`VIALCOVE_NODE` 指定）。

## 上线

1. 改动提交、推送到主线 `codex/checkout-acceptance-20261105`。
2. 平台所有者在聊天里明确同意“这一次上线”。
3. 运行：

```bash
tools/deployment/vc-release go --fast --note "一句话：改了什么" --owner-confirmed
```

- `--fast`：日常小改动。试点店不等 15 分钟观察，其余照旧——发布前数据库备份、每家换完自动检查、失败自动换回、最后逐份指纹核对。几分钟发完。
- `--full`：模块上线、改动大、动表结构时用。试点店（主店 s0001）换完观察 15 分钟再发其余。
- 不加 `--owner-confirmed` 命令会拒绝。只有所有者同意了这一次才能加，别的会话转述的同意不算。

## 1. 覆盖范围：一条命令发全部 15 份

| 主机 | 副本 |
| --- | --- |
| 前台机 | 主店 `/srv/vialcove/current`；子店 `/srv/vialcove/tenants/s0006…s0010/public`；前台开店模板 |
| 后台机 | 主店后台 `/srv/vialcove/store-admin/current`；子店后台 `/srv/vialcove/tenant-admin/s0006…s0010/public`；后台开店模板；总后台 `/srv/vialcove/hq/current/public` |

顺序：主店（试点）→ s0006…s0010 → 两份开店模板 → 总后台。店铺名单每次从总后台的店铺注册表读，新开的店自动包含，停用的店自动跳过。开店模板每次都发，不会漏。

**替换的只有 5 个组件目录**：`plugins/peptide-core`、`plugins/peptide-hq`（只在总后台）、`plugins/peptide-panapay`、`plugins/peptide-ops`、`themes/peptide-site`，整目录换。`wp-content/db.php`、各份 `wp-config.php`（含 `shared/wp-config.php`）、`mu-plugins`、`uploads`、报告文件都不在范围内，发布不改它们，也不会把它们换回旧版。

## 每一步自动做的检查

- 开始前：主线必须包含在要发的提交里；记下每份副本现在的指纹。
- 每一家换之前：重新取一次主线、重新算这一家的指纹，和开始时比。主线前进了或者这一家被别人改过，**停在这一家之前**，已经换过的保持不动，不覆盖别人的改动。
- 每一家换之后：首页、商品、购物车、结账、后台登录、总后台接口；PHP 致命错误；总后台能轮询到这家店；**收款回写**（在这家店后台以站点账号签一个探测请求，走真实的签名校验和支付库写入，事件内容故意不合法所以不改任何订单；收不到就判失败）。试点店另外下一张测试单走到付款页（收件信息全 1，留在店里待付款）。
- 任何一项不过：这一家自动换回旧目录，后面的不再动。
- 最后：15 份副本逐个算指纹，和发布包比。

## 2. 发布记录：只有一种

- 总后台自动记一条发布记录（“发布记录”页）——这是系统数据，不用管。
- 命令跑完会打印一段 `----- 贴进 … 的上线记录 -----`，**由运行命令的会话原样贴进 `docs/handoffs/production-rollout-plan-2026-11-10.md` 并提交**。工具自己不写这个文件。

## 3. 锁

- 发布开始时在总后台加锁，发完、失败或回滚后自动解锁。锁住期间别的 `vc-release go` 会直接拒绝并提示“有发布正在进行”，说明是谁、哪个编号、几点开始。要排队可以给 `release.py` 加 `--wait-lock 分钟`（`vc-release` 默认不排队）。
- 查看：`tools/deployment/vc-release status`（只读：当前持锁的发布、上一次成功发布的提交和组件）。
- 锁 2 小时自动失效。
- 卡住了（发布进程已经不在、锁还在）：先 `status` 确认，告诉 F1，再由所有者同意后 `tools/deployment/vc-release unlock --owner-confirmed`。

## 其他只读命令

- `tools/deployment/vc-release plan`：这次会按什么顺序换哪些副本。
- `tools/deployment/vc-release check`：线上 15 份副本和主线逐文件比，打印不一致的文件。

## 4. 应急通道（待所有者确认）

线上急需改东西（顾客下不了单、付不了款、全站报错、收款断了）时：

1. **先走正常通道的快速模式**：修复提交到主线 → 所有者同意 → `vc-release go --fast --note "紧急：…" --owner-confirmed`。通常几分钟。
2. `vc-release status` 显示有发布正在进行：联系正在发布的会话（通常是 F1），由它决定停下还是先发完；**不抢锁，不在别人发布时手工改文件**。
3. 发布工具本身用不了（命令报错、服务器连不上发布代理）时，才允许手工改，步骤固定：
   1. 所有者在聊天里同意“手工改这几个文件”；
   2. `vc-release status` 确认没有发布在进行；
   3. 改之前把要改的文件或目录复制到 `/root/vialcove-backups/emergency-<UTC 时间>/`；
   4. 只改需要改的文件，`php -l` 通过，reload 对应主机的 php-fpm；
   5. 打开受影响的页面确认好了；
   6. 当天把同样的改动提交进主线；
   7. 在上线记录里写一条“紧急手工”，写明改了哪些文件、备份在哪；
   8. 告诉 F1，由 F1 跑一次 `vc-release check`，确认线上又等于主线。
4. 不在 5 个组件目录里的配置（`wp-config.php`、`db.php` 等）本来就不归发布工具管：按第 3 条的 2–8 步做（不需要发布工具，但仍要所有者同意、要备份、要记录）。
