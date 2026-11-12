# M4 价格引擎 · 沙盒走查清单（给 F1 照着跑）

对象：沙盒店铺站（`sandbox-shop`）。前提：沙盒上的 `peptide-core` 是含 M4 的代码（分支 `claude/m4-pricing-35ba90`）。全程不碰生产。下面的 `wp` 都在沙盒店铺站的目录里执行。

走完要回答三个问题：关着时和以前一样吗？影子档有没有不一致？开档顾客看到的数字变没变？

---

## 第 0 步 · 关着时什么都没变（1 分钟）

```bash
wp peptide price-engine status
```

```bash
wp db query "SHOW TABLES LIKE '%pc_m4_shadow%'"
```

```bash
wp eval 'var_dump( \PeptideCore\Activity\Quote_Cart::supported_hooks() );'
```

| 看什么 | 通过 |
| --- | --- |
| 第一条的输出 | `档位：off`，下一行“最近 7 天没有核对记录。” |
| 第二条的输出 | 空（关着时不建表） |
| 第三条的输出 | `bool(true)`。**如果是 `bool(false)`：加购推荐现在是关着的**，原因和修法见 `docs/CONTRACT.md` M4 一节“给日常会话”第 1 条；这不是 M4 引起的，但要先告诉平台所有者 |

## 第 1 步 · 一条命令走完 24 个组合（约 1 分钟）

```bash
M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-shadow-walkthrough-native.php
```

它做什么：临时建 9 个隐藏的测试商品、3 个满减活动、7 张优惠码、1 个赠品活动、1 个捆绑、1 个运费折扣，把首单优惠临时指到测试券上；用真实的 WooCommerce 购物车把下表的组合在**影子档**和**开档**各走一遍；每个组合算完后引擎再算一遍并逐项核对；最后删除临时数据，把首单优惠、运费折扣、手动订单链接、档位恢复成运行前的值。不建订单、不发邮件、不碰支付。

| 组合 | 怎么造的（脚本里自动做） |
| --- | --- |
| 单品 / 多品，没有优惠 | 加 1 件、加两种 |
| 满额减元：刚好到门槛、差一分、第二档 | 两个 $50 的商品凑 $100.00；$50 + $49.99；四个 $50 |
| 两个活动各管各的 | 专辑满 2 件 9 折 + 指定商品第 2 件 7 折 |
| 优惠码 10% 叠加满减 | 满减生效的购物车里输可叠加的券 |
| “不叠加”的券遇到满减 / 没遇到满减 | 满减生效时输（应被拒绝）、没到门槛时输（应生效） |
| “替换满减”的券 | 输了以后满减不算 |
| 活动设了不能和优惠码同时使用 | 输任意券后这个活动不算 |
| 两张券（按比例 + 固定金额） | 三种商品，先后输两张 |
| 每件减 $3、最多 2 件、不含促销商品 | 促销商品 + 普通商品 |
| 券的满金额条件：够 / 不够 | $60 门槛，$60 和 $40 的购物车 |
| 首单券：新顾客 / 加满减 / 下过单的邮箱 | 结账邮箱分别是新邮箱和沙盒里已有已付款订单的邮箱（只读，不显示） |
| 赠品：满额自动送、遇到券不送 | 促销商品满 $100；再输一张券 |
| 捆绑一口价 + 普通商品 | 加一套捆绑再加一件 |
| 离开挽回 / 转盘发的券 | 把券记在会话里再用（和弹窗领券一样） |
| 手动订单：改价 + 自定义运费 + 自定义优惠 | 建一条手动订单链接，按链接的内容搭购物车 |
| 手动订单：开店铺满减 + 开首单优惠 | 同上，这一单选了一个满减、开了首单 |

**看哪里**：

- 每行开头是 `ok` / `FAIL` / `跳过`，后面是这个组合的应付总额、优惠码、满减、运费。
- `== 档位：shadow ==` 和 `== 档位：on ==` 两段各 24 行。
- 倒数第三行“请求末尾的核对记录”：应该有 `"compared":1` 以上、`"mismatched":0`。
- 最后两行：`RESULT 一致 48，不一致 0，跳过原因 0 种：全部一致` 和“临时数据已删除……（档位：off）”。

**什么算通过**：退出码 0，`不一致 0`，最后一行的档位是 `off`（或运行前的值）。

**不通过怎么办**：

| 现象 | 做法 |
| --- | --- |
| 有 `FAIL`，下面写“引擎和线上不一致” | 把整段输出原样发给 F2。不要开影子档以外的档 |
| 有 `FAIL`，下面写“开档和影子档顾客看到的数字不同”或“开档下引擎没有接管” | 同上；另外执行 `wp peptide price-engine log --limit=5` 把输出一起发 |
| 全部是 `跳过`，退出码 2 | 看“跳过的原因”。`prices_include_tax` / `display_including_tax` = 沙盒开了含税价，引擎不支持；`foreign_fee` / `filter:…` = 有别的插件改了金额。把原因发给 F2，这家店的设置需要先确认 |
| “首单券：下过单的邮箱”那一行没有出现 | 沙盒里没有已付款订单，这一项没跑；不算失败 |
| 运费一列全是 0.00 | 沙盒对美国地址没有运费方案，运费折扣没被走到。先确认运费方案（$15 / $45）在沙盒生效，再跑一次 |
| 脚本中途被打断 | 再跑一次即可恢复（它每次都从干净状态开始）；另外检查 `wp peptide price-engine status` 是不是 `off`，不是就 `wp peptide price-engine mode off`；临时商品名字以 `M4WT` 开头，可在商品列表里搜出来删掉 |

2026-11-12 F2 在本机真实站点（WordPress 7.1.4 + WooCommerce 11.2.1，SQLite，运费 $15）上的结果：一致 48，不一致 0，跳过 0。

## 第 2 步 · 按系列接收活动（约 30 秒，M3 合进来之后再跑也可以）

```bash
M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-series-campaigns-native.php
```

通过：最后是 `RESULT 23 项检查：全部通过`，退出码 0。有 `FAIL` 时把输出发给 F2。

## 第 2b 步 · 基线里归 F2 的 5 个冒烟用例（约 2 分钟，用你的 `tools/sandbox/run-smoke.sh`）

2026-11-12 基线（`12b0f63`）里这 5 个是 `skipped`。根因和处理见 `docs/handoffs/m4-pricing-engine.md` 第三部分的“认领的冒烟用例”。换上本分支后在沙盒重跑，应该是：

| 用例 | 基线 | 现在应为 | 备注 |
| --- | --- | --- | --- |
| `smoke-exit-intent` | 30 / 7 | 40 / 0 | 修的是代码（统计表不在时自动补建），加了 3 项检查 |
| `smoke-marketing` | 80 / 1 | 81 / 0 | 用例不再看店铺自己的满减 |
| `smoke-promotions` | 33 / 1 | 34 / 0 | 同上 |
| `smoke-pricing` | 22 / 1 | 23 / 0 | 用例改为比较商品部分 |
| `smoke-shipping-plans` | 29 / 2 | 31 / 0 | 用例里当作没有店铺的运费折扣 |
| `smoke-data-apps`（F4 名下） | 13 / 8 | 21 / 0 | 和 `smoke-exit-intent` 同一个根因，F2 的修复顺带解决；F4 的文件没有改 |

通过后请把 `tests/smoke-suites.json` 里这几条的 `status` 改回 `gate`（`smoke-data-apps` 和 F4 说一声）。还有不过的，把那个用例的完整输出（不只是前两条 `FAIL`，连同 `WordPress database error` 开头的行）发给 F2。

顺带请在沙盒店铺站看一眼，确认根因（只读）：

```bash
wp db query "SHOW TABLES LIKE '%pc_app_stats'"
```

```bash
wp option get peptide_core_app_stats_db
```

第一条是空、第二条是 `1` = 和 F2 在本机复现的情况一样（版本号在、表不在）。生产 6 家店的同样两条命令见 `docs/CONTRACT.md` M4 一节“给 F1”第 8 条。

## 第 3 步 · 开影子档，用浏览器走真实流程（约 20 分钟）

脚本走的是服务端的计算；下面这些只有浏览器里才会发生，需要人点一遍（手机宽度，沙盒店铺前台）。

```bash
wp peptide price-engine clear
```

```bash
wp peptide price-engine mode shadow
```

| # | 做什么 | 走到哪里 |
| --- | --- | --- |
| 1 | 加一件商品，打开购物车抽屉，改数量到 3 再改回 1 | 购物车 |
| 2 | 加到满减门槛以上（沙盒是 s0010 的活动），看进度条和商品页的满减券解锁 | 结账页 |
| 3 | 在首页首单券条点“Claim”（或在购物车手输 WELCOME5） | 结账页，填一个新邮箱 |
| 4 | 结账页把邮箱改成沙盒里下过单的邮箱 | 看 WELCOME5 被移除后的总额 |
| 5 | 用带 `?pc_coupon=<沙盒里一张有效的码>` 的链接进站，再加商品 | 购物车 |
| 6 | 触发离开挽回弹窗（手机按返回键）并领取；如果沙盒开的是转盘就转一次 | 购物车 |
| 7 | 购物车里出现加购推荐时点“加一盒” | 购物车（第 0 步是 `false` 的话这里不会出现，跳过） |
| 8 | 结账页填美国地址，切换两种运费 | 结账页 |
| 9 | 后台“订单 → 手动订单”建一条（改价、自定义运费、加一笔优惠、开一个满减），用无痕窗口打开链接 | 结账页 |

每做完两三项看一次：

```bash
wp peptide price-engine status
```

```bash
wp peptide price-engine log --limit=10
```

| 看什么 | 通过 |
| --- | --- |
| `status` 里 `shadow：核对 N 次，一致 N，不一致 0，跳过 x，出错 0` | 核对次数随操作增加；**不一致 0、出错 0** |
| `log` | “没有明细。”，或只有 `[shadow/skipped]`（原因 `stale_cart` 是购物车改完还没重算的瞬间，正常） |
| 前台 | 每一步的价格、弹窗、进度条和开影子档之前一样（影子档不改任何显示） |

出现 `[shadow/mismatched]` 或 `[shadow/errors]`：把那条的整行 JSON 发给 F2（里面有购物车内容和两边的数字，没有顾客邮箱）。

## 第 4 步 · 收尾

- 全部通过：沙盒可以留在影子档继续观察，或 `wp peptide price-engine mode off`。把第 1、3 步的最后几行输出贴进发布记录。
- 生产上怎么开：见 `docs/handoffs/m4-pricing-engine.md` 第四部分（先试点店影子档 24 小时，再全部店，连续几天不一致为 0 才考虑开档）。
- 任何时候要关：`wp peptide price-engine mode off`；够不着后台时在该店 `wp-config.php` 里加 `define( 'PC_PRICING_ENGINE_MODE', 'off' );`。

## 附 · 不需要站点的测试（任何有 PHP 8.1+ 的机器）

```bash
node tests/run-data-observations.cjs --suite m4-price-engine-pure --suite m4-engine-mode-pure --suite m4-marketing-quiet-pure --suite m4-campaigns-readonly-pure --suite m4-marketing-quiet
```

五行都是 `"exit_code":0` 即通过（2026-11-12：对照 59 个样例、档位 37 项、静默 17 + 16 项、只读 50 项）。想用真实的 WooCommerce 源文件复核金额算法：

```bash
PC_WC_SRC=/path/to/wp-content/plugins/woocommerce php -n wp-content/plugins/peptide-core/tests/m4-price-engine-pure.php --fuzz=30000
```

最后一行应是 `RESULT 59 个样例 + 30000 份随机购物车：全部逐分一致`。
