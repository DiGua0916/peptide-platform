# M1-7 发布规则：切换材料（草稿，还没生效）

状态：**只准备，不切换。** 切换会改变日常会话上线的方式，要平台所有者点头并先通知它。下面三样东西切换那天一起做。

## 一、加进 `AGENTS.md` 和 `CLAUDE.md` 的一段（两个文件用同一段）

```markdown
## 发布规则（M1，自 <切换日期> 起）

1. **生产只由发布负责人（F1 会话）发布，每次发布都要平台所有者确认。** 其他会话只提交、推送自己的分支，不登服务器改文件，不直接推生产。
2. **只有一条发布路径**：`tools/deployment/release.py`。从已推送的提交整包打包 → 沙盒（健康检查 + critical-path）→ 试点店（下一张测试单走到付款页，观察 15 分钟）→ 其余店逐家 → 主店 → 开店模板 → 总后台 → 每份副本核对哈希。任何一步失败自动回滚那一组并停下。
3. **不再按文件推。** `tools/deploy-store-plugin-files.sh` 已挪到 `tools/deployment/emergency/`，只在紧急修复时由 F1 使用，24 小时内必须补一次正式发布；入口文件（`class-plugin.php`、主题 `functions.php`）任何时候都不许单独推。
4. **要上线，告诉 F1 这几样**：分支和提交、改了哪些组件、有没有新表新列、开关默认是什么、怎么验收。写在共享笔记或自己的交接里。
5. **版本号**：改了哪个插件或主题，就抬它的版本号（同一个版本号不能对应两份不同的内容，发布脚本会拦）。
6. **数据库只加表加列**，升级脚本可以重复执行；回滚只回代码不回库，所以旧代码必须能在新表结构上跑。
7. **每个需求至少带一个测试**，文件名带模块编号；修线上问题先补一个能复现它的测试。测试登记在 `tests/data-observations-suites.json`（纯逻辑、前端）或 `tests/smoke-suites.json`（要站点的）。
8. **发布锁**：同一时间只有一个发布在跑；总后台“发布记录”页能看到谁在发、每家店现在是什么版本。看到“代码版本不一致”的告警，先找 F1，不要自己去服务器上改。
9. **发布时段**：避开美国晚间。按近 7 天访客数据，最安静的是北京时间 14:00–17:00（美东凌晨 1–4 点）。
10. **紧急修复**可以跳过沙盒，但不能跳过试点店和验收脚本；发布记录里标“紧急”。
```

## 二、直推脚本挪到紧急目录

```bash
git mv tools/deploy-store-plugin-files.sh tools/deployment/emergency/deploy-store-plugin-files.sh
```

并在脚本开头加一道保险（切换时一起提交）：没有设置环境变量 `EMERGENCY_REASON` 就拒绝执行；`FILES` 里出现 `class-plugin.php`、`functions.php`、`peptide-*.php`（插件主文件）就拒绝执行。目录说明已经放在 `tools/deployment/emergency/README.md`。

注意：`tools/deploy-store-plugin-files.sh` 不在 F1 的文件清单里（它在 `tools/` 根目录，日常会话在用）。挪动由 F1 在切换那天做，做之前在共享笔记里通知。

## 三、切换那天的顺序

1. 平台所有者确认切换日期。
2. 在共享笔记里通知全部会话：从这一刻起不再按文件推，要上线找 F1。
3. 日常会话把手头已经推到生产、但还没提交的改动全部提交并推送（切换前 F1 用 `tools/deployment/inventory/drift.py` 再盘点一次，确认生产和主线逐文件一致）。
4. F1 提交上面两处改动并合进主线。
5. 第一次正式发布（见 `m1-first-production-release-plan.md`）。

## 为什么必须先切换、再合并各模块（已核实）

各模块分支都往共用入口里加了“无条件启动自己的类 / 载入自己的文件”的行：

| 共用文件 | 模块分支加进去的 | 单独推这个文件会怎样 |
| --- | --- | --- |
| `peptide-core/includes/class-plugin.php` | `Staff_Buyer`、`Buyer_Status`、`Series_Sync`、`Order_Report`、`Pricing\Runtime`、`Data\Metrics_Switch` 六行 `::register_hooks()` | 对应的类文件不在服务器上 → 每个请求都报“找不到类”，**整站 500** |
| `peptide-hq/includes/class-plugin.php` | `Releases`、`Series`、`Orders` 三行 | 总后台整站 500 |
| 主题 `functions.php` | 五行 `require_once`（`storefront-identity`、`entry-address`、`product-trust`、`product-activity`、`front-speed`） | 文件不在 → 前台整站 500 |

日常会话现在的习惯是“改了哪些文件就推哪些文件”，而且经常改 `class-plugin.php`（每加一个新类都要改）。所以只要模块分支先进了主线，它下一次按文件推 `class-plugin.php` 就会出事——和 11-10 那次全站 500 是同一类事故。除了这三个入口，模块分支改过的其他现有文件也可能依赖新文件，按文件推都不安全。

结论：**先让所有人改用整包发布，再把模块分支合进主线。** 整包发布不存在“推了入口没推文件”的问题，而且沙盒那一步会先把这类错误拦住（11-13 演练一就是这种错误，5 秒发现、6 秒回滚）。
