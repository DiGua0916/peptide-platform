# 全仓库代码检查报告（2026-11-06）

范围：`peptide-core`、`peptide-hq`、`peptide-ops`、`peptide-panapay` 四个插件和 `peptide-site` 主题，基于分支 `codex/checkout-acceptance-20261105`（`c7521f6`）。

**本次只检查，没有改动任何代码。**

## 0. 怎么查的

- **备份**：检查前把整个仓库备份到会话文件夹的 `backups/`：
  - `peptide-platform-all-branches-*.bundle`：36 个分支和完整历史，已校验；
  - `peptide-platform-c7521f6-*.zip`：当前代码。
- **规模**：不含测试的源码约 22 万行。
  - `peptide-core`：约 15.2 万行，7.7 MB；
  - `peptide-hq`：4.3 万行，其中约 1.1 MB 是复制来的 WordPress 文件；
  - 主题：1.8 万行；
  - `panapay`：0.6 万行；
  - `ops`：0.2 万行。
- **方法**：
  - 4 路并行只读审查，分别看后台性能、`peptide-core` 架构、主题、支付/总后台/运维；
  - 在沙盒上用命令行实际测量后台；
  - 下文标 ✅ 的条目，是我回到代码里亲自核实过的。
- **实测限制**：沙盒几乎没有访客和订单数据，所以和数据量相关的问题，在正式站上会比沙盒严重。正式站这次没有测。

**一句话结论**：安全写法总体规范（写操作都有 nonce 和权限检查，SQL 基本都用了 prepare）。后台卡顿有几个明确、好修的原因。真正的"屎山"集中在三类地方：
- 几个巨型文件；
- 同一个功能写了多套并行实现；
- 大段复制来的代码。

---

## 1. 后台为什么卡（按收益排序）

| # | 问题 | 位置 | 影响 | 改动大小 |
|---|---|---|---|---|
| 1 ✅ | 大多数商家后台页面会多加载约 3.5 MB 用不上的脚本（WordPress 和 WooCommerce 的命令面板）。代码里本来就有去掉它的逻辑，但只覆盖了 3 个页面。 | `peptide-core/includes/admin/class-workspace.php:158-170`，白名单只有 `pc-overview`、`pc-store-design-domains`、`pc-data` | 实测：主题装修、检测报告页要加载 53 个 JS 文件共 3.46 MB；仪表盘只加载 122 KB | 小 |
| 2 ✅ | 每个后台页面都会阻塞渲染，整页生成完之前浏览器一帧都不画（`<link rel="expect" blocking="render">`）。WooCommerce 的页面还会额外再等最多 700 ms。 | `peptide-core/includes/admin/class-workspace.php:37`；`peptide-hq/includes/admin/class-menu.php:163` | 慢页面会一直白屏，感觉像卡死 | 小 |
| 3 ✅ | 每个请求（前台、后台、轮询都算）都要执行 8 条检查表结构的 SQL（`SHOW FULL COLUMNS` / `SHOW INDEX`）。缓存只是请求内的静态变量，每次请求都从空开始。 | `peptide-core/includes/tracking/class-tracker.php:41`，`class-schema.php:35-42,164-168` | 每个请求多 8 条 SQL，叠加在所有轮询上 | 小 |
| 4 | 仪表盘一次打开要完整加载两遍后台：先输出一个外壳页，再用 iframe 加载真正的内容。 | `peptide-core/includes/admin/class-workspace-shell.php`，`assets/workspace-shell.js:113-142` | 登录后的落地页，启动时间翻倍 | 中 |
| 5 ✅ | 仪表盘在页面输出前同步做重统计：订单不设上限查询（`limit => -1`），再逐单、逐商品行查询。 | `peptide-core/includes/class-overview.php:86`；`class-workbench.php:26,78,123,169,198` | 订单越多越慢，正式站会比沙盒明显 | 中 |
| 6 ✅ | 轮询太频繁：仪表盘每 10 秒查实时访客、每 60 秒全量重算统计；访客页每 5 秒轮询一次。 | `peptide-core/assets/overview.js:371-392`；`assets/tracking-admin.js:171`；`includes/admin/class-tracking-page.php:75` | 多人同时开着后台时，数据库压力成倍增加 | 小 |
| 7 ✅ | 实时访客查询对全表做 `GROUP BY ... HAVING MAX(...)`，用不上索引；之后每个访客再单独查一次；`visitors.cart_key` 没有索引。 | `peptide-core/includes/tracking/class-query.php:106-174`；`tracking/class-carts.php:205-219` | 耗时随历史数据量乘以在线人数增长 | 小 到 中 |
| 8 ✅ | 检测报告页每次打开要查约 290 次数据库；报告库接口单次 440 ms。 | 后台页 `pc-product-reports`，接口 `/peptide/v1/report-library`；`includes/admin/class-report-library-page.php:114-125`、`class-report-metabox.php` | 实测：页面 101 ms、290 次查询；接口 440 ms、70 次查询 | 小 |
| 9 | 所有后台页都关掉了脚本合并（`concatenate_scripts=false`），而且每个请求都要对多个大 JS 文件做 sha256 哈希。 | `peptide-core/includes/admin/class-dialogs.php:17-24,46-113` | 请求数变多，每页多花一些 CPU | 中 |
| 10 ✅ | 写错了一个判断：`! self::SYSTEM_SLUG === $page` 永远不成立，导致 `store-settings.css` 被加载到所有后台页。 | `peptide-core/includes/admin/class-store-settings.php:28` | 每个后台页多一个 CSS | 极小 |
| 11 | `admin_init` 里同步向总后台发远程请求，超时设的是 15 秒。 | `peptide-core/includes/class-hq-connector.php:65,132-147` | 总后台慢或连不上时，偶尔会卡住 15 秒 | 小 |
| 12 | 总后台每个后台请求（包括每 4 秒一次的 ajax 轮询）都要做数据库和文件 I/O；"刷新所有店铺"在一个请求里同步串行请求所有店铺，每家超时 20 秒。 | `peptide-hq/includes/class-site-lifecycle.php:12,148-189`；`admin/class-menu.php:309-317` | 总后台越用越慢，店铺多了容易超时 | 小 |

实测参考（沙盒，命令行，无代码缓存，所以绝对值偏大，相对比较有效）：
- 后台启动加 `admin_init` 约 0.82 s、84 条查询；其中 `peptide-core` 占约 0.22 s、24 条查询。
- 所有后台页面都会执行的公共钩子合计约 62 ms。
- 大多数 `pc-*` 页面在服务端生成都很快（< 40 ms）。

所以后台的"卡"主要来自四处：
1. 前端要加载的脚本太多（第 1 条）；
2. 页面渲染被阻塞（第 2 条）；
3. 仪表盘的结构和统计方式（第 4、5、6 条）；
4. 每个请求都要付的固定开销（第 3 条）。

---

## 2. 需要优先处理的功能缺陷与安全风险

| # | 问题 | 位置 | 说明 |
|---|---|---|---|
| A ✅ | 报告的"仅已审核客户可见"实际上没有人能满足 | `peptide-core/includes/class-access.php:19,47`；`admin/class-report-metabox.php:83` | 全仓库没有任何代码写入 `pc_buyer_approved`；而登录审核用的是另一个标记 `_pc_pending_approval`（`class-store-config.php`）。商家选了这个选项后，除了员工没人能看到报告。 |
| B ✅ | 店铺的 HMAC 主密钥明文存在 `wp_options` 里，并且被用作运维接口的备用密钥 | `peptide-core/includes/class-hq-connector.php:108-113`；`peptide-ops/includes/class-runtime.php:17-21` | 一旦数据库泄露（比如备份外泄或 SQL 注入），就可能调用运维接口，上传并部署插件包、恢复备份。建议正式环境强制使用 `PEPTIDE_OPS_SERVER_KEY` 常量，并把运维密钥和登录/状态密钥分开。 |
| C ✅ | 建店成功后，明文管理员密码和数据库密码留在磁盘上 | `peptide-ops/includes/class-provisioner.php:56-61,476` | `credentials.json` 只在建店失败或拆除测试店时才删除。管理员密码设置之后再也没被用到，留着没有意义。 |
| D | 所有店铺共用一个注册密钥，注册时接受任意 http 地址，之后总后台会定期请求这些地址 | `peptide-hq/includes/class-register.php:28-44,88`；`class-client.php:35` | 任何一家店被攻破，攻击者就能让总后台去请求内网地址（SSRF）。建议改成每家店一次性的注册令牌，并改用 `wp_safe_remote_request`。 |
| E ✅ | 满减活动以负数费用、`taxable => false` 的方式实现 | `peptide-core/includes/class-promotions.php:742-748` | 开启税费后，计税基数不会因优惠而降低，可能多收税。现在的配置不计税，暂时不受影响；以后开税前必须实测。 |
| F | 运维任务共用一把全局锁，总后台的任务在锁内同步轮询，最长可达 40 分钟 | `peptide-ops/includes/class-runtime.php:122-123`；`peptide-hq/includes/class-operations.php:101-109` | 一家店卡住，所有店的备份、恢复、建店都要排队。店铺越多问题越明显。 |
| G | 运维私有存储从不清理，任务列表每 4 秒全量扫描一次 | `peptide-ops/includes/class-runtime.php:199`；`peptide-hq/assets/hq-operations.js:63,73` | `jobs`、`tickets`、`diagnostics`、`packages`（单个最大 256 MB）只增不减。 |
| H | 不再使用的 `/hq/staff` 路由仍对外开放，可以创建或删除店员 | `peptide-core/includes/class-hq-connector.php:289-313` | 总后台那边对应的页面已经不用了，这个接口只剩下攻击面。 |

---

## 3. "屎山"结构问题（长期重构）

1. ✅ **把 WordPress 核心的后台脚本整份复制了 13 个，约 1.1 MB，而且两个插件各放一份**（`peptide-core/assets/wordpress-dialogs/` 和 `peptide-hq/assets/wordpress-dialogs/`）。每份只改了 3 到 23 行，目的是把系统弹窗换成自定义对话框。WordPress 每次升级（包括安全修复）都要手工同步两份。
   - 建议：改为保留 WordPress 原版脚本，只用一个小脚本统一替换 `confirm`/`alert`。
   - 另外，`peptide-core/assets/woocommerce-dialogs/` 里还有一份 WooCommerce 的脚本副本。
2. ✅ **`class-commerce-blocks.php` 一个文件 290 KB、2447 行、108 个方法**。
   - 一个方法 `definitions()` 就有 714 行；
   - 有 162 行超过 300 字符，最长一行 1851 字符；
   - `render()` 里有 44 个 if 分支；
   - 17 个块各有新旧两套渲染路径并存。

   建议：每种块拆成一个独立的类，用注册表分发，按块逐个迁移。
3. **同一个功能写了多套**：
   - "最近浏览"有三套实现。其中旧 cookie 会让全站页面不缓存（`class-commerce-blocks.php:915`，✅）；v1 的后备分支永远不会执行，是死代码。
   - 优惠系统至少有 7 套：满减、优惠码、积分、首单、赠品套餐、运费折扣、推荐券。叠加规则分散在 4 个 `woocommerce_coupon_is_valid` 回调里，判断活动"未开始/进行中/已结束"的代码写了 5 份。
   - 签名 token 的生成和校验手写了 6 份。
   - "草稿/发布 + 乐观锁"的存储类有 4 个，几乎完全相同。
   - 金额格式化函数在 JS 里有 4 份、在 PHP 里有 4 份。
   - 购物车前端客户端有 2 套，旧购物车抽屉的 HTML 仍然输出在每个页面上。
4. **巨型类和巨型方法**：
   - `Store_Config`：1070 行，涉及约 37 个不同的钩子，横跨 8 个以上的业务领域；
   - `Registry::definitions()`：1068 行；
   - 编辑器的 `App()`：1370 行，22 个 `useState`；
   - `Ingest::handle()`：314 行、78 个 if；
   - `Data_Pages::register_rest()`：一个闭包里注册了 22 个路由；
   - `Plugin::boot()`：手写了 129 次 `register_hooks()`。
5. **手写的超长行**：
   - 主题有 214 行超过 300 字符；
   - `site.css` 的第 87 行一行就有 24329 字符；
   - 总后台、运维、支付插件合计 163 行超过 300 字符。

   这些代码没有构建步骤，所以既难审阅，体积上也没有真正压缩。
6. **三套翻译机制并存**：`.po` 文件、`customer-*.php` 数组、`gettext` 过滤器 + 整页 HTML 改写。另外：
   - `class-commerce-blocks.php` 里有 640 个直接写死的中文字符串；
   - 有 103 个直接写中文的 `WP_Error`。
7. **三个插件互相直接调用对方的内部代码，形成环**：总后台 ↔ 运维 ↔ 支付 ↔ 核心。无法单独部署或测试。
8. **死代码**：
   - AI 文案功能被硬编码关闭，但约 150 行相关代码还在（`class-content-tools.php:90`）；
   - 总后台的 `class-accounts-page.php` 从未注册；
   - `Provisioner::preflight`/`teardown_test` 没有任何调用方；
   - `pcAdminDialogs` 没有被任何 JS 读取。

---

## 4. 前台主题（影响顾客体验）

- **大量资源不分页面全站加载**：22 个 CSS（242 KB）和 12 个 JS（78 KB）在每个页面都加载，连结账页、账户页也要下载首页演示用的样式。代码里已经有 `vc_demo_home_active()` 这个判断函数，却只用了一次。
- **商品弹窗整包约 197 KB（17 个 JS）在页面加载时就下载**，而弹窗只有用户点击才会打开。建议改成点击或悬停时再加载。
- **9 个 MutationObserver 监听整个页面**，其中 3 个只在编辑器预览时才有用；3 个访客追踪用的观察器还监听了 `class`/`style` 的变化。
- **`modules-shared.js` 滚动时不节流**，每次滚动都强制重新计算布局；`visitor-video.js` 在页面上没有视频时也会每秒运行一次。
- **首页目录服务端一次渲染最多 200 张卡片**，前端再隐藏起来；每张卡片还会把"显示用的商品"重复计算 3 次。区块缓存已经减轻了这一点，但"只渲染第一页、其余按需加载"会更省。
- **216 个 CSS 选择器在多个文件里重复定义**，共有 106 个 `!important`。
- **用正则或字符串替换去改插件输出的 HTML**（`inc/campaign-bubble.php:26-46`）。插件那边一改，这里就会悄悄失效。
- **关于今天加的结账重试**：审查认为 `checkout-polish.js` 的 250 ms × 40 次重试和 MutationObserver 重复了。实际上它是因为在真实结账页上观察到 WooCommerce 先渲染空名字的占位行才加的，属于必要的兜底。可以在 WooCommerce 提供更可靠的事件后再收掉。

---

## 5. 建议的修复顺序

**第一批：小改动、大收益，约 1～2 天，先在沙盒验证**
1. 命令面板的白名单扩展到所有服务端渲染的 `pc-*` 页面（1-1）。
2. 去掉后台的渲染阻塞，或只保留在少数轻量页面（1-2）。
3. 表结构检查只在升级时运行，平时只比较版本号（1-3）。
4. 修正 `store-settings` 的判断条件（1-10）；`admin_init` 里的远程请求改成后台任务（1-11）。
5. 轮询间隔放宽到 30 秒，服务端加 30～60 秒缓存；实时访客查询改写，并给 `cart_key` 加索引（1-6、1-7）。
6. 修复"仅已审核客户可见"（2-A）；建店后删除 `credentials.json`（2-C）；删掉 `/hq/staff` 路由和总后台的死代码（2-H）。

**第二批：中等改动，约 1 周**
- 仪表盘改为直接渲染，不再走外壳页 + iframe；统计改查 WooCommerce 的分析表，并加缓存（1-4、1-5）。
- 修复检测报告页和报告库的 N+1 查询（1-8）。
- 主题资源按页面加载，商品弹窗改为按需加载（第 4 节前两条）。
- 运维私有存储加保留期清理（2-G）；"刷新所有店铺"改成后台任务（1-12）。
- 运维密钥改用 wp-config 常量，并与登录密钥分开（2-B）；注册改成每家店一次性的令牌（2-D）。

**第三批：重构，持续进行，每一步都要有测试**
- 去掉复制的 WordPress 脚本，改用一个统一替换弹窗的小脚本（3-1）。
- 拆分 `Commerce_Blocks`、`Store_Config`、`Registry`（3-2、3-4）。
- 合并"最近浏览"、优惠叠加规则、签名 token、存储类、金额格式化、购物车客户端（3-3）。
- 引入构建步骤（JS/CSS 打包压缩），源码改回可读格式（3-5）。
- 三个插件之间改为通过公开接口通信（3-7）。

每一项都会先在本地和沙盒验证，只部署到沙盒，正式站上线前单独确认。

## 6. 未覆盖或未验证

- 正式站没有测。和数据量相关的问题（仪表盘统计、实时访客、报告库）在正式站上可能更严重。
- 第 1 节的耗时是在沙盒命令行下测的，没有代码缓存，所以绝对值偏大；浏览器里的实际感受建议登录沙盒后再测一次。
- 关于自托管视频：这台服务器（54.144.250.46）上没有任何视频文件，视频应该在另一台服务器（"独立站"密钥对应的那台）上，这次没有检查。
- 没有标 ✅ 的条目来自审查代理的读代码结论，有具体文件和行号，但没有逐一复核。修之前会再核实一遍。

---

## 7. 第一批修复（已完成，本地验证；尚未部署沙盒）

| 条目 | 改动 | 验证 |
|---|---|---|
| 1-1 多余的命令面板脚本 | `Workspace::trims_optional_assets()`：从原来 3 个页面扩展到所有 `pc-*` 页面，可视化编辑器 `pc-design-editor` 除外。和原来一样只出队根脚本，其他功能依赖的共享包仍会加载。 | 本地真实 WordPress：主题装修、检测报告页的 WordPress JS 从 53 个文件 3.46 MB 降到 8 个文件 122 KB；WooCommerce 自己的页面不变。 |
| 1-2 渲染阻塞 | 去掉 core 和 HQ 里的 `<link rel="expect" blocking="render">`，保留跨页过渡动画样式和就绪标记。 | `workspace-navigation-pure.php` 27/27。HQ 的 `navigation-meta.php` 在改动前就失败（"nine dedicated pages only"），与本次无关。 |
| 1-3 每请求 8 条 SHOW 语句 | `Schema::installed()`：严格检查 `ready()` 通过一次后，把结果写进自动加载的选项 `peptide_core_tracking_db_verified`（按版本、数据库名、表名生成指纹）。`install()` 会清除它；版本变化后它自然失效。 | 本地连续 3 个独立请求，SHOW 语句都是 0（原来每个请求 8 条）。单元测试覆盖：失效标记会强制重新检查；旧版本永远不算已安装。 |
| 1-6 轮询 | 仪表盘实时访客从 10 秒改为 30 秒；访客页从 5 秒改为 15 秒；`/overview` 接口在服务端按"日期范围 + 当天日期 + 语言"缓存 30 秒（权限检查在缓存之前；数据是全店统计，不区分用户）。 | 代码检查 |
| 1-7 实时访客查询 | 改成 `WHERE last_signal_ms >= ? GROUP BY …`，不再用 `HAVING MAX(…)`，可以走 `last_signal` 索引。 | 本地 310 行合成数据、5 个时间窗口：新旧查询结果和顺序完全一致，合成数据已删除。`cart_key` 索引需要升级表结构，放到第二批。 |
| 1-10 判断写错 | `store-settings.css` 改为在所有 `pc-*` 页面加载。这份样式被税费、运费折扣、语言、像素、应用等多个店铺页共用，所以不能只限 `pc-system`。 | 本地确认：商品列表等 WooCommerce 页面不再加载这份样式，店铺页仍然加载。 |
| 1-11 同步远程注册 | `maybe_register()` 不再同步调用，改为在 `shutdown` 时先 `fastcgi_finish_request()`（页面先发给浏览器）再注册。不依赖 WP-Cron，因为沙盒没有 cron 执行器。 | 相关的 smoke 测试已改成新的约定（需要连接真实总后台才能跑）。 |
| 2-A "仅已审核客户可见" | `Store_Config::approve()`（单个和批量都走这里）同时写入 `pc_buyer_approved`。`Access::is_approved()` 增加一种情况：开启账号审核（force login）时，已注册且不在待审核状态的账号也算已审核。未开启审核时行为不变。 | 单元测试覆盖 6 种情况。本地 `smoke-read-api` 18/18、`smoke-security` 23/23。 |
| 2-C 明文密码文件 | 建店进入 `complete` 后删除 `credentials.json`。 | 代码检查。之前已经建好的店，旧文件还在，需要在服务器上单独清理，要你确认后再做。 |

**这次没有做：2-H `/hq/staff` 路由。** 核实时发现它背后是一个真实的功能："从总后台创建和管理销售账号"，`smoke-hq-connector.php` 和 HQ 的 `smoke-client-nonce.php` 都在测它。总后台的入口页目前被隐藏了，但删掉这个接口属于产品决定，需要先确认这个功能是否还要。

**测试汇总**
- 新增 `tests/admin-batch1-pure.php` 29/29。
- 插件独立测试 181 个通过，仍是原来那 54 个依赖环境的失败。
- 主题 387/387。
- 本地真实 WordPress：`smoke-read-api` 18/18、`smoke-security` 23/23、`smoke-pixels` 42/42。
- 另有两个失败在改动前就存在，和改动无关：`smoke-order-batches` 17/18（"my-account shows batch and report link"）、`smoke-tracking` 16/17（本地 SQLite 的限制）。
