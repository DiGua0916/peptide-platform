# 正式站上线计划（草案，待用户批准）— 2026-11-07

> 本文只是计划，还没有对正式站做任何操作。每个阶段开始前都要用户明确说"开始第 N 阶段"。
> 原则：先保证营销功能和下单不出问题，再谈提速；每一步都能在 1 分钟内回滚。

## 1. 现状（2026-11-07 只读检查）

| 组成 | 位置 | 当前版本 | 说明 |
| --- | --- | --- | --- |
| 独立站前台（顾客访问） | 34.231.148.231，`www.vialcovepeptides.com`，前面有 Cloudflare | `checkout-return-fixes-1793879522` = main 最新提交 `b1c1717`（11-05；所有待改文件逐个核对过哈希，0 处不一致） | 4 核 / 7.6 GB；PHP 进程池 `vialweb01`，ondemand 模式，最多 8 个进程；没有 Redis；数据库 `vialcove_web01` |
| 店铺后台 s0001 | 54.144.250.46，`s0001.vialcoveresearch.com` | `store-admin/releases/checkout-return-fixes-1793879470`（11-05） | **和前台共用同一个数据库**（通过隧道连接），所以必须和前台一起升级 |
| 总后台 HQ | 54.144.250.46，`vialcoveresearch.com` | 11-05（同 `b1c1717`，0 处不一致） | 本计划只改 5 个文件：peptide-hq 1 个、peptide-ops 1 个、panapay 3 个 |

- 发布方式：每个版本是 `/srv/vialcove/releases/` 下一个只读目录（属主 root），`current` 是指向当前版本的软链接；uploads 和配置都放在共享目录（`/srv/vialcove-data/sites/web01/`）。**切换和回滚都只是改软链接。**
- 定时任务每分钟运行一次，每天自动备份（最近一次 11-06 19:42 UTC）。今天 PHP 错误日志 0 条。
- 实测：首页单个访客 TTFB 约 2.0 秒（没有缓存）；图片/CSS/JS 在浏览器和 Cloudflare 都缓存 7 天；商品图只有 PNG（首页一张就有 377 KB），没有 WebP。

## 2. 这次上线的内容

分支 `codex/checkout-acceptance-20261105` 的提交 `98481cb`，相比正式站（`b1c1717`）多了 40 个提交。运行文件共 90 个（22 个新增，0 个删除）：peptide-core 25 个、主题 60 个、panapay 3 个、peptide-hq 1 个、peptide-ops 1 个（测试和文档不上线）。所有 PHP/JS 文件都通过了语法检查。

> 2026-11-07 第 0 阶段只读核对：起初按 `033d622` 比对，发现 29 处不一致，查明是正式站已经上了 main 上 11-05 晚间的提交（`38c8329`、`154a507`、`3ba5cb6` 等）。改按 main 最新提交 `b1c1717` 比对后，前台、s0001、HQ 都是 0 处不一致，说明没有人直接在服务器上改过文件。

主要包括：

- 压力测试修复：加购并发锁、结账摘要显示数量/每盒 10 支、已取消订单收到付款改为"保留"状态、总后台新增收款方式默认关闭；
- 首页缓存（**默认关闭**，第 2 阶段才打开）、区块缓存、广告落地页缓存；
- 后台提速第一批；
- 前台手机优化：弹窗脚本按需加载、非阻塞样式、目录首屏、响应式卡片图；ViewContent（像素 + CAPI）；
- 其他提交（Codex 11-06 的商品/COA 冷加载、手机 SKU 图片等）。这些都已经在沙盒上运行并验证过。

不在本次范围：WebP（第 4 阶段，需要改代码）、Redis、营销活动的具体设置（由用户在后台自己开）。

## 3. 需要用户先决定的事

1. **从哪里部署**：✅ 用户 2026-11-07 已批准：直接从分支提交 `98481cb` 部署，不合入 main。发布目录名为 `98481cb-web01` / `98481cb-s0001`。
2. **上线时间**：选广告流量最低的时段，各阶段间隔至少 30 分钟观察。
3. **测试订单**：正式站是真实收款。第 1 阶段之后，需要**你本人**用手机下一单小额测试单（可以事后退款），或者暂时打开一个只在测试用的付款方式。我不会在正式站代下单。
4. **"测试"商品 11738** 上线前下架（你说过自己处理）。
5. **营销设置**：活动监听、推荐加购、推荐券在正式站要不要开、关联哪个活动、券的金额，由你在后台设置。我只确认它们能正常工作。

## 4. 分阶段步骤

### 第 0 阶段：准备（不影响线上，约 30 分钟）

- [ ] 从 `98481cb` 导出运行文件，生成 sha256 清单；每个文件记录"正式站当前哈希"和"新哈希"。正式站当前文件必须和 `b1c1717` 一致（11-07 已只读核对：0 处不一致），否则停下来查原因（防止有人在服务器上直接改过文件）。
- [ ] **数据库备份**：把 `vialcove_web01` 完整导出到 `/srv/vialcove-data/sites/web01/private/` 下（只有 root 能读），并核对行数和大小。HQ 数据库同样备份。
- [ ] 记录当前软链接目标（前台、s0001、HQ）和 PHP 错误日志的当前大小，作为回滚点和对比基线。
- [ ] 基线测速：首页、商品落地页、结账页各 3 次（单个访客，不压测）。

### 第 1 阶段：前台 + s0001 同时换代码，**缓存保持关闭**（约 15 分钟）

1. 把当前发布目录完整复制成新目录 `releases/98481cb-web01`，用清单里的新文件覆盖；s0001 同样处理（`store-admin/releases/98481cb-s0001`）。
2. 逐个核对哈希，PHP 语法检查（`php -l`）全部通过。
3. **原子切换**：两个 `current` 软链接在同一分钟内用 `ln -sfn` + `mv -T` 切换，然后平滑重载 PHP（前台 `php8.3-fpm`，s0001 只重载它自己的进程池）。
4. 验证（全部通过才继续，否则立刻回滚）：
   - [ ] 首页、商品弹窗、购物车、结账页返回 200，手机尺寸显示正常；
   - [ ] 购物车里的满减、档位进度条（如果已开）、优惠券计算正确；
   - [ ] 你本人下一单测试单，订单状态、邮件、库存都正常；
   - [ ] Facebook 事件管理工具（测试事件）依次收到 PageView → ViewContent → AddToCart → InitiateCheckout → Purchase，浏览器和服务器的事件能去重；
   - [ ] s0001 后台能打开，订单列表、商品编辑正常；
   - [ ] 10 分钟内 PHP 错误日志没有新错误。
5. **回滚**（1 分钟）：把两个软链接指回旧目录，重载 PHP。数据库不需要回滚：这次没有改表结构，只有 tracking 的表结构检查标记改成存一个选项，旧代码会忽略它。

### 第 2 阶段：打开首页缓存（约 10 分钟）

1. 在 `/srv/vialcove-data/sites/web01/config/wp-config.php` 加一行 `define( 'PEPTIDE_CORE_PAGE_CACHE', true );`，改之前先备份，保持属主和权限不变。
2. 验证：
   - [ ] 首页 MISS→HIT；`?fbclid=`、`?vc_product=ID` 都命中缓存；已加购的访客不走缓存；
   - [ ] 在服务器本机模拟 10 个新访客同时加购，每个人的购物车只有自己的商品（同沙盒的测试）；
   - [ ] 缓存页面里的活动倒计时正确，换活动或改价格后缓存自动失效；
   - [ ] 像素：缓存页面上每次浏览的事件 ID 都不同。
3. **回滚**：删掉这一行（或改成 false），立即生效；缓存目录可以留着，也可以清空。

### 第 3 阶段：总后台 HQ（约 10 分钟，可以另选时间）

- peptide-hq 2 个文件（后台导航和预加载链接）、panapay 总后台"新增收款方式默认关闭"、peptide-ops 建店后删除明文凭据文件。做法同上：新发布目录 + 切换软链接，可以回滚。
- 验证：总后台能登录，店铺列表正常，收款账户列表没变。

### 第 4 阶段：WebP（需要先开发，单独批准）

- 沙盒的做法是"同一个图片地址按浏览器的 Accept 返回 PNG 或 WebP"。**正式站前面有 Cloudflare，这个做法不安全**：Cloudflare 免费版同一个地址只缓存一份，可能把 WebP 发给只要 PNG 的浏览器。
- 推荐做法：离线生成 `.png.webp` 副本（无损、逐像素核对，原图不动，沿用 `tools/sandbox/lossless-webp.php`），页面里的图片地址**直接改成 .webp 文件**（只在副本存在时改）。这样每个地址只有一种内容，在 CDN 上是安全的。需要新增少量代码并在沙盒验证，预计能省 30–40% 的图片流量。
- 备选：如果 Cloudflare 是 Pro 套餐，可以开启它自带的 Polish（自动 WebP），不用改代码。

### 第 5 阶段：后续（单独评估）

- 打开购物车时预先加载结账脚本；
- Redis 对象缓存（回头客和结账提速）；
- 上线挽回弹窗/大转盘之前，测"打开商品 → 按返回 → 弹窗"这条路径。

## 5. 上线后 24 小时监控

- PHP 错误日志（前台 `/srv/vialcove-data/sites/web01/private/php-error.log`，s0001 同样看）；
- 订单数量和付款成功率，和前一天同时段对比；
- Facebook 事件管理工具：事件量、去重率、匹配质量；CAPI 发送记录（插件的发送日志表）；
- 首页缓存命中率（响应头 `X-PC-Page-Cache`），以及 TTFB 抽查；
- 出现异常就按对应阶段回滚，事后复盘。

## 6. 风险和应对

| 风险 | 可能性 | 应对 |
| --- | --- | --- |
| 前台和 s0001 版本不一致（共用数据库） | 低 | 第 1 阶段两边同时切换；回滚也两边同时 |
| 缓存导致顾客看到别人的购物车 | 极低（沙盒 10 并发实测没有串号；缓存不保存 Set-Cookie） | 第 2 阶段再测一次；有疑问立刻关掉缓存 |
| 活动或价格改了，页面还显示旧的 | 低（改商品、库存、活动、选项都会让缓存失效；最长 5 分钟） | 大促切换前可以先关缓存 |
| 付款/订单异常 | 低（第 1 阶段由你本人下测试单验证） | 回滚第 1 阶段 |
| 35 个老测试本来就失败 | 已知，和本次改动无关 | 以后单独清理 |

## 7. 执行记录

### 第 0 阶段（2026-11-07 11:27–11:28 UTC，用户批准"Start now"）

- 独立站数据库 `vialcove_web01`：一致性导出到 `34.231.148.231:/var/backups/vialcove-rollout/20261107T112756Z/vialcove_web01.sql.gz`（只有 root 能读），共 3.1 MB；71/71 张表，导出完整（Dump completed），订单 20 笔。同一目录里还有改前的 wp-config.php 副本、旧版本路径和 PHP 错误日志位置。
- 总后台数据库：导出到 `54.144.250.46:/var/backups/vialcove-rollout/20261107T112756Z/hq.sql.gz`，18/18 张表。第一次用 WP-CLI 导出得到的是空文件（vchq 用户读不到一个 MySQL 配置文件，`--no-tablespaces` 也被转换错了），改用 root 加临时凭据文件导出，用完已删除。
- 回滚点：前台 `checkout-return-fixes-1793879522`，s0001 `checkout-return-fixes-1793879470`，HQ `checkout-return-fixes-1793879606`（HQ 本阶段没动）。

### 第 1 阶段（11:30:36 UTC 切换）

- 新版本目录 `/srv/vialcove/releases/98481cb-web01` 和 `/srv/vialcove/store-admin/releases/98481cb-s0001`：都是从旧版本完整复制，再覆盖 89 个文件（peptide-hq 两边都没有安装，跳过）。逐个核对哈希，服务器上 PHP 语法检查 0 失败；和旧版本相比恰好 89 处不同。
- 前台新版本里加了 `wp-content/cache → /srv/vialcove-data/sites/web01/cache`（属主 vialweb01，权限 750），给第 2 阶段的缓存用。目前缓存开关没开，这个目录不会被使用。
- 切换前用 WP-CLI 加载新版本做冒烟测试，两边都能正常启动。两边同时原子切换软链接，然后对 php8.3-fpm 做平滑 reload。前台的 systemd 提示"单元文件有改动，需要 daemon-reload"，这是服务器原本就有的提示，和本次无关，我没有执行 daemon-reload。
- 切换后验证：
  - 首页、带 fbclid 的首页、购物车返回 200；结账页在空购物车时 302 跳到购物车（正常）；Store API 正常；s0001 和 HQ 跳到登录页，s0001 登录页返回 200；
  - 新功能都在：18 个按需加载的弹窗脚本加上加载器，目录 83 张卡里 75 张初始隐藏；
  - 正式站真实加购返回 201，再删除返回 200，购物车清空。商品  + 运费  = ；运费来自店铺现有设置，本次没有改动任何运费代码；
  - 手机尺寸浏览器：弹窗脚本未执行时点商品（当时 17 个未执行），弹窗正确打开了 Tirzepatide；黑五倒计时、29% OFF、.00/box、每盒 10 支、动态播报都正常；控制台 0 个错误；
  - 切换后前台和 s0001 的 PHP 错误日志都没有新内容。
- 速度：首页（缓存还没开）TTFB 从改前约 2.0 秒降到约 0.82–0.93 秒。

### 发现：正式站没有安装任何广告像素

- 插件的像素设置从来没有保存过（`any pixel configured: 0`）；页面上也没有 Facebook、GTM、Cloudflare Zaraz 或 TikTok 的代码。这和本次上线无关，改前也是这样。
- 影响：Facebook 广告目前收不到网站的任何转化数据（PageView、ViewContent、加购、下单都没有）。
- 需要用户在店铺后台"系统设置 → 广告像素"里填 Facebook 像素 ID 和 Conversions API 访问令牌（令牌只能由用户自己填写）。填好后再用 Facebook 事件管理工具的"测试事件"核对第 1 阶段的事件链。

### 结账页验证（不付款）

- 手机尺寸：从弹窗加购 → 购物车抽屉（黑五档位进度条正式站已经开着："Spend $245.00 more to unlock $25.00 off"）→ 结账页。字段齐全，电话必填，付款方式有 Credit card 和 PayPal，下单按钮在；"Qty: 1 box · 10 vials per box"、黑五倒计时、$23 商品优惠、合计 $100（含运费 $45）都显示正常；控制台 0 个错误。测试商品已从购物车删除。
- 用户 PayPal 账号被封，目前没有可用的测试付款通道，所以真实下单测试先跳过。用户说明之前用 PayPal 测试账户下单成功过。

### 第 2 阶段：首页缓存已开启（用户要求今晚上线，"先把上线前的流程做好"）

- 在 `/srv/vialcove-data/sites/web01/config/wp-config.php` 第 2 行加了 `define( 'PEPTIDE_CORE_PAGE_CACHE', true );`。属主和权限没变（root:vialweb01 640），语法检查通过，缓存有效期 300 秒。改前副本在第 0 阶段的备份目录里。**回滚：删掉这一行。**
- 服务器本机验证：
  - 首页 MISS→HIT；`?fbclid=` 命中；`?vc_product=11306` 先 MISS，带 fbclid 和 utm 的同一商品命中；不存在的商品返回 BYPASS；
  - 10 个新访客同时访问：10/10 都拿到缓存页，没有带出别人的会话 Cookie，加购都返回 201，每个人的购物车只有自己的商品；加购后再访问首页都是 BYPASS；测试商品都已删除。
- 外部经过 Cloudflare 实测（美国）：缓存命中后广告落地页 TTFB **0.18–0.22 秒**（改前约 2.0 秒）；不走缓存时 0.44–0.87 秒。
- 已知局限：缓存副本按访客国家和设备分别保存，而且只有不带参数的 `/` 或 `/?vc_product=ID` 能生成副本，带 fbclid 的广告落地页只能用、不能生成。如果流量几乎全部来自 Facebook 链接，缓存经常是空的，访客会走不缓存的页面（0.5–0.9 秒，仍然比改前快）。后续改进：允许广告落地页生成副本（跟踪参数不影响页面内容；点击 ID 的 Cookie 照常按访客设置，不会存进副本）。需要先在沙盒验证。
- 切换后 PHP 错误日志 0 字节。

### 广告落地页缓存更新（11:58:34 UTC，用户批准"Deploy to production"）

- 只换了 `class-page-cache.php` 一个文件（提交 6d8a16a）。按发布目录的方式做：新建 `releases/6d8a16a-web01` 和 `store-admin/releases/6d8a16a-s0001`，都是从 98481cb 版本完整复制后只改这一个文件；部署前核对了线上文件是 98481cb 版本，和旧目录相比恰好 1 处不同；冒烟测试能正常启动；两边同时切换软链接，然后平滑 reload PHP。
- **回滚**：软链接改回 `98481cb-web01` 和 `98481cb-s0001`。
- 验证（正式站服务器本机）：
  - 首页、购物车、Store API 返回 200；
  - 访客 A（fbclid+utm）MISS 并生成副本，访客 B（另一个 fbclid）HIT；商品落地页 C 先 MISS，D 命中 HIT；
  - B 的页面里没有 A 的点击 ID 和活动名，D 的页面里没有 C 的点击 ID；语言链接是干净的；
  - 每个访客的 `_fbc` 都是自己的；
  - 10 个新访客同时访问：10/10 命中缓存，加购返回 201，购物车互不串，加购后首页都是 BYPASS，测试商品都已删除；
  - PHP 错误 0 字节。
- 外部经过 Cloudflare（美国），只用广告链接：第一次 MISS 0.91 秒 / 0.58 秒（商品），之后都是 HIT，**0.18–0.30 秒**。

### 第 3 阶段：总后台 HQ（12:07:25 UTC，用户要求"服务器更新到优化后的最新版本"）

- 先重新备份 HQ 数据库：`54.144.250.46:/var/backups/vialcove-rollout/hq-20261107T120656Z/hq.sql.gz`，18/18 张表，导出完整。
- 新版本目录 `/srv/vialcove/hq/releases/6d8a16a-hq`：从旧版本完整复制后改了 5 个文件，分别是 peptide-hq 的 `admin/class-menu.php`、peptide-ops 的 `class-provisioner.php`、panapay 的 `class-hub-accounts.php`、`class-hub-page.php`、`class-session-service.php`。部署前核对线上这 5 个文件和 `b1c1717` 一致；装好后哈希和语法都通过；和旧目录相比恰好 5 处不同；冒烟测试能正常启动。然后切换软链接，平滑 reload PHP。
- **回滚**：软链接改回 `checkout-return-fixes-1793879606`。
- 验证：
  - HQ、s0001 登录页和独立站首页、购物车都返回 200；3 个店铺都在线；HQ PHP 错误 0 字节；
  - 总后台新增收款方式的默认值是 `enabled=no, test_mode=yes, all_sites=no`；
  - 独立站的付款方式没变：Credit card 和 PayPal 都可用。
- 至此三台都是最新代码：前台和 s0001 是 `6d8a16a`，HQ 是 6d8a16a 时的代码（HQ 没有 peptide-core，所以页面缓存的改动和它无关）。

### 营销设置：正式站和沙盒对比（只读）

- 两边一样：活动监听开着，关联"黑色星期五"（10980），推荐加购开着；都有首单券 WELCOME5。
- 不一样：沙盒有推荐券 ADDON5（整单减 $5，可叠加）并已开启；正式站没有这张券，推荐券也没开。在正式站建券会影响真实成交价，要用户决定。

### 正式站推荐券 ADDON5（用户批准"Yes, same as sandbox"）

- 新建优惠券 ADDON5（ID 11758）：整单减 $5，`fixed_cart`，不限单独使用，叠加策略为 stack，不是首单券，所有顾客都能用。系统校验它可以作为推荐券（supported=1）。
- 活动监听用插件自带的保存逻辑发布为第 6 版：开启推荐券，关联 11758，提示文案 "Add it now and get an extra $5 off with ADDON5."。活动、推荐加购和其他设置都没变。
- 正式站手机尺寸实测：购物车 $440（离 $500 档差 $60），看过 EPO（$66）后，购物车里出现推荐加购卡片和 ADDON5 提示；点"Add 1 box"后，黑五满减从 −$25 升到 −$55，ADDON5 −$5 自动用上，合计 $491（含运费 $45）。测试用的 2 个商品和优惠券都已从购物车删除。
- **撤销方法**：后台 应用 → 活动监听 关掉"推荐优惠券"，或者把优惠券 ADDON5 移到回收站。

### 待办

- 用户本人用手机下一单小额测试单（第 1 阶段最后一项验证）。
- 用户配置像素后，核对 Facebook 测试事件。
- 后续：WebP；PayPal 通道恢复后补做真实下单测试；广告像素由用户配置后核对 Facebook 测试事件。

### 后台「数据」10 个页面版式对照参考后台（用户批准 "Deploy to production"，2026-11-07 16:01 UTC）

- 内容：提交 `f2384a9`、`c466262`、`1786bba`、`6322d02`（实时数据、运营数据、其余 8 个数据页的版式；商品小图 / 国旗；口径说明默认收起），外加从 main cherry-pick 的 `ee0774f` → `0f77b63`（Codex 的实时面板时区标签，正式站已有，带上避免回退）。共 17 个运行文件，全部在 peptide-core：13 个前端资源、`class-data-pages.php`、`class-rankings.php`、`class-realtime.php`、`customer-data-observations-zh_CN.php`。接口只多了字段：实时商品条目加 `image`、`sku`，排行行加 `image`。
- 部署前核对：s0001 当前 `02e5e6d-s0001`、web01 当前 `02e5e6d-web01`，17 个文件的线上哈希都和基线一致（其中 `data-realtime-dimensions.js` 的基线是 main 的 `ee0774f`）。
- 新版本目录：`/srv/vialcove/store-admin/releases/6322d02-s0001`、`/srv/vialcove/releases/6322d02-web01`，都是从当前版本完整复制后覆盖 17 个文件；哈希全部一致、PHP 语法检查通过、和旧目录相比恰好 17 处不同；两边冒烟启动都通过。16:01:10 两边同时切换软链接并平滑 reload PHP。
- 验证：前台首页 / 购物车 200；s0001 登录页 200；切换后 3 分钟内 PHP 错误日志 0 条；正式后台实时数据、运营数据（近 7 天）用真实数据显示正常，排行表商品小图正常加载。
- 测试：本机 tools/node 跑 data-*.cjs 共 25 个套件，24 个全部通过；`data-native-search.cjs` 失败 1 项，在改动前的 `3c3c5e3` 上同样失败（原有问题，不是这次引入）。
- 回滚：把两个 `current` 软链接指回 `02e5e6d-s0001` / `02e5e6d-web01` 再 reload PHP。
- 附注：s0001 所在主机 reload 时 systemd 提示 php8.3-fpm 的 unit 文件在磁盘上被改过（需要 daemon-reload）；不是这次部署改的，未处理。

### 后台「数据」第二轮对照（用户批准 "Deploy to production"，2026-11-07 17:06 UTC）

- 内容：`72ab06f`…`8832e70`（参考后台式时间工具栏与对比下拉、共用分页、标题行操作位、实时页筛选进标题行、运营页一行指标条 / 漏斗分卡 / 流量渠道卡 / 趋势“查看更多” / 排行“指标1 / 指标2 / 同比”表头、转化率详情阶梯卡、邮件六格指标 + 每日发送曲线、应用逐日曲线、落地页列表 / 图表切换）。23 个运行文件，均在 peptide-core。接口只多了字段：应用统计 `apps[].series`（每日弹出次数），邮件 `series`（每日发送数，按店铺日期，夏令时安全）。
- 部署前核对：两边当前都是 `6322d02-*`，23 个文件线上哈希与 `6322d02` 一致；main 自 `ee0774f` 后无新提交。
- 新版本目录 `store-admin/releases/8832e70-s0001`、`releases/8832e70-web01`：完整复制后覆盖 23 个文件，哈希一致、语法通过、恰好 23 处不同；冒烟启动通过；17:06:57 两边同时切换并平滑 reload PHP。
- 验证：前台首页 / 购物车 200，s0001 登录页 200；切换后 PHP 错误日志 0 条；正式后台运营数据（近 7 天）、邮件详情（近 30 天，2,084 封、每日曲线）用真实数据显示正常。
- 测试：data-*.cjs 25 个套件 24 个通过，`data-native-search.cjs` 为原有失败。
- 回滚：两个 `current` 指回 `6322d02-s0001` / `6322d02-web01` 后 reload PHP。
- 未做（需要新的后端报表，不只是界面）：运营数据按入口域名筛选；独立的“款式详情数据”页。商品详情默认十列是既定约定（测试固定），未改。

### 运营数据入口域名筛选 + 款式详情数据（用户批准 "Deploy to production"，2026-11-07 17:34 UTC）

- 内容：`aea0a64`。13 个运行文件（新增 `includes/data/class-entry-domain.php`）。接口新增可选参数 `entry_domain`（operations / trends / rankings / rankings/table）和新接口 `/data/products/variations`；不带参数时响应与原来一致。
- 口径：入口域名 = `visits.attribution_host`（attribution_version = 1，且在时间段结束前保存）；订单按 `ORDER_VISIT_META` 归属。注册用户按域名为 null；八层明细漏斗按域名时不返回；访客来源 / 域名 / 国家、搜索词、收藏 5 张排行表仍按全部域名，并在 `all_domain_tables` 标出。
- 部署：两边当前 `8832e70-*`，12 个已有文件哈希一致、新文件不存在；新目录 `aea0a64-s0001` / `aea0a64-web01` 恰好 13 处不同，哈希、语法、冒烟启动通过；17:34:04 同时切换并 reload PHP。前台首页 / 购物车 200，s0001 200，PHP 错误日志 0。
- 正式数据只读核对（近 30 天）：全部访客 859、订单 4、销售额 1,119.25；3 个入口域名访客合计 819（另 40 个访客没有保存入口域名），订单 4 / 销售额 1,119.25 全部归属 www.vialcovepeptides.com；同一域名下指标、趋势、排行（销量 12）三处一致；款式 22 个；非法域名 400。
- 附注：入口域名里出现 `s0001.vialcovepeptides.com`（8 次访问）和 `vialcovepeptidescom.local`（2 次），像是内部 / 本地测试流量。
- 回滚：两个 `current` 指回 `8832e70-s0001` / `8832e70-web01` 后 reload PHP。

### 首页视频：封面、进入视口静音自动播放、离开暂停、间距（用户批准 "Deploy to production"，2026-11-07 17:58 UTC）

- 测量（改前）：用户上传的视频 `VialCove_v26_原仓储卡片恢复版.mp4`（附件 11760）1920×1080、92 秒、74.8 MB、约 6.5 Mbps；`preload=metadata` 使每次首页访问都对源站发 3 个范围请求；nginx 对 mp4 返回 `no-store`，Cloudflare 不缓存（DYNAMIC）；无封面，显示黑块；手机两侧缩进 40px（站点设计宽度 + 模块 20px 内边距叠加）。
- 转码：本机 AVFoundation 重新编码为 1280×720 H.264 约 1.77 Mbps + AAC 96 kbps，faststart，21.4 MB（附件 11762）。原文件保留在媒体库。封面取 0:10 的 VialCove Tirzepatide 瓶子画面，裁掉底部字幕、保留右上角水印，1600×900 JPEG（附件 11763，手机实际加载 768 宽约 95 KB）。
- 代码 `e51cb9a`（3 个运行文件：peptide-core `modules.css`、`modules.js`、`includes/modules/class-fallback.php`）：上传视频模块改为“舞台”——封面 + “▶ Watch video | 1:32”胶囊（不挡商品）、声音 / 全屏按钮、细进度条；视频不随页面下载（`data-src` + `preload=none`），页面 load 后 400ms 且卡片至少一半在视口内才接上并静音自动播放；离开视口或切后台暂停、回来继续；用户自己暂停后不自动恢复；省流量模式 / 减少动态效果只显示封面；“Tap for sound”提示 4 秒后收成图标不挡字幕；无 JS 时保留原生控件。站点设计下去掉重复内边距，卡片 14px（手机）/ 18px 圆角，手机上下 8 / 40px。
- 部署：两边当前 `aea0a64-*`，3 个文件线上哈希与 `d9b1d3d` 一致；新目录 `e51cb9a-web01` / `e51cb9a-s0001` 恰好 3 处不同（先删掉打包带进去的 macOS `._*` 文件），语法通过；切换软链接并 reload PHP。
- 内容：首页布局 685 的视频模块（已发布内容和草稿各 1 处精确替换）：视频 11760→11762、封面 11763、autoplay=true、muted=true、标题 “From research to your door.”；随后清页面缓存（generation→8）和对象缓存。
- nginx：`vialcove-web01-customer-domains.conf` 静态缓存规则加上 `mp4|webm|m4v`（只改这一行），`nginx -t` 通过后 reload。源站现在返回 `public, max-age=604800`，但 Cloudflare 对 mp4 仍是 DYNAMIC（同目录 JPG 正常缓存），是 Cloudflare 侧对视频的设置 / 策略，需要在 Cloudflare 后台加缓存规则或改用 Stream / R2。
- 验证：首页 200、s0001 200、PHP 错误日志无新增；手机视口下首屏前视频 0 字节、封面 768 宽、标题和时长正确、卡片左右 20px；页面加载后静音自动播放 720p 文件；Black Friday 条、购物车、客服按钮正常。
- 备份：`34.231.148.231:/home/ubuntu/vialcove-deploy-backups/video-stage-20261107T175839Z/`（685 已发布内容、草稿、nginx 配置）。
- 回滚：两个 `current` 指回 `aea0a64-web01` / `aea0a64-s0001` 后 reload PHP；用备份恢复 685 的 `_pc_published_content` / `post_content` 后清缓存；nginx 用备份配置覆盖后 `nginx -t` + reload。

### 首页视频手机端修正（用户批准 "Deploy to production"，2026-11-07）

- 手机预览发现：站点设计给所有按钮统一圆角（`.pc-design-styled :is(button…)`，8px），盖掉了播放胶囊 / 圆形按钮的圆角；视频开头是黑场，停在开头时显示黑画面。
- 代码 `4ff94fc`（2 个文件：peptide-core `assets/modules.css`、`assets/modules.js`）：舞台按钮用更高优先级保持 999px；在 0.5 秒内暂停时回到封面。
- 部署：两边当前 `70a65ac-*`（另一会话的数据页 CSS），两个文件线上哈希与 `e51cb9a` 一致；从 live current 复制出 `4ff94fc-web01` / `4ff94fc-s0001`，恰好 2 处不同，切换并 reload PHP，清页面缓存。首页 / s0001 200，PHP 错误日志无新增；手机视口下自动播放、按钮圆角 999px、停在开头回到封面。
- 回滚：两个 `current` 指回 `70a65ac-web01` / `70a65ac-s0001` 后 reload PHP。

### 商品链接落地计入商品查看（eeaefe9，用户批准 "Deploy to production"）

- 问题：商品链接（如 Facebook 广告）先跳转到 `/?vc_product=ID` 自动弹出商品窗口，没有点击，追踪不记 product_view，访问也到不了漏斗"查看商品"。沙盒模拟 30 人中 4 次查看漏记。
- 修复：主题 `inc/visitor-tracking.php` 在这种落地页把页面上下文报为该商品页（资格和跳转本身相同）；`tests/visitor-config.php` 197 项通过。
- 沙盒：shop/admin 同一文件替换，备份 `/home/ubuntu/vialcove-deploy-backups/entry-product-view-20261107T180127Z`，清页面缓存。120 访客 / 40 单模拟全部指标和预设一致（含 20 位商品链接直达访客）。
- 正式站：两边当前 `4ff94fc-*`，线上文件哈希等于基线；从 live current 复制出 `eeaefe9-web01` / `eeaefe9-s0001`，恰好 1 处不同，语法通过；18:23:58 UTC 同时切换并 reload PHP，清前台页面缓存。商品链接返回 `page_type: product`，首页仍为 home；首页 / 购物车 / s0001 200，PHP 错误日志无新增。
- 回滚：两个 `current` 指回 `4ff94fc-web01` / `4ff94fc-s0001` 后 reload PHP。

### 结账「Place Order」点按保护（b5c9f0b，用户批准 "Deploy to production"）

- 问题：刚在输入框打完字就点 Place Order，输入框失焦后 Woo 保存地址并短暂禁用按钮，浏览器把点击丢给父元素，这一下没反应（修复前实测：电脑 0/3、iPhone 1/3）。
- 修复：主题新增 `assets/checkout-place-order-tap.js`（由 `inc/cart-pricing.php` 只在结账页加载）。按位置判断按在按钮内、却没产生点击的一次点按，等按钮恢复可用后补点一次；每次点按最多一次，Woo 正在下单时不补。`tests/checkout-place-order-tap-browser.py` 20/20。
- 沙盒：真站点按 48/48、慢网络 18/18、少填 9/9 未提交；真实下单 60 访客 / 24 单（打完字立刻点、不重试，10 位连点两下）24/24，无重单，后台数据全部一致。
- 正式站：两边当前 `628c72b-*`，`cart-pricing.php` 线上哈希等于基线、新脚本不存在；从 live current 复制出 `b5c9f0b-web01` / `b5c9f0b-s0001`，恰好 2 处不同，语法通过；19:27:54 UTC 切换并 reload PHP，清前台页面缓存。iPhone / 电脑 / 安卓各打开结账页，脚本已加载，打完字立刻点一次即发出下单请求（请求被拦截，未到服务器，未产生订单；GPC 关闭统计；测试购物车已清空）。首页 / 购物车 / s0001 200，PHP 错误日志无新增，近 1 小时正式订单 0。
- 回滚：两个 `current` 指回 `628c72b-web01` / `628c72b-s0001` 后 reload PHP。

### 推广人专属链接（REF-01，用户批准 "Deploy to production"，2026-11-07）

- 内容：`4b8890c`（由 `260c21a` 变基而来），4 个运行文件：peptide-core 新增 `includes/class-referrals.php`、`includes/admin/class-referrals-page.php`；修改 `includes/class-plugin.php`、`includes/class-page-cache.php`。说明见 `docs/handoffs/referrals-2026-11-07.md`。
- 部署：两边当前 `89fe635-*`（另一会话的仪表盘版本），两个要改的文件线上哈希与 `4b8890c^` 一致、两个新文件不存在。第一次打包时 zsh 没拆开文件列表，生成了空包，两边在切换前就停了，留下的半成品目录与当前版本完全相同，已删掉。改用 bash 重做：`4b8890c-web01` / `4b8890c-s0001` 恰好 4 处不同，哈希、语法通过；切换并 reload PHP。
- 建表 `wp_pc_referral_touches`（schema 1），清页面缓存。推广人名单为空，所以功能暂不生效，等用户在后台 顾客 → 推广人 填写。
- 验证：首页 / 购物车 / s0001 200，PHP 错误日志无新增；`/?ref=a`、`/?ref=a&utm_source=fb` 页面缓存 HIT（约 0.4 秒，走 Cloudflare）；s0001 推广人页面正常显示。
- 回滚：两个 `current` 指回 `89fe635-web01` / `89fe635-s0001` 后 reload PHP（新表和选项留着不影响）。

### 总后台宽屏右侧留白修正（用户批准 "Deploy to production"，2026-11-07）

- 问题：Layout v2 把内容区限制在 1320px 并靠左，1920 宽屏右侧空出约 350px，和通栏顶栏对不齐。
- 代码 `94c322f`（1 个文件：peptide-hq `assets/hq-workspace.css`）：内容区不再限宽，左右与顶栏同为 32px；表单卡片保持 840px；手机边距 16px。
- 部署：线上 `3c3c5e3-hq` 的该文件哈希与 `3c3c5e3` 一致；复制为 `94c322f-hq`，恰好 1 处不同，切换并 reload PHP。HQ 登录页 200；1920 宽右边距 32px、无横向滚动；自动开店表单卡 840px；手机左右 16px。
- 回滚：`/srv/vialcove/hq/current` 指回 `3c3c5e3-hq` 后 reload PHP。

### 推广人菜单单独一项（用户批准 "Deploy to production"，2026-11-07）

- 问题：用户在“顾客”下面找不到“推广人”。原因是点“顾客”打开的是 WooCommerce 自带的顾客页，那里不展开子菜单。
- 代码 `0532574`（1 个文件：peptide-core `includes/admin/class-referrals-page.php`）：“推广人”改成左侧单独一项，菜单排好后放在“顾客”正后面。
- 部署：两边当前 `4b8890c-*`，文件哈希与 `4b8890c` 一致；`0532574-web01` / `0532574-s0001` 恰好 1 处不同，切换并 reload PHP。正式后台左侧菜单顺序：……营销活动、顾客、推广人、访客数据……；首页、s0001 均为 200。
- 回滚：两个 `current` 指回 `4b8890c-web01` / `4b8890c-s0001`。

### 推广人页面重新设计（用户批准 "Deploy to production"，2026-11-07 20:15 UTC）

- 代码 `554e242`（3 个运行文件：peptide-core `includes/admin/class-referrals-page.php`、`includes/class-referrals.php`，新增 `assets/referrals.css`）：
  - 顶部时间切换和 4 个汇总卡片；
  - 每个推广人一行，带复制链接按钮、四项数据，以及“订单 · 客户 · 编辑”操作；
  - 添加、改名、删除表单取代“代号|名字”文本框，删除要先确认；
  - 改归属和规则说明放在侧栏卡片里；客户列表按推广人筛选；
  - 手机宽度没有横向滚动。
- 范围：按另一会话的说明，新开店模板和 s0002 / s0003 也跑同一套代码，所以这次共更新 8 处。
  - 主店：`554e242-web01` / `554e242-s0001` 从 `0532574-*` 复制，恰好 3 处不同，切换软链接。
  - 就地更新：`/srv/vialcove/tenants/s000{2,3}/public`、`/srv/vialcove/tenant-admin/s000{2,3}/public`、`/srv/vialcove-provision-template/yy-sync-0532574-1794082452-{web,admin}/source`。每处先核对两个旧文件的哈希与 `0532574` 一致，再备份旧文件（`/home/ubuntu/vialcove-deploy-backups/referral-ui-20261107T2018*`），然后写入 3 个文件（root:root 644）。两台服务器 reload PHP。
- 验证：
  - 8 处文件哈希都与 `554e242` 一致，语法检查通过；
  - s0001、s0002、s0003 的推广人页面都能正常输出（以各自的 PHP 用户运行）；
  - 前台 www、s0002、s0003 首页和购物车返回 200，三个后台登录页返回 200。
- 回滚：主店把 `current` 指回 `0532574-*`；其余 6 处用上面的备份覆盖回去，再删掉 `assets/referrals.css`。
## 2026-11-07 20:15 UTC — new-store template and s0002/s0003 brought to live code (0532574)

- Scope (same as the 11-05 overall sync, plus language packs): `plugins/peptide-core`, `peptide-panapay`, `peptide-ops`, `themes/peptide-site`, `wp-content/languages`, copied from live `0532574-s0001` / `0532574-web01`. WordPress/WooCommerce/SMTP, mu-plugins (incl. the new-store mail gate), wp-config, uploads and databases untouched.
- New templates: `/srv/vialcove-provision-template/yy-sync-0532574-1794082452-admin/source` (admin host) and `…-web/source` (web host); `/etc/vialcove-provision/config.json` changed only `source_admin_root` / `source_web_root` (previous values `checkout-return-fixes-1793879470` / `-1793879522`; backups `/var/lib/vialcove-ui-deploy/config.json.before-yy-sync-{admin,web}`).
- s0002/s0003 admin and web public roots swapped to the updated copy; previous roots kept as `<root>.pre-yy-sync-0532574-1794082452-{admin,web}`. DB dumps before the change: web host `/var/lib/vialcove-ui-deploy/yy-sync-20261107T201404Z-db/`.
- Checks: scope diff vs live = 0 for both templates and all four roots; s0002/s0003 boot via wp-cli (overview.js 6df763f083ac, Referrals present); HTTPS 200 for s0002/s0003 management login and storefront, s0001 login, www storefront; provisioning worker run after the change finished normally. No new store created.
- Still different for a newly created store (by design of `worker.py` bootstrap, not changed here): en_US site language, UTC time zone, mail gate on, visitor tracking off, no payment gateway, no products; design bundle is the 2026-11-03 snapshot.
- From now on a production deploy must also update the template roots and s0002/s0003, not only s0001.

## 2026-11-07 ~20:25 UTC — new-store starting settings now match s0001

- `tools/deployment/provisioning/worker.py` (installed on both hosts as `/usr/local/lib/vialcove-provision/worker.py`, sha256 74fbc4f2…; previous 0941bc7b… kept at `/var/lib/vialcove-ui-deploy/worker.py.before-new-store-settings`):
  - `copy_runtime` no longer writes `vialcove-mail-gate.php` (a copy in the source is still removed with the other non-allowlisted mu-plugins);
  - `bootstrap` activates `fluent-smtp` as well, sets `blog_public=1`, `woocommerce_default_country=US:CA`, `timezone_string=Asia/Shanghai`, owner/admin user locale `zh_CN`, and turns visitor tracking on.
- Tests: full provisioning suite 65/65 OK as root on the admin host (locally the 16 chown-dependent tests error the same before and after).
- s0002/s0003 given the same settings (fluent-smtp activated, mail gate files moved to `/var/lib/vialcove-ui-deploy/new-store-settings/` on each host).
- Settings comparison s0001 / s0002 / s0003: identical except payment gateway (PanaPay needs each store's own merchant account) and content (products 82/0/0, pages 12/5/5). Store address is empty on all three. No new store created.

### 广告像素后台只显示 Facebook（用户批准 "Deploy to production"，2026-11-07）

- 用户：核心只投 Facebook，其他平台先隐藏。
- 代码 `2ab55b0`（1 个文件：peptide-core `includes/admin/class-pixels-page.php`）：只显示 Facebook 卡片，其余 22 个平台卡片加 `hidden`（仍在表单里提交，已存设置不丢）；恢复显示改 `VISIBLE` 或过滤器 `peptide_core_pixels_visible_platforms`。前台回传逻辑未改。
- 部署：两边 `554e242-*` 的该文件哈希与上一版一致；复制为 `2ab55b0-web01` / `2ab55b0-s0001`，恰好 1 处不同，语法通过，切换并 reload PHP。前台 / s0001 200；s0001 像素页只见 Facebook，隐藏 22 个，无报错。正式站仍未填任何像素。
- 回滚：两个 `current` 指回 `554e242-*` 后 reload PHP。

### 像素页同步到其他 6 处 + 隐藏“说明”栏（用户批准，2026-11-07）

- 用户批准 "Yes, update all 6"：`class-pixels-page.php`（`2ab55b0` 版）原地替换到 s0002 / s0003 前台（`/srv/vialcove/tenants/sNNNN/public`）、后台（`/srv/vialcove/tenant-admin/sNNNN/public`）和开店模板 `yy-sync-0532574-1794082452-{web,admin}/source`；替换前核对旧哈希 261f0ae0…，保留属主和权限，原文件备份在两台服务器 `~/vialcove-deploy-backups/pixels-fb-only-20261107T2207*`。
- 代码 `ca4d33c`（同一文件）：隐藏右侧“说明”栏（`SHOW_NOTES = false`），卡片占满整行。用户批准 "Deploy to production" 后：主店复制为 `ca4d33c-web01` / `ca4d33c-s0001`（恰好 1 处不同，切换并 reload PHP）；其他 6 处同样核对旧哈希 78063f4d… 后原地替换，备份 `~/vialcove-deploy-backups/pixels-notes-hidden-20261107T2229*`。8 处哈希都是 4edd9edc…。
- 验证：前台、s0001、s0002、s0003 后台登录页 200；s0001 像素页只见 Facebook，说明栏隐藏，卡片宽度 = 网格宽度，无报错。
- 回滚：主店 `current` 指回 `2ab55b0-*`（或 `554e242-*` 回到全部平台）；其他 6 处用对应备份覆盖后 reload PHP。

### 像素关联域名默认 = 店铺绑定域名（用户批准 "Deploy to production"，2026-11-07）

- 用户：关联域名功能保留，但默认是店铺绑定的域名，不要“所有域名”。
- 代码 `87dbf64`（4 个文件：peptide-core `includes/class-pixels.php`、`includes/pixels/class-server.php`、`includes/admin/class-pixels-page.php`、`assets/pixels-admin.js`）：新增 `Pixels::store_domain()`（首页地址主机名，去 www）和 `pixel_domain()`；新建像素预填店铺域名，空值保存为店铺域名；浏览器加载和 CAPI 回传都把旧的空值当作店铺域名；后台不再显示“所有域名”。
- 部署：8 处 4 个文件的旧哈希都与 `ca4d33c` 一致；主店复制为 `87dbf64-web01` / `87dbf64-s0001`（恰好 4 处不同，语法通过，切换并 reload PHP）；s0002 / s0003 前后台和两份开店模板原地替换，原文件备份在两台服务器 `~/vialcove-deploy-backups/pixels-store-domain-20261107T2305*`。8 处新哈希一致。
- 验证：前台、s0001、s0002、s0003 200；s0001 像素页“新建像素”弹窗的关联域名预填 `vialcovepeptides.com`（取消，未保存）。正式站仍未填任何像素。
- 回滚：主店 `current` 指回 `ca4d33c-*`；其他 6 处用备份覆盖后 reload PHP。

### 其他广告平台改为折叠显示（用户批准 "Deploy to production"，2026-11-08）

- 用户：隐藏的平台都打开，做成折叠即可。
- 代码 `07451dd`（1 个文件：peptide-core `includes/admin/class-pixels-page.php`）：顺序为 Facebook 卡片 → 服务端回传记录 → “其他广告平台（22 个）”折叠区（默认收起；有平台已填像素时标题显示“已配置 N 个”并默认展开）。右侧说明栏仍隐藏。
- 部署：8 处旧哈希都是 a3ac8431…；主店复制为 `07451dd-web01` / `07451dd-s0001`（恰好 1 处不同，切换并 reload PHP）；其他 6 处原地替换，备份 `~/vialcove-deploy-backups/pixels-collapsible-20261108T0006*`。8 处新哈希 e5f74175…。
- 验证：前台、s0001、s0002、s0003 200；s0001 像素页底部显示“其他广告平台（22 个） 点击展开”，展开后 23 个平台卡片，无报错。
- 回滚：主店 `current` 指回 `87dbf64-*`；其他 6 处用备份覆盖后 reload PHP。

### 总后台插件管理上线（用户批准 "Deploy and test s0003"，2026-11-08）

- 代码 `1932632`（功能）+ `0fe0c12`（root 任务在 `UMask=0077` 下显式设 `/srv/vialcove/store-plugins` 和店铺目录为 0755，否则店铺 PHP 读不到插件；新增测试在 umask 077 下验证）+ `bb4f46e`（总后台对已停用 / 正在下线的店铺拒绝装卸插件，和店铺列表同一规则 `Site_Lifecycle::active`）。
- root 任务：两台服务器 `/usr/local/lib/vialcove-provision/worker.py` 旧哈希都是 74fbc4f2…（与上一版一致），备份到 `/root/vialcove-backups/plugin-manager-20261108T024546Z/`；新 `worker.py` c65c87bc…、新增 `plugins.py` 87e4c4ee…（root 0700）。下一轮任务成功（Result=success），创建 `/var/lib/vialcove-provision/plugins/{requests,packages}`（root:vchq 0730）、`results`（0750）。
- 总后台：线上 peptide-hq 与 `f3872fe` 一致（除不发布的 tests）；复制 `94c322f-hq` → `0fe0c12-hq`（恰好 5 个文件不同，语法通过），再 → `bb4f46e-hq`（恰好 1 个文件不同）；切换并 reload PHP，登录页 200，新表 `wp_pchq_plugins` / `wp_pchq_site_plugins` 已建，`ready=true`。nginx 已有 `client_max_body_size 36m`（`snippets/vialcove-hq-app.conf`），PHP 池 32M / 36M，无需修改。
- 验证：在正式总后台“插件管理”上传无害测试插件 `vc-selftest` 1.0.0（只在页面头部加一个 meta 标签）成功入库。店铺列表只显示主店：s0002（site 10）/ s0003（site 11）的下线操作 11-04 失败（`inventory_site_missing`，“总后台保持停用”），总后台已把它们当停用店，因此不能在 s0003 上测；`request(11, …)` 现在返回“店铺已停用或正在下线”，队列为空。两台机器上的完整安装 → 自检 → 卸载尚未在正式环境跑过。
- 回滚：总后台 `current` 指回 `94c322f-hq` 后 reload PHP（新表留着无害）；两台服务器把备份的 `worker.py` 复制回去并删除 `plugins.py`。
- 主店实测（用户批准 "Test on main store"）：第一次安装安全失败 `web_store_code_root_missing`，没有改动任何东西（web 机清单里主店是先列 `current`（symlink）再列 `releases`，旧代码只看 directory）。修复 `31cbdbc`（按清单顺序尝试 symlink / directory，测试夹具改成和线上一致；旧代码在新夹具下 3 项失败），两台机 `plugins.py` 换成 0077b33c…（上一版备份为 `plugins.py.87e4c4ee`），只读核对：web → `/srv/vialcove/current`，admin → `/srv/vialcove/store-admin/current`。
- 重试：安装 done（约 25 秒）；两台机 `wp-content/plugins/vc-selftest` → `/srv/vialcove/store-plugins/s0001/vc-selftest`，目录 0755 / 文件 0644，店铺 PHP 用户可读，`wp plugin list` 为 active，正式前台页面头部出现测试 meta，首页 / 购物车 200，总后台显示“已启用 1.0.0”。卸载 done：两台机链接和插件目录删除、`active_plugins` 里没有、前台 meta 消失、首页 / 购物车 200。测试插件已从插件库删除（表和包目录都为空）。剩下空目录 `/srv/vialcove/store-plugins/s0001/`（无害）。

### 清理测试店 s0002 / s0003（用户批准 "s0002/s0003, clean up" + “自动提交，不用问我了”，2026-11-08）

- 背景：两店（总后台 site 10「隔离验收店 B」/ 11「隔离验收店 A」）是生命周期库存建立前的老格式开店（job 无 customer_domain、无域名认领），11-04 的下线因 `inventory_site_missing` 失败，自动工具按设计拒绝猜测归属。0 订单，库各 5 MB 测试数据。改为逐项手动拆除。
- 备份（两机 `/root/vialcove-backups/retire-s0002-s0003-20261108T0330Z/`，root 0700 / 文件 0600）：web 机两店 `mysqldump`（`sNNNN-db.sql.gz`）+ 数据目录、ledger、nginx / FPM / sshd / 桥接密钥 / 定时任务 / unit（`sNNNN-web.tgz`）；admin 机 owner marker、`shared/wp-config.php`、nginx / FPM / unit（`sNNNN-admin.tgz`）。程序代码与开店模板相同，未备份。
- admin 机（54.144.250.46）：停用并删除 `vialcove-sNNNN-{uploads,reports}.service`（sshfs 挂载已卸）、nginx 站点、FPM 池（`nginx -t` / `php-fpm -t` 通过后 reload）、系统账号 `vcsNNNN`、目录 `/srv/vialcove/tenant-admin/sNNNN`、证书 `sNNNN.vialcoveresearch.com`。
- web 机（34.231.148.231）：停用并删除 `vialcove-sNNNN-cron.{timer,service}`、`vialcove-export-sNNNN.service`、`/srv/vialcove-media-export/sNNNN` 绑定挂载；nginx 站点、FPM 池、`45-vialcove-sNNNN.conf` 与 `vcm_sNNNN` 桥接密钥（`sshd -t` 后 reload）；账号 `vcsNNNN` / `vcm_sNNNN`；数据库 `vc_sNNNN` 和 `vcf_` / `vca_` 账号；`/srv/vialcove/tenants/sNNNN`、`/srv/vialcove-data/tenants/sNNNN`；证书 `sNNNN.vialcovepeptides.com`。ledger `sNNNN.json` 移到 `/var/lib/vialcove-provision-web/retired/`（保留记录，不再被备份当成在用店铺）。
- 墓碑：两机 `lifecycle/tenants/<uuid>/current.json` 写 `purged`（retired_at = 11-04 实际下线请求时间，purge_at = +72 小时）；admin 机覆盖原失败回执 `lifecycle/results/<retirement_id>.json` 为 `purged`。总后台 sync 后两店 `purged`、`summary_cleaned`，告警 37 / 38 和每日统计删除，site 行只剩身份墓碑（店名、密钥、服务器清空）。admin 机 jobs 保留，下一家新店编号为 s0004，不复用。
- 验证：主店前台 / 购物车、s0001 后台、总后台登录 200；两机 nginx / php-fpm / ssh active；s0001 的 sshfs 挂载正常；monitor、provision worker、两机 `vialcove-tenant-backup` 实跑成功（web 只备份 s0001 库）。旧的 s0002 / s0003 域名不再有店铺响应。
- 未处理：Cloudflare 上 4 条子域名 DNS 需用户删除；`max_new_stores=4` 仍把两个已完成的老 job 计入名额（新店剩 2 个名额，需要时调大配置）；admin 机 logrotate 自 11-07 失败（`vialcove-hq` 与另一配置重复写了 HQ access log，和本次无关）。
- 回滚：不可整体恢复（目录 / 账号 / 证书已删）；需要时可用备份的 SQL 和数据目录 + 开店模板手动重建。

### 插件管理页改版 + 显示店铺自带插件（用户要求，2026-11-08）

- 代码 `afa34fd`：`class-plugins-page.php` 重写页面渲染（处理上传 / 安装 / 卸载的代码不变），新增 `assets/hq-plugins.css` / `hq-plugins.js`（只在本页加载）。顶部统计（插件库 / 已安装 / 店铺）；上传区改成可拖拽的选择框，选中后显示文件名和大小，本地先检查 .zip 和 32 MB，未选文件时按钮不可点；第三方插件卡片按店铺一行显示状态点和按钮；新增“店铺自带插件”（读取总后台定时同步的店铺已启用插件 `info.plugins`，只读，标“自带 / 插件库”）。确认弹窗改为 `data-confirm` + 脚本。
- 部署：复制 `bb4f46e-hq` → `afa34fd-hq`（恰好 3 个文件不同，语法通过），切换并 reload PHP，登录页 200；正式页面加载新样式和脚本，主店显示 4 个自带插件，宽度无溢出。本地临时总后台验证了桌面和 375 px 手机宽度。
- 回滚：`current` 指回 `bb4f46e-hq` 后 reload PHP。
- 说明：两个域名在 Cloudflare 用的是通配符记录（`*.vialcovepeptides.com` → web01，`*.vialcoveresearch.com` → HQ），没有 s0002 / s0003 单独记录，无需删除；未知子域名由 nginx 默认站点返回 444。

### 总后台控制店铺能用哪些内置应用（用户要求，2026-11-08）

- 用户：插件管理要管的是应用中心那 8 个应用（以后还会新增）能不能给某个店铺用，店铺开不开与总后台无关；不是系统插件。
- 代码 `0e83357`（含中间提交 `b32c13a`，该版本未上线）。店铺端 peptide-core：`Apps_Page::catalog()` 全部应用、`apps()` 只返回已开通的（应用中心、应用统计用）；选项 `pc_app_access` 存被停用的应用键（没有记录 = 全部可用，新应用默认可用）；被停用的应用设置页显示“暂未开通”，四个旧式保存入口和四个新式 Store 的 `can_manage` 都拒绝。`/hq/apps`（签名）接收 `{key, allowed}`：停用时先以店铺管理员身份经 `App_Switch` 走应用自己的保存把它关掉，关掉成功才记为停用；重新开通后保持关闭。`status()` 上报 `apps`（key / name / text / allowed）。总后台：插件管理顶部“店铺应用”改为应用 × 店铺开通表，每格“可用 / 不可用”开关（停用前确认），结果写回店铺信息；系统插件折叠到页面底部。
- 部署：主店前后台、两份开店模板的 9 个旧文件哈希与 `afa34fd` 一致后才动；主店复制为 `0e83357-web01` / `0e83357-s0001`（恰好 10 个文件不同），切换并 reload PHP，前台 / 后台登录 200；模板原地替换，原文件备份 `/home/ubuntu/vialcove-deploy-backups/store-apps-20261108T0342*`。总后台 `afa34fd-hq` → `0e83357-hq`（恰好 5 个文件不同），登录 200；同步后主店上报 8 个应用、全部可用，正式页面开通表正常、无横向溢出。期间第一次部署脚本因 `diff` 退出码中止（未切换），未用的 `b32c13a-web01` 已删除。
- 本地验证：本地店铺上停用加购反馈 → 被关闭、应用中心不显示、店铺不能再开；重新开通 → 显示但保持关闭；未知应用 / 错误参数被拒；测试后恢复原状。正式环境的开关实际点击未做（自动操作被安全检查拦下），留给用户在总后台确认。
- 回滚：主店 `current` 指回 `07451dd-*`、模板用备份覆盖；总后台指回 `afa34fd-hq`；均 reload PHP。`pc_app_access` 选项留着无害（旧代码不读）。

### 插件管理报错改成看得懂的话 + 连接异常时锁开关（2026-11-08）

- 起因：用户在本地临时总后台（127.0.0.1:8767，假店铺 s0002 / Acme Wheel 测试数据）上看到原始 cURL 报错和“超过 1 小时没有结果”，以为是正式环境。已停掉本地临时总后台并关闭该标签页；正式总后台只有主店，开通表无异常。
- 代码 `43574bb`：总后台开关失败时把连接类错误翻成中文（连不上店铺 / 连接密钥被拒 / 店铺程序版本较旧 / 已下线），并说明“这次没有做任何修改”；店铺给的业务原因原样显示。最近一次连接检查失败的店铺在开通表表头显示“连接异常 · 重新检查”，开关锁住。插件任务卡住的提示、root 任务各错误码改为说明原因和下一步（默认提示“可以再点一次重试；仍失败请把错误代码发给技术人员”）。
- 部署：两台机 `plugins.py` 0077b33c… → 0efa0c2b…（上一版备份 `plugins.py.0077b33c`），下一轮任务成功；总后台 `0e83357-hq` → `43574bb-hq`（恰好 3 个文件不同），登录 200。正式环境核对：错误翻译四种情况输出正确，主店未锁，插件管理页无报错、8 个开关可用、无横向溢出。
- 回滚：总后台指回 `0e83357-hq`；两台机 `plugins.py` 用备份恢复。

### 插件管理页按“当前店铺”组织（用户：店铺多了表格不好用，2026-11-08）

- 代码 `6d6ad51`（3 个文件：`class-plugins-page.php` 重写、`hq-plugins.css`、`hq-plugins.js`）：顶部“当前店铺”选择（超过 6 家显示搜索框，按名称 / 域名过滤，回车切换；显示连接状态、重新检查、进入店铺后台），地址带 `?site=`，所有操作完成后回到同一店铺。店铺应用：当前店铺 8 个应用卡片 + 可用 / 不可用开关，每个应用显示“全部店铺 N / M 家可用”，两家以上时有“全部可用 / 全部停用”（跳过连接异常和版本较旧的店铺，限时 60 秒，结果汇总；有跳过或失败时用提醒色）。第三方插件：只显示当前店铺的状态和按钮，标题行显示“已装 N / M 家”，可“装到所有店铺”（已装 / 处理中的跳过）。系统插件只显示当前店铺。
- 本地验证（临时总后台，30 家假店铺，测完已停掉并关闭标签页）：页面大小不随店铺数增加；搜索“s0010”回车切到该店，连接异常时 8 个开关锁住；批量设置正确统计“本来就是 / 跳过”。
- 部署：`43574bb-hq` → `6d6ad51-hq`（恰好 3 个文件不同），登录 200；正式页面当前店铺为主店、连接正常、8 个应用可用、无报错、无横向溢出（只有 1 家店时不显示批量按钮）。
- 回滚：总后台指回 `43574bb-hq` 后 reload PHP。

### CDN 自动配置 + 插件管理页店铺改名（用户要求，2026-11-08）

- 代码 `6149c5b`：新增 `PeptideHq\Cdn`（`includes/class-cdn.php`）和“CDN 加速”页（`admin/class-cdn-page.php`），缓存规则模板 `assets/cdn/storefront-cache-bypass.txt`（与主店 `tools/deployment/cloudflare/peptides-cache-bypass-v2.txt` 除域名行外逐字节相同）。流程：读取开店结果里的客户域名和 A 记录 IP → Cloudflare 找 zone（可选自动添加，显示要改的 NS）→ 先检查 @ / www 两个名字没有 AAAA / CNAME / 多条 A 冲突 → 建灰色 A 记录（已有记录保持原灰 / 橙，不降级）→ 开店完成、直连服务器 HTTPS 证书可用、Cloudflare 边缘证书生效后 → SSL Full (strict)、写入自己的绕过缓存规则（保留该 zone 其他规则）→ 两条记录切橙色。每 5 分钟定时检查，提交开店后 1 分钟内也会检查；已开启 CDN 的域名不再调用。令牌加密保存（`Crypto`），默认关闭，需用户在总后台填写令牌并勾选。开店页说明文字随开关变化。插件管理页：店铺选择处显示“未起名”，可直接改总后台里的店铺名称（`pchq_site_rename` 带 `return=plugins` 回到插件管理）。
- 测试：本地假 Cloudflare 接口 12 项全部通过（令牌格式 / 验证、域名不在、自动添加待改 NS、灰色、等服务器证书、等边缘证书、开启后幂等、已走 CDN 的域名不降级且保留原规则、AAAA 冲突时一条都不写、关闭时定时不调用、开店结果自动推进、完成后不再调用）。
- 部署：`6d6ad51-hq` → `6149c5b-hq`（恰好 8 处不同），登录 200；定时任务已排上，自动配置为关闭（未填令牌），CDN 页与插件管理页正常。
- 发现（未改）：新店装修母版来自 11-03 的快照 `/srv/vialcove-provision-template/20261103T075211Z-9e6eff/template.json`。主店现在首页 12 个模块（多了促销卡、视频、首单 5% 优惠、第二个 FAQ，少了 CTA），页眉 / 页脚也不同，只有全局设置一致；导出脚本只带图片，视频不会进快照。需用户确认后刷新快照并让快照支持视频。
- 回滚：总后台指回 `6d6ad51-hq`；`pchq_cdn` / `pchq_cdn_domains` 选项和定时任务留着无害（旧代码不读）。

### 支付中心“彻底删除” + 插件管理页去掉改名（用户要求，2026-11-08）

- 用户：已删除的付款方式要能彻底删除；店铺名称在自动开店时填写，插件管理页不要改名框（当前店铺只是测试）。
- 代码 `86321fb`：peptide-panapay `Hub_Accounts::purge_deleted()`——只允许已在“已删除”列表、且不是 `default` 的付款方式；按 revision 防并发；从账户簿移除（参数、密钥一起清除，不再占 20 个上限），只在 `pc_panapay_hub_purged` 留名称；交易记录显示“名称（已彻底删除）”。依据：会话、支付方通知验签、回写店铺都用每笔记录上冻结的密钥和策略（`Hub::notify` 用记录里的 link key，店铺 `Session_Service::attempt_config` 用冻结配置），不依赖账户簿。`class-hub-page.php` 在已删除列表加“彻底删除”（确认弹窗）。插件管理页移除改名框和“未起名”。
- 测试：隔离单元测试（内存账户簿）——默认通道、未删除、过期 revision、非法 ID 都拒绝；删除后不在账户簿、名称保留、密钥在任何存储里都不留；重复删除拒绝。本地临时总后台没有 MySQL，未做整页测试。
- 部署：两个 Panapay 文件线上哈希与上一版一致后，`6149c5b-hq` → `86321fb-hq`（恰好 3 个文件不同），登录 200；正式“已删除”列表 PayPal 有“彻底删除”，Credit card 是 `default` 通道没有（旧店铺的单通道配置还读它）。未点任何删除。只部署到总后台（Hub 类只在总后台使用）。
- 回滚：总后台指回 `6149c5b-hq`。已彻底删除的付款方式不能靠回滚恢复。

### 统一模板：总后台下发统一装修 + 锁定店铺装修 + 刷新新店母版快照（用户要求，2026-11-08）

- 用户：所有店铺装修保持一致，后台编辑好统一下发，店铺不需要自己配置模板。
- 代码 `cc211bc`。店铺端 peptide-core：新增 `Design_Sync`——母版店 `export()` 导出默认模板已发布的 home / header / footer / global 和引用的图片 / 视频（公开地址、大小、SHA-256）；其他店铺 `apply()` 下载缺少的媒体（只认母版店域名、校验大小和哈希，失败时不改装修）、写入“平台统一模板”并设为默认、清除按域名指定的模板、发布、清页面缓存、记录版本并锁定。锁定时：`Layout_Controller::can_edit` 拒绝（编辑器保存 / 发布 / 撤销）、`Store_Design::handle` 拒绝全部模板操作、主题装修页和编辑器显示“由平台统一下发”。首页 SEO、域名、菜单、自定义页面不受影响。连接器新增签名接口 `/hq/design-export`、`/hq/design`、`/hq/design-lock`，状态上报 `design`；一次性登录支持 `to=design`。总后台：新增 `Design_Push` 和“统一模板”页——选母版店（默认最早的店铺 = 主店）、“去母版店编辑装修”、“下发到所有店铺”（浏览器只导出 + 排队，后台任务逐个店铺下发，每次 ≤ 240 秒；跳过连接异常 / 已下线；不会下发给母版店自己）、单独下发、锁定 / 解锁；新店注册 5 分钟后自动下发。`Client` 请求支持自定义超时（下发 280 秒）。开店快照：`export-template.php` / worker / `import-template.php` 支持 mp4 / webm（≤ 64 MB，总量 ≤ 128 MB），新增对应测试。
- 测试：本地店铺用主店真实导出下发——下载 21 MB 视频和 5 张图 2.2 秒、视频模块改指向本地附件、第二次全部复用、篡改哈希被拒、锁定后编辑器拒绝保存、外部域名媒体被拒，测试后恢复本地店铺。本地总后台模拟 29 家店：只导出一次、27 家完成、2 家连接异常跳过、母版店不下发、单独下发和解锁正常。worker 测试在 Linux 以 root 运行 37 项全部通过（含新视频测试）；macOS 上 9 个环境性错误前后一致。
- 部署：主店前后台 5 个文件旧哈希与 `0e83357` 一致后复制为 `cc211bc-web01` / `cc211bc-s0001`（恰好 5 处不同），前台 / 后台 200；两份开店模板原地替换（备份 `store-apps-20261108T0429*`）。两台机 `worker.py`、`export-template.php`、`import-template.php` 旧哈希一致后替换（备份 `/root/vialcove-backups/template-video-20261108T0430*`），任务成功。总后台 `86321fb-hq` → `cc211bc-hq`（恰好 5 处不同），登录 200，母版店 = 主店（site 2），从主店导出 6 个媒体、版本 517dea83。主店未锁定。目前除主店外没有其他店铺，没有执行下发。
- 新店快照：从主店当前装修重新导出到 `/srv/vialcove-provision-template/20261108T043118Z-design-cc211bc/`（首页 12 个模块，含促销卡、视频、首单 5% 优惠；21.8 MB 媒体），web 机 `config.json` 的 `template_bundle` / `source_uploads` 指向新快照（原配置备份 `/root/vialcove-backups/config.json.before-20261108T043118Z`，旧快照保留）。按 worker 规则校验通过。
- 回滚：主店 `current` 指回 `0e83357-*`、模板用备份覆盖；总后台指回 `86321fb-hq`；两台机 worker 三个文件用备份恢复；web 机 `config.json` 用备份恢复即回到 11-03 快照。

### 统一模板：自动下发、真实连通测试、链接改写、页面对齐（2026-11-08）

- 用户：编辑好后统一下发、店铺直接用最新的、不用通知；页面对齐；测试下发是否打通；问“最新的是不是测试站改的模板”。
- 核对：测试站（sandbox-admin）和主店的默认模板——页眉、全局样式完全一致；首页主店多了今天加的视频模块，其余 11 个模块相同；页脚内容相同，只是链接各指向自己的域名。主店是最新完整版本。
- 代码 `e639569`、`81ab283`、`ea8d68f`：总后台每 5 分钟检查母版店装修修改时间，变了自动下发（默认开启，可关；第一次只记录基准）；“测试下发（不改动）”——真实读取母版、真实发给店铺，店铺下载并核对全部媒体后丢弃，不写入不锁定（只有母版店时测母版店自己）；下发 / 开店快照导入时把指向母版店域名（含 www / 不带 www / 后台域名）的链接改成收到装修的店铺自己的顾客地址（避免页脚等链接跳到主店）；导出的媒体地址和来源统一用母版店顾客地址（之前从后台域名导出会给 s0001.vialcoveresearch.com 地址，被店铺按安全规则拒绝——真实测试发现）；统一模板页母版店一行对齐、按钮改名“编辑统一模板”；店铺“主题装修 → 我的模板库”表格不再挤成一列一个字（宽度不够时收起每行都一样的“主题 / 创建人”列）。
- 部署：主店前后台 `cc211bc-*` → `e639569-*` → `81ab283-*` → `ea8d68f-*`（每次核对旧哈希、恰好对应文件不同、前台 / 后台 200），两份开店模板同步替换（备份 `store-apps-20261108T04*`）；两台机 `export-template.php` / `import-template.php` 替换（备份 `template-links-20261108T0440*`）；总后台 `cc211bc-hq` → `e639569-hq`（4 个文件）。新店快照重新生成为 `/srv/vialcove-provision-template/20261108T044235Z-design-81ab283/`（带 origins，原配置备份 `config.json.before-20261108T044235Z`）。
- 真实连通测试（正式环境）：总后台读取母版 0.5 秒、6 个媒体；发到主店（测试模式）下载并核对 6 个文件 22.9 MB 用时 0.7 秒；前后核对主店装修修改时间、默认模板、附件数、未锁定都不变。真正写入的路径在本地店铺用主店真实数据测过。
- 回滚：主店 `current` 指回 `cc211bc-*`；总后台指回 `cc211bc-hq`；脚本和 `config.json` 用对应备份恢复。

### 店铺后台去掉“搜索引擎工具”（用户要求，2026-11-08）

- 代码 `b29341e`：`Admin\Content_Tools_Page::register_hooks()` 不再注册菜单（店铺装修、博客下的“搜索引擎工具”）和页面操作；保留旧 AI 请求的拒绝拦截。前台 `Content_Tools`（页面标题 / 描述 / 站点验证 meta、IndexNow 密钥文件）不变，已有设置继续生效。
- 部署：主店前后台旧哈希与 `ea8d68f` 一致后复制为 `b29341e-*`（恰好 1 个文件不同），前台 / 后台 200；两份开店模板同步（新店也没有这个菜单）。正式后台“店铺装修”菜单已无此项，无报错。
- 回滚：主店 `current` 指回 `ea8d68f-*`，模板用备份覆盖。

### 确认框改为页面内弹框 + 去掉“测试下发”按钮（用户：点了没反应，2026-11-08）

- 原因：统一模板 / 插件管理 / CDN 页带确认的按钮用浏览器自带 `confirm()`，内置浏览器不显示这类弹窗，点了直接被取消，看起来没反应（HQ 访问日志里没有对应 POST，`pchq_design` 选项也没写过）。支付中心“彻底删除”同样用 `confirm()`。
- 代码 `3f2d470`：`hq-plugins.js` 改为页面内确认框（取消 / 确定，Esc 和点遮罩关闭），提交后按钮显示“处理中…”并禁用；统一模板页去掉“测试下发（不改动）”按钮和处理（`Design_Push::test()` 保留供命令行排查）；锁定 / 解锁提示写明结果（只有母版店时提示“以后新开的店会按这个设置”）。支付中心“彻底删除”改为两步：点“彻底删除”后出现说明和“确认彻底删除”。
- 部署：总后台 `e639569-hq` → `3f2d470-hq`（恰好 5 个文件不同），登录 200。正式页面点“解锁店铺装修”弹出页面内确认框，取消后不提交；之后用户自己点了解锁再锁定（日志两次 POST），当前 `pchq_design` = 锁定、自动下发开启。
- 回滚：总后台指回 `e639569-hq`。

### 模板第二版：系统模板 + 修订版、总后台模板库内装修（用户要求，2026-11-08）

- 用户：店铺模板库里一套“系统模板”（只能平台下发、店铺不能改）+ 开店时复制一套“修订版”（店铺随便改）；下发只更新系统模板，店铺自己选用哪套；平台会做新模板；去掉“锁定店铺装修”；在总后台里做模板、装修（和店铺里的装修一样）；模板库表格排版重做。
- 代码 `f134e97`、`a5d531b`、`6bb5242`。店铺端 `Design_Sync` 重写：按工作室模板编号（`m<ID>`）对应店里的系统模板（`_pc_system_template`），第一次收到时把开店导入的“店铺默认母版”变成修订版（`_pc_revision_of`，内容换成系统模板；没有时复制一份），并默认用系统模板；以后下发只更新系统模板（含改名），不动修订版、不改店铺选用、不再清域名指定。锁定改为按模板：`Layout_Controller::can_edit`、编辑器、`Store_Design` 的装修 / 改名 / 删除只拒绝系统模板；复制、选用不受限。工作室接口：`/hq/design-templates`、`/hq/design-template`（create / update / trash）、`/hq/design-export?template=`。一次性登录 `to=design_tpl_<ID>` 直接打开该模板的编辑器并带 `pc_hq_embed` 会话标记；有标记时店铺后台和前台（编辑器预览）只额外允许总后台地址嵌入（`frame-ancestors 'self' https://vialcoveresearch.com`），其他访问保持原来的禁止嵌入。店铺“我的模板库”改为一行一个模板（系统模板 / 修订版 / 使用中标签、绑定域名一行、按钮一行），系统模板只能查看 / 复制 / 选用。总后台：“统一模板”改为“模板库”——列出工作室模板、新建（空白或复制）、改名 / 备注、开关“下发给店铺”（系统模板）、删除（系统模板和工作室在用的不能删）、“装修”打开总后台内嵌的同一套装修编辑器（隐藏页面 `pchq-design-editor`）；下发区：自动下发（每 5 分钟比较系统模板修改时间和名称）、立即下发、各店“系统模板 N / M 最新”和“店铺在用：系统模板 / 修订版 / 自建”；去掉锁定开关；工作室店铺选择收到“高级”。
- 本地测试（本地店铺 + 主店真实导出）：第一次下发建系统模板和修订版并选用系统模板；店铺改修订版、改选修订版后第二次下发只更新并改名系统模板，修订版内容和选用都不变；系统模板编辑被拒、修订版和复制件可编辑；工作室新建 / 改名 / 设为下发 / 下发中禁止删除 / 取消后可删除；测试后清理本地数据。
- 部署：主店前后台 `b29341e-*` → `f134e97-*`（6 个文件）→ `a5d531b-*`（2 个文件），每次核对旧哈希、前台 / 后台 200，两份开店模板同步；总后台 `3f2d470-hq` → `f134e97-hq` → `a5d531b-hq` → `6bb5242-hq`，登录 200。主店（工作室）的“VialCove”已设为系统模板；目前没有其他店铺，未发生下发。正式环境验证：总后台模板库列出 2 个模板；“装修”在总后台内打开同一套编辑器，预览正常显示首页；店铺模板库新排版无溢出。
- 回滚：主店 `current` 指回 `b29341e-*`、模板用备份覆盖；总后台指回 `3f2d470-hq`。

### 开店名额：已清理的店不再占名额（用户要求，2026-11-08）

- 查明：上限是两台机 `/etc/vialcove-provision/config.json` 的 `max_new_stores = 4`，代码和记录里没有按服务器容量计算的依据，是部署时的保守初始值。两台机各 4 核 / 7.6 GB 内存（可用约 5.9 GB，无 swap）、磁盘 260 GB 以上空闲；新店 PHP 池 `ondemand`、最多 2 个进程、每个 256 MB 上限，空闲时几乎不占内存。web 机按 ledger 计数（s0002 / s0003 的 ledger 已移到 `retired/`，不再计入）；admin 机按完成的开店任务计数，之前仍把已清理的 s0002 / s0003 算进去。
- 代码 `c579f9d`：`worker.py` 新增 `store_purged()`，admin 机计数时跳过生命周期墓碑为 `purged` 的店（编号仍不复用，下一家是 s0004）；新增测试（Linux root 下 38 项全部通过）。
- 部署：两台机 `worker.py` 旧哈希一致后替换（备份 `/root/vialcove-backups/store-limit-20261108T0522*`），任务成功。生产只读核对：已计入 0 家，上限 4，空余 4 个名额。上限数值未改，等用户决定。
- 回滚：两台机用备份恢复 `worker.py`。
- 用户批准 "Raise to 15"：两台机 `config.json` 的 `max_new_stores` 4 → 15（原配置备份 `/root/vialcove-backups/config.json.before-limit15-*`，属主和权限不变），之后开店任务正常运行。当前已占 0 家，可开 15 家。回滚：用备份恢复 `config.json`。

### CDN 令牌验证失败：总后台外部请求白名单（2026-11-08）

- 现象：CDN 加速页保存令牌报“连不上 Cloudflare（用户已阻止通过 HTTP 请求此 URL）”。原因：总后台 `/srv/vialcove/hq/shared/wp-config.php` 设了 `WP_HTTP_BLOCK_EXTERNAL`，`WP_ACCESSIBLE_HOSTS` 只含店铺后台域名和备份地址，没有 `api.cloudflare.com`。店铺端没有这个限制（模板媒体下载不受影响）。
- 处理：`WP_ACCESSIBLE_HOSTS` 追加 `api.cloudflare.com`（原文件备份 `/root/vialcove-backups/hq-wp-config.before-cloudflare-20261108T054018Z`，属主 / 权限不变，语法检查通过；开店 worker 只追加新店域名，不会覆盖）。代码 `d0ec9ef`：`Cdn::origin_ready()` 改用 cURL 直连店铺服务器 IP（只 HTTPS、正常证书校验），因为新店域名不在白名单里、`wp_remote_get` 会被拦。总后台 `6bb5242-hq` → `d0ec9ef-hq`，登录 200。
- 验证：总后台通过 WordPress 请求 Cloudflare API 返回 HTTP 400（无令牌时的正常结果，已不被拦）；证书检查对 www.vialcovepeptides.com @ 34.231.148.231 通过，对错误 IP 失败。令牌由用户自己粘贴保存。
- 回滚：用备份恢复 `wp-config.php` 并 reload PHP；总后台指回 `6bb5242-hq`。

### CDN 配置核对 + 页面对齐（2026-11-08）

- 用户已保存令牌（05:42 验证通过）。只读核对：令牌看得到 vialcovepeptides.com、vialcoveresearch.com 两个 zone（都 active），DNS / SSL 设置 / 证书 / 缓存规则都能读，账户可见 1 个（自动添加新域名可用）。设置：自动配置开启，“域名不在 Cloudflare 时自动添加”关闭——不在 Cloudflare 的新域名会停在“域名还不在 Cloudflare”，开店任务一直等待解析（不会失败）。
- 开店链路核对：worker 写出的开店结果 root:vchq 0640，总后台可读；等待解析时任务持续重试；新店支付跟随支付中心（信用卡“全部已接入店铺”）；开店账号是新店管理员；新店域名白名单由 worker 追加。
- 代码 `0f4759f`：CDN 页“手动加入已有域名”一行对齐（输入框、下拉、按钮底边一致），说明文字另起一行。总后台 `d0ec9ef-hq` → `0f4759f-hq`，登录 200。
- 用户批准 "Turn it on"：CDN 设置开启“域名不在 Cloudflare 时自动添加”（`create_zones = true`），自动配置保持开启，令牌不变。

### 清空支付通道测试数据（用户批准 "Yes, delete all"，2026-11-08）

- 用户：清空测试店铺（主店 vialcovepeptides.com）里支付通道测试留下的全部数据和总后台交易记录；用户确认 4 个 PayPal 已付款订单（合计 USD 1,119.25）为测试。
- 备份：web 机 `/root/vialcove-backups/test-payment-cleanup-20261108T060344Z/store-db-full.sql.gz`（主店数据库全量）；admin 机同名目录 `hq-payment-ledger.sql.gz`（`wp_pc_pay_hub_attempts` / `_events` / `wp_pchq_daily`，第一次导出因 mysqldump 读不到配置为空，重导后完整）。均 root 0600。
- 店铺：订单 #11731–#11757 共 20 个强制删除（HPOS，含商品行 / 备注 / meta）；`pc_panapay_creations` 20、`pc_panapay_transactions` 5、`pc_recovery` 7、`pc_track_events` 70、`pc_track_visits` 9 条（仅这些订单）删除；4 个已扣库存订单按 WooCommerce 规则加回库存（DSIP 10mg 454→455、Tirzepatide 20mg 986→993、120mg 997→1000）；WELCOME5 使用次数 1→0；清页面缓存。剩余订单 0。
- 总后台：`wp_pc_pay_hub_attempts` 20、`wp_pc_pay_hub_events` 6 条删除（全部为 site 2），`wp_pchq_daily` site 2 的 103 行删除后重新拉取店铺汇总（31 天，订单 0、销售额 0，访客数保留）。支付中心显示“还没有付款记录”，待回写 0、需核对 0。
- 验证：主店结账可用网关仍为信用卡（来自总后台、已开启），首页 / 购物车 / 结账 200；信用卡付款方式配置未改动。
- 恢复方式：用上述备份导回对应表（会覆盖之后的新数据，需要先核对）。

### 2026-11-08 06:30 UTC 支付中心：原始付款方式也可彻底删除

- 原因：已删除列表里的原始付款方式（id `default`）没有“彻底删除”按钮。旧版接口和店铺迁移按 `default` 读取这条记录，所以原来的代码故意不让删。
- 改动：`default` 彻底删除时只清空参数和密钥，标记 `purged`，并从列表隐藏，记录本身保留；交易记录显示“（已彻底删除）”。其他付款方式仍直接移除。已彻底删除的不能恢复。
- 部署：HQ 新版本 `87380ae-hq`（基于 `0f4759f-hq`，只替换 `class-hub-accounts.php` 和 `class-hub-page.php`），已重载 PHP-FPM，登录页返回 200；已删除列表出现“彻底删除”按钮。正式的信用卡方式不受影响：开启、正式模式，可信域名 marina-style.com 加网关域名 backupaoy.top。
- 支付测试：清理后总后台和店铺都没有新的付款记录，跳转测试没有在系统里留下痕迹。

### 2026-11-08 06:35 UTC 结账图标：新增 Amazon Pay、Klarna、Cash App（peptide-panapay 0.4.7）

- 新增三个图标（`amazon_pay`、`klarna`、`cashapp`）。每个付款方式可选的图标上限从固定 6 个改为跟随图标总数（现为 9）。店铺按 HQ 下发的图标做校验，遇到不认识的图标会拒绝整份支付配置，所以先更新店铺，最后更新 HQ。
- 部署顺序：
  1. 店铺前台 `0f774c4-web01`（基于 `a5d531b-web01`）。首页、购物车 200；空购物车访问结账页 302 跳到购物车，属正常；图标文件 200。
  2. 新店模板 web/admin 两份已更新，备份在两台服务器的 `/home/ubuntu/vialcove-deploy-backups/panapay-icons-20261108/`。
  3. 店铺后台 `0f774c4-s0001`（基于 `a5d531b-s0001`）。
  4. HQ `0f774c4-hq`（基于 `87380ae-hq`）。登录页 200；编辑付款方式能看到 9 个图标并正常加载。
- 部署前核对：线上插件与 git 只差 HQ 专用文件和 tests，没有其他差异。
- 未保存付款方式配置：新图标由用户自己勾选保存。

### 2026-11-08 06:50 UTC 支付中心：每个付款方式可选收银台打开方式

- 新增“收银台打开方式”：按支付方返回（默认）、嵌入结账页、跳转到收银台。HQ 在创建成功后按设置改写类型并随这次付款保存。只改 HQ（`class-hub-accounts.php`、`class-hub.php`、`class-hub-page.php`），店铺不用更新。
- 部署：HQ `8161aa9-hq`（基于 `0f774c4-hq`），PHP 语法检查通过，登录页 200。两个付款方式（信用卡、WALLET）读取结果都是 `auto`，行为不变。编辑弹窗里新选项显示正常。未保存任何付款方式。
- WALLET 的可信域名仍为空。之前的记录里，PP 渠道的收银台在 `bestgoodsforyou.top`，信用卡在 `marina-style.com`。需要用 WALLET 实际跳转一次，再从付款记录读出真实域名。

### 2026-11-08 07:05 UTC 优惠码：未付款订单不再占用使用次数

- 问题：WELCOME5 每位顾客限用 1 次，WooCommerce 把“待付款”订单也算一次。顾客看不到待付款订单，第一次付款失败后重新下单，WELCOME5 会被自动移除。
- 走过的弯路：曾直接把 WELCOME5 的每人限用改成 0，结果首单优惠（弹窗）要求限用 1 次而失效（`First_Order_Offer::coupon()` 返回 null），已立即改回 1，首单优惠恢复正常。
- 修复：`peptide-core/includes/class-coupon-rules.php` 增加 `woocommerce_update_coupon_usage_invalid_statuses` 过滤，加入 `pending`。付款成功（处理中/已完成/保留）才计次，取消、失败退回。首单规则（只看已付款订单）不变。
- 部署：
  - 店铺前台 `637fb55-web01`（基于 `0f774c4-web01`）：首页、购物车 200，过滤生效，首单优惠 ok。
  - 店铺后台 `637fb55-s0001`：登录页 200。
  - 新店模板 web/admin：已更新，备份在 `/home/ubuntu/vialcove-deploy-backups/coupon-usage-20261108/`。
- 测试订单 #11765 在改动前已计入一次，60 分钟未付款自动取消后会退回。

### 2026-11-08 07:20 UTC 支付跳转验收（信用卡、WALLET）

- WALLET：请求模式改为异步（2）后，收银台为 `valewood-llc.com/pay/pay/`，可正常打开（同步模式下 `/pay/singlepay/` 是 404）。用户已把可信域名改为 valewood-llc.com，跳转正常（订单 #11766）。
- 信用卡：支付方把收银台改到 `nexus-globaltrade.com`（内嵌）。用户把它加进可信域名后，订单 #11768 的收银台已嵌在结账页里正常显示（Apple Pay、Google Pay、卡号表单）。
- 尚未验证：真实付款后的结果通知、订单自动变成已付款、顾客跳回店铺。测试订单 #11765–#11768 均未付款，会自动作废。

### 2026-11-08 07:35 UTC 支付中心：可查看支付方返回的参数

- 新表 `wp_pc_pay_hub_details`（独立标记 `pc_panapay_hub_details_db`，不改原有付款表版本），记录三类内容：
  - 创建付款失败或结果不明时，支付方的原始响应（HTTP 状态和返回 JSON 或文本）；
  - 签名验证通过的付款结果通知（全部字段）；
  - 顾客跳回时带的参数（去掉 token/reference）。
  签名、密钥、口令、卡号类字段打码，内容加密保存，每笔付款最多 20 条。读写失败一律忽略，不影响收款。
- 交易记录里，有返回内容的付款，“支付方确认”可点开查看；失败的标红。
- 部署：HQ `39a9181-hq`（基于 `8161aa9-hq`）。PHP 语法检查通过，登录页 200，表已创建。用订单 #11765 写入一条示例，确认打码和展开都正常后已删除。

### 2026-11-08 07:45 UTC 购物车推荐：用了优惠码也照常推荐

- 问题：顾客购物车里只要有优惠码（如弹窗送的 WELCOME5），接近满减档位时的商品推荐就被关掉（`coupon_conflict`）。
- 修复（peptide-core activity）：
  - 报价时带上顾客已用的优惠码，按 WooCommerce 原生规则计算。只接受原生百分比/固定金额类型，遇到未知类型整体放弃报价。
  - 会话重建的报价购物车会载入这些优惠码对象（`Quote_Cart::hydrate_applied_coupons`）。
  - 加购券 ADDON5 只在能叠加时加上；不能叠加或不适用这个商品时，仍推荐商品，只是不送券。加购流程接受“只加商品”的票据。
- 部署：
  - 店铺前台 `bc520fa-web01`，随后 `a84cf2e-web01`：补上从会话载入优惠码；排查时用过临时调试版本，已删除。
  - 店铺后台 `a84cf2e-s0001`。
  - 新店模板 web/admin，备份在 `/home/ubuntu/vialcove-deploy-backups/recommend-coupons-20261108/`。
- 实测（WELCOME5 + 5 盒 Tirzepatide，差 $25 到 $300 档）：推荐 Retatrutide $86.30，承诺多省 $34.31。点“Add 1 box”后，ADDON5 -$5、WELCOME5 -$18.06、满减 $25 都生效，合计与承诺一致。
